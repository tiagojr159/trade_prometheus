<?php
declare(strict_types=1);

namespace Prometheus\core;

use PDO;

/** Simulador em papel: não envia ordens a corretoras nem movimenta dinheiro real. */
final class PaperTrader
{
    private const BUY_THRESHOLD = 0.58;
    private const SELL_THRESHOLD = 0.58;
    private const MIN_CONFIDENCE = 0.20;
    private const COOLDOWN_MINUTES = 15;

    public function run(string $symbol = 'BTCUSDT'): array
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->exec('INSERT IGNORE INTO paper_trading_accounts (id, initial_cash, cash_balance, btc_balance) VALUES (1, 100, 100, 0)');
            $stmt = $pdo->prepare('SELECT * FROM paper_trading_accounts WHERE id=1 FOR UPDATE');
            $stmt->execute();
            $account = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$account || !(int)$account['enabled']) {
                $pdo->commit();
                return ['status' => 'disabled'];
            }

            $priceStmt = $pdo->prepare('SELECT close_price FROM market_data WHERE symbol=? ORDER BY open_time DESC LIMIT 1');
            $priceStmt->execute([$symbol]);
            $price = (float)$priceStmt->fetchColumn();
            if ($price <= 0) {
                $pdo->commit();
                return ['status' => 'no_price'];
            }

            // O sinal de 15m é a base de execução; só ordens baseadas em previsão recente.
            $predStmt = $pdo->prepare('SELECT id, predicted_direction, probability_up, confidence, created_at FROM predictions WHERE symbol=? AND horizon="15m" AND created_at >= DATE_SUB(NOW(), INTERVAL 20 MINUTE) ORDER BY id DESC LIMIT 1');
            $predStmt->execute([$symbol]);
            $prediction = $predStmt->fetch(PDO::FETCH_ASSOC);
            if (!$prediction || (float)$prediction['confidence'] < self::MIN_CONFIDENCE) {
                $pdo->commit();
                return ['status' => 'waiting_signal'];
            }

            $cooldown = $pdo->prepare('SELECT COUNT(*) FROM paper_trading_orders WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)');
            $cooldown->execute([self::COOLDOWN_MINUTES]);
            if ((int)$cooldown->fetchColumn() > 0) {
                $pdo->commit();
                return ['status' => 'cooldown'];
            }

            $side = null;
            $pUp = (float)$prediction['probability_up'];
            if ((float)$account['btc_balance'] <= 0.000000000001 && $pUp >= self::BUY_THRESHOLD && $prediction['predicted_direction'] === 'UP') {
                $side = 'BUY';
                $quantity = (float)$account['cash_balance'] / $price;
                $newCash = 0.0;
                $newBtc = (float)$account['btc_balance'] + $quantity;
            } elseif ((float)$account['btc_balance'] > 0.000000000001 && (1 - $pUp) >= self::SELL_THRESHOLD && $prediction['predicted_direction'] === 'DOWN') {
                $side = 'SELL';
                $quantity = (float)$account['btc_balance'];
                $newCash = (float)$account['cash_balance'] + $quantity * $price;
                $newBtc = 0.0;
            }

            if ($side === null) {
                $pdo->commit();
                return ['status' => 'no_edge'];
            }
            $gross = $quantity * $price;
            $reason = $side === 'BUY'
                ? 'Previsão de alta com probabilidade mínima de 58%.'
                : 'Previsão de baixa com probabilidade mínima de 58%.';
            $insert = $pdo->prepare('INSERT INTO paper_trading_orders (prediction_id, side, symbol, quantity_btc, price, gross_usd, cash_after, btc_after, probability_up, confidence, reason) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $insert->execute([(int)$prediction['id'], $side, $symbol, $quantity, $price, $gross, $newCash, $newBtc, $pUp, (float)$prediction['confidence'], $reason]);
            $update = $pdo->prepare('UPDATE paper_trading_accounts SET cash_balance=?, btc_balance=?, last_trade_at=NOW() WHERE id=1');
            $update->execute([$newCash, $newBtc]);
            $pdo->commit();
            return ['status' => 'traded', 'side' => $side, 'quantity_btc' => $quantity, 'price' => $price, 'gross_usd' => $gross];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
