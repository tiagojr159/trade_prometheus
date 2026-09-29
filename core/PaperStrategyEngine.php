<?php
declare(strict_types=1);

namespace Prometheus\core;

use Prometheus\indicators\ATR;
use Prometheus\indicators\BollingerBands;
use Prometheus\indicators\EMA;
use Prometheus\indicators\RSI;

/** Independent rule engines for the paper strategy tournament. */
final class PaperStrategyEngine
{
    private StrategyDecisionEngine $directional;

    private const MODES = [
        'PROMETHEUS' => 'PROMETHEUS · previsão direta',
        'MULTIHORIZON' => 'Multi-horizonte · confirmação',
        'INVERSE' => 'Inversa · contrária à previsão',
        'MOMENTUM' => 'Momentum · EMA 9/26',
        'MEAN_REVERSION' => 'Reversão à média · RSI/Bollinger',
        'BREAKOUT' => 'Rompimento · canal e volume',
        'ADAPTIVE' => 'Adaptativa · regime de mercado',
    ];

    public function __construct(?StrategyDecisionEngine $directional = null)
    {
        $this->directional = $directional ?: new StrategyDecisionEngine();
    }

    public static function modes(): array
    {
        return self::MODES;
    }

    public function decide(string $mode, array $prediction, array $position, array $config, array $candles1m = [], array $candles15m = []): array
    {
        if ($mode === 'INVERSE') {
            $inverse = $prediction;
            $inverse['predicted_direction'] = $prediction['predicted_direction'] === 'UP' ? 'DOWN' : ($prediction['predicted_direction'] === 'DOWN' ? 'UP' : 'INDETERMINATE');
            $decision = $this->directional->decide('DIRECTIONAL', $inverse, [], $position, $config, [], true);
            $decision['explanation'] = 'Estratégia inversa: opera contra a direção prevista pelo PROMETHEUS. ' . $decision['explanation'];
            $decision['reason_codes'] = array_merge(['INVERSE_SIGNAL'], $decision['reason_codes']);
            return $decision;
        }

        if ($mode === 'MOMENTUM') return $this->momentum($prediction, $position, $config, $candles1m, $candles15m, 'MOMENTUM');
        if ($mode === 'MEAN_REVERSION') return $this->meanReversion($prediction, $position, $config, $candles1m, 'MEAN_REVERSION');
        if ($mode === 'BREAKOUT') return $this->breakout($prediction, $position, $config, $candles15m);
        if ($mode === 'ADAPTIVE') {
            $regime = strtoupper((string)($prediction['regime'] ?? ''));
            if (in_array($regime, ['BULLISH', 'BEARISH'], true)) return $this->momentum($prediction, $position, $config, $candles1m, $candles15m, 'ADAPTIVE_TREND');
            if ($regime === 'SIDEWAYS') return $this->meanReversion($prediction, $position, $config, $candles1m, 'ADAPTIVE_RANGE');
            return $this->result('NO_TRADE', $prediction, null, null, 0.0, ['REGIME_FILTER'], 'Estratégia adaptativa aguarda: regime de alta volatilidade ou indefinido.');
        }

        return $this->result('NO_TRADE', $prediction, null, null, 0.0, ['UNKNOWN_STRATEGY'], 'Estratégia simulada desconhecida.');
    }

    private function momentum(array $p, array $position, array $config, array $candles1m, array $candles15m, string $label): array
    {
        $closes = $this->closes($candles1m);
        if (count($closes) < 30 || count($candles15m) < 5) return $this->result('NO_TRADE', $p, null, null, 0.0, ['WARMUP'], 'Momentum aguarda candles suficientes para EMA e confirmação de 15 minutos.');
        $fast = EMA::calculate($closes, 9);
        $slow = EMA::calculate($closes, 26);
        $price = (float)end($closes);
        $recent15m = array_slice($candles15m, -5);
        $trend15m = (float)$recent15m[4]['close_price'] - (float)$recent15m[0]['close_price'];
        $side = null;
        if ((float)end($fast) > (float)end($slow) && $price > (float)end($fast) && $trend15m > 0) $side = 'LONG';
        if ((float)end($fast) < (float)end($slow) && $price < (float)end($fast) && $trend15m < 0) $side = 'SHORT';
        $spreadPct = abs((float)end($fast) - (float)end($slow)) / max(1e-12, $price) * 100;
        $trendPct = abs($trend15m) / max(1e-12, (float)$recent15m[0]['close_price']) * 100;
        return $this->signal($side, $p, $position, $config, max($spreadPct, $trendPct * 0.5), $label,
            'Momentum: EMA 9/26 e movimento de 15 minutos confirmam a mesma direção.');
    }

    private function meanReversion(array $p, array $position, array $config, array $candles1m, string $label): array
    {
        $closes = $this->closes($candles1m);
        if (count($closes) < 20) return $this->result('NO_TRADE', $p, null, null, 0.0, ['WARMUP'], 'Reversão à média aguarda 20 candles para RSI e bandas de Bollinger.');
        $price = (float)end($closes);
        $rsi = RSI::latest($closes, 14);
        $bands = BollingerBands::latest($closes, 20, 2.0);
        if ($rsi === null || $bands['middle'] === null || (float)$bands['middle'] <= 0) return $this->result('NO_TRADE', $p, null, null, 0.0, ['WARMUP'], 'Indicadores de reversão ainda não estão disponíveis.');

        $current = (string)($position['position_side'] ?? 'FLAT');
        if ($current === 'LONG' && ($price >= (float)$bands['middle'] || $rsi >= 50)) return $this->result('CLOSE_LONG', $p, 0.5 - $rsi / 100, null, 0.0, ['MEAN_REACHED'], 'Fecha LONG: preço voltou à média ou RSI cruzou 50.');
        if ($current === 'SHORT' && ($price <= (float)$bands['middle'] || $rsi <= 50)) return $this->result('CLOSE_SHORT', $p, 0.5 - $rsi / 100, null, 0.0, ['MEAN_REACHED'], 'Fecha SHORT: preço voltou à média ou RSI cruzou 50.');

        $side = null;
        if ($price <= (float)$bands['lower'] && $rsi <= 30) $side = 'LONG';
        elseif ($price >= (float)$bands['upper'] && $rsi >= 70) $side = 'SHORT';
        $expected = $side === 'LONG' ? ((float)$bands['middle'] - $price) / $price * 100 : ($side === 'SHORT' ? ($price - (float)$bands['middle']) / $price * 100 : 0.0);
        return $this->signal($side, $p, $position, $config, max(0.0, $expected), $label,
            'Reversão à média: extremo de Bollinger confirmado pelo RSI; alvo é a banda central.');
    }

    private function breakout(array $p, array $position, array $config, array $candles15m): array
    {
        if (count($candles15m) < 21) return $this->result('NO_TRADE', $p, null, null, 0.0, ['WARMUP'], 'Rompimento aguarda 20 candles anteriores de 15 minutos.');
        $current = array_pop($candles15m);
        $range = array_slice($candles15m, -20);
        $high = max(array_map(static fn(array $c): float => (float)$c['high_price'], $range));
        $low = min(array_map(static fn(array $c): float => (float)$c['low_price'], $range));
        $averageVolume = array_sum(array_map(static fn(array $c): float => (float)$c['volume'], $range)) / count($range);
        $close = (float)$current['close_price'];
        $volume = (float)$current['volume'];
        $side = null;
        if ($close > $high && $volume >= 1.25 * $averageVolume) $side = 'LONG';
        elseif ($close < $low && $volume >= 1.25 * $averageVolume) $side = 'SHORT';
        $atr = ATR::latest(array_merge($range, [$current]), 14);
        $expected = $atr !== null && $close > 0 ? $atr / $close * 100 : 0.0;
        return $this->signal($side, $p, $position, $config, $expected, 'BREAKOUT',
            'Rompimento: fechamento saiu do canal de 20 candles com volume pelo menos 25% acima da média.');
    }

    private function signal(?string $side, array $p, array $position, array $config, float $expected, string $label, string $reason): array
    {
        $current = (string)($position['position_side'] ?? 'FLAT');
        $score = $side === 'LONG' ? 1.0 : ($side === 'SHORT' ? -1.0 : null);
        $cost = 2.0 * ((float)$config['fee_pct'] + (float)$config['slippage_pct']);
        if ($side === null) {
            return $this->result($current === 'FLAT' ? 'NO_TRADE' : 'HOLD_' . $current, $p, $score, null, $cost, ['NO_SIGNAL'], 'A regra técnica não confirmou uma nova entrada.' );
        }
        if ($side === 'SHORT' && empty($config['allow_short'])) return $this->result($current === 'LONG' ? 'CLOSE_LONG' : 'NO_TRADE', $p, $score, $expected, $cost, ['SHORT_DISABLED'], 'SHORT sintético está desativado.');
        if ($current === $side) return $this->result('HOLD_' . $side, $p, $score, $expected, $cost, ['POSITION_ALREADY_ALIGNED'], 'Mantém a posição alinhada com a regra técnica.');
        $required = $cost + max(0.0, (float)$config['min_edge_pct']);
        if ($expected <= $required) return $this->result('NO_TRADE', $p, $score, $expected, $cost, ['COST_TOO_HIGH'], 'Entrada bloqueada: alvo técnico estimado não cobre taxas, slippage e margem.');
        if ($current === 'FLAT') return $this->result('OPEN_' . $side, $p, $score, $expected, $cost, ['TECHNICAL_SIGNAL'], $reason);
        $action = 'CLOSE_' . $current;
        if (($config['reversal_policy'] ?? 'CLOSE_REVERSE') === 'CLOSE_REVERSE') $action .= '+OPEN_' . $side;
        return $this->result($action, $p, $score, $expected, $cost, ['TECHNICAL_SIGNAL', 'DIRECTION_REVERSAL'], $reason);
    }

    private function closes(array $candles): array
    {
        return array_map(static fn(array $candle): float => (float)$candle['close_price'], $candles);
    }

    private function result(string $action, array $p, ?float $score, ?float $expected, float $cost, array $codes, string $explanation): array
    {
        return ['action' => $action, 'prediction_id' => (int)($p['id'] ?? 0), 'score' => $score,
            'expected_edge_pct' => $expected, 'estimated_cost_pct' => $cost,
            'confidence' => (float)($p['confidence'] ?? 0), 'reason_codes' => $codes,
            'explanation' => $explanation];
    }
}
