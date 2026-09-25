<?php
declare(strict_types=1);

namespace Prometheus\intelligence;

use Prometheus\core\AsOfTime;
use Prometheus\core\Signal;

/**
 * SIGNAL NORMALIZER — ponto único de normalização (Item 13).
 *
 * TODO módulo do sistema entrega o MESMO contrato, validado AQUI:
 *   module      string            — identificador do módulo
 *   signal      float [-1, +1]    — valor do sinal
 *   confidence  float [0, 1]      — confiança do módulo no próprio sinal
 *   timestamp   string (ISO/SQL)  — momento do cálculo
 *   horizon     string            — horizonte alvo (15m/1h/4h/24h)
 *   metadata    array             — evidências, contribuições, status das fontes
 *
 * Regras de clamp/confiança vivem SOMENTE aqui. Módulos não reimplementam
 * limites (fim de max()/min() espalhados com escalas incompatíveis).
 */
final class SignalNormalizer
{
    /** Módulos válidos do sistema (contrato fechado). */
    public const MODULES = ['ATHENA', 'HERMES', 'POSEIDON', 'HEPHAESTUS', 'CRONOS', 'MARKET_RELATIONS'];

    /**
     * Normaliza e valida um Signal, devolvendo objeto no contrato padronizado.
     * Garante: signal ∈ [-1,1], confidence ∈ [0,1], timestamp e horizon preenchidos.
     */
    public function normalize(Signal $signal): Signal
    {
        $metadata = $signal->metadata;
        $reason = strtolower((string)($metadata['reason'] ?? ''));
        if (!isset($metadata['status'])) {
            $metadata['status'] = preg_match('/(no_|insufficient_|without_usable|unavailable|no_data|llm_unavailable)/', $reason) ? 'UNAVAILABLE' : 'AVAILABLE';
        }

        // Sanity PRIMEIRO: valores não finitos (NAN/INF) viram neutro explícito
        // ANTES do clamp (comparações com NAN produzem lixo).
        $value = $signal->value;
        if (!is_finite($value)) {
            $metadata['sanitized'] = 'non_finite_signal_rejected';
            $metadata['status'] = 'ERROR';
            $metadata['reason'] = 'non_finite_signal';
            $value = 0.0;
        }
        $confidence = $signal->confidence;
        if (!is_finite($confidence)) {
            $metadata['sanitized'] = 'non_finite_confidence_rejected';
            $metadata['status'] = 'ERROR';
            $metadata['reason'] = 'non_finite_confidence';
            $confidence = 0.0;
        }

        // Contrato: timestamp sempre presente.
        if (!isset($metadata['timestamp'])) {
            $metadata['timestamp'] = date('Y-m-d H:i:s');
        }

        // ETAPA 19 — data freshness: sinal construído sobre dados velhos perde
        // confiança proporcionalmente à idade. Dados STALE nunca pesam igual
        // aos atuais. Idade considerada: a idade dos dados declarada pelo módulo
        // (metadata.data_age_seconds) OU inferida do carimbo do último candle.
        $age = null;
        if (isset($metadata['data_age_seconds']) && is_numeric($metadata['data_age_seconds'])) {
            $age = max(0, (int)$metadata['data_age_seconds']);
        } elseif (isset($metadata['last_candle_time'])) {
            $ts = strtotime((string)$metadata['last_candle_time']);
            if ($ts !== false) {
                // As-of-time: idade medida contra o "agora" efetivo (T no backtest).
                $age = max(0, strtotime(AsOfTime::getEffectiveNow()) - $ts);
            }
        }
        if ($age !== null && $age > 0) {
            $metadata['data_age_seconds'] = $age;
            // FRESHNESS POR FONTE: meia-vida da ordem da frequência natural do
            // dado (config freshness.* — justificativas no config.php). Dado
            // diário de on-chain não é "velho" aos 15 minutos.
            $halfLife = (float)prometheus_config('freshness.' . $signal->module, 900.0);
            // Floor por horizonte: previsão de longo prazo tolera dado mais velho
            // (nunca abaixo de horizonte/4). Horizonte em metadata é '15m','1h'...
            $horizonSeconds = $this->horizonSeconds((string)($metadata['horizon'] ?? '1h'));
            $halfLife = max($halfLife, $horizonSeconds / 4.0);
            $metadata['expected_frequency'] = $this->frequencyLabel($halfLife);
            $factor = 2.0 ** (-$age / $halfLife);
            $factor = max(0.05, $factor); // nunca zera totalmente; registra fator
            $metadata['freshness_factor'] = round($factor, 4);
            if ($factor < 0.5 && $metadata['status'] === 'AVAILABLE') {
                $metadata['status'] = 'STALE';
            }
            $confidence = $confidence * $factor;
        }

        $value = $this->clamp($value, -1.0, 1.0);
        $confidence = $this->clamp($confidence, 0.0, 1.0);

        return new Signal($signal->module, $value, $confidence, $metadata);
    }

    /**
     * Valida o contrato completo (uso antes de persistir/agregar).
     * @return array{ok: bool, errors: string[]}
     */
    public function validate(Signal $signal): array
    {
        $errors = [];
        if (!in_array($signal->module, self::MODULES, true)) {
            $errors[] = "unknown module: {$signal->module}";
        }
        if (!is_finite($signal->value) || $signal->value < -1.0 || $signal->value > 1.0) {
            $errors[] = 'signal out of [-1,1] or non-finite';
        }
        if (!is_finite($signal->confidence) || $signal->confidence < 0.0 || $signal->confidence > 1.0) {
            $errors[] = 'confidence out of [0,1] or non-finite';
        }
        if (!isset($signal->metadata['timestamp'])) {
            $errors[] = 'metadata.timestamp missing';
        }
        if (!isset($signal->metadata['horizon'])) {
            $errors[] = 'metadata.horizon missing';
        }
        return ['ok' => $errors === [], 'errors' => $errors];
    }

    /** Squash determinístico (tanh) para converter scores brutos em sinais. */
    public function squash(float $value, float $scale = 1.0): float
    {
        return $this->clamp(tanh($value / max(0.0001, $scale)), -1.0, 1.0);
    }

    /** segundos do horizonte ('15m'→900, '1h'→3600...). */
    private function horizonSeconds(string $horizon): float
    {
        $map = ['15m' => 900.0, '1h' => 3600.0, '4h' => 14400.0, '24h' => 86400.0];
        return $map[$horizon] ?? 3600.0;
    }

    /** rótulo legível da frequência esperada (auditoria no metadata). */
    private function frequencyLabel(float $halfLife): string
    {
        if ($halfLife <= 1200) {
            return 'minutes';
        }
        if ($halfLife <= 43200) {
            return 'hours';
        }
        if ($halfLife <= 259200) {
            return 'daily';
        }
        return 'weekly_or_slower';
    }

    private function clamp(float $v, float $min, float $max): float
    {
        return max($min, min($max, $v));
    }
}
