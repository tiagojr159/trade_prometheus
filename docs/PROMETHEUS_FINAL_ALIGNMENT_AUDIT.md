# PROMETHEUS - auditoria de alinhamento final

Data: 2026-09-24. Escopo: auditoria estatica, correcao pontual, validacao local e amostra preditiva atualmente persistida. Este relatorio separa integridade do software, operacao e capacidade preditiva.

## Resumo executivo

- `DirectionPolicy` v2 e o contrato usado em novas previsoes: `UP`, `DOWN`, `INDETERMINATE`; realizado: `UP`, `DOWN`, `FLAT`; hit/correct nulo para casos nao comparaveis.
- `TargetPriceResolver` e compartilhado pela avaliacao de producao, backtest historico, walk-forward e recompute administrativo. O alvo e o primeiro candle do intervalo base que inicia em ou depois do target e fecha ate o instante de avaliacao.
- O ensemble ignora `UNAVAILABLE`/`ERROR`, preserva neutralidade `AVAILABLE`, e aplica a meia-vida de freshness ja configurada por modulo. Falhas de modulo ficam explicitas.
- `edge = abs(P(up)-0.5)` e persistido pelo motor; o dashboard apenas converte para pontos percentuais.
- PHP lint: 103/103 arquivos sem erros. Testes locais listados abaixo passaram. E2E externo completo nao foi executado: o script do repositorio chama coletores externos, usa credenciais configuradas, grava previsoes e atualiza pesos no banco operacional.
- A amostra de previsoes v2 disponivel no banco ainda e curta. Os resultados abaixo nao demonstram vantagem estatistica.

## Tabela de auditoria

| ID | Item | Problema encontrado | Arquivos | Alteracao | Teste / evidencia | Status |
|---|---|---|---|---|---|---|
| P0.1 | Schema DirectionPolicy v2 | Schema base antigo permitia SIDEWAYS e nao tinha os campos v2; error_score NOT NULL nao representava FLAT | `sql/prometheus.sql`, migrations 002/005/007 | Schema novo usa direcoes v2, campos nullable/versionados, edge e backtest_results; migration preserva enums legados | Fresh e migrado comparados em bancos temporarios; campos nullable/default equivalentes, enums antigos mantidos para historico | 🟡 FUNCIONAL COM LIMITACAO |
| P0.2 | Idempotencia | 005 adicionava colunas sem guarda; 006/007 precisavam de verificacao de repeticao | migrations 005/006/007 | 005 e 007 usam ADD COLUMN IF NOT EXISTS; ALTERs de tipo podem ser repetidos | 005 e 007 executadas duas vezes no MariaDB 10.4 sem falha | 🟢 CORRIGIDO E VALIDADO |
| P0.3 | Candle fechado | `open_time <= NOW()` nao provava que o candle fechou | `evaluation/TargetPriceResolver.php`, `evaluation/PredictionEvaluator.php` | Exige `close_time <= evaluation_time`, preserva `open_time >= target_time` | Cenarios A/B/C testados nos 4 horizontes | 🟢 CORRIGIDO E VALIDADO |
| P0.4 | Politica unica de preco | Evaluator, backtesters e recompute tinham SQL/indices temporais proprios | `evaluation/TargetPriceResolver.php`, PredictionEvaluator, HistoricalPipelineBacktester, WalkForwardBacktester, `admin/migrate_eval_v2.php` | Regra compartilhada, com selecao em memoria usando o mesmo resolver no backtest | `direction_v2_tests.php`; politica compartilhada verificada | 🟢 CORRIGIDO E VALIDADO |
| P0.5 | Teste temporal | Nao cobria candle ainda aberto nem os quatro horizontes | `admin/target_price_policy_tests.php` | Cobertura de candle anterior, alvo fechado e candle C aberto/fechado | PASS 15m/1h/4h/24h | 🟢 CORRIGIDO E VALIDADO |
| P1.1 | Edge | Dashboard multiplicava a distancia por 200 e recalculava formula | PrometheusEngine, PredictionService, schema, APIs, dashboard | Motor calcula/persiste `edge`; API expoe; dashboard formata `edge * 100` | Valores novos cobertos em teste; 47% => 3 p.p. | 🟢 CORRIGIDO E VALIDADO |
| P1.2 | Testes de edge | Casos e simetria nao estavam completos | `admin/target_price_policy_tests.php` | Casos 0.50, 0.51, 0.47, 0.70 e 0.30 | PASS, incluindo simetria | 🟢 CORRIGIDO E VALIDADO |
| P1.3-P1.6 | Status e ensemble | Dados faltantes eram representados por sinal numerico baixo e entravam como voto | SignalNormalizer, EnsembleEngine, PrometheusEngine, Signal | Status explicito; falha de modulo vira ERROR; ensemble agrega apenas AVAILABLE/STALE | `availability_semantics_tests.php`: indisponivel excluido, neutro valido incluido, NAN vira ERROR | 🟢 CORRIGIDO E VALIDADO |
| P1.7 | Freshness | Precisava manter meia-vida especifica por fonte e horizonte | SignalNormalizer, config existente | Mantida config por modulo; status STALE quando freshness cai abaixo da meia-vida | `item19_freshness_tests.php`: 7/7 | 🟢 CORRIGIDO E VALIDADO |
| P1.8 | HERMES/OpenAI | Sem LLM podia parecer neutralidade; falha LLM nao era status expresso | `modules/HermesNews.php` | Sem output/API key => UNAVAILABLE; JSON/HTTP/rate error => ERROR; sem noticia apos analise valida => AVAILABLE neutro | Teste de semantica do ensemble; simulacao de chamada externa nao executada | 🟡 FUNCIONAL COM LIMITACAO |
| P1.9 | Seis modulos | Sem dados tinha que ficar fora da agregacao; excecao nao podia simular sinal saudavel | PrometheusEngine, SignalNormalizer | Razoes de dado insuficiente viram UNAVAILABLE; excecao vira ERROR; invalidos ficam fora | Auditoria estatica dos seis modulos; teste sintetico de status | 🟢 CORRIGIDO E VALIDADO |
| P1.10 | Dashboard/source health | Dashboard mostrava sinal 0 sem status | `admin/dashboard.php`, Signal | Status e exibido; sinal vira traco para unavailable/error; metadata antiga classificada por reason conhecido | Security/dashboard test: HTML renderizado, 18/18 checks | 🟢 CORRIGIDO E VALIDADO |
| P2.1 | Confidence/probability/edge/regime | Camadas precisavam preservar conceitos separados | Engine, APIs, dashboard | Edge persistido em campo proprio; P(up/down), confidence e regime continuam separados | API/dashboard render e testes direction | 🟢 CORRIGIDO E VALIDADO |
| P2.2 | Pesos | Metricas podiam aprender de sinais invalidos | `evaluation/MetricsCalculator.php`, WeightOptimizer | Novas metricas ignoram sinais UNAVAILABLE/ERROR; minimo, shrinkage e default mantidos | Teste do ensemble/status; auditoria estatica | 🟡 FUNCIONAL COM LIMITACAO |
| P2.3 | Evaluation version | Performance API/OOS podiam juntar versoes | `api/performance.php`, `evaluation/OutOfSampleEvaluator.php` | Queries oficiais filtram v2; migration preserva v1 | Migration local; direction tests 28/28 | 🟢 CORRIGIDO E VALIDADO |
| P2.4 | Evaluator/backtester | Indices de candle divergiam e podiam usar candle aberto | TargetPriceResolver e backtesters | Mesma regra de alvo e DirectionPolicy | Resolver e suite direction passaram; nao foi executado backtest completo com escrita | 🟡 FUNCIONAL COM LIMITACAO |
| P2.5-P2.6 | As-of / FRED | FRED grava observation date, sem horario real de publicacao | MacroCollector, AsOfTime existentes | Nenhuma disponibilidade foi inventada; limitacao mantida e documentada | Queries existentes cortam em AsOfTime; data de release nao existe no schema atual | 🟡 FUNCIONAL COM LIMITACAO |
| P2.7-P2.8 | Coin Metrics / Market Relations | Risco de alterar fontes ou criar dados artificiais | PoseidonOnChain, MarketRelations | Sem mudanca de fonte/formula; Coin Metrics e series reais preservadas | Auditoria estatica; coleta live nao executada | 🟡 FUNCIONAL COM LIMITACAO |
| P3 | Validacao preditiva | Precisava evitar ajuste retrospectivo e apresentar baselines comuns | `admin/alignment_baselines_v2.php`, `admin/alignment_confusion_v2.php` | Comparacao read-only com mesmo subconjunto, candles fechados as-of e seed 42 | Resultados na secao abaixo; sem alteracao de modelo | 🟡 FUNCIONAL COM LIMITACAO |

### Limite de compatibilidade do schema

O schema de instalacao nova impede SIDEWAYS nos ENUMs de `predicted_direction` e `actual_direction`. O banco existente continha 51 previsoes e 228 linhas de backtest com direcao prevista SIDEWAYS. Para preservar IDs e historico sem reinterpretacao, a migration mantem SIDEWAYS como valor legado permitido no ENUM e adiciona INDETERMINATE. O motor atual nunca emite SIDEWAYS. Portanto os campos e null/default da instalacao migrada estao alinhados, mas o ENUM legado nao e estruturalmente identico ao fresh install e um INSERT manual ainda poderia gravar esse valor.

Os campos SQL sao DATETIME sem timezone. Neste ambiente, PHP usa America/Fortaleza e MariaDB usa America/Cayenne; durante a auditoria ambos estavam em UTC-3 e NOW() coincidiu. A timezone nao fica gravada por linha.

FRED usa `observation_date 00:00:00` como observed_at, nao o instante real em que o dado foi publicado. News usa published_at; nao ha coluna separada de ingestion/availability. Coin Metrics usa observed_at. O corte AsOfTime foi preservado, mas esses campos nao provam availability real quando evento e publicacao diferem.

## Componentes

| Componente | Engineering | Operational | Predictive | Observacao |
|---|---|---|---|---|
| ATHENA | DirectionPolicy v2 preservada | Insufficient data agora UNAVAILABLE | Sem demonstracao | Indicadores/formula sem alteracao |
| HERMES | Status explicito e sem fallback neutral | OpenAI/NewsAPI real nao foram simulados | Sem demonstracao | Falha de LLM e separada de noticia sem impacto |
| POSEIDON | Formula preservada | Coin Metrics mantido | Sem demonstracao | Sem nova coleta live |
| HEPHAESTUS | DirectionPolicy preservada | Falta de dado UNAVAILABLE | Sem demonstracao | Sem alteracao de formula |
| CRONOS | AsOfTime mantido | FRED operacional depende de chave | Sem demonstracao | Availability real de release ausente |
| MARKET_RELATIONS | Fontes e metadados preservados | Series reais existentes | Sem demonstracao | Sem mock/fallback inventado |
| REGIME | Independente da direcao | Detectores existentes | Sem demonstracao | SIDEWAYS continua regime |
| ENSEMBLE | Exclui UNAVAILABLE/ERROR; inclui neutro AVAILABLE | Cobertura registrada | Sem demonstracao | Stale mantém freshness reduzida |
| WEIGHTS | Min sample/shrinkage/default preservados | Sinais invalidos excluidos das novas amostras | Sem demonstracao | Linhas agregadas antigas nao tem provenance de status |
| PROBABILITY | P(up)+P(down)=1; edge separado | Edge persistido e servido | Avaliada na amostra abaixo | Nenhuma slope alterada |
| EVALUATOR | Usa somente candle fechado | Pendente sem candle disponivel | Nao mede operacao futura | DB usa DATETIME local sem timezone |
| BACKTESTER | Resolver temporal compartilhado | Backtest completo nao executado | N pequeno | Nao alteramos formulas |
| DASHBOARD | Status e edge coerentes | Render e API passaram | Exibe historico, nao prova capacidade | Metricas historicas v1 preservadas |
| API | edge e evaluation_version expostos/filtrados | API/security tests passaram | Sem demonstracao | Baselines sao relatorio local |

## Resultados preditivos

Dataset corrente, DirectionPolicy v2, atualizacao em 2026-09-24. Os baselines usam os mesmos timestamps elegiveis, mesmos resultados reais e somente candles fechados disponiveis em created_at. Linhas sem dois candles causais e empates matematicos sao excluidos para todos. RANDOM usa seed 42. Amostras pequenas nao sustentam conclusao de vantagem.

| HORIZON | N | ACCURACY | BRIER | LOGLOSS |
|---|---:|---:|---:|---:|
| 15m | 26 | 0.4231 | 0.2615 | 0.7162 |
| 1h | 35 | 0.5143 | 0.2557 | 0.7046 |
| 4h | 18 | 0.5556 | 0.2589 | 0.7110 |
| 24h | 3 | 1.0000 | 0.2451 | 0.6833 |
| TOTAL | 82 | 0.5122 | 0.2578 | 0.7089 |

| MODEL | N | ACCURACY | BRIER | LOGLOSS |
|---|---:|---:|---:|---:|
| PROMETHEUS | 82 | 0.5122 | 0.2578 | 0.7089 |
| ALWAYS_UP | 82 | 0.4146 | 0.5854 | 12.1307 |
| PREV_DIRECTION | 82 | 0.6098 | 0.2500 | 0.6931 |
| RANDOM_50_50 | 82 | 0.5244 | 0.2500 | 0.6931 |
| TECH_SIMPLE | 82 | 0.5244 | 0.2500 | 0.6931 |

Matriz de confusao PROMETHEUS (direcao recalculada de P(up), mesmos N=82): DOWN/DOWN=42, DOWN/UP=34, UP/DOWN=6, UP/UP=0. Calibration bins: 40-50% n=76, mean P(up)=0.4825, observed UP=0.4474; 50-60% n=6, mean P(up)=0.5585, observed UP=0.0000. N=82 ainda e amostra curta; baseline PREV_DIRECTION e RANDOM ficam acima de PROMETHEUS em accuracy neste recorte. Isto nao constitui evidencia preditiva de vantagem.

## Validacao executada

- `php -l` em todos os 102 arquivos PHP: 0 erros.
- `admin/target_price_policy_tests.php`: passou em edge e candles fechados para 15m, 1h, 4h e 24h.
- `admin/availability_semantics_tests.php`: passou; indisponivel excluido, neutro valido incluido, valor nao finito classificado ERROR.
- `admin/direction_v2_tests.php`: 28 PASS / 0 FAIL.
- `admin/item19_freshness_tests.php`: 7 PASS / 0 FAIL.
- `admin/item29_api_security_tests.php`: 18 PASS / 0 FAIL.
- Migrations 005 e 007 repetidas no MariaDB 10.4 sem erro. Banco temporario fresh versus schema legado + migrations: colunas/nullability/defaults relevantes alinham; ENUMs incluem SIDEWAYS apenas no caminho migrado para preservar legado.
- Baselines e matrizes calculados em modo somente leitura. Nenhuma formula ou peso foi ajustado a partir deles.

Nao foram executados todos os coletores, CRON e E2E completo: o script E2E escreve novas previsoes/resultados e invoca APIs externas reais, podendo consumir chaves e alterar o banco operacional. Nenhum resultado aqui afirma sucesso preditivo.

## Arquivos alterados e motivo

- `sql/prometheus.sql`, `sql/migrations/002_backtest_results.sql`, `sql/migrations/005_evaluation_v2.sql`, `sql/migrations/007_direction_policy_v2_hardening.sql`: schema v2, nullability, edge, versao e migracao rerun-safe preservando valores legados.
- `evaluation/TargetPriceResolver.php`, `evaluation/PredictionEvaluator.php`, `evaluation/HistoricalPipelineBacktester.php`, `evaluation/WalkForwardBacktester.php`, `admin/migrate_eval_v2.php`: regra compartilhada de target candle fechado em producao, backtests e recompute.
- `core/Signal.php`, `intelligence/SignalNormalizer.php`, `intelligence/EnsembleEngine.php`, `intelligence/PrometheusEngine.php`, `modules/HermesNews.php`, `evaluation/MetricsCalculator.php`: status explicito, erro sem voto, exclusao de sinais invalidos e aprendizado somente de sinal disponivel.
- `prediction/PredictionService.php`, `api/prediction.php`, `api/history.php`, `api/performance.php`, `admin/dashboard.php`: edge persistido/exposto, performance v2 e status honesto na interface.
- `admin/target_price_policy_tests.php`, `admin/availability_semantics_tests.php`, `admin/direction_v2_tests.php`, `admin/item25_e2e_full.php`: regressao temporal, edge, disponibilidade e consistencia do candle fechado.
- `admin/item25_e2e_full.php`: consulta de performance de teste agora usa somente v2 comparavel.
- `admin/alignment_baselines_v2.php`, `admin/alignment_confusion_v2.php`: metricas e baselines no mesmo conjunto temporal para relatorio, sem escrita no banco.
- `docs/PROMETHEUS_FINAL_ALIGNMENT_AUDIT.md`: evidencias, limitacoes e resultados.
