# PROMETHEUS

Sistema PHP/MySQL para coleta, análise e previsão probabilística do movimento do Bitcoin.

## Instalação

1. Crie o banco importando `sql/prometheus.sql` no MySQL 8+.
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

## Módulos

- ATHENA: indicadores técnicos.
- HERMES: notícias e sentimento com OpenAI apenas para estruturar texto.
- POSEIDON: métricas on-chain.
- HEPHAESTUS: derivativos.
- CRONOS: macroeconomia.
- MARKET_RELATIONS: comportamento de mercado.

Cada módulo produz sinal normalizado entre `-1` e `+1`, com confiança. O motor central detecta regime, combina pesos históricos por horizonte/regime e grava previsões antes do resultado ocorrer.
