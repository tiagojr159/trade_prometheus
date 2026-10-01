# PROMETHEUS

Sistema PHP/MySQL para coleta, análise e previsão probabilística do movimento do Bitcoin.

## Instalação

1. Crie o banco importando `sql/prometheus.sql` no MySQL 8+.
   Para bancos existentes criados antes da lineage temporal, aplique `sql/migrations/008_temporal_lineage.sql`.
   Para instalar a simulação antiga, importe `sql/migrations/010_paper_trading.sql` uma única vez.
   Para paper trading V2 e shadow ATHENA-50, importe em sequência `sql/migrations/011_paper_trading_v2.sql` e `sql/migrations/012_athena50_shadow.sql`. Para o torneio de doze estratégias, aplique em sequência `sql/migrations/013_paper_strategy_tournament.sql`, `sql/migrations/014_paper_tournament_allow_sample_orders.sql` e `sql/migrations/015_paper_trading_twelve_strategies.sql`.
2. Ajuste `config/database.php` ou use variáveis de ambiente:
   - `DB_HOST`
   - `DB_PORT`
   - `DB_NAME`
   - `DB_USER`
   - `DB_PASS`
3. Configure chaves opcionais por variável de ambiente ou em `config/api_keys.php`:
   - `OPENAI_API_KEY`
   - `OPENAI_MODEL`
   - `NEWS_API_KEY`
   - `FRED_API_KEY`
4. Acesse `http://localhost/trade_prometheus/prometheus/`.

## CRON

Exemplos:

```bash
* * * * * php /caminho/prometheus/cron/collect_market.php
*/5 * * * * php /caminho/prometheus/cron/generate_predictions.php
*/5 * * * * php /caminho/prometheus/cron/evaluate_predictions.php
*/15 * * * * php /caminho/prometheus/cron/collect_derivatives.php
*/30 * * * * php /caminho/prometheus/cron/collect_onchain.php
0 * * * * php /caminho/prometheus/cron/collect_news.php
0 */6 * * * php /caminho/prometheus/cron/collect_macro.php
```

Também é possível executar tudo:

```bash
php cron.php all
```

Para executar a simulação automática a cada minuto, adicione ao agendador do servidor:

```bash
* * * * * php /caminho/prometheus/cron.php paper_trade
```

As ordens sao apenas simuladas, sem corretora ou dinheiro real. A migration 013 preserva as contas antigas e cria sete contas de US$ 100: PROMETHEUS, Multi-horizonte, Inversa, Momentum, Reversao a media, Rompimento e Adaptativa. As migrations 014 e 015 habilitam a amostragem prospectiva e acrescentam cinco contas: Spot Grid, Spot DCA, viés de funding, rebalanceamento BTC/USDT e VWAP. A estrategia de funding e um sinal direcional, nao uma arbitragem spot/futuros delta-neutral; o sistema ainda nao registra basis ou pernas hedgeadas. Taxas e slippage continuam abatidos do resultado, e o ranking acompanha equity e PnL. Resultados simulados nao garantem lucro.

A migration 012 ativa o experimento prospectivo ATHENA-50 isolado do ensemble. Ela não recupera dados brutos antigos; os resultados só aparecem depois que as previsões shadow novas forem avaliadas.

## Módulos

- ATHENA: indicadores técnicos.
- HERMES: notícias e sentimento com OpenAI apenas para estruturar texto.
- POSEIDON: métricas on-chain.
- HEPHAESTUS: derivativos.
- CRONOS: macroeconomia.
- MARKET_RELATIONS: comportamento de mercado.

Cada módulo produz sinal normalizado entre `-1` e `+1`, com confiança. O motor central detecta regime, combina pesos históricos por horizonte/regime e grava previsões antes do resultado ocorrer.
