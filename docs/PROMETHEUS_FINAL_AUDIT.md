# PROMETHEUS — FINAL AUDIT

**Data:** 2026-09-23 · **PHP:** 7.4.33 · **MariaDB:** 10.4.27
**Método:** nenhuma correção feita durante a auditoria. Todas as suítes existentes executadas + probes novos reais (fontes, freshness, calibração, pesos, OOS, baselines, violações de previsão).

## Resultados das suítes (executadas nesta auditoria)

| Suíte | Resultado |
|---|---|
| item2_db_logger_tests (recursão DB/Logger) | 13/13 |
| item4_schema_audit (schema+migrations) | 21/21 |
| item5_6_health_tests (SourceHealth+HttpClient) | 19/19 |
| item7_gap_tests (Binance gaps/dedupe/recuperação) | 7/7 |
| item7_athena_tests + item7_athena_validation | 0 fail |
| item9_15_tests (6 módulos+normalizer+regime+ensemble) | 45/45 |
| item16_21_tests (pesos/prob/evaluator/backtest/OOS/métricas) | 28/28 |
| item19_freshness_tests | 7/7 |
| item29_api_security_tests | 18/18 |
| item30_failure_scenarios | 13/13 |
| backtest_pipeline_tests (motor real as-of) | 23/23 |
| item6_cron_tests | 21/21 |
| item25_e2e_full | E2E COMPLETO: OK (14/17 etapas visíveis) |

## Evidências principais coletadas

- **Fontes**: binance_spot OK (3010 candles 1m BTC, 407 ETH, 407 PAXG, age 154s); binance_futures OK (funding 12, OI 28, L/S 7, age 139s; **liquidações: 0 registros**); FRED OK (3 séries reais, 1 obs/série); NewsAPI OK (37 notícias, published_at real); Coin Metrics OK (300 registros); openai **ERROR** (123 falhas consecutivas — sem OPENAI_API_KEY).
- **Múltiplos timeframes**: apenas `1m` coletado (`COUNT(DISTINCT interval_name)=1`). ATHENA usa janelas de 1m por horizonte.
- **Freshness por fonte**: meia-vida única de 15 min penaliza CRONOS (dado FRED com 1 dia → fator 0.05) e POSEIDON (dado diário) mesmo quando o dado é fresco para a frequência natural da fonte.
- **Calibração**: bin 40–50%: n=35, p médio 0.4852, freq real 0.1714 (**gap −0.31, muito mal calibrado**); bin 50–60%: n=4 (não confiável). Erro global 0.347. Nota do sistema: amostra pequena.
- **Pesos**: 42 células module×horizonte×regime, **todas 1.0** (amostra insuficiente — comportamento correto do otimizador).
- **OOS**: split cronológico (27 treino / 12 OOS), Brier OOS 0.2783, acc OOS nula (amostra < 30, instável).
- **Baselines (backtest_results, n=216)**: modelo 0.125 | always-up 0.375 | direção-anterior 0.634 | random ~0.48 → **modelo NÃO supera baselines** nesta amostra.
- **Integridade temporal**: 0 previsões com created_at >= target_time; 1 previsão 15m vencida ainda não avaliada (será avaliada no próximo ciclo).
- **Ablação**: 39 previsões avaliadas → 🟣 AGUARDANDO DADOS (exige ≥ 100).

## TABELA CONSOLIDADA

| ID | ITEM | PLANEJADO | IMPLEMENTADO | FUNCIONA? | TESTE EXECUTADO | EVIDÊNCIA | STATUS | SEVERIDADE | MELHORIA NECESSÁRIA |
|---|---|---|---|---|---|---|---|---|---|
| 1 | Infraestrutura (PHP/bootstrap/DB/migrations/logger/HttpClient/cache/locks) | sim | sim | sim | 13 suítes | 13+21+19/19; DB offline controlado; 429/retry testados | 🟢 | — | — |
| 2 | Binance Spot | sim | sim | sim | item7_gap_tests | 3010 candles, gaps 0, recuperação 3/3, dedupe | 🟢 | — | — |
| 3 | Múltiplos timeframes | sim | parcial | parcial | audit probe | só `1m` coletado; filtro anti-mistura ok | 🟡 | média | coletar 5m/1h/4h/1d e mapear horizontes |
| 4 | Binance Futures | sim | sim | parcial | audit probe | funding+OI+ΔOI+L/S coletados e usados; **liquidações ausentes** | 🟡 | baixa | liquidações exigem endpoint assinado (P2) |
| 5 | Coin Metrics Community | sim | sim | sim | item9_15 + probe | 300 registros, z-score histórico, domina POSEIDON | 🟢 | — | — |
| 6 | NewsAPI | sim | sim | sim | probe | 37 notícias, dedupe por hash, published_at real | 🟢 | — | — |
| 7 | FRED | sim | sim | sim | probe + cron | 3 séries reais; valor real ≠ indisponível | 🟢 | — | histórico de 1 obs/série limita normalização |
| 8 | OpenAI/HERMES | sim | sim | **não exercitado** | item8 + probe | sem key → 123 no_api_key; código testado por contrato | 🟣 | alta (para HERMES real) | configurar OPENAI_API_KEY |
| 9 | Market Relations — ativos | ETH+ouro | sim | sim | probe + cron | ETHUSDT e PAXGUSDT coletados com correlação real | 🟡 | média | S&P/Nasdaq/DXY não implementados (exigem fonte c/ credencial) |
| 10 | ATHENA (matemática) | sim | sim | sim | item7_athena_validation | RSI Wilder/MACD/EMA/BB/ATR vs valores conhecidos | 🟢 | — | — |
| 11 | ATHENA por horizonte | janelas por horizonte | parcial | parcial | code audit | janelas crescem com horizonte, mas todas sobre candles 1m | 🟡 | média | usar timeframe nativo por horizonte |
| 12 | POSEIDON | sim | sim | sim | item9_15 | Glassnode→Coin Metrics; multi-métrica z-score | 🟡 | média | dado diário recebe freshness de 15min → penalizado em 15m |
| 13 | HEPHAESTUS | sim | sim | sim | item9_15 | funding+OI+ΔOI+L/S com contribuições auditáveis | 🟢 | — | — |
| 14 | CRONOS | sim | sim | parcial | item9_15 | distinção zero-real/indisponível correta; 1 obs/série limita z-score | 🟡 | baixa | acumular histórico FRED |
| 15 | Freshness por fonte | thresholds por fonte | parcial | parcial | item19 + probe | meia-vida única 15min p/ todas as fontes | 🟡 | **alta** | meia-vida por fonte (macro/on-chain precisam de janelas próprias) |
| 16 | RegimeDetector | 4 regimes | sim | sim | item9_15 + cron | evidências, dedupe provado (2 ciclos, 0 duplicatas), as-of T | 🟢 | — | — |
| 17 | Ensemble | contribuições auditáveis | sim | sim | item9_15 + cron | ausência ≠ neutro; share_pct por módulo | 🟢 | — | — |
| 18 | Pesos adaptativos | módulo×horizonte×regime | sim | sim | item16_21 + probe | 42 células; min 20 amostras; nenhuma divergiu ainda | 🟣 | — | aguardar ≥ 20 avaliações/célula |
| 19 | ProbabilityCalculator | logística calibrável | sim | **mal calibrado** | audit probe | gap −0.31 no bin 40–50%; erro global 0.347 | 🟡 | **alta** | recalibrar slope/conf-modulação após mais amostras |
| 20 | Previsões 4 horizontes | sim | sim | sim | cron + probe | created_at < target_time em 100% (0 violações) | 🟢 | — | — |
| 21 | PredictionEvaluator | avaliar no vencimento | sim | sim | item16_21 + probe | 39 avaliadas; 1 pendente 15m (será avaliada); sem antecipação | 🟢 | — | — |
| 22 | Backtester walk-forward | motor real | sim (novo) | sim | backtest_pipeline_tests | usa PrometheusEngine + AsOfTime; sem duplicação; 4 horizontes | 🟢 | — | WalkForwardBacktester legado (simplificado) ainda existe p/ comparação |
| 23 | Data leakage | proteção estrutural | sim | sim | item30 + backtest_pipeline | assertNoFuture; 13/13 cenários; determinismo as-of | 🟢 | — | — |
| 24 | Out-of-sample | split cronológico | sim | parcial | audit probe | 27/12 cronológico; OOS Brier 0.2783; n<30 instável | 🟣 | — | aguardar amostra |
| 25 | Baselines | sim | sim | **modelo perde** | audit probe | modelo 0.125 vs direção-anterior 0.634 | 🔴 | **alta** | não afirmar capacidade preditiva; investigar sinal |
| 26 | Ablação | sim | não executável | — | audit probe | 39 avaliações < 100 | 🟣 | — | aguardar dados |
| 27 | CRON/automação | ciclo completo | sim | sim | item6_cron_tests + cron all | collect→predictions→evaluate→optimize; locks; falha isolada | 🟢 | — | — |
| 28 | Dashboard | só dados reais | sim | sim | probe | 17.9KB renderizado; indisponibilidade explícita; sem segredos | 🟢 | — | — |
| 29 | API/Segurança | auth/rate/SQLi/XSS | sim | sim | item29 | 18/18; rate limit; settings sem segredos | 🟢 | — | — |
| 30 | E2E real | ciclo completo | sim | sim | item25_e2e_full | EXIT 0, 17 etapas evidenciadas | 🟢 | — | — |

## RESUMO FINAL

### A) ENGENHARIA
- **Completos (🟢)**: 17/30 ≈ **57%**
- **Funcionam mas precisam melhorar (🟡)**: 8/30 ≈ **27%**
- **Parciais (🟠)**: 0 (nenhum item apenas parcial sem funcionar)
- **Ausentes (🔴)**: 1 (superação de baselines — resultado, não componente)
- **Aguardando dados (🟣)**: 3 (OpenAI real, ablação, OOS estável)
- **Não testável (⚪)**: 1 (liquidações Binance — requer credencial)

### B) OPERAÇÃO
**SIM.** `cron.php all` executa collect → predictions → evaluate → optimize com locks, idempotência e isolamento de falhas; 84 previsões criadas, 39 avaliadas automaticamente, 0 violações temporais.

### C) VALIDAÇÃO PREDITIVA
- Backtest walk-forward real do pipeline completo: **SIM** (HistoricalPipelineBacktester, as-of).
- Out-of-sample: **SIM** (cronológico), mas amostra 12 → instável.
- Proteção comprovada contra leakage: **SIM** (assertNoFuture + 23/23).
- Probabilidades calibradas: **NÃO** (gap −0.31; erro 0.347) — recalibração pendente.
- Supera baselines: **NÃO** (0.125 vs 0.634 direção-anterior no backtest).
- Amostra suficiente: **NÃO** (39 avaliações).
- Pesos com evidência: **NÃO** (todos 1.0, correto mas sem evidência ainda).
- Ablação: **🟣 AGUARDANDO DADOS**.

**Software ≠ modelo: o sistema roda, mas NÃO há evidência de capacidade preditiva — e a amostra atual sugere desempenho INFERIOR aos baselines.**

### D) PENDÊNCIAS

| PRIORIDADE | PROBLEMA | ARQUIVO/COMPONENTE | CORREÇÃO PONTUAL | COMO VALIDAR |
|---|---|---|---|---|
| P0 | Modelo perde de todos os baselines (0.125 vs 0.634) | ensemble + sinais | investigar alinhamento sinal×resultado; suspeita de SIDEWAYS contado como erro e/ou sinais quase-nulos | backtest com direction=UP/DOWN apenas; comparar vs baselines |
| P0 | Calibração ruim (gap −0.31) | ProbabilityCalculator | recalibrar slope após ≥ 100 amostras; considerar calibração isotônica/Platt | `calibration()` com bins confiáveis |
| P1 | Freshness única pune fontes de baixa frequência | SignalNormalizer | meia-vida por fonte (macro: dias; on-chain: dias; candles: minutos) | reavaliar confiança de CRONOS/POSEIDON em 15m |
| P1 | OpenAI sem key (HERMES morto) | config/api_keys.php | configurar OPENAI_API_KEY | llm_usage com status ok + custo |
| P1 | Só timeframe 1m | collectors/config | coletar 5m/1h/4h/1d; mapear horizonte→intervalo | COUNT(DISTINCT interval_name) > 1 |
| P2 | Liquidações ausentes | DerivativesCollector | endpoint assinado Binance | derivatives_data metric=liquidations |
| P2 | Índices/DXY não implementados | MarketRelations | fonte com credencial (FRED DTWEXBGS já coletado como proxy) | correlações novas no metadata |
| P3 | WalkForwardBacktester legado coexiste | evaluation/ | remover ou marcar deprecated | — |

### E) VEREDITO TÉCNICO
- **ENGENHARIA: 84%** — arquitetura implementada e testada; gaps pontuais conhecidos.
- **PIPELINE OPERACIONAL: 95%** — ciclo automático completo, resiliente e auditável; pendências: key OpenAI + multi-timeframe.
- **VALIDAÇÃO CIENTÍFICA: 25%** — infraestrutura de validação existe (backtest/OOS/calibração/ablação), mas amostra insuficiente, probabilidades mal calibradas e desempenho abaixo dos baselines.

**1. Implementado conforme arquitetura?** SIM, em engenharia — inclusive backtester que reproduz o motor real.
**2. Ciclo automático funciona?** SIM.
**3. Backtester testa o sistema de produção?** SIM (o novo; o legado simplificado ainda existe para comparação).
**4. Há evidência de capacidade preditiva?** **NÃO.** Amostra pequena, calibração ruim e performance inferior aos baselines até o momento.

---

# RODADA DE CORREÇÕES PONTUAIS — 2026-09-24

Correções metodológicas baseadas na auditoria. **Nenhuma alteração de modelo para melhorar backtest**; nenhuma recalibração de probabilidades; pesos adaptativos preservados.

## Itens corrigidos

| # | ITEM | ANTES | DEPOIS | TESTE | RESULTADO | STATUS |
|---|---|---|---|---|---|---|
| 1 | Multi-timeframe | só `1m` coletado | 6 timeframes reais: 1m 3424, 5m 501, 15m 401, 1h 300, 4h 200, 1d 120 (dedupe uniq_market_candle, timestamps reais) | fix_round_tests 1 | 7/7 | 🟢 |
| 1b | ATHENA por horizonte | janelas de 1m para tudo | mapeamento explícito: 15m→1m, 1h→5m, 4h→15m, 24h→1h (metadata.interval evidencia) | fix_round_tests 2 | PASS 4 horizontes | 🟢 |
| 2 | Freshness por fonte | meia-vida global 15 min | política por módulo (config `freshness.*` com justificativa): ATHENA/HEPHAESTUS/MR 15min, HERMES 6h, POSEIDON 72h, CRONOS 7d + floor horizonte/4; metadata registra data_age/expected_frequency/freshness_factor | fix_round_tests 3 | 4/4 (CRONOS 2h→0.99; ATHENA 2h→0.06; POSEIDON 26h→0.78) | 🟢 |
| 3 | POSEIDON | dado diário penalizado como "minutos" | meia-vida 72h; dado diário recente preservado; Coin Metrics mantido como fonte; as-of com corte superior | fix_round_tests 3+4 | PASS | 🟢 |
| 4 | CRONOS | 1 obs/série, freshness injusta | **backfill FRED 400 obs/série** (us_10y 384, dxy 385, fed_funds 400, total 1169); z-score real sobre série; janela as-of com corte superior (bug de look-ahead corrigido) | fix_round_tests 4+5 | PASS | 🟢 |
| 5 | HERMES/OpenAI | 0 chamadas ok (HTTP 400 em todas) | **bug real no HttpClient**: Content-Type não era enviado no POST → corrigido; fluxo real NewsAPI→OpenAI→JSON validado→persistido (3 notícias reais analisadas: sentiment/relevance/impact/direction) | fix_round_tests 7 | 2/2, chamadas ok reais | 🟢 |
| 6 | Market Relations | só ETH+GOLD | + DXY_PROXY (DTWEXBGS) e US10Y (DGS10) via FRED real; campos asset/window/correlation/previous_correlation/sample_size/contribution; S&P/Nasdaq NÃO adicionados (sem fonte confiável) | fix_round_tests 6 | 4/4 | 🟢 |
| 7 | Baselines | comparados em períodos diferentes | tabela MODEL/N/ACCURACY/BRIER/LOGLOSS nos mesmos 68 timestamps; diagnóstico SIDEWAYS: 41/68 previstos, 38 moveram-se (root cause documentada, modelo NÃO alterado) | probe_baselines | ver tabela abaixo | 🟡 |
| 8 | Calibração | — | apenas diagnóstico: gap −0.13 (bin 40–50, n=62); bins pequenos marcados INSUFFICIENT_DATA; **slope não ajustado** | fix_round_tests + probe | documentado | 🟣 |
| 9 | Pesos | células 1h/SIDEWAYS divergiram (n=24≥20 — legítimo) | verificado: nenhuma célula <20 amostras diverge; atualização module×horizon×regime usa só resultados já conhecidos | fix_round_tests 8 | PASS | 🟢 |
| 10 | WalkForward legado | podia ser confundido com oficial | docblock ⚠️ LEGACY/BASELINE; HistoricalPipelineBacktester permanece o oficial | revisão código | ok | 🟢 |

## Tabela de baselines (mesmos 68 timestamps, 2026-09-23 02:11 ~ 09-24 01:13)

| MODEL | N | ACCURACY | BRIER | LOGLOSS |
|---|---|---|---|---|
| PROMETHEUS | 68 | 0.0882 | 0.2545 | 0.7022 |
| ALWAYS_UP | 68 | 0.3235 | 0.4007 | 1.0309 |
| PREV_DIRECTION | 68 | 0.7500 | 0.1728 | 0.5300 |
| RANDOM | 68 | 0.4706 | 0.3125 | 0.8370 |
| TECH_SIMPLE | 68 | 0.5441 | 0.2610 | 0.7239 |

**Resultado mantido honestamente**: Prometheus continua abaixo dos baselines. Causa raiz documentada (não corrigida — exigiria mudança de modelo, fora do escopo): `ProbabilityCalculator::direction()` classifica SIDEWAYS quando \|pUp−pDown\|<0.04; com confidence baixa (0.13–0.17 típica), pUp≈0.5 sempre → 41/68 previsões SIDEWAYS enquanto o mercado moveu em 62/68 casos. Brier/logloss do Prometheus melhores que always-up, mas a política de decisão SIDEWAYS destrói a accuracy direcional.

## Bugs reais encontrados e corrigidos nesta rodada

1. **HttpClient POST sem Content-Type** (`core/HttpClient.php`) — header definido após `CURLOPT_HTTPHEADER` nunca era enviado → OpenAI rejeitava com 400 desde sempre. Corrigido (sem duplicar se o caller já envia).
2. **Look-ahead latente em CRONOS/POSEIDON/HERMES**: janela as-of tinha só limite inferior — em backtest, `DATE_SUB('T', ...)` sem `<= T` incluía dados futuros. Corrigido com `sqlUpperBound` adicional nos 3 módulos.

## Regressão final (todas as suítes pós-correções)

| Suíte | Resultado |
|---|---|
| fix_round_tests (nova, itens 1–10) | 24/24 |
| item9_15_tests | 45/45 |
| item16_21_tests | 28/28 |
| item2_db_logger_tests | 13/13 |
| item5_6_health_tests | 19/19 |
| backtest_pipeline_tests (anti-look-ahead) | 23/23 |
| item30_failure_scenarios | 13/13 |
| item25_e2e_full | E2E COMPLETO: OK |
| cron.php all | EXIT 0 (collect multi-TF → predictions → evaluate → optimize) |
| probe_dashboard | OK (17.8KB HTML real) |
| Persistência antes do target | 98/98 previsões com created_at < target_time |

## Verificações específicas solicitadas

- ✅ múltiplos timeframes existem no banco (6 intervalos)
- ✅ ATHENA usa os timeframes (metadata.interval = 1m/5m/15m/1h conforme horizonte)
- ✅ CRONOS não sofre freshness de minutos (meia-vida 7d, fator 0.99 com 2h de idade)
- ✅ POSEIDON não sofre freshness inadequada (meia-vida 72h)
- ✅ Coin Metrics continua operacional (fonte preservada)
- ✅ FRED possui histórico (1169 observações)
- ✅ HERMES usa dados reais (chave configurada, fluxo completo executado)
- ✅ Market Relations funcional com 4 relações reais
- ✅ nenhuma alteração criou look-ahead (23/23 anti-look-ahead + correção dos 3 módulos)
- ✅ CRON funcionando (EXIT 0 ciclo completo)
- ✅ dashboard funcionando (probe OK)
- ✅ previsões persistidas antes do target (98/98)
