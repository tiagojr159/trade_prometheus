# PROMETHEUS — paper trading V2, ATHENA-50 e validação

Auditoria dos dois projetos feita antes das alterações, em 28/09/2026. PROMETHEUS está em `C:\xampp\htdocs\trade_prometheus`; Bitcoin Pulse/ `trade_indicador` está em `C:\xampp\htdocs\trade_indicador`, fora da raiz autorizada para escrita nesta sessão. Nenhuma ordem real é enviada e nenhuma corretora é integrada.

## 1. Auditoria e alterações

| Item | Antes | Alteração | Arquivo | Teste | Resultado | Status |
|---|---|---|---|---|---|---|
| Paper trader | Long-only, thresholds rígidos, sem custos completos, short ou ledger de NO_TRADE | Dois modos, short sintético sem alavancagem, custos, decisão idempotente por modo/previsão, PnL e equity limitados a zero | `core/PaperTrader.php`, migration 011 | 23 testes, lint e execuções integrativas | Com candles atualizados, ambos os modos ficaram ativos; não havia previsão nova elegível na primeira chamada; nenhuma ordem foi criada | 🟡 funcional / aguardando decisões elegíveis |
| Heartbeat e cron | Task `paper_trade` presente no código; execução do host desconhecida | Heartbeat com início/fim, previsão, decisão, motivo, erro e próxima avaliação; alerta após 5 minutos | `core/PaperTrader.php`, `admin/simulation.php` | teste de status stale e inspeção do dispatcher | Não foi possível observar o cron remoto | ⚪ não testável aqui |
| Preço e lineage | Trader antigo não guardava a candle usada | Exige candle fechado, recente e temporalmente disponível; decisão, ordem e equity guardam ID, close time, available_at e ingested_at | `core/PaperTrader.php`, migration 011 | teste point-in-time e inspeção de schema | Candle aberto/futuro é rejeitado; ingestão stale interrompe a execução | 🟢 funcional em código |
| StrategyDecisionEngine | Decisão de ordem misturada ao preditor | Motor separado, concordância de horizontes, custos e pelo menos 30 resultados anteriores disponíveis em T | `core/StrategyDecisionEngine.php` | casos de concordância, custos e amostra mínima | Regra experimental; não calibrada por walk-forward | 🟡 funcional / sem evidência |
| Tela | Ausência de decisão e comparação suficiente | Modos, saldo, posição, custos, heartbeat, decisão, histórico, curva e pesquisa | `admin/simulation.php` | lint; rotas PHP locais conferidas | Migrations instaladas; ainda requer upload do código para abrir no host | 🟡 código pronto / não publicado |
| ATHENA-50 shadow | Scores compactados sem raw features/lineage suficientes para replay | Grava features disponíveis, cobertura, regime, modelos candidatos, ablações por grupo e scorecard após avaliação | `prediction/Athena50Shadow.php`, migration 012 | filtro temporal, scoring de ablação e teste sintético de correlação | Quatro previsões novas (IDs 192–195) produziram snapshots `READY_PARTIAL`, 43/50 features e 15 candidatos cada; alvos ainda não maturaram | 🟡 funcional / aguardando avaliação |
| Tradução | Dashboard já continha português, mas mostrava rótulo legado SIDEWAYS | Localizado como “LATERAL (LEGADO)”; simulação também traduz esse rótulo | `admin/dashboard.php`, `admin/simulation.php` | lint | Não foi possível validar visualmente o host que retorna 404 | 🟡 código revisado / sem QA remoto |
| Credenciais | DB_USER/DB_PASS na configuração local e chaves em arquivo local do PROMETHEUS | Arquivos de configuração estão cobertos pelo .gitignore e não aparecem como arquivos versionados; DB do PROMETHEUS lê getenv | `.gitignore`, `config/database.php`, `config/api_keys.php`, projeto antigo | `git check-ignore` e `git ls-files`; auditoria sem imprimir valores | Segredos permanecem em arquivos locais ignorados; nenhum valor foi logado ou copiado | 🟢 conforme configuração local |

O inventário de equivalência, redundância e fontes dos 50 indicadores está em [INDICATOR_50_MAPPING.md](INDICATOR_50_MAPPING.md). Mantive os seis módulos existentes.

## 2. Métricas preditivas disponíveis

Recalculadas por consulta somente de leitura ao banco configurado. A acurácia abaixo usa apenas previsões UP/DOWN e alvos UP/DOWN, excluindo SIDEWAYS e INDETERMINATE. Brier e LogLoss pontuam probabilidades para todos os alvos UP/DOWN do horizonte; por isso seus N são diferentes. São estatísticas descritivas dos registros existentes, não walk-forward.

| Modelo | Horizonte | N direcional (N prob.) | Accuracy | Brier | LogLoss |
|---|---|---:|---:|---:|---:|
| PROMETHEUS atual (A) | 15m | 28 (42) | 25,00% | 0,2616 | 0,7163 |
| PROMETHEUS atual (A) | 1h | 34 (57) | 32,35% | 0,2579 | 0,7090 |
| PROMETHEUS atual (A) | 4h | 26 (39) | 34,62% | 0,2581 | 0,7095 |
| PROMETHEUS atual (A) | 24h | 8 (18) | 0,00% | 0,2619 | 0,7170 |
| PROMETHEUS com ATHENA-50 substituindo ATHENA (B) | 15m / 1h / 4h / 24h | — | — | — | — |
| PROMETHEUS + informação ATHENA-50 validada (C) | 15m / 1h / 4h / 24h | — | — | — | — |
| ATHENA clássico isolado (D) | 15m / 1h / 4h / 24h | — | — | — | — |
| ATHENA-50 isolado (E) | 15m / 1h / 4h / 24h | — | — | — | — |

B, D e E passam a ser registrados prospectivamente agora que as migrations estão instaladas, quando houver features elegíveis. C fica deliberadamente sem modelo até que uma informação incremental seja validada. Os snapshots 192–195 têm `READY_PARTIAL` (43/50 features), 15 candidatos e ainda nenhum resultado avaliado. O dashboard separa horizontes e regimes e mostra accuracy, Brier e LogLoss; métricas por regime aguardam amostra.

O histórico contém rótulos SIDEWAYS e INDETERMINATE. As acurácias desta tabela foram recalculadas excluindo esses rótulos, sem editar os resultados históricos. Chamadas UP foram 0 corretas em todos os horizontes; no histórico o modo direcional favoreceu DOWN. Não se deve interpretar os números como evidência robusta: há apenas 191 previsões totais, 156 resultados e poucos dias; previsões também se sobrepõem no tempo.

## 3. Métricas de trading

| Estratégia | N trades | Retorno líquido | Max drawdown | Profit factor | Win rate | Custos |
|---|---:|---:|---:|---:|---:|---:|
| DIRECTIONAL paper | 0 | — | — | — | — | — |
| STRATEGY paper | 0 | — | — | — | — | — |
| BUY & HOLD no mesmo período | não calculado | — | — | — | — | — |
| ALWAYS_LONG | não calculado | — | — | — | — | — |
| RANDOM_DIRECTION, seed fixa | não calculado | — | — | — | — | — |

O dashboard agora reprecifica trades já registrados em custo LOW (0,5×), NORMAL (1×) e STRESS (2,5×), sem alterar o ledger. A integração criou uma entrada SHORT sintética em DIRECTIONAL; ainda não há trades V2 fechados para preencher métricas de retorno. As métricas BUY & HOLD, ALWAYS_LONG e RANDOM_DIRECTION não foram calculadas. A matriz de alocação 10%/25%/50%, regras alternativas de saída e walk-forward de política de trading com separação train/validation/test continuam pendentes; não devem ser inferidas do backtest direcional do motor.

## 4. ATHENA-50 e ablação

| Grupo | Incremento medido | Amostra pareada | Status |
|---|---|---:|---|
| Tendência | — | 0 | 🟣 aguardando histórico |
| Momentum | — | 0 | 🟣 aguardando histórico |
| Volume/fluxo | — | 0 | 🟣 aguardando histórico |
| Volatilidade/estrutura | — | 0 | 🟣 aguardando histórico |
| Microestrutura/derivativos | — | 0 | 🟣 aguardando histórico |
| Módulos PROMETHEUS sem ATHENA/HERMES/POSEIDON/HEPHAESTUS/CRONOS/MARKET_RELATIONS | — | 0 | 🟣 aguardando histórico |

Para cada shadow elegível, o sistema grava modelos candidatos nos mesmos timestamps/alvos, leave-one-module-out e leave-one-group-out. O analisador de correlação detecta features constantes e pares com |r| ≥ 0,95 a partir de pelo menos 30 observações pareadas; apenas informa redundância e não remove feature. A disponibilidade retrospectiva dos dados do Bitcoin Pulse impede uma comparação histórica honesta. Taker buy, order book, basis e top-trader ratio não têm lineage compatível no PROMETHEUS atual; proxies técnicos ficam identificados como tais.

## 5. Estado do host e próximos bloqueios

Na consulta inicial ao banco configurado, o destino era não local, havia 13.250 candles e as tabelas paper/shadow não existiam. As migrations 011 e 012 foram então aplicadas com sucesso; agora existem as duas contas paper iniciais e todas as tabelas V2/shadow. A coleta de mercado `cron.php market` foi executada com sucesso para 1m/5m/15m/1h/4h/1d. Em seguida `cron.php predictions` criou as previsões 192–195 e seus snapshots ATHENA-50. Uma execução manual de `cron.php paper_trade` processou a previsão 192 nos dois modos: DIRECTIONAL abriu SHORT sintético e STRATEGY registrou `NO_TRADE` com `HORIZON_DISAGREEMENT`. O heartbeat ficou ACTIVE em ambos; existe uma ordem simulada e nenhuma ordem real.

Os arquivos locais `simulation.php` e `performance.php` redirecionam para as telas administrativas. O 404 mostrado no navegador é do host, que ainda precisa receber os arquivos atualizados. Falta publicar os arquivos e confirmar cron a cada minuto com `php cron.php paper_trade`; o agendamento remoto não foi verificável. O host do usuário não pode ser alterado a partir deste workspace.

Testes locais: 23 verificações do paper/shadow/scoring; suíte existente do projeto Bitcoin Pulse aprovada com 447 linhas e testes anti-look-ahead; lint PHP nos arquivos alterados. Execução integrativa inicial: ambos os modos marcaram ERROR quando os candles estavam stale e não criaram ordens. Após coleta, os heartbeats voltaram a ACTIVE. A execução da tarefa `paper_trade` confirmou o comportamento por modo: SHORT simulado em DIRECTIONAL e `NO_TRADE` explicável em STRATEGY. O banco contém quatro snapshots shadow parciais sem resultado maturado. As migrations foram gravadas no banco remoto; previsões/resultados históricos não foram reescritos.

**Conclusão:** a implementação local aumenta a observabilidade e iniciou as comparações prospectivas, e uma execução da rotina comprovou SHORT simulado no modo DIRECTIONAL e `NO_TRADE` explicado no STRATEGY. Ainda não há evidência de rentabilidade ou ganho ATHENA-50: as previsões novas não maturaram e o walk-forward da política de trading continua pendente. O host continua retornando 404 até receber os arquivos locais; a execução automática recorrente do cron no host também precisa ser confirmada. A análise de credenciais foi somente leitura e encontrou arquivos ignorados pelo Git; nenhuma credencial foi publicada.
