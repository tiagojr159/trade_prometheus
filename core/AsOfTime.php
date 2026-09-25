<?php
declare(strict_types=1);

namespace Prometheus\core;

/**
 * AS-OF-TIME — relógio virtual para execução histórica do pipeline real.
 *
 * Em produção (AsOfTime::disabled(), default) nada muda: NOW() real.
 * No backtest, AsOfTime::set($T) fixa o "agora" em T. Componentes que usam
 * AsOfTime::now() passam a enxergar somente dados <= T — reutilizando a
 * lógica de produção SEM duplicá-la e SEM acesso ao futuro.
 *
 * Regras de segurança:
 *  - getEffectiveNow() em modo histórico lança exceção se chamado sem set()
 *    (falha explícita, nunca silêncio);
 *  - assertNoFuture() é o guarda anti-look-ahead: qualquer componente pode
 *    declarar "isto não pode ver dados posteriores a T" e uma violação de
 *    programação (timestamp futuro passado como dado) é detectada.
 *
 * PHP 7.4 compatível.
 */
final class AsOfTime
{
    /** @var string|null Timestamp SQL do relógio virtual, ou null = modo real. */
    private static ?string $asOf = null;

    /** Ativa o modo histórico fixando o "agora" em $timestamp (SQL datetime). */
    public static function set(string $timestamp): void
    {
        $ts = strtotime($timestamp);
        if ($ts === false) {
            throw new \InvalidArgumentException("invalid as-of timestamp: {$timestamp}");
        }
        self::$asOf = date('Y-m-d H:i:s', $ts);
    }

    /** Desativa o modo histórico (volta ao relógio real). */
    public static function clear(): void
    {
        self::$asOf = null;
    }

    /** true quando o pipeline está rodando em modo histórico (backtest). */
    public static function enabled(): bool
    {
        return self::$asOf !== null;
    }

    public static function get(): ?string
    {
        return self::$asOf;
    }

    /**
     * "Agora" efetivo: T no modo histórico, time() real em produção.
     * Em modo histórico, chamar sem T configurado é um bug de programação → exceção.
     */
    public static function getEffectiveNow(): string
    {
        if (self::$asOf !== null) {
            return self::$asOf;
        }
        return date('Y-m-d H:i:s');
    }

    /** Effective cutoff formatted as UTC for *_at lineage columns. */
    public static function getEffectiveNowUtc(): string
    {
        if (self::$asOf !== null) {
            $ts = strtotime(self::$asOf);
            return gmdate('Y-m-d H:i:s', $ts === false ? 0 : $ts);
        }
        return gmdate('Y-m-d H:i:s');
    }

    /** SQL bound for UTC DATETIME columns; ingested_at is populated with UTC_TIMESTAMP(). */
    public static function sqlUtcUpperBound(string $column): string
    {
        if (self::$asOf !== null) {
            return $column . " <= '" . self::getEffectiveNowUtc() . "'";
        }
        return $column . ' <= UTC_TIMESTAMP()';
    }

    /**
     * Guarda anti-look-ahead: valida que $dataTimestamp não é posterior ao
     * agora efetivo. Lança em violação (modo histórico) — falha barulhenta,
     * nunca uso silencioso de dado futuro.
     */
    public static function assertNoFuture(string $dataTimestamp, string $consumer): void
    {
        if (self::$asOf === null) {
            return; // produção: sem relógio virtual a checar
        }
        $data = strtotime($dataTimestamp);
        $now = strtotime(self::$asOf);
        if ($data !== false && $data > $now) {
            throw new \RuntimeException(
                "LOOK-AHEAD BLOCKED: {$consumer} tentou usar dado de {$dataTimestamp} posterior ao as-of time " . self::$asOf
            );
        }
    }

    /** SQL helper: expressão de corte temporal para WHERE (dados <= agora efetivo). */
    public static function sqlUpperBound(string $column): string
    {
        if (self::$asOf !== null) {
            return $column . " <= '" . self::$asOf . "'";
        }
        return $column . ' <= NOW()';
    }

    /** SQL helper: expressão de limite INFERIOR relativo (>= agora efetivo − intervalo). */
    public static function sqlLowerBoundRelative(string $column, string $intervalSql): string
    {
        if (self::$asOf !== null) {
            return $column . " >= DATE_SUB('" . self::$asOf . "', INTERVAL " . $intervalSql . ")";
        }
        return $column . ' >= DATE_SUB(NOW(), INTERVAL ' . $intervalSql . ')';
    }
}
