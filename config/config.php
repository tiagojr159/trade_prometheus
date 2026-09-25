<?php
declare(strict_types=1);

if (!defined('PROMETHEUS_ROOT')) {
    define('PROMETHEUS_ROOT', dirname(__DIR__));
}
if (!defined('PROMETHEUS_TIMEZONE')) {
    define('PROMETHEUS_TIMEZONE', getenv('PROMETHEUS_TIMEZONE') ?: 'America/Fortaleza');
}
if (!defined('PROMETHEUS_ENV')) {
    define('PROMETHEUS_ENV', getenv('PROMETHEUS_ENV') ?: 'development');
}
if (!defined('PROMETHEUS_CACHE_DIR')) {
    define('PROMETHEUS_CACHE_DIR', PROMETHEUS_ROOT . '/storage/cache');
}
if (!defined('PROMETHEUS_LOG_DIR')) {
    define('PROMETHEUS_LOG_DIR', PROMETHEUS_ROOT . '/storage/logs');
}

date_default_timezone_set(PROMETHEUS_TIMEZONE);

return [
    'app_name' => 'PROMETHEUS',
    'base_url' => getenv('PROMETHEUS_BASE_URL') ?: '/prometheus',
    'default_symbol' => 'BTCUSDT',
    'horizons' => [
        '15m' => 15 * 60,
        '1h' => 60 * 60,
        '4h' => 4 * 60 * 60,
        '24h' => 24 * 60 * 60,
    ],
    'collector' => [
        // Timeframe base do sistema (candles 1m: regime, Market Relations,
        // índice temporal do backtester). NUNCA misturar intervalos numa query.
        'market_interval' => '1m',
        // TODOS os timeframes coletados e armazenados (cada um com sua linha
        // em market_data, dedupe por uniq_market_candle).
        'market_intervals' => ['1m', '5m', '15m', '1h', '4h', '1d'],
        // Limite de candles por coleta do timeframe BASE.
        'market_limit' => 500,
        // Limites por timeframe (candles por chamada Binance). Justificativa:
        // 1m precisa de histórico denso p/ gaps; TFs altos precisam de menos
        // linhas para cobrir a mesma janela temporal.
        'market_limits_by_interval' => ['1m' => 500, '5m' => 500, '15m' => 400, '1h' => 300, '4h' => 200, '1d' => 120],
        'news_cache_ttl' => 1800,
        'api_timeout' => 15,
    ],
    'out_of_sample' => [
        // Fração INICIAL da série usada como treino/calibração (split cronológico).
        'train_fraction' => 0.7,
    ],
    'optimizer' => [
        // Peso só muda com no mínimo esta quantidade de observações por célula.
        'min_samples' => 20,
        // Blend entre peso atual e peso alvo por ciclo (suavização).
        'learning_rate' => 0.3,
        // Teto de variação por ciclo (fração do peso atual).
        'max_step_pct' => 0.25,
    ],
    'regime' => [
        // Nova linha em market_regimes só se o regime mudar ou a última
        // classificação for mais antiga que isto (minutos).
        'dedupe_minutes' => 15,
    ],
    'llm' => [
        // Limite de chamadas à OpenAI por hora (janela deslizante).
        'max_calls_per_hour' => 30,
        // TTL do cache de resposta por hash de conteúdo (segundos).
        'response_cache_ttl' => 86400,
    ],
    // FRESHNESS POR FONTE (meia-vida em segundos) — justificativa documentada:
    // a meia-vida é da ORDEM DA FREQUÊNCIA NATURAL de atualização do dado.
    //  - ATHENA/HEPHAESTUS/MARKET_RELATIONS: candles 1m/derivativos 5m →
    //    informação deprecada em ~15 min (meia-vida 900s);
    //  - HERMES: notícias perdem relevância em horas (21600s = 6h);        //  - POSEIDON: Coin Metrics é DIÁRIO — a observação do dia permanece
        //    representativa por ~3 dias (72h = 259200s) até a próxima publicação;
        //  - CRONOS: FRED publica diário/mensal → meia-vida 7 dias (604800s).
    // Um horizonte longo tolera dado mais velho: a meia-vida EFETIVA nunca é
    // menor que horizonte/4 (floor por horizonte).
    'freshness' => [
        'ATHENA' => 900,
        'HEPHAESTUS' => 900,
        'MARKET_RELATIONS' => 900,
        'HERMES' => 21600,
        'POSEIDON' => 259200,
        'CRONOS' => 604800,
    ],
    'prediction' => [
        'min_confidence' => 0.05,
        'probability_slope' => 1.65,
        'default_module_weight' => 1.0,
        // Janela de dedupe de previsões (segundos). Duas previsões do mesmo
        // símbolo+horizonte dentro dessa janela são consideradas duplicadas.
        'dedupe_window_seconds' => 60,
    ],
];
