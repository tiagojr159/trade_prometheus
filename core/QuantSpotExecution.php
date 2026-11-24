<?php
declare(strict_types=1);

namespace Prometheus\core;

final class QuantSpotExecution
{
    public static function mark(array $account, array $quote, array $policy): string
    {
        $price = self::price($quote, $policy, false);
        $proceeds = bcmul($account['quantity'], $price, 8);
        return bcsub($proceeds, bcmul($proceeds, $policy['fee_rate'], 8), 8);
    }

    public static function fill(array $account, array $intent, array $quote, array $policy): array
    {
        $buy = $intent['cash_budget'] !== null;
        $price = self::price($quote, $policy, $buy);
        if ($buy) {
            $budget = bccomp($intent['cash_budget'], $account['cash'], 8) < 0
                ? $intent['cash_budget'] : $account['cash'];
            $unitCost = bcmul($price, bcadd('1', $policy['fee_rate'], 12), 12);
            $steps = bcdiv($budget, bcmul($unitCost, $policy['step_size'], 18), 0);
            $quantity = bcmul($steps, $policy['step_size'], 12);
        } else {
            $quantity = $intent['quantity'];
            if (bccomp($quantity, $account['quantity'], 12) > 0) return ['reason' => 'POSITION_CHANGED'];
        }
        if (bccomp($quantity, '0', 12) <= 0) return ['reason' => 'MIN_NOTIONAL'];
        if (bccomp($quantity, $quote[$buy ? 'ask_qty' : 'bid_qty'], 12) > 0) {
            return ['reason' => 'INSUFFICIENT_TOP_OF_BOOK'];
        }
        $notional = bcmul($quantity, $price, 8);
        if (bccomp($notional, $policy['min_notional'], 8) < 0) return ['reason' => 'MIN_NOTIONAL'];
        $fee = bcmul($notional, $policy['fee_rate'], 8);
        if ($buy) {
            $outflow = bcadd($notional, $fee, 8);
            return ['price' => $price, 'qty_delta' => $quantity, 'cash_delta' => bcsub('0', $outflow, 8),
                'fee' => $fee, 'realized_pnl' => '0', 'basis_delta' => $outflow];
        }
        $proceeds = bcsub($notional, $fee, 8);
        $basis = bccomp($quantity, $account['quantity'], 12) === 0 ? $account['cost_basis']
            : bcmul($account['cost_basis'], bcdiv($quantity, $account['quantity'], 20), 8);
        return ['price' => $price, 'qty_delta' => bcsub('0', $quantity, 12), 'cash_delta' => $proceeds,
            'fee' => $fee, 'realized_pnl' => bcsub($proceeds, $basis, 8), 'basis_delta' => bcsub('0', $basis, 8)];
    }

    public static function spreadBps(array $quote): float
    {
        return ((float)$quote['ask'] / (float)$quote['bid'] - 1.0) * 10000;
    }

    private static function price(array $quote, array $policy, bool $buy): string
    {
        $multiplier = $buy ? bcadd('1', $policy['impact_rate'], 8) : bcsub('1', $policy['impact_rate'], 8);
        return bcmul($quote[$buy ? 'ask' : 'bid'], $multiplier, 8);
    }
}
