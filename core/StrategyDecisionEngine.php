<?php
declare(strict_types=1);

namespace Prometheus\core;

/** Separates trading policy from market prediction. All inputs are point-in-time snapshots. */
final class StrategyDecisionEngine
{
    private const HORIZON_WEIGHTS = ['15m' => 0.40, '1h' => 0.30, '4h' => 0.20, '24h' => 0.10];

    public function decide(string $mode, ?array $prediction, array $horizons, array $position, array $config, array $evidence, bool $fresh): array
    {
        if ($prediction && !empty($prediction['created_at']) && strtotime((string)$prediction['created_at']) > time()) {
            return $this->result('NO_TRADE', $prediction, null, null, 0.0, ['FUTURE_PREDICTION'], 'Previsão futura rejeitada pelo simulador.');
        }
        if ($mode === 'DIRECTIONAL') {
            return $this->directional($prediction, $position, $config, $fresh);
        }
        return $this->strategy($prediction, $horizons, $position, $config, $evidence, $fresh);
    }

    private function directional(?array $p, array $position, array $config, bool $fresh): array
    {
        if (!$fresh) return $this->result('NO_TRADE', $p, null, null, 0.0, ['STALE_DATA'], 'Cotação ou previsão indisponível/desatualizada.');
        if (!$p || $p['predicted_direction'] === 'INDETERMINATE') return $this->result('INDETERMINATE', $p, null, null, 0.0, ['INDETERMINATE'], 'A previsão não indica uma direção definida.');
        $desired = $p['predicted_direction'] === 'UP' ? 'LONG' : 'SHORT';
        $score = $desired === 'LONG' ? 1.0 : -1.0;
        $current = $position['position_side'] ?? 'FLAT';
        if ($current === $desired) return $this->result('HOLD_' . $desired, $p, $score, null, 0.0, ['POSITION_ALREADY_ALIGNED', 'SIGNAL_CONFIRMED'], 'A posição simulada já acompanha a direção prevista.');
        if ($desired === 'SHORT' && empty($config['allow_short'])) {
            return $this->result($current === 'LONG' ? 'CLOSE_LONG' : 'NO_TRADE', $p, $score, null, 0.0, ['SHORT_DISABLED'], 'Previsão de baixa; short sintético está desativado.');
        }
        if ($current === 'FLAT') return $this->result('OPEN_' . $desired, $p, $score, null, 0.0, ['SIGNAL_CONFIRMED'], 'Modo direcional: a posição acompanha a previsão, sem afirmar rentabilidade.');
        $decision = 'CLOSE_' . $current;
        if (($config['reversal_policy'] ?? 'CLOSE_REVERSE') === 'CLOSE_REVERSE') $decision .= '+OPEN_' . $desired;
        return $this->result($decision, $p, $score, null, 0.0, ['SIGNAL_CONFIRMED', 'DIRECTION_REVERSAL'], 'A previsão inverteu a posição; fecha e aplica a política de reversão configurada.');
    }

    private function strategy(?array $p, array $horizons, array $position, array $config, array $evidence, bool $fresh): array
    {
        if (!$fresh) return $this->result('NO_TRADE', $p, null, null, 0.0, ['STALE_DATA'], 'Dados insuficientes ou desatualizados.');
        if (!$p || $p['predicted_direction'] === 'INDETERMINATE') return $this->result('NO_TRADE', $p, null, null, 0.0, ['INDETERMINATE'], 'Sem direção definida para a previsão de 15 minutos.');
        if ((float)$p['confidence'] < (float)$config['min_confidence']) return $this->result('NO_TRADE', $p, null, null, 0.0, ['LOW_CONFIDENCE'], 'Confiança abaixo do limite configurado.');

        $score = 0.0; $weightSum = 0.0; $availableDirections = [];
        foreach (self::HORIZON_WEIGHTS as $horizon => $weight) {
            if (empty($horizons[$horizon])) continue;
            $hp = $horizons[$horizon];
            if ($hp['predicted_direction'] === 'INDETERMINATE') continue;
            $sign = $hp['predicted_direction'] === 'UP' ? 1.0 : -1.0;
            $margin = abs((float)$hp['probability_up'] - (float)$hp['probability_down']);
            $confidence = max(0.0, min(1.0, (float)$hp['confidence']));
            $score += $weight * $sign * $margin * $confidence;
            $weightSum += $weight;
            $availableDirections[] = $hp['predicted_direction'];
        }
        if ($weightSum <= 0) return $this->result('NO_TRADE', $p, null, null, 0.0, ['HORIZON_DISAGREEMENT'], 'Não há previsões de horizonte disponíveis para confirmação.');
        $score /= $weightSum;
        $agree = count(array_filter($availableDirections, static fn($d) => $d === $p['predicted_direction']));
        if ($agree < 2) return $this->result('NO_TRADE', $p, $score, null, 0.0, ['HORIZON_DISAGREEMENT'], 'Os horizontes não confirmam a direção de 15 minutos.');
        if (empty($evidence['n']) || (int)$evidence['n'] < 30 || $evidence['avg_abs_return_pct'] === null) {
            return $this->result('NO_TRADE', $p, $score, null, 0.0, ['INSUFFICIENT_HISTORY'], 'Aguardando pelo menos 30 resultados históricos já conhecidos para estimar magnitude.');
        }

        $expected = abs($score) * (float)$evidence['avg_abs_return_pct'];
        $cost = 2.0 * ((float)$config['fee_pct'] + (float)$config['slippage_pct']);
        $safety = (float)$config['min_edge_pct'];
        $side = $score > 0 ? 'LONG' : 'SHORT';
        $codes = [];
        if ($side === 'SHORT' && empty($config['allow_short'])) $codes[] = 'SHORT_DISABLED';
        if (abs($score) < 0.10) $codes[] = 'HORIZON_DISAGREEMENT';
        if ($expected <= $cost + $safety) $codes[] = 'COST_TOO_HIGH';
        if (!$codes) $codes = ['SIGNAL_CONFIRMED'];
        $current = $position['position_side'] ?? 'FLAT';
        if (!$codes || $codes === ['SIGNAL_CONFIRMED']) {
            if ($current === $side) return $this->result('HOLD_' . $side, $p, $score, $expected, $cost, ['POSITION_ALREADY_ALIGNED'], 'Expectativa líquida supera custos; mantém a posição alinhada.');
            if ($current !== 'FLAT') {
                $decision = 'CLOSE_' . $current;
                if (($config['reversal_policy'] ?? 'CLOSE_REVERSE') === 'CLOSE_REVERSE') $decision .= '+OPEN_' . $side;
                return $this->result($decision, $p, $score, $expected, $cost, ['SIGNAL_CONFIRMED', 'DIRECTION_REVERSAL'], 'Expectativa líquida positiva e confirmação; aplica a política de reversão.');
            }
            return $this->result('OPEN_' . $side, $p, $score, $expected, $cost, ['SIGNAL_CONFIRMED'], 'Concordância de horizontes e expectativa estimada acima dos custos e margem.');
        }
        if ($current !== 'FLAT' && $current !== $side) return $this->result('CLOSE_' . $current, $p, $score, $expected, $cost, $codes, 'Fecha a posição após perda da condição de entrada; não abre a posição oposta.');
        return $this->result('NO_TRADE', $p, $score, $expected, $cost, $codes, 'A expectativa estimada não passa os filtros de estratégia.');
    }

    private function result(string $action, ?array $p, ?float $score, ?float $expected, float $cost, array $codes, string $explanation): array
    {
        return ['action' => $action, 'prediction_id' => $p ? (int)$p['id'] : null, 'score' => $score,
            'expected_edge_pct' => $expected, 'estimated_cost_pct' => $cost,
            'confidence' => $p ? (float)$p['confidence'] : null, 'reason_codes' => $codes,
            'explanation' => $explanation];
    }
}
