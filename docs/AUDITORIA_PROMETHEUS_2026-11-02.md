# Auditoria PROMETHEUS — 2 de novembro de 2026

## Conclusão

O histórico consultado não sustenta ativar uma estratégia de lucratividade expressiva. Os 12 bots foram auditados; 176 variantes de sinais foram testadas. Das 98 variantes com pelo menos 30 operações no treino, nenhuma foi lucrativa depois dos custos. Isso não demonstra que nenhuma estratégia possa funcionar; demonstra que as alternativas testadas não justificam promoção para operação.

O principal problema econômico é uma vantagem bruta pequena diante de custos altos para o horizonte de operação. Há também problemas de funcionamento, coleta, avaliação e integridade do experimento ATHENA-50. Reduzir filtros para aumentar o número de operações agravaria a exposição a uma vantagem ainda não demonstrada.

## Escopo e dados

- Base local `prometheus`, conexão PDO em transação somente leitura. Nenhum saldo, previsão, resultado ou configuração foi alterado. Snapshot final: 2026-11-02 20:18:58 (Fortaleza).
- 11,787 previsões entre 2026-09-23 02:11:30 e 2026-11-02 03:23:46, quatro horizontes; 60.080 candles BTC de 1m/15m exportados.
- 256 trades fechados na base, dos quais 215 pertencem aos 12 bots atuais; os outros 41 são do modo legado. Existem 14 contas físicas: 12 atuais e 2 legadas.
- 11.659 previsões passaram os filtros da auditoria; 76 ainda sem resultado, 35 com primeiro candle de alvo mais de 120 segundos atrasado e 17 com preço final divergente do candle atual em mais de US$ 0,02 foram excluídas dos testes. Três resultados FLAT não entram nas métricas binárias.
- As 17 divergências exigem investigação: revisão do candle ou mudança de avaliador são hipóteses, não causas comprovadas. A auditoria não reescreveu esses resultados.
- Os bots iniciais têm snapshots desde 29/09; os cinco novos desde 01/10. O gráfico da interface usa somente os últimos 720 snapshots, portanto não representa necessariamente toda a vida da conta.

## Resultado dos 12 bots

Equity no último snapshot de cada bot, em torno de 03:23 de 02/11. Valores em US$. Custos da tabela referem-se a trades fechados; equity inclui posição aberta. Não são cotações atuais de mercado.

| Bot | Trades fechados | Equity | PnL fechado antes de todos os custos | Custos fechados | PnL fechado líquido | Acerto |
|---|---:|---:|---:|---:|---:|---:|
| SPOT_DCA | 0 | 100,00 | 0,00 | 0,00 | 0,00 | — |
| SPOT_GRID | 1 | 99,93 | -0,01 | 0,06 | -0,07 | 0,0 |
| BREAKOUT | 2 | 99,74 | -0,14 | 0,12 | -0,26 | 0,0 |
| ADAPTIVE | 19 | 99,33 | 0,47 | 1,14 | -0,67 | 31,6 |
| PROMETHEUS | 15 | 99,23 | 0,13 | 0,89 | -0,77 | 26,7 |
| FUNDING_BIAS | 12 | 99,20 | -0,08 | 0,72 | -0,80 | 41,7 |
| INVERSE | 20 | 99,00 | 0,20 | 1,19 | -1,00 | 20,0 |
| MEAN_REVERSION | 20 | 98,73 | -0,08 | 1,19 | -1,27 | 30,0 |
| MULTIHORIZON | 15 | 98,60 | -0,51 | 0,89 | -1,40 | 20,0 |
| VWAP_TREND | 41 | 98,45 | 0,89 | 2,44 | -1,55 | 41,5 |
| REBALANCE | 0 | 98,15 | 0,00 | 0,00 | 0,00 | — |
| MOMENTUM | 70 | 96,31 | 0,44 | 4,13 | -3,69 | 35,7 |

O conjunto dos 215 trades fechados produziu **US$ 1,3141 antes de todos os custos**, pagou **US$ 12,7745** e perdeu **US$ 11,4604 líquidos**. A reconciliação de `net_pnl = gross_pnl - fees_usd` passou em todos os trades; o slippage já está nos preços de execução e foi somado de volta apenas para medir o resultado antes de todos os custos. Taxas e slippage acumulados nas contas também conferem com as ordens.

- **SPOT_DCA:** preservou US$ 100 porque não fez operações. Não há evidência de desempenho de uma estratégia ativa.
- **SPOT_GRID / BREAKOUT:** uma e duas operações, respectivamente; amostra insuficiente para classificar habilidade.
- **PROMETHEUS / MULTIHORIZON / INVERSE:** últimos encerramentos em 30/09. A curva parada em outubro não significa um mês de operações repetidamente perdedoras; os filtros passaram a impedir entradas. Não remover o bloqueio de vantagem líquida.
- **MOMENTUM:** 70 trades, ganho bruto de US$ 0,44 e custo de US$ 4,13. Quarenta e cinco saídas por tempo máximo, vinte por stop e quatro por alvo. O custo consumiu muito mais que a vantagem bruta.
- **ADAPTIVE / VWAP:** têm resultado bruto positivo, mas insuficiente para custos. Mesmo reduzindo todos os custos dos trades fechados pela metade, continuam negativos.
- **MEAN_REVERSION / FUNDING_BIAS / BREAKOUT / GRID / MULTIHORIZON:** resultado fechado negativo mesmo antes de taxas e slippage.
- **REBALANCE:** uma compra ainda aberta, zero trades encerrados e US$ 0,02997 de custo já pago. A interface mostrar zero em custos fechados não significa operação sem custo. A perda de equity é principalmente marcação da posição BTC.

## Previsões

Métricas descritivas sobre todo o período válido. As observações aqui se sobrepõem; não interpretar milhares de linhas como milhares de apostas independentes. Brier menor é melhor; 0,25 é a previsão constante de 50%.

| Horizonte | Resultados binários | Acerto | Previsões de alta | Movimento absoluto médio | Retorno médio bruto seguindo sinal | Brier |
|---|---:|---:|---:|---:|---:|---:|
| 15m | 2927 | 49,30% | 13,29% | 0,124% | 0,007% | 0,25052 |
| 1h | 2949 | 51,03% | 14,78% | 0,237% | 0,023% | 0,24928 |
| 4h | 2925 | 53,91% | 14,12% | 0,445% | 0,046% | 0,24644 |
| 24h | 2855 | 37,44% | 11,31% | 0,928% | -0,205% | 0,25879 |

A previsão de 15m indica alta em apenas 13,29% dos casos, embora 50,56% dos resultados tenham sido de alta. Existe forte inclinação para baixa, sem vantagem suficiente nesse horizonte. Em 24h o resultado agregado é ruim, mas a amostra independente é pequena e o desempenho muda ao longo do tempo; inverter o sinal retrospectivamente não constitui estratégia validada.

A configuração cobra 0,10% de taxa e 0,02% de slippage por lado: **0,24% por ida e volta**, mais margem de entrada de 0,03%. Em 15m, o movimento absoluto médio foi 0,124% e o retorno bruto médio direcional foi 0,0068%. A diferença entre probabilidade e 50% não é retorno esperado em preço.

## Todos os módulos

Acerto direcional de sinais AVAILABLE e não nulos. Colunas não têm exatamente a mesma amostra; são diagnóstico e não ranking causal de contribuição.

| Módulo | 15m | 1h | 4h | 24h |
|---|---:|---:|---:|---:|
| ATHENA | 48,29% (n=2924) | 48,57% (n=2946) | 47,60% (n=2922) | 48,48% (n=2855) |
| HERMES | 61,11% (n=18) | 31,58% (n=19) | 26,67% (n=15) | sem sinal válido |
| POSEIDON | 50,00% (n=2922) | 56,62% (n=2944) | 59,49% (n=2920) | 54,82% (n=2853) |
| HEPHAESTUS | 54,64% (n=2923) | 51,99% (n=2945) | 53,92% (n=2921) | 46,32% (n=2854) |
| CRONOS | 49,36% (n=2905) | 48,80% (n=2914) | 51,05% (n=2903) | 42,93% (n=2837) |
| MARKET_RELATIONS | 49,69% (n=2924) | 47,56% (n=2946) | 50,48% (n=2922) | 42,56% (n=2855) |

HEPHAESTUS em 15m e POSEIDON em 4h merecem acompanhamento, mas seus retornos brutos médios direcionais foram apenas 0,0135% e 0,1029%, respectivamente, ainda abaixo de 0,24% por operação. Não aumentar seus pesos apenas por essa taxa de acerto.

HERMES esteve AVAILABLE em só 39 das 2.930 previsões válidas de 15m; 1.184 estavam ERROR e 1.707 UNAVAILABLE. Apenas 18 sinais não nulos avaliáveis restam. O snapshot recente registra `llm_analysis_failed` e erros HTTP; não há base para atribuir poder preditivo às notícias.

### ATHENA-50 e grupos de indicadores

A tabela `athena50_shadow_predictions` está **vazia**. O código exige `regime`, `group_ablation_predictions`, `group_ablation_results`, `candidate_predictions` e `candidate_results`, ausentes no schema conectado. Os logs registram **10.395** eventos `athena50_shadow_failed` e **10.672** `athena50_shadow_evaluation_unavailable`. A mensagem recente é `Unknown column regime in field list`.

A migration 012 atual contém as colunas, mas usa CREATE TABLE IF NOT EXISTS: reaplicá-la não atualiza uma tabela antiga. É necessária uma migration aditiva. Não existem resultados prospectivos dos 50 indicadores ou dos seus grupos para avaliar. Recriá-los hoje a partir de candles revisados não pode ser apresentado como previsão registrada no passado.

## Testes de estratégias e validação

Divisão por tempo: primeiros 70% para treino, últimos 30% para teste, corte em **2026-10-21 03:02:05.200000**. Treino só inclui alvos vencidos e resultados avaliados antes do corte. Nenhuma posição atravessa o corte. A seleção usa exclusivamente retorno do treino, com pelo menos 30 trades.

Foram testadas 176 combinações: quatro horizontes; ensemble ou um dos seis módulos; sinal direto ou inverso; limiares do ensemble de 0/0,025/0,05/0,10 e dos módulos de 0/0,10/0,25. O limiar de módulo incide em |sinal × confiança|. Cada candidato parte de US$ 100, aloca 25% do patrimônio, mantém até o alvo e não acumula posições sobrepostas. Custos normais de 0,24% e estresse de 0,60% sobre o nocional.

Estes são testes de variantes dos sinais congelados, **não uma reprodução exata das 12 máquinas de execução**: não usam as reversões/stop/target de cada bot. O desempenho dos 12 bots vem do livro real de simulação e foi reprecificado separadamente. Short é sintético; funding, aluguel, spread variável, fila e liquidez não estão modelados. Portanto, os testes não aprovam execução real.

**Resultado:** nenhuma das 98 variantes elegíveis deu lucro no treino. As demais têm menos de 30 trades, incluindo variantes de 24h cujo treino é curto demais. Caixa com retorno zero supera todos os elegíveis no treino. A variante menos negativa foi ATHENA invertido em 4h, limiar 0,25:

| Etapa | Trades | Retorno da conta | Profit factor |
|---|---:|---:|---:|
| Treino | 44 | -0,27% | 0,94 |
| Teste separado | 21 | -0,83% | 0,69 |
| Teste, custos de estresse | 21 | -2,69% | 0,30 |

Uma segunda verificação usa entrada no primeiro fechamento de 1m após a previsão, em vez do preço inicial congelado: **21 trades, −0,78%**. O intervalo exploratório por reamostragem de dias para a média líquida por trade foi de −0,330% a +0,184%; são poucos dias ativos, não há correção por seleção múltipla e isso não demonstra vantagem positiva.

Também houve três testes sequenciais, selecionando novamente apenas no passado e avaliando no bloco seguinte:

| Bloco de teste | Escolha no passado | Trades | Retorno |
|---|---|---:|---:|
| 2026-10-13 a 2026-10-19 | ensemble 4h, inversão=-1, limiar=0.05 | 13 | -0,27% |
| 2026-10-19 a 2026-10-25 | ATHENA 4h, inversão=-1, limiar=0.25 | 15 | -0,30% |
| 2026-10-25 a 2026-11-02 | ATHENA 4h, inversão=-1, limiar=0.25 | 10 | -0,37% |

Não houve teste de todas as estratégias possíveis, otimização de stops nem prova de impossibilidade de lucro. Procurar centenas de variações até encontrar um vencedor aumenta o risco de ajuste ao ruído. O arquivo de candidatos preserva os resultados, inclusive variantes que parecem boas no teste, sem escolhê-las retroativamente.

## Experimentos para melhorar as probabilidades

Foram ajustados somente no treino: calibração logística da probabilidade original (Platt), regressão logística regularizada com os cinco módulos de maior cobertura e a mesma regressão sem CRONOS. HERMES foi excluído pela baixa cobertura. Penalidade L2 fixa de 0,05, 600 passos, sem escolher hiperparâmetros olhando o teste. Amostragem sem sobreposição com margem temporal de dois minutos.

| Horizonte | N treino / teste independente | Brier original | Brier Platt | Brier cinco módulos | Brier sem CRONOS |
|---|---:|---:|---:|---:|---:|
| 15m | 1313 / 671 | 0,25046 | 0,25023 | 0,25027 | 0,25028 |
| 1h | 410 / 201 | 0,24613 | 0,25107 | 0,25050 | 0,25053 |
| 4h | 132 / 64 | 0,24340 | 0,25189 | 0,25196 | 0,25199 |
| 24h | 23 / 11 | 0,21983 | 0,25264 | 0,27974 | 0,27993 |

Em 15m houve pequena redução do Brier, mas nenhuma dessas alternativas superou 0,25. Em 1h/4h/24h a previsão original foi melhor no teste. Em 24h existem só 11 resultados independentes de teste. **Nenhuma recalibração foi promovida.** Não se deve confundir melhora mínima de Brier com expectativa líquida de lucro.

## Problemas do sistema e correções prioritárias

1. **Monitoramento e cadência:** última execução por volta de 03:23, com mais de 16 horas de atraso no snapshot da auditoria. Intervalo mediano entre previsões de 15m: 19,93 minutos, já maior que o horizonte. Há 10 intervalos superiores a uma hora; o maior tem 4.150 minutos. Conferir o Agendador do Windows/cron, duração dos jobs e alertar por ausência de heartbeat. O motivo operacional da parada não foi comprovado.
2. **Stop independente de nova previsão:** `PaperTrader::runMode()` chama `riskExit()` dentro do laço que só processa previsão inédita e recente. Sem previsão nova, não verifica stop, alvo nem duração máxima, mesmo com preço disponível. Criar evento de risco separado, idempotente por candle, com controle transacional, que não exige prediction_id novo. Trades chegaram a 82,28 minutos para limite configurado de 60. O limite de preço não é garantido durante gaps.
3. **Restaurar o shadow ATHENA-50:** acrescentar as cinco colunas ausentes, validar schema antes de habilitar a coleta e verificar gravação/avaliação de novas linhas. Não apagar previsões nem fabricar backfill prospectivo. A correção deve preservar o isolamento do ensemble produtivo.
4. **Corrigir linhagem temporal:** `MarketCollector` sobrescreve `ingested_at` em cada atualização. Assim, o banco perde o instante da primeira disponibilidade e um replay histórico pode rejeitar candles realmente conhecidos naquele momento. Preservar primeira ingestão e registrar revisões/última ingestão separadamente; valores revisados também precisam de versionamento, não basta manter o timestamp antigo.
5. **Limitar tolerância do alvo:** `TargetPriceResolver` pega o primeiro candle depois do alvo sem atraso máximo. A base contém um gap de 3.652 minutos. Marcar alvo sem candle próximo como indisponível/inválido, armazenar identificação do candle e atraso; não substituir silenciosamente 15 minutos por horas ou dias.
6. **Separar vantagem de volatilidade:** `PaperStrategyEngine` usa distância de EMA/VWAP, ATR e largura das bandas como movimento esperado. Essas grandezas não medem, sozinhas, lucro futuro condicional. Aprender retorno líquido realizado por regra, regime e horizonte, usando apenas resultados vencidos, amostras sem sobreposição e limite inferior conservador; permitir decisão de não operar.
7. **Ajustar objetivo dos pesos e calibração:** `ProbabilityCalculator::calculate()` aplica uma logística com inclinação fixa de 1,65; não é calibração aprendida. `calibration()` mistura horizontes e lê as primeiras 5.000 linhas, deixando de refletir a amostra recente. `WeightOptimizer` usa acerto/Brier agregados, incluindo resultados sobrepostos, e o ciclo completo o chama após o avaliador já ter otimizado. Separar horizontes, manter versões históricas de pesos, validar cronologicamente e otimizar uma única vez por lote novo.
8. **Precisão do painel:** corrigir texto de sete para doze contas, explicitar janela de 720 snapshots, separar custos pagos em ordens de custos apenas em trades encerrados e identificar mudança de versão da estratégia. Não reiniciar saldos para ocultar perdas anteriores.

## Estratégia recomendada para a próxima fase

A decisão sustentada pelos testes é **não aumentar exposição nem afrouxar os filtros**. Caixa é o controle a ser batido. A próxima fase deve usar novas contas shadow versionadas, mantendo as contas e perdas existentes, após corrigir funcionamento e coleta.

Como hipóteses para pesquisa prospectiva, priorizar horizontes de 1h/4h, execução menos frequente e estimativa explícita de retorno líquido; acompanhar HEPHAESTUS e POSEIDON sem lhes atribuir peso maior automaticamente. Manter DCA/rebalanceamento como alocação spot separada de previsão direcional. Funding bias não é arbitragem de funding, pois não existem duas pernas hedgeadas nem fluxo de funding contabilizado.

Antes de aprovar um candidato: exigir resultado positivo em vários blocos futuros, comparação com caixa e BTC com mesma exposição, custos realistas, drawdown marcado a mercado, amostra suficiente para estimar incerteza e ausência de falhas temporais. Os 30 trades usados nesta triagem são apenas piso de pesquisa, não certificação estatística. Não há prazo ou rentabilidade garantidos.

## Reprodutibilidade e limitações

Scripts adicionados em `research/`: `export_audit.php`, `analyze.py`, `calibrate.py`, `verify.py`, `report.py`. Exports locais em `storage/research/`, ignorados pelo Git e bloqueados via `.htaccess` quando Apache permite esse controle. Nenhum script contém credenciais copiadas: o exportador lê a configuração existente e só aceita CLI.

```powershell
C:\xampp\php\php.exe research\export_audit.php
python research\analyze.py
python research\calibrate.py
python research\verify.py
python research\report.py
```

Saídas: `analysis.json`, `candidates.csv`, `candidates.json`, `calibration_experiments.json`, `verification.json`, schema e snapshots. Uma nova exportação substitui os snapshots locais e pode mudar os resultados se a base continuar recebendo dados. A análise não executa jobs de trading, chamadas à corretora, migrations ou atualização de pesos.

O período é curto, contém mudanças de código sem versionamento completo e tem forte sobreposição de resultados. Os testes usam sinais já gravados e candles atualmente disponíveis, sem alegar reconstrução completa do que era conhecido antes de revisões. Retornos de pesquisa usam custo percentual fixo; drawdowns dos candidatos são medidos nos encerramentos, não intrabar. Não foram inferidas taxas reais de corretora a partir desses custos simulados.

Referência metodológica: Bailey et al., [The Probability of Backtest Overfitting](https://www.davidhbailey.com/dhbpapers/backtest-prob.pdf), sobre o risco de seleção de estratégias após muitas tentativas. Todos os números financeiros deste relatório foram calculados exclusivamente da base local.
