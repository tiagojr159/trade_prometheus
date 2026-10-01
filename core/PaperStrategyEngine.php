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
        'SPOT_GRID' => 'Spot Grid · faixa e grade',
        'SPOT_DCA' => 'Spot DCA · compras escalonadas',
        'FUNDING_BIAS' => 'Funding · viés de carregamento',
        'REBALANCE' => 'Rebalanceamento · BTC/USDT',
        'VWAP_TREND' => 'VWAP · preço e volume',
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
        if ($mode === 'SPOT_GRID') return $this->grid($prediction, $position, $config, $candles1m);
        if ($mode === 'SPOT_DCA') return $this->spotDca($prediction, $position, $config, $candles1m);
        if ($mode === 'FUNDING_BIAS') return $this->fundingBias($prediction, $position, $config, $candles1m, $candles15m);
        if ($mode === 'REBALANCE') return $this->rebalance($prediction, $position, $config, $candles1m);
        if ($mode === 'VWAP_TREND') return $this->vwapTrend($prediction, $position, $config, $candles1m, $candles15m);
        if ($mode === 'ADAPTIVE') {
            $regime = strtoupper((string)($prediction['regime'] ?? ''));
            if (in_array($regime, ['BULLISH', 'BEARISH'], true)) return $this->momentum($prediction, $position, $config, $candles1m, $candles15m, 'ADAPTIVE_TREND');
            if (in_array($regime, ['VOLATILE', 'HIGH_VOLATILITY'], true)) return $this->breakout($prediction, $position, $config, $candles15m);
            if ($regime === 'SIDEWAYS') return $this->meanReversion($prediction, $position, $config, $candles1m, 'ADAPTIVE_RANGE');
            return $this->result('NO_TRADE', $prediction, null, null, 0.0, ['REGIME_FILTER'], 'Estratégia adaptativa aguarda: regime de alta volatilidade ou indefinido.');
        }

        return $this->result('NO_TRADE', $prediction, null, null, 0.0, ['UNKNOWN_STRATEGY'], 'Estratégia simulada desconhecida.');
    }

    /** Spot-grid proxy: buy near the lower grid edge and sell near its midpoint in a range. */
    private function grid(array $p, array $position, array $config, array $candles1m): array
    {
        $closes = $this->closes($candles1m);
        if (count($closes) < 20) return $this->result('NO_TRADE', $p, null, null, 0.0, ['WARMUP'], 'Spot Grid aguarda 20 candles fechados.');
        $price = (float)end($closes);
        $bands = BollingerBands::latest($closes, 20, 2.0);
        $rsi = RSI::latest($closes, 14);
        $current = (string)($position['position_side'] ?? 'FLAT');
        if ($rsi === null || (float)$bands['middle'] <= 0) return $this->result('NO_TRADE', $p, null, null, 0.0, ['WARMUP'], 'Bandas e RSI ainda não estão disponíveis.');

        if ($current === 'LONG' && $price >= (float)$bands['middle']) return $this->result('CLOSE_LONG', $p, 0.5 - $rsi / 100, null, 0.0, ['GRID_LEVEL_REACHED'], 'Fecha a compra quando o preço retorna ao centro da faixa.');
        if ($current === 'SHORT' && $price <= (float)$bands['middle']) return $this->result('CLOSE_SHORT', $p, 0.5 - $rsi / 100, null, 0.0, ['GRID_LEVEL_REACHED'], 'Fecha a venda quando o preço retorna ao centro da faixa.');

        if (strtoupper((string)($p['regime'] ?? '')) !== 'SIDEWAYS') {
            return $this->result($current === 'FLAT' ? 'NO_TRADE' : 'HOLD_' . $current, $p, null, null, 0.0, ['GRID_RANGE_FILTER'], 'A grade só abre posições no regime lateral.');
        }
        $side = null;
        $lowerWidth = max(1e-12, (float)$bands['middle'] - (float)$bands['lower']);
        if ($price <= (float)$bands['lower'] + 0.20 * $lowerWidth && $rsi <= 45) $side = 'LONG';
        $expected = $side === null ? 0.0 : abs((float)$bands['middle'] - $price) / max(1e-12, $price) * 100;
        return $this->signal($side, $p, $position, $config, $expected, 'SPOT_GRID', 'Spot Grid: entrada perto da borda, confirmação por RSI e saída no centro da faixa.');
    }

    /** Spot-DCA proxy with a base buy and at most two additional buys on defined drawdowns. */
    private function spotDca(array $p, array $position, array $config, array $candles1m): array
    {
        $closes = $this->closes($candles1m);
        if (count($closes) < 30) return $this->result('NO_TRADE', $p, null, null, 0.0, ['WARMUP'], 'Spot DCA aguarda 30 candles para a tendência de referência.');
        $price = (float)end($closes);
        $ema = EMA::calculate($closes, 26);
        $current = (string)($position['position_side'] ?? 'FLAT');
        $cost = 2.0 * ((float)$config['fee_pct'] + (float)$config['slippage_pct']);
        $minConfidence = (float)($config['min_confidence'] ?? 0.20);
        if ($current === 'SHORT') return $this->result('CLOSE_SHORT', $p, null, null, $cost, ['DCA_SPOT_ONLY'], 'Spot DCA encerra uma posição short antes de acumular BTC.');

        if ($current === 'LONG') {
            $entry = (float)($position['entry_price'] ?? 0.0);
            $entries = max(1, (int)($position['dca_entries'] ?? 1));
            $atr = ATR::latest($candles1m, 14);
            $stepPct = max(1.0, $atr !== null && $price > 0 ? ($atr / $price) * 125 : 1.0);
            $targetPct = max(0.5, (float)($config['take_profit_pct'] ?? 1.5));
            if ($entry > 0 && $price >= $entry * (1 + $targetPct / 100)) {
                return $this->result('CLOSE_LONG', $p, 1.0, $targetPct, $cost, ['DCA_TAKE_PROFIT'], 'Fecha a rodada DCA ao atingir o alvo sobre o preço médio.');
            }
            if ($entries < 3 && $entry > 0 && $price <= $entry * (1 - $stepPct / 100) && (float)($p['probability_up'] ?? 0.5) >= 0.45) {
                $expected = max(0.0, (($entry * (1 + $targetPct / 100)) - $price) / max(1e-12, $price) * 100);
                if ($expected <= $cost + max(0.0, (float)($config['min_edge_pct'] ?? 0.0))) {
                    return $this->result('HOLD_LONG', $p, null, $expected, $cost, ['NET_EDGE_TOO_LOW'], 'O próximo lote DCA fica suspenso: o alvo restante não cobre custos e margem.');
                }
                return $this->result('ADD_LONG', $p, 1.0, $expected, $cost, ['DCA_PRICE_DEVIATION'], sprintf('DCA adiciona lote %d de até 3 após recuo de %.2f%%; preço médio e custos são atualizados.', $entries + 1, $stepPct));
            }
            return $this->result('HOLD_LONG', $p, null, null, 0.0, ['DCA_WAITING_DEVIATION'], 'Mantém a posição e aguarda o próximo desvio de preço ou o alvo.');
        }

        $regime = strtoupper((string)($p['regime'] ?? ''));
        if (($p['predicted_direction'] ?? '') !== 'UP' || (float)($p['confidence'] ?? 0.0) < $minConfidence
            || in_array($regime, ['BEARISH', 'HIGH_VOLATILITY', 'VOLATILE'], true)
            || (float)end($ema) >= $price) {
            return $this->result('NO_TRADE', $p, null, null, 0.0, ['DCA_BASE_FILTER'], 'A compra-base exige previsão de alta, preço acima da EMA 26 e regime sem queda forte.');
        }
        $atr = ATR::latest($candles1m, 14);
        $expected = max($atr !== null && $price > 0 ? 2.0 * $atr / $price * 100 : 0.0, (float)($p['edge'] ?? 0.0) * 2.0);
        return $this->signal('LONG', $p, $position, $config, $expected, 'SPOT_DCA', 'Spot DCA: abre a compra-base com tendência curta positiva; lotes seguintes dependem de desvios definidos.');
    }

    /** Funding/crowding directional proxy. This is not a delta-neutral arbitrage pair. */
    private function fundingBias(array $p, array $position, array $config, array $candles1m, array $candles15m): array
    {
        $parts = $this->derivativeParts($p);
        $funding = isset($parts['funding_rate']['value']) && is_numeric($parts['funding_rate']['value']) ? (float)$parts['funding_rate']['value'] : null;
        $ratio = isset($parts['long_short_ratio']['value']) && is_numeric($parts['long_short_ratio']['value']) ? (float)$parts['long_short_ratio']['value'] : null;
        $oiZ = isset($parts['open_interest']['z']) && is_numeric($parts['open_interest']['z']) ? (float)$parts['open_interest']['z'] : null;
        if ($funding === null) return $this->result('NO_TRADE', $p, null, null, 0.0, ['FUNDING_DATA_UNAVAILABLE'], 'A previsão não contém uma observação de funding disponível.');

        $score = -max(-1.0, min(1.0, $funding / 0.0005)) * 0.70;
        if ($ratio !== null) $score += max(-1.0, min(1.0, (1.0 - $ratio) / 0.5)) * 0.30;
        if ($oiZ !== null && $oiZ > 1.0) $score *= 1.10;
        $score = max(-1.0, min(1.0, $score));
        $side = $score >= 0.35 ? 'LONG' : ($score <= -0.35 ? 'SHORT' : null);
        $closes = $this->closes($candles1m);
        $price = $closes ? (float)end($closes) : (float)($p['initial_price'] ?? 0.0);
        $atr = count($candles15m) > 14 ? ATR::latest($candles15m, 14) : (count($candles1m) > 14 ? ATR::latest($candles1m, 14) : null);
        $expected = $side === null ? 0.0 : abs($score) * ($atr !== null && $price > 0 ? 1.5 * $atr / $price * 100 : 0.0);
        $crowding = $ratio === null ? 'sem razão long/short' : sprintf('razão long/short %.3f', $ratio);
        $oi = $oiZ === null ? 'OI sem z-score' : sprintf('z-score de OI %.2f', $oiZ);
        return $this->signal($side, $p, $position, $config, $expected, 'FUNDING_BIAS', sprintf('Viés contrarian: funding %.5f%%, %s, %s. É um sinal direcional sem hedge spot/futuros.', $funding * 100, $crowding, $oi));
    }

    /** Holds BTC near the configured BTC/USDT exposure using partial paper fills. */
    private function rebalance(array $p, array $position, array $config, array $candles1m): array
    {
        $closes = $this->closes($candles1m);
        if (!$closes) return $this->result('NO_TRADE', $p, null, null, 0.0, ['WARMUP'], 'Rebalanceamento aguarda preço de referência.');
        $price = (float)end($closes);
        $side = (string)($position['position_side'] ?? 'FLAT');
        if ($side === 'SHORT') return $this->result('CLOSE_SHORT', $p, null, null, 0.0, ['REBALANCE_SPOT_ONLY'], 'O rebalanceamento BTC/USDT opera apenas com saldo spot e encerra o short sintético.');
        $target = max(0.05, min(0.95, (float)($config['allocation_pct'] ?? 25.0) / 100.0));
        $quantity = max(0.0, (float)($position['quantity_btc'] ?? 0.0));
        $cash = max(0.0, (float)($position['cash_balance'] ?? 0.0));
        $equity = max(1e-12, $cash + $quantity * $price);
        $weight = $quantity * $price / $equity;
        $band = 0.05;
        $cost = 2.0 * ((float)$config['fee_pct'] + (float)$config['slippage_pct']);
        $score = (float)($p['probability_up'] ?? 0.5) - (float)($p['probability_down'] ?? 0.5);
        if ($weight < $target - $band) {
            $action = $side === 'FLAT' ? 'OPEN_LONG' : 'ADD_LONG';
            return $this->result($action, $p, $score, ($target - $weight) * 100, $cost, ['REBALANCE_UNDERWEIGHT'], sprintf('Exposição BTC em %.1f%%; recompõe até a faixa-alvo de %.1f%%.', $weight * 100, $target * 100));
        }
        if ($weight > $target + $band) {
            return $this->result('TRIM_LONG', $p, $score, ($weight - $target) * 100, $cost, ['REBALANCE_OVERWEIGHT'], sprintf('Exposição BTC em %.1f%%; reduz parcialmente até a faixa-alvo de %.1f%%.', $weight * 100, $target * 100));
        }
        return $this->result($side === 'LONG' ? 'HOLD_LONG' : 'NO_TRADE', $p, $score, null, 0.0, ['REBALANCE_IN_BAND'], sprintf('Exposição BTC em %.1f%%, dentro da banda de ±5 p.p. do alvo de %.1f%%.', $weight * 100, $target * 100));
    }

    /** Volume-weighted trend filter using only candles available at prediction time. */
    private function vwapTrend(array $p, array $position, array $config, array $candles1m, array $candles15m): array
    {
        if (count($candles1m) < 60 || count($candles15m) < 3) return $this->result('NO_TRADE', $p, null, null, 0.0, ['WARMUP'], 'VWAP aguarda 60 candles de 1m e três candles de 15m.');
        $window = array_slice($candles1m, -60);
        $volumeSum = 0.0; $priceVolume = 0.0;
        foreach ($window as $candle) {
            $volume = max(0.0, (float)$candle['volume']);
            $volumeSum += $volume;
            $priceVolume += (float)$candle['close_price'] * $volume;
        }
        if ($volumeSum <= 0.0) return $this->result('NO_TRADE', $p, null, null, 0.0, ['VOLUME_UNAVAILABLE'], 'VWAP não opera sem volume utilizável.');
        $vwap = $priceVolume / $volumeSum;
        $closes = $this->closes($candles1m);
        $price = (float)end($closes);
        $recent15m = array_slice($candles15m, -3);
        $trend = (float)$recent15m[2]['close_price'] - (float)$recent15m[0]['close_price'];
        $lastVolume = (float)end($window)['volume'];
        $averageVolume = $volumeSum / count($window);
        $side = null;
        if ($price > $vwap * 1.001 && $trend > 0 && $lastVolume >= $averageVolume && ($p['predicted_direction'] ?? '') === 'UP') $side = 'LONG';
        elseif ($price < $vwap * 0.999 && $trend < 0 && $lastVolume >= $averageVolume && ($p['predicted_direction'] ?? '') === 'DOWN') $side = 'SHORT';
        $expected = $side === null ? 0.0 : abs($price - $vwap) / max(1e-12, $price) * 100;
        return $this->signal($side, $p, $position, $config, $expected, 'VWAP_TREND', 'VWAP: preço, volume recente, tendência de 15m e previsão precisam confirmar a mesma direção.');
    }

    private function derivativeParts(array $p): array
    {
        $signals = $p['signals_json'] ?? [];
        if (is_string($signals)) {
            $decoded = json_decode($signals, true);
            $signals = is_array($decoded) ? $decoded : [];
        }
        foreach ((array)$signals as $signal) {
            if (($signal['module'] ?? '') === 'HEPHAESTUS') {
                $metadata = $signal['metadata'] ?? [];
                if (is_string($metadata)) $metadata = json_decode($metadata, true) ?: [];
                $status = strtoupper((string)($signal['status'] ?? ($metadata['status'] ?? 'AVAILABLE')));
                if ($status !== 'AVAILABLE') return [];
                return is_array($metadata['parts'] ?? null) ? $metadata['parts'] : [];
            }
        }
        return [];
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
        if ($price <= (float)$bands['lower'] && $rsi <= 35) $side = 'LONG';
        elseif ($price >= (float)$bands['upper'] && $rsi >= 65) $side = 'SHORT';
        $expected = $side === 'LONG' ? ((float)$bands['middle'] - $price) / $price * 100 : ($side === 'SHORT' ? ($price - (float)$bands['middle']) / $price * 100 : 0.0);
        return $this->signal($side, $p, $position, $config, max(0.0, $expected), $label,
            'Reversão à média: extremo de Bollinger confirmado pelo RSI; alvo é a banda central.');
    }

    private function breakout(array $p, array $position, array $config, array $candles15m): array
    {
        if (count($candles15m) < 13) return $this->result('NO_TRADE', $p, null, null, 0.0, ['WARMUP'], 'Rompimento aguarda 12 candles anteriores de 15 minutos.');
        $current = array_pop($candles15m);
        $range = array_slice($candles15m, -12);
        $high = max(array_map(static fn(array $c): float => (float)$c['high_price'], $range));
        $low = min(array_map(static fn(array $c): float => (float)$c['low_price'], $range));
        $averageVolume = array_sum(array_map(static fn(array $c): float => (float)$c['volume'], $range)) / count($range);
        $close = (float)$current['close_price'];
        $volume = (float)$current['volume'];
        $side = null;
        if ($close > $high && $volume >= $averageVolume) $side = 'LONG';
        elseif ($close < $low && $volume >= $averageVolume) $side = 'SHORT';
        $atr = ATR::latest(array_merge($range, [$current]), 14);
        $expected = $atr !== null && $close > 0 ? $atr / $close * 100 : 0.0;
        return $this->signal($side, $p, $position, $config, $expected, 'BREAKOUT',
            'Rompimento: fechamento saiu do canal de 12 candles, com volume igual ou acima da média.');
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
        $required = $cost + max(0.0, (float)($config['min_edge_pct'] ?? 0.0));
        if ($expected <= $required) {
            $action = $current === 'FLAT' ? 'NO_TRADE' : 'CLOSE_' . $current;
            $reason = sprintf(
                'Entrada bloqueada: movimento técnico estimado (%.3f%%) não supera custos e margem (%.3f%%).',
                $expected,
                $required
            );
            return $this->result($action, $p, $score, $expected, $cost, ['NET_EDGE_TOO_LOW'], $reason);
        }
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
