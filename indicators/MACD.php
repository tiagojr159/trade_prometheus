<?php
declare(strict_types=1);

namespace Prometheus\indicators;

final class MACD
{
    /**
     * @return array{macd: ?float, signal: ?float, histogram: ?float}
     */
    public static function latest(array $closes): array
    {
        $ema12 = EMA::calculate($closes, 12);
        $ema26 = EMA::calculate($closes, 26);
        if (!$ema12 || !$ema26) {
            return ['macd' => null, 'signal' => null, 'histogram' => null];
        }
        $macd = [];
        foreach ($closes as $i => $_) {
            if (($ema12[$i] ?? null) !== null && ($ema26[$i] ?? null) !== null) {
                $macd[] = $ema12[$i] - $ema26[$i];
            }
        }
        $signal = EMA::calculate($macd, 9);
        $m = end($macd);
        $s = end($signal);
        // Nota: comparação explícita com false — 0.0 é valor legítimo de MACD.
        $macdValue = $m === false ? null : (float)$m;
        $signalValue = $s === false ? null : (float)$s;
        $histogram = ($macdValue !== null && $signalValue !== null) ? $macdValue - $signalValue : null;
        return ['macd' => $macdValue, 'signal' => $signalValue, 'histogram' => $histogram];
    }
}
