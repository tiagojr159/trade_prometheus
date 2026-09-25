# PROMETHEUS — Temporal Hardening Audit

Data: 2026-09-24. Escopo pontual: `backtest_results.evaluation_version`, identificação de ferramentas de benchmark e lineage temporal das fontes. Nenhuma fórmula/sinal/indicador/peso/threshold preditivo foi alterado.

## Resultado por item

| ID | ITEM | ANTES | DEPOIS | ARQUIVOS ALTERADOS | TESTE | STATUS | OBSERVAÇÃO |
|---|---|---|---|---|---|---|---|
| E1 | Default v2 em instalação/upgrade | Schema base e migrations antigas podiam deixar novos resultados de backtest com default 1. | Schema novo e tabela criada pela migration 002 usam default 2. Se 007 encontra uma tabela antiga sem coluna de versão, adiciona primeiro como v1 para preservar as linhas existentes, depois muda o default de novas linhas para 2. Migration 009 faz esse último ajuste em instalações já migradas sem reescrever linhas. | `sql/prometheus.sql`, `sql/migrations/002_backtest_results.sql`, `sql/migrations/007_direction_policy_v2_hardening.sql`, `sql/migrations/009_backtest_evaluation_default_v2.sql` | Teste estático e SQL temporário do upgrade de tabela não versionada; 009 aplicado/verificado na base após backup. | PASS | Histórico operacional permaneceu em v1=228 e v2=225. Não foi executado UPDATE global para 2. |
| E2 | INSERTs de backtest | Componentes atuais já inseriam versão explícita, mas default era inconsistente. | HistoricalPipelineBacktester e WalkForwardBacktester mantêm `evaluation_version=2` explícito. | `evaluation/HistoricalPipelineBacktester.php`, `evaluation/WalkForwardBacktester.php` | `admin/evaluation_version_schema_tests.php`; SQL temporário: default 2 e v1 explícito 1. | PASS | WalkForward é baseline técnico simplificado, não benchmark do pipeline completo. |
| E3 | Leitura/métricas por versão | Risco de relatórios misturarem linhas legadas. | Métricas atuais de avaliação/performance filtram versão 2; probe histórico lê somente v1 e avisa. | `evaluation/OutOfSampleEvaluator.php` (já filtrava), `admin/audit_probe_1.php`, `admin/alignment_baselines_v2.php`, `admin/alignment_confusion_v2.php`, `admin/probe_baselines_v2.php` | Auditoria estática de consultas e DirectionPolicy. | PASS | `MetricsCalculator` atualiza `module_performance` para novas avaliações do PredictionEvaluator, não lê `backtest_results` nem possui dimensão de versão. |
| L1 | Inventário LEGACY | `probe_baselines.php` podia parecer benchmark atual e calculava diagnóstico SIDEWAYS antigo. | Banner de execução LEGACY; probe de auditoria restringe baselines a v1; utilitários e fontes oficiais classificados. | `admin/probe_baselines.php`, `admin/audit_probe_1.php`, `evaluation/WalkForwardBacktester.php`, `docs/LEGACY_TOOLS.md` | Revisão semântica e `rg` em scripts/admin/evaluation. | PASS | `SIDEWAYS` em regime/testes de regime não foi classificado como legado. |
| L2 | Direção/hit oficial | Probes v2 tinham comparações próprias redundantes. | Ferramentas v2 delegam predição/hit/Brier/log loss a `core/DirectionPolicy.php`, filtram evaluation_version=2 e mostram que são relatórios de alinhamento, não validação preditiva. | `admin/alignment_baselines_v2.php`, `admin/alignment_confusion_v2.php`, `admin/probe_baselines_v2.php`, `docs/LEGACY_TOOLS.md` | Teste estático + revisão de chamadas. | PASS | Benchmark oficial de baselines: `admin/alignment_baselines_v2.php`; motor histórico completo: `evaluation/HistoricalPipelineBacktester.php`. |
| T1 | Schema de lineage | Dados externos só tinham timestamp de evento e `created_at`, sem distinguir publicação e recebimento. | Campos nullable e quality explícita em macro/news/onchain/derivatives/market; histórico permanece NULL/UNKNOWN. | `sql/prometheus.sql`, `sql/migrations/008_temporal_lineage.sql` | Migration 008 aplicada em banco isolado e base `prometheus` após backup validado. | PASS | `ingested_at` é nullable para respeitar histórico; coletores novos preenchem em UTC. Nenhum backfill foi feito. |
| T2 | Persistência de ingestão | Coletores não registravam o instante real que persistiram dados. | FRED, News/OpenAI, Coin Metrics, Binance Spot e Futures gravam `UTC_TIMESTAMP()` por inserção/upsert. | `collectors/MacroCollector.php`, `collectors/NewsCollector.php`, `collectors/OnChainCollector.php`, `collectors/MarketCollector.php`, `collectors/DerivativesCollector.php` | Revisão estática/lint; nenhuma API externa chamada. | PASS | Upsert atualiza a ingestão mais recente; valores/vintages antigos sobrescritos não são reconstruíveis e ficam conservadoramente indisponíveis antes dessa ingestão. |
| T3 | AsOf externo | Módulos cortavam somente por observed/published time. | CRONOS, HERMES, POSEIDON, HEPHAESTUS e MARKET_RELATIONS exigem ingestão <= T, quality conhecida e available_at <= T quando presente; se há availability, a persistência também precisa ocorrer depois dela. ATHENA e relações usam candle fechado (`close_time`) e lineage. | `core/AsOfTime.php`, `core/TemporalAvailability.php`, `modules/CronosMacro.php`, `modules/HermesNews.php`, `modules/PoseidonOnChain.php`, `modules/HephaestusDerivatives.php`, `modules/MarketRelations.php`, `modules/AthenaTechnical.php` | Casos A–H e teste de conversão de fuso local→UTC. | PASS | Sem linha temporalmente elegível, fontes retornam sem dados/UNAVAILABLE; ensemble mantém o contrato de exclusão de indisponíveis. |
| T4 | Backtest estrito | Candle era tratado como previsível no close mesmo que coletado depois; fontes históricas podiam não ter lineage. | Instante da previsão usa recebimento do candle fechado; dados externos são filtrados por AsOf; alvos usam candle fechado e corte de avaliação. UNKNOWN legado fica fora. | `evaluation/HistoricalPipelineBacktester.php`, `evaluation/WalkForwardBacktester.php`, `evaluation/TargetPriceResolver.php` | Inspeção AsOf e teste temporal helper; backtest real não foi executado. | PASS COM LIMITAÇÃO | Amostra histórica pode ficar muito pequena até acumular novas coletas com lineage; não se declara backtest histórico plenamente reconstruído. |
| T5 | Análise OpenAI | Features eram gravadas na notícia sem tempo/modelo/status próprios. | News grava `analysis_created_at` UTC, model, status e mantém `published_at` como timestamp da fonte vinculado à mesma linha. HERMES corta análise e news por AsOf; status LLM também respeita AsOf. | `sql/prometheus.sql`, `sql/migrations/008_temporal_lineage.sql`, `collectors/NewsCollector.php`, `modules/HermesNews.php` | Lint e casos news 09:59/10:03. | PASS | `published_at` é proxy de availability quando recebido; não equivale a release timestamp confirmado. |

## Política temporal e lineage por fonte

Para coluna `observed_at`/`published_at` legada, o projeto mantém o padrão DATETIME local da aplicação (`America/Fortaleza`). Novas colunas `available_at` e `ingested_at` são UTC. `AsOfTime::sqlUtcUpperBound()` converte T da aplicação para UTC e, em live, usa `UTC_TIMESTAMP()`. Histórico exige ingestão comprovada até T; quando `available_at` existe também precisa ser <= T. `EXACT` exige publicação conhecida; `INGESTION_ONLY` usa recebimento real como limite conservador; `UNKNOWN` nunca é admitido no backtest.

| SOURCE | OBSERVED_AT | AVAILABLE_AT | INGESTED_AT | TEMPORAL_QUALITY |
|---|---|---|---|---|
| BINANCE SPOT | open/close do candle Binance, convertido para timezone local da aplicação | `close_time` em UTC como limite inferior conservador, `BINANCE_CLOSE_TIME_LOWER_BOUND`; não se afirma o milissegundo exato de publicação | `UTC_TIMESTAMP()` ao persistir/upsert; precisa ser posterior ao limite inferior para candle final | `INGESTION_ONLY`; legado `UNKNOWN` |
| BINANCE FUTURES | funding time, timestamp OI ou timestamp da série, local da aplicação | NULL; evento do dado não prova publicação | `UTC_TIMESTAMP()` ao persistir/upsert | `INGESTION_ONLY`; legado `UNKNOWN` |
| NEWS | `published_at` fornecido por NewsAPI, local da aplicação | mesmo instante em UTC como proxy `PUBLISHED_AT_PROXY`; NULL se ausente | `UTC_TIMESTAMP()` ao persistir notícia/análise | `INGESTION_ONLY`; legado `UNKNOWN` |
| OPENAI ANALYSIS | `published_at` da notícia vinculada à linha | disponibilidade herdada da notícia; não representa disponibilidade da inferência | `analysis_created_at=UTC_TIMESTAMP()` ao persistir; model/status armazenados na própria linha `news` | tempo de análise interno conhecido; análises legadas sem timestamp ficam UNKNOWN |
| COIN METRICS | tempo diário da API convertido para timezone local da aplicação | NULL; API consultada não comprova release histórico exato | `UTC_TIMESTAMP()` ao persistir/upsert | `INGESTION_ONLY`; legado `UNKNOWN` |
| FRED | `observations[].date` como período/data de observação | NULL; `realtime_start` segue preservado em metadata, mas não contém hora confiável de release | `UTC_TIMESTAMP()` ao persistir/upsert | `INGESTION_ONLY`; legado `UNKNOWN` |
| MARKET RELATIONS | candles Binance e observações FRED que compõem as relações | não tem tabela própria; herda os limites dos inputs e exige candles fechados | não tem ingestão independente; herda lineage consultado dos inputs | derivado; UNKNOWN/indisponível se inputs não têm lineage elegível |

**Availability EXACT de fontes externas:** nenhuma fonte externa integrada fornece neste fluxo um instante de publicação suficientemente preciso e comprovado para o nível EXACT. O `analysis_created_at` do OpenAI é um instante interno de persistência exato, não um timestamp de publicação da notícia.

**Histórico UNKNOWN:** todas as linhas já existentes em `macro_data`, `news`, `onchain_data`, `derivatives_data` e `market_data` recebem os novos campos nullable vazios/default `UNKNOWN`. Não copiamos `created_at`, observed date nem NOW para essas colunas. Análises OpenAI antigas também não recebem horário retroativo. Migração 008 não apaga nem reescreve histórico.

## Leitores/escritores de evaluation_version

- Base: `prediction_results` e `backtest_results` têm `DEFAULT 2`; migration 002 inicia backtest em 2, migration 007 preserva v1 ternário conhecido e muda somente o default, migration 009 atualiza instalações já migradas sem atualizar linhas.
- Escritores atuais: `PredictionEvaluator` fornece `DirectionPolicy::EVALUATION_VERSION`; `HistoricalPipelineBacktester` e `WalkForwardBacktester` incluem literal 2 explicitamente.
- Leitores de avaliação: `OutOfSampleEvaluator`, `api/performance.php`, dashboard de performance, probes/alignment v2 e testes DirectionPolicy restringem versões v2. `api/history.php` expõe o campo da versão por registro (histórico pode conter v1).
- `admin/migrate_eval_v2.php` recomputa `prediction_results` e grava v2 quando executado; é uma utility administrativa separada, não lê nem escreve `backtest_results` e não foi executada nesta tarefa.
- `admin/probe_baselines.php` permanece diagnóstico v1 histórico com alerta visual; `admin/audit_probe_1.php` limita sua seção de backtest a `evaluation_version=1`.
- `MetricsCalculator` não lê `evaluation_version`; agrega `module_performance` a partir das chamadas pós-avaliação de `PredictionEvaluator`.

## Arquivos alterados

- `admin/alignment_baselines_v2.php`
- `admin/alignment_confusion_v2.php`
- `admin/audit_probe_1.php`
- `admin/evaluation_version_schema_tests.php`
- `admin/probe_baselines.php`
- `admin/probe_baselines_v2.php`
- `admin/temporal_hardening_tests.php`
- `collectors/DerivativesCollector.php`
- `collectors/MacroCollector.php`
- `collectors/MarketCollector.php`
- `collectors/NewsCollector.php`
- `collectors/OnChainCollector.php`
- `core/AsOfTime.php`
- `core/TemporalAvailability.php`
- `docs/LEGACY_TOOLS.md`
- `docs/PROMETHEUS_TEMPORAL_HARDENING_AUDIT.md`
- `evaluation/HistoricalPipelineBacktester.php`
- `evaluation/TargetPriceResolver.php`
- `evaluation/WalkForwardBacktester.php`
- `modules/AthenaTechnical.php`
- `modules/CronosMacro.php`
- `modules/HephaestusDerivatives.php`
- `modules/HermesNews.php`
- `modules/MarketRelations.php`
- `modules/PoseidonOnChain.php`
- `sql/prometheus.sql`
- `sql/migrations/002_backtest_results.sql`
- `sql/migrations/007_direction_policy_v2_hardening.sql`
- `sql/migrations/008_temporal_lineage.sql` — lineage e índices.
- `sql/migrations/009_backtest_evaluation_default_v2.sql` — atualiza default de instalações existentes sem alterar valores.

## Validação separada

- **ENGINEERING VALIDATION — PASS COM UMA REGRESSÃO HISTÓRICA IDENTIFICADA:** PHP lint de 106 arquivos (0 erros); 9 testes estáticos de schema/versão, 14 casos temporais, freshness/availability/target policy e smoke read-only das consultas AsOf nos módulos. Migration 007 foi exercitada com uma tabela antiga não versionada: linha preexistente ficou v1, nova linha ficou v2; migration 008 executada em schema isolado e na base ativa; migration 009 executada e verificada nas duas. Default efetivo 2; contagens v1/v2 inalteradas. O teste DirectionPolicy integrado teve 27/28 PASS: falhou somente a verificação de preço da avaliação antiga #96 (guardada em `prediction_results` com `final_price=84213.80`; a primeira vela 1m encontrada após target é 84236.01). A vela tem `ingested_at=NULL`, qualidade UNKNOWN; é uma divergência histórica anterior sem lineage, não foi recalculada nem corrigida automaticamente.
- **TEMPORAL VALIDATION — PASS COM LIMITAÇÃO:** 14 cenários puros cobrem release futura/conhecida, ingestão futura, proxy de notícia, snapshot Binance persistido antes do close, Coin Metrics D+1, live ingestion-only, histórico UNKNOWN e conversão de AsOf para UTC. Isso valida a política/código, não cria vintages históricos ausentes.
- **OPERATIONAL VALIDATION — PARCIAL:** migration 008 e default 009 aplicados à base `prometheus` após backup consistente `C:\Users\tiago\AppData\Local\Temp\prometheus_pre_temporal_hardening_20260924_094152.sql` (5.500.577 bytes; SHA-256 `1BE7409A45D64CEB309008BA1C01414F682370E52D2B69E49D9DECF35834FE0F`). O Windows negou gravação/cópia para `storage/backups`; o dump permanece no TEMP validado. Contagens de `backtest_results` antes/depois: v1=228, v2=225. As tabelas antigas permanecem com todos os seus registros `ingested_at=NULL`, `temporal_quality=UNKNOWN`. Nenhum coletor/API externa foi executado e nenhum E2E/CRON foi acionado.
- **PREDICTIVE VALIDATION — NÃO EXECUTADA:** nenhuma afirmação de accuracy, vantagem preditiva ou melhora de backtest é feita. A disponibilidade de lineage pode reduzir ou zerar amostras históricas legadas; resultados devem ser reavaliados quando houver dados forward suficientes.
