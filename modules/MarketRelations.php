<?php
declare(strict_types=1);

namespace Prometheus\modules;

use Prometheus\core\AsOfTime;
use Prometheus\core\Database;
use Prometheus\core\HttpClient;
use Prometheus\core\Logger;
use Prometheus\core\Signal;

/**
 * MARKET RELATIONS — relações de mercado reais (Item 12).
 *
 * NÃO é momentum do próprio BTC (isso é papel da ATHENA).
 * Coleta séries de ativos relacionados disponíveis nas fontes já usadas:
 *  - ETHUSDT (Binance)  — beta cripto
 *  - PAXGUSDT (Binance)  — ouro tokenizado (proxy de ouro/避险)
 *  - market_data do BTC  — baseline para correlação
 *
 * Para cada par BTC↔ativo calcula correlação de Pearson em janelas
 * (4h, 24h de retornos por minuto). O sinal combina:
 *  1. Divergência: se o ativo correlacionado movimentou e o BTC ainda não
 *     acompanhou na mesma direção, a relação sugere catch-up.
 *  2. Força da relação (|ρ|) modula a confiança — correlação fraca reduz peso.
 *
 * Correlação histórica NÃO é tratada como causalidade: o módulo apenas
 * mede co-movimento recente e o quanto o par andou junto na janela.
 */
final class MarketRelations
{
    /** pares externos: symbol Binance → papel no sinal. */
    private const EXTERNAL = [
        'ETHUSDT'  => ['label' => 'ETH',  'weight' => 1.0],
        'PAXGUSDT' => ['label' => 'GOLD', 'weight' => 0.5],
    ];

    /**
     * Relações macro (FRED, já coletadas pelo MacroCollector): série macro →
     * papel no sinal. Direção = impacto esperado da SUBIDA da série em BTC.
     * Contribuição = (z-score do retorno acumulado da série vs BTC) modulado
     * pela correlação na janela. S/P e Nasdaq NÃO adicionados: sem fonte
     * confiável disponível no projeto (FRED não publica cotações intradiárias
     * de índices de capital; SP500 via FRED é diário e atrasado).
     */
    private const MACRO_RELATIONS = [
        // Subida do dólar pressiona BTC → correlação esperada negativa;
        // divergência dólar↓/BTC↓ sugere catch-up bearish.
        'dollar_index_proxy' => ['label' => 'DXY_PROXY', 'weight' => 0.6, 'direction' => -1],
        // Yield de 10y: risco real vs cripto.
        'us_10y_yield'       => ['label' => 'US10Y', 'weight' => 0.5, 'direction' => -1],
    ];
    /** mín. de observações macro na janela para calcular correlação válida. */
    private const MACRO_MIN_OBS = 5;

    private HttpClient $http;

    public function __construct(?HttpClient $http = null)
    {
        $this->http = $http ?: new HttpClient();
    }

    public function signal(string $horizon = '1h', string $symbol = 'BTCUSDT'): Signal
    {
        $btc = $this->closes($symbol, 240); // 240 candles 1m = 4h
        if (count($btc) < 60) {
            return new Signal('MARKET_RELATIONS', 0.0, 0.1, ['reason' => 'insufficient_btc_data', 'horizon' => $horizon]);
        }

        $contributions = [];
        $scoreSum = 0.0;
        $weightSum = 0.0;
        $confidenceSum = 0.0;
        $sourcesUnavailable = [];

        foreach (self::EXTERNAL as $pair => $cfg) {
            $ext = $this->closes($pair, 240);
            if (count($ext) < 60) {
                $sourcesUnavailable[] = $pair;
                Logger::info('market_relations_pair_unavailable', ['pair' => $pair, 'obs' => count($ext)]);
                continue;
            }

            $n = min(count($btc), count($ext));
            $btcR = $this->returns(array_slice($btc, -$n));
            $extR = $this->returns(array_slice($ext, -$n));

            $rho24h = $this->pearson($btcR, $extR);
            // Janela curta (última hora) para capturar mudança de regime de correlação.
            $rho1h = $this->pearson(array_slice($btcR, -60), array_slice($extR, -60));

            if ($rho24h === null || $rho1h === null) {
                $sourcesUnavailable[] = $pair;
                continue;
            }

            $strength = abs($rho24h); // 0..1
            // Divergência: retorno acumulado do ativo vs BTC na janela 24h.
            $btcCum = array_sum($btcR);
            $extCum = array_sum($extR);
            // Se o par se moveu junto (ρ>0) mas o BTC ficou para trás → catch-up na direção do par.
            $lead = $extCum - $btcCum;
            $contrib = max(-1.0, min(1.0, $lead * 80.0)) * ($rho24h > 0 ? 1 : -1) * $strength * $cfg['weight'];

            $contributions[$cfg['label']] = [
                'pair' => $pair,
                'pearson_24h' => round($rho24h, 3),
                'pearson_1h' => round($rho1h, 3),
                'previous_pearson_24h' => $this->previousPearson($btcR, $extR, 60),
                'btc_cum_return' => round($btcCum, 5),
                $cfg['label'] . '_cum_return' => round($extCum, 5),
                'sample_size' => $n,
                'contribution' => round($contrib, 4),
                'obs' => $n,
            ];
            $scoreSum += $contrib;
            $weightSum += $cfg['weight'];
            $confidenceSum += $strength * $cfg['weight'];
        }

        if ($weightSum === 0.0) {
            return new Signal('MARKET_RELATIONS', 0.0, 0.1, [
                'reason' => 'no_external_series_available',
                'sources_unavailable' => $sourcesUnavailable ?: array_keys(self::EXTERNAL),
                'horizon' => $horizon,
            ]);
        }

        // ===== Relações macro reais (FRED): dólar e yields vs BTC =====
        $macro = $this->macroRelations($btcR, $contributions, $scoreSum, $weightSum, $confidenceSum);
        $scoreSum = $macro['scoreSum'];
        $weightSum = $macro['weightSum'];
        $confidenceSum = $macro['confidenceSum'];

        if ($weightSum === 0.0) {
            return new Signal('MARKET_RELATIONS', 0.0, 0.1, [
                'reason' => 'no_external_series_available',
                'sources_unavailable' => $sourcesUnavailable ?: array_keys(self::EXTERNAL),
                'horizon' => $horizon,
            ]);
        }

        $value = max(-1.0, min(1.0, $scoreSum / $weightSum));
        $confidence = max(0.1, min(0.7, $confidenceSum / $weightSum));

        return new Signal('MARKET_RELATIONS', $value, $confidence, [
            'contributions' => $contributions,
            'sources_unavailable' => $sourcesUnavailable,
            'horizon' => $horizon,
        ]);
    }

    /**
     * Correlação/janela entre retornos macro e BTC (mesmos timestamps que o
     * módulo já usa). Somente séries com histórico real suficiente entram.
     */
    private function macroRelations(array $btcR, array &$contributions, float $scoreSum, float $weightSum, float $confidenceSum): array
    {
        foreach (self::MACRO_RELATIONS as $metric => $cfg) {
            $macroR = $this->macroReturns($metric);
            if ($macroR === null || count($macroR) < self::MACRO_MIN_OBS) {
                $contributions[$cfg['label']] = [
                    'series' => $metric,
                    'status' => 'UNAVAILABLE',
                    'obs' => $macroR === null ? 0 : count($macroR),
                    'contribution' => null,
                ];
                continue;
            }
            $n = min(count($btcR), count($macroR));
            $btcSlice = array_slice($btcR, -$n);
            $macSlice = array_slice($macroR, -$n);
            $rho = $this->pearson($btcSlice, $macSlice);
            if ($rho === null) {
                $contributions[$cfg['label']] = ['series' => $metric, 'status' => 'UNAVAILABLE', 'reason' => 'insufficient_variance', 'contribution' => null];
                continue;
            }
            // Divergência: z-score do spread de retornos acumulados (macro vs BTC).
            $cumBtc = array_sum($btcSlice);
            $cumMacro = array_sum($macSlice);
            $spread = $cumMacro - $cumBtc * $rho;
            // Normaliza o spread pelo desvio-padrão dos retornos macro (escala real).
            $std = $this->stddev($macSlice);
            $contrib = $std > 1e-12
                ? max(-1.0, min(1.0, ($spread / max(1e-12, $std * sqrt(max(1, $n)))) * $cfg['direction'])) * abs($rho)
                : 0.0;
            $contributions[$cfg['label']] = [
                'series' => $metric,
                'window' => $n,
                'correlation' => round($rho, 3),
                'previous_correlation' => $this->previousPearson($btcSlice, $macSlice, max(2, (int)($n / 2))),
                'sample_size' => $n,
                'contribution' => round($contrib, 4),
            ];
            $scoreSum += $contrib * $cfg['weight'];
            $weightSum += $cfg['weight'];
            $confidenceSum += abs($rho) * $cfg['weight'];
        }
        return ['scoreSum' => $scoreSum, 'weightSum' => $weightSum, 'confidenceSum' => $confidenceSum];
    }

    /** retornos diários da série macro as-of (últimos 30d), alinhados por índice. */
    private function macroReturns(string $metric): ?array
    {
        $rows = Database::fetchAll(
            'SELECT value, observed_at FROM macro_data
             WHERE metric = ? AND ' . AsOfTime::sqlLowerBoundRelative('observed_at', '30 DAY') . '
               AND ' . AsOfTime::sqlUpperBound('observed_at') . '
               AND ingested_at IS NOT NULL AND ' . AsOfTime::sqlUtcUpperBound('ingested_at') . '
               AND temporal_quality IN ("EXACT","INGESTION_ONLY")
               AND (available_at IS NULL OR (' . AsOfTime::sqlUtcUpperBound('available_at') . ' AND ingested_at >= available_at))
             ORDER BY observed_at ASC LIMIT 60',
            [$metric]
        );
        if (count($rows) < 3) {
            return null;
        }
        $vals = array_map(fn($r) => (float)$r['value'], $rows);
        $out = [];
        for ($i = 1, $n = count($vals); $i < $n; $i++) {
            $out[] = ($vals[$i] - $vals[$i - 1]) / max(1e-12, abs($vals[$i - 1]));
        }
        return $out;
    }

    /** correlação da janela ANTERIOR (mudança de correlação). */
    private function previousPearson(array $x, array $y, int $window): ?float
    {
        if (count($x) <= $window || count($y) <= $window) {
            return null;
        }
        return $this->pearson(array_slice($x, -2 * $window, $window), array_slice($y, -2 * $window, $window));
    }

    private function stddev(array $vals): float
    {
        $n = count($vals);
        if ($n < 2) {
            return 0.0;
        }
        $m = array_sum($vals) / $n;
        return sqrt(array_sum(array_map(fn($v) => ($v - $m) ** 2, $vals)) / ($n - 1));
    }

    private function closes(string $symbol, int $limit): array
    {
        // Etapa 08 — filtro explícito de timeframe (nunca misturar intervalos).
        // As-of-time: em backtest, só candles <= T (sem look-ahead).
        $interval = prometheus_config('collector.market_interval', '1m');
        $rows = Database::fetchAll(
            'SELECT close_price, open_time FROM market_data WHERE symbol=? AND interval_name=? AND ' . AsOfTime::sqlUpperBound('close_time') . ' AND ingested_at IS NOT NULL AND temporal_quality IN ("EXACT","INGESTION_ONLY") AND ' . AsOfTime::sqlUtcUpperBound('ingested_at') . ' AND (available_at IS NULL OR (' . AsOfTime::sqlUtcUpperBound('available_at') . ' AND ingested_at >= available_at))'
            . ' ORDER BY open_time DESC LIMIT ' . (int)$limit,
            [$symbol, $interval]
        );
        if ($rows) {
            AsOfTime::assertNoFuture((string)$rows[0]['open_time'], 'MarketRelations');
        }
        return array_map(fn($r) => (float)$r['close_price'], array_reverse($rows));
    }

    /** retornos simples entre candles consecutivos. */
    private function returns(array $closes): array
    {
        $out = [];
        for ($i = 1; $i < count($closes); $i++) {
            $out[] = ($closes[$i] - $closes[$i - 1]) / max(1e-12, $closes[$i - 1]);
        }
        return $out;
    }

    /** correlação de Pearson; null se variância insuficiente. */
    private function pearson(array $x, array $y): ?float
    {
        $n = count($x);
        if ($n !== count($y) || $n < 10) {
            return null;
        }
        $mx = array_sum($x) / $n;
        $my = array_sum($y) / $n;
        $sxy = 0.0;
        $sxx = 0.0;
        $syy = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dx = $x[$i] - $mx;
            $dy = $y[$i] - $my;
            $sxy += $dx * $dy;
            $sxx += $dx * $dx;
            $syy += $dy * $dy;
        }
        if ($sxx < 1e-18 || $syy < 1e-18) {
            return null;
        }
        return $sxy / sqrt($sxx * $syy);
    }
}
