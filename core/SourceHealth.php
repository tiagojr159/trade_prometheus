<?php
declare(strict_types=1);

namespace Prometheus\core;

/**
 * ETAPA 05 — Observabilidade central das fontes.
 *
 * Cada fonte (binance_spot, binance_futures, news_api, openai, fred, onchain)
 * registra: status, último attempt/sucesso, timestamp do dado, latência,
 * falhas consecutivas. Estados possíveis: OK, STALE, UNAVAILABLE, ERROR, NO_DATA.
 *
 * Regras: "fonte OK" não implica "módulo tem dados suficientes" — módulos
 * reportam NO_DATA separadamente. Nunca inventa dados: ausência é NO_DATA.
 */
final class SourceHealth
{
    /** Idade máxima (s) por fonte antes de marcar STALE. */
    private const STALE_AFTER = [
        'binance_spot'    => 300,   // candles 1m/5m: 5 min
        'binance_futures' => 900,   // funding/OI: 15 min
        'news_api'        => 7200,  // notícias: 2 h
        'openai'          => 86400, // análise LLM: 24 h
        'fred'            => 259200,// macro: 3 dias (séries diárias/mensais)
        'onchain'         => 86400, // métricas on-chain: 24 h
    ];

    /** Registra início de tentativa; retorna token de latência. */
    public static function begin(string $source): int
    {
        self::touch($source, ['last_attempt' => date('Y-m-d H:i:s')]);
        return (int)round(microtime(true) * 1000);
    }

    /** Sucesso na coleta. $dataTimestamp = timestamp REAL do evento/dado (não NOW). */
    public static function success(string $source, ?string $dataTimestamp, int $startedAtMs): void
    {
        $latency = max(0, (int)round(microtime(true) * 1000) - $startedAtMs);
        self::touch($source, [
            'status' => 'OK',
            'last_success' => date('Y-m-d H:i:s'),
            'last_data_timestamp' => $dataTimestamp,
            'latency_ms' => $latency,
            'error_message' => null,
            'consecutive_failures' => 0,
        ]);
    }

    /** Falha na coleta (erro HTTP, timeout, payload inválido). */
    public static function failure(string $source, string $errorMessage): void
    {
        $cur = self::get($source);
        $n = ((int)($cur['consecutive_failures'] ?? 0)) + 1;
        self::touch($source, [
            'status' => 'ERROR',
            'error_message' => mb_substr($errorMessage, 0, 500),
            'consecutive_failures' => $n,
        ]);
    }

    /** Fonte configurada mas indisponível (sem API key, serviço fora). */
    public static function unavailable(string $source, string $reason): void
    {
        self::touch($source, [
            'status' => 'UNAVAILABLE',
            'error_message' => mb_substr($reason, 0, 500),
        ]);
    }

    /** Sem dados (pode coexistir com fonte OK — ex.: OpenAI OK, HERMES NO_DATA). */
    public static function noData(string $source, string $reason = ''): void
    {
        self::touch($source, ['status' => 'NO_DATA', 'error_message' => mb_substr($reason, 0, 500)]);
    }

    /** Recalcula STALE por idade do último dado. Chamar na leitura (dashboard/API). */
    public static function snapshot(): array
    {
        $rows = Database::fetchAll('SELECT * FROM source_health ORDER BY source');
        $out = [];
        foreach ($rows as $row) {
            $age = null;
            if (!empty($row['last_data_timestamp'])) {
                $age = max(0, time() - strtotime($row['last_data_timestamp']));
            }
            $status = $row['status'];
            if ($status === 'OK' && $age !== null) {
                $threshold = self::STALE_AFTER[$row['source']] ?? 3600;
                if ($age >= $threshold) {
                    $status = 'STALE';
                }
            }
            $row['age_seconds'] = $age;
            $row['status_effective'] = $status;
            $out[$row['source']] = $row;
        }
        return $out;
    }

    public static function get(string $source): ?array
    {
        return Database::fetch('SELECT * FROM source_health WHERE source = ?', [$source]);
    }

    private static function touch(string $source, array $fields): void
    {
        $row = self::get($source);
        if ($row === null) {
            Database::execute(
                'INSERT INTO source_health (source, status, last_attempt, last_success, last_data_timestamp, latency_ms, error_message, consecutive_failures, updated_at)
                 VALUES (?, COALESCE(?, "NO_DATA"), ?, ?, ?, ?, ?, ?, NOW())',
                [
                    $source,
                    $fields['status'] ?? null,
                    $fields['last_attempt'] ?? null,
                    $fields['last_success'] ?? null,
                    $fields['last_data_timestamp'] ?? null,
                    $fields['latency_ms'] ?? null,
                    $fields['error_message'] ?? null,
                    $fields['consecutive_failures'] ?? 0,
                ]
            );
            return;
        }
        $sets = ['updated_at = NOW()'];
        $params = [];
        foreach ($fields as $k => $v) {
            if (in_array($k, ['last_attempt', 'last_success', 'last_data_timestamp'], true) && $v === null) {
                continue; // não apaga histórico de sucesso por uma tentativa falha
            }
            $sets[] = "$k = ?";
            $params[] = $v;
        }
        $params[] = $source;
        Database::execute('UPDATE source_health SET ' . implode(', ', $sets) . ' WHERE source = ?', $params);
    }
}
