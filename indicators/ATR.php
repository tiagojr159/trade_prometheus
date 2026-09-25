<?php
declare(strict_types=1);

namespace Prometheus\indicators;

/**
 * ATR padrão de Wilder: primeiro ATR = média simples dos primeiros $period TRs;
 * depois ATR_n = (ATR_{n-1} * (period-1) + TR_n) / period.
 */
final class ATR
{
    public static function latest(array $candles, int $period = 14): ?float
    {
        if (count($candles) <= $period) {
            return null;
        }
        $trs = [];
        for ($i = 1; $i < count($candles); $i++) {
            $high = (float)$candles[$i]['high_price'];
            $low = (float)$candles[$i]['low_price'];
            $prevClose = (float)$candles[$i - 1]['close_price'];
            $trs[] = max($high - $low, abs($high - $prevClose), abs($low - $prevClose));
        }
        $atr = array_sum(array_slice($trs, 0, $period)) / $period;
        for ($i = $period; $i < count($trs); $i++) {
            $atr = (($atr * ($period - 1)) + $trs[$i]) / $period;
        }
        return $atr;
    }
}
