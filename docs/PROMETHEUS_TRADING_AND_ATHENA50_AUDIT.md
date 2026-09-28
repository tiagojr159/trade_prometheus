# Auditoria, paper trading V2 e ATHENA-50

Auditoria dos dois projetos locais concluída antes de alterar o código, em 28/09/2026. PROMETHEUS está em `C:\xampp\htdocs\trade_prometheus`; o projeto Bitcoin Pulse/`trade_indicador` fica em `C:\xampp\htdocs\trade_indicador`, fora da raiz gravável desta sessão e não foi alterado. Nenhuma corretora é integrada e nenhuma ordem real é enviada.

## Resultado da comparação

| Dimensão | PROMETHEUS | Bitcoin Pulse / `trade_indicador` | Implicação |
|---|---|---|---|
| Modelagem | Seis módulos combinados pelo `EnsembleEngine`; previsões probabilísticas UP/DOWN e pesos persistidos | 50 indicadores em cinco grupos, scores discretos -2..+2, mais um preditor por analogia | Scores dos projetos não são probabilidades equivalentes |
| Fontes | Candles, sentimento/notícias, on-chain, derivativos, macro e relações de mercado | Binance Spot, derivativos USD-M, indicadores técnicos e snapshots | Há sobreposição substancial em indicadores técnicos e derivativos |
| Execução existente | Paper trader antigo long-only, sem custos e sem ledger completo por decisão | `trade_policy.php` e `trade_exec.php` simulam custos, limiares, gestão de posição e validação temporal | V2 separa diagnóstico direcional do teste de rentabilidade |
| Histórico | Candles com metadados temporais; `prediction_results` separado da previsão | Histórico resumido de sinais; sem raw values/lineage temporal completa por feature | A junção histórica dos dois projetos não é evidência comparável suficiente |

O inventário dos 50 indicadores, equivalências, duplicações e disponibilidade de dados está em [INDICATOR_50_MAPPING.md](INDICATOR_50_MAPPING.md).

## Alterações implementadas

| Item | Implementação | Evidência / situação |
|---|---|---|
| Dois modos independentes | Contas DIRECTIONAL e STRATEGY com US$ 100 iniciais; posições LONG/SHORT sintéticas sem alavancagem; configuração de risco, taxa e slippage | Migração `011_paper_trading_v2.sql`; precisa ser aplicada no banco do host |
| Decisão auditável e automática | O cron existente executa os dois modos; decisão por previsão idempotente; grava também HOLD/NO_TRADE, proveniência, ordem, fill, custo, PnL, equity e heartbeat | `core/PaperTrader.php`; cron remoto não observável nesta sessão |
| Estratégia de rentabilidade | `StrategyDecisionEngine` separado do preditor; exige concordância de horizontes, pelo menos 30 resultados anteriores e expectativa bruta estimada maior que custos mais margem | Regras experimentais ainda sem calibração walk-forward; não constituem recomendação nem prova de lucro |
| Tela | Alternância de modos, saldo/equity, posição, PnL, previsões e decisão, sinais/pesos do ensemble, curva contra buy-and-hold, configurações, histórico e trades encerrados | `admin/simulation.php`; requer migrations, sessão de administrador e execução do cron |
| Shadow ATHENA-50 | Registro prospectivo independente do ensemble, vetor e cobertura das features disponíveis, filtro point-in-time e scorecard após avaliação | Migração `012_athena50_shadow.sql`; não disponíveis: taker buy, book, basis e top-trader ratio por falta de snapshots temporais compatíveis |
| Mapa e relatório | Equivalência entre indicadores e restrições de dados | `docs/INDICATOR_50_MAPPING.md` e este relatório |

## Testes realizados

- `admin/paper_trading_v2_tests.php`: 14 verificações aprovadas sobre LONG/SHORT/reversão, sinal indeterminado, dados velhos/futuros, concordância, custos, mínimo de histórico, unicidade e filtro temporal do shadow.
- `trade_indicador/tests/previsao_motor_test.php`: suíte existente aprovada com 447 linhas de dados fornecidas; inclui preservação contra vazamento de futuro, abstinência sem histórico, limites e validações temporais. O projeto externo foi apenas lido/executado.
- Lint PHP aprovado em `core/PaperTrader.php`, `core/StrategyDecisionEngine.php`, `prediction/Athena50Shadow.php`, `prediction/PredictionService.php`, `evaluation/PredictionEvaluator.php`, `admin/simulation.php` e `cron.php`.
- `git diff --check` não apontou erros de whitespace; exibiu apenas avisos de conversão de final de linha CRLF.

## Snapshot somente leitura do banco configurado

O banco configurado aponta para um host não local, então nenhuma migration nem escrita foi executada nele. Na consulta de 28/09/2026 havia 191 previsões, 156 resultados avaliados e 13.250 candles; a tabela shadow e as tabelas paper V2 não existiam. O último `close_time` de candle era 25/09/2026 20:59:59 e o último `ingested_at` era 25/09/2026 03:44:50, ambos atrasados para operar em 28/09. O V2 recusa execução com preço desatualizado.

Resultados descritivos já armazenados, versão 2; acurácia usa apenas resultados UP/DOWN e não é uma simulação de rentabilidade:

| Horizonte | N direcional | Acurácia | Brier médio |
|---|---:|---:|---:|
| 15m | 39 | 41,03% | 0,2616 |
| 1h | 54 | 48,15% | 0,2579 |
| 4h | 36 | 52,78% | 0,2581 |
| 24h | 11 | 27,27% | 0,2619 |

As previsões se sobrepõem no tempo, as amostras são pequenas e os intervalos disponíveis cobrem poucos dias. Esses números são uma leitura descritiva, não validação walk-forward, não provam rentabilidade e não permitem comparar ATHENA-50, cuja coleta ainda não foi instalada.

## O que ainda não se pode concluir

O arquivo de dados fornecido para Bitcoin Pulse cobre aproximadamente 10,54 horas; suas métricas direcionais amostrais variam por horizonte e não são comparáveis à validação do PROMETHEUS. Os snapshots antigos não guardam todas as features brutas e seus instantes de disponibilidade. Por isso não há estimativa honesta de retorno, Brier/LogLoss ATHENA-50 vs clássico, walk-forward, ablation ou stress de custos. O shadow precisa acumular previsões prospectivas avaliadas antes que essas métricas possam ser reportadas.

As migrations 011 e 012 ainda precisam ser aplicadas no MySQL do host. A tela não consegue confirmar o estado do banco nem a instalação do cron remoto a partir deste workspace. Após aplicar as migrations, habilitar os modos e confirmar o cron, o dashboard expõe o heartbeat para observar as execuções.

**Conclusão:** código paper-only implementado, mas a lucratividade da estratégia e o valor incremental ATHENA-50 permanecem **não comprovados**. O projeto externo não foi modificado, pois está fora da raiz autorizada para escrita.
