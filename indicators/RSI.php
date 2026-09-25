<?php
declare(strict_types=1);

namespace Prometheus\indicators;

/**
 * RSI padrão de Wilder (smoothing exponencial com alpha = 1/period),
 * compatível com os valores publicados por Wilder (1978) e plataformas
 * como TradingView/StockCharts.
 */
final class RSI
{
    public static function latest(array $closes, int $period = 14): ?float
    {
        if (count($closes) <= $period) {
            return null;
        }

        // Semente: média simples dos primeiros $period deltas (método de Wilder).
        $gains = 0.0;
        $losses = 0.0;
        for ($i = 1; $i <= $period; $i++) {
            $diff = $closes[$i] - $closes[$i - 1];
            if ($diff >= 0) {
                $gains += $diff;
            } else {
                $losses += abs($diff);
            }
        }
        $avgGain = $gains / $period;
        $avgLoss = $losses / $period;

        // Suavização de Wilder nas demais observações.
        for ($i = $period + 1; $i < count($closes); $i++) {
            $diff = $closes[$i] - $closes[$i - 1];
            $gain = $diff > 0 ? $diff : 0.0;
            $loss = $diff < 0 ? abs($diff) : 0.0;
            $avgGain = (($avgGain * ($period - 1)) + $gain) / $period;
            $avgLoss = (($avgLoss * ($period - 1)) + $loss) / $period;
        }

        if ($avgGain == 0.0 && $avgLoss == 0.0) {
            return 50.0; // série flat: neutro, não 100
        }
        if ($avgLoss == 0.0) {
            return 100.0; // só ganhos
        }
        if ($avgGain == 0.0) {
            return 0.0; // só perdas
        }
        $rs = $avgGain / $avgLoss;
        return 100 - (100 / (1 + $rs));
    }
}
