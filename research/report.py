"""Generate the reviewable Portuguese audit from computed results."""
import json
from pathlib import Path
D=Path(__file__).resolve().parents[1]/'storage/research';ROOT=D.parents[1]
def load(n):return json.loads((D/(n+'.json')).read_text(encoding='utf-8'))
A=load('analysis');V=load('verification');C=load('calibration_experiments');cand=load('candidates')
def f(v,n=2):return '—' if v is None else f'{v:.{n}f}'.replace('.',',')
lines=['# Auditoria PROMETHEUS — 2 de novembro de 2026','',
'## Conclusão', '',
'O histórico consultado não sustenta ativar uma estratégia de lucratividade expressiva. Os 12 bots foram auditados; 176 variantes de sinais foram testadas. Das 98 variantes com pelo menos 30 operações no treino, nenhuma foi lucrativa depois dos custos. Isso não demonstra que nenhuma estratégia possa funcionar; demonstra que as alternativas testadas não justificam promoção para operação.', '',
'O principal problema econômico é uma vantagem bruta pequena diante de custos altos para o horizonte de operação. Há também problemas de funcionamento, coleta, avaliação e integridade do experimento ATHENA-50. Reduzir filtros para aumentar o número de operações agravaria a exposição a uma vantagem ainda não demonstrada.', '',
'## Escopo e dados', '',
f"- Base local `prometheus`, conexão PDO em transação somente leitura. Nenhum saldo, previsão, resultado ou configuração foi alterado. Snapshot final: {load('clock')[0]['db_now']} (Fortaleza).",
f"- {A['prediction_count']:,} previsões entre {A['period'][0]} e {A['period'][1]}, quatro horizontes; 60.080 candles BTC de 1m/15m exportados.",
'- 256 trades fechados na base, dos quais 215 pertencem aos 12 bots atuais; os outros 41 são do modo legado. Existem 14 contas físicas: 12 atuais e 2 legadas.',
'- 11.659 previsões passaram os filtros da auditoria; 76 ainda sem resultado, 35 com primeiro candle de alvo mais de 120 segundos atrasado e 17 com preço final divergente do candle atual em mais de US$ 0,02 foram excluídas dos testes. Três resultados FLAT não entram nas métricas binárias.',
'- As 17 divergências exigem investigação: revisão do candle ou mudança de avaliador são hipóteses, não causas comprovadas. A auditoria não reescreveu esses resultados.',
'- Os bots iniciais têm snapshots desde 29/09; os cinco novos desde 01/10. O gráfico da interface usa somente os últimos 720 snapshots, portanto não representa necessariamente toda a vida da conta.', '',
'## Resultado dos 12 bots', '',
'Equity no último snapshot de cada bot, em torno de 03:23 de 02/11. Valores em US$. Custos da tabela referem-se a trades fechados; equity inclui posição aberta. Não são cotações atuais de mercado.', '',
'| Bot | Trades fechados | Equity | PnL fechado antes de todos os custos | Custos fechados | PnL fechado líquido | Acerto |',
'|---|---:|---:|---:|---:|---:|---:|']
for b in sorted(A['bots'],key=lambda x:-x['equity']):
 lines.append(f"| {b['mode']} | {b['trades']} | {f(b['equity'])} | {f(b['gross_before_all_costs'])} | {f(b['closed_costs'])} | {f(b['net_closed'])} | {f(b['win_pct'],1)} |")
lines+=['',
'O conjunto dos 215 trades fechados produziu **US$ 1,3141 antes de todos os custos**, pagou **US$ 12,7745** e perdeu **US$ 11,4604 líquidos**. A reconciliação de `net_pnl = gross_pnl - fees_usd` passou em todos os trades; o slippage já está nos preços de execução e foi somado de volta apenas para medir o resultado antes de todos os custos. Taxas e slippage acumulados nas contas também conferem com as ordens.', '',
'- **SPOT_DCA:** preservou US$ 100 porque não fez operações. Não há evidência de desempenho de uma estratégia ativa.',
'- **SPOT_GRID / BREAKOUT:** uma e duas operações, respectivamente; amostra insuficiente para classificar habilidade.',
'- **PROMETHEUS / MULTIHORIZON / INVERSE:** últimos encerramentos em 30/09. A curva parada em outubro não significa um mês de operações repetidamente perdedoras; os filtros passaram a impedir entradas. Não remover o bloqueio de vantagem líquida.',
'- **MOMENTUM:** 70 trades, ganho bruto de US$ 0,44 e custo de US$ 4,13. Quarenta e cinco saídas por tempo máximo, vinte por stop e quatro por alvo. O custo consumiu muito mais que a vantagem bruta.',
'- **ADAPTIVE / VWAP:** têm resultado bruto positivo, mas insuficiente para custos. Mesmo reduzindo todos os custos dos trades fechados pela metade, continuam negativos.',
'- **MEAN_REVERSION / FUNDING_BIAS / BREAKOUT / GRID / MULTIHORIZON:** resultado fechado negativo mesmo antes de taxas e slippage.',
'- **REBALANCE:** uma compra ainda aberta, zero trades encerrados e US$ 0,02997 de custo já pago. A interface mostrar zero em custos fechados não significa operação sem custo. A perda de equity é principalmente marcação da posição BTC.', '',
'## Previsões', '',
'Métricas descritivas sobre todo o período válido. As observações aqui se sobrepõem; não interpretar milhares de linhas como milhares de apostas independentes. Brier menor é melhor; 0,25 é a previsão constante de 50%.', '',
'| Horizonte | Resultados binários | Acerto | Previsões de alta | Movimento absoluto médio | Retorno médio bruto seguindo sinal | Brier |',
'|---|---:|---:|---:|---:|---:|---:|']
for h,z in A['forecast'].items():lines.append(f"| {h} | {z['n']} | {f(z['hit_pct'])}% | {f(z['predicted_up_pct'])}% | {f(z['mean_abs_move_pct'],3)}% | {f(z['direct_mean_gross_pct'],3)}% | {f(z['brier'],5)} |")
lines+=['',
'A previsão de 15m indica alta em apenas 13,29% dos casos, embora 50,56% dos resultados tenham sido de alta. Existe forte inclinação para baixa, sem vantagem suficiente nesse horizonte. Em 24h o resultado agregado é ruim, mas a amostra independente é pequena e o desempenho muda ao longo do tempo; inverter o sinal retrospectivamente não constitui estratégia validada.', '',
'A configuração cobra 0,10% de taxa e 0,02% de slippage por lado: **0,24% por ida e volta**, mais margem de entrada de 0,03%. Em 15m, o movimento absoluto médio foi 0,124% e o retorno bruto médio direcional foi 0,0068%. A diferença entre probabilidade e 50% não é retorno esperado em preço.', '',
'## Todos os módulos', '',
'Acerto direcional de sinais AVAILABLE e não nulos. Colunas não têm exatamente a mesma amostra; são diagnóstico e não ranking causal de contribuição.', '',
'| Módulo | 15m | 1h | 4h | 24h |',
'|---|---:|---:|---:|---:|']
for m in ['ATHENA','HERMES','POSEIDON','HEPHAESTUS','CRONOS','MARKET_RELATIONS']:
 cells=[]
 for h in ['15m','1h','4h','24h']:
  z=next(x for x in A['modules'] if x['module']==m and x['horizon']==h)
  cells.append(f"{f(z['hit_pct'])}% (n={z['usable']})" if z['usable'] else 'sem sinal válido')
 lines.append('| '+m+' | '+' | '.join(cells)+' |')
lines+=['',
'HEPHAESTUS em 15m e POSEIDON em 4h merecem acompanhamento, mas seus retornos brutos médios direcionais foram apenas 0,0135% e 0,1029%, respectivamente, ainda abaixo de 0,24% por operação. Não aumentar seus pesos apenas por essa taxa de acerto.', '',
'HERMES esteve AVAILABLE em só 39 das 2.930 previsões válidas de 15m; 1.184 estavam ERROR e 1.707 UNAVAILABLE. Apenas 18 sinais não nulos avaliáveis restam. O snapshot recente registra `llm_analysis_failed` e erros HTTP; não há base para atribuir poder preditivo às notícias.', '',
'### ATHENA-50 e grupos de indicadores', '',
'A tabela `athena50_shadow_predictions` está **vazia**. O código exige `regime`, `group_ablation_predictions`, `group_ablation_results`, `candidate_predictions` e `candidate_results`, ausentes no schema conectado. Os logs registram **10.395** eventos `athena50_shadow_failed` e **10.672** `athena50_shadow_evaluation_unavailable`. A mensagem recente é `Unknown column regime in field list`.', '',
'A migration 012 atual contém as colunas, mas usa CREATE TABLE IF NOT EXISTS: reaplicá-la não atualiza uma tabela antiga. É necessária uma migration aditiva. Não existem resultados prospectivos dos 50 indicadores ou dos seus grupos para avaliar. Recriá-los hoje a partir de candles revisados não pode ser apresentado como previsão registrada no passado.', '',
'## Testes de estratégias e validação', '',
f"Divisão por tempo: primeiros 70% para treino, últimos 30% para teste, corte em **{A['split'].replace('T',' ')}**. Treino só inclui alvos vencidos e resultados avaliados antes do corte. Nenhuma posição atravessa o corte. A seleção usa exclusivamente retorno do treino, com pelo menos 30 trades.", '',
'Foram testadas 176 combinações: quatro horizontes; ensemble ou um dos seis módulos; sinal direto ou inverso; limiares do ensemble de 0/0,025/0,05/0,10 e dos módulos de 0/0,10/0,25. O limiar de módulo incide em |sinal × confiança|. Cada candidato parte de US$ 100, aloca 25% do patrimônio, mantém até o alvo e não acumula posições sobrepostas. Custos normais de 0,24% e estresse de 0,60% sobre o nocional.', '',
'Estes são testes de variantes dos sinais congelados, **não uma reprodução exata das 12 máquinas de execução**: não usam as reversões/stop/target de cada bot. O desempenho dos 12 bots vem do livro real de simulação e foi reprecificado separadamente. Short é sintético; funding, aluguel, spread variável, fila e liquidez não estão modelados. Portanto, os testes não aprovam execução real.', '',
'**Resultado:** nenhuma das 98 variantes elegíveis deu lucro no treino. As demais têm menos de 30 trades, incluindo variantes de 24h cujo treino é curto demais. Caixa com retorno zero supera todos os elegíveis no treino. A variante menos negativa foi ATHENA invertido em 4h, limiar 0,25:', '',
'| Etapa | Trades | Retorno da conta | Profit factor |',
'|---|---:|---:|---:|',
f"| Treino | 44 | {f(A['selected_on_train']['train']['return_pct'])}% | {f(A['selected_on_train']['train']['profit_factor'])} |",
f"| Teste separado | 21 | {f(A['selected_on_train']['test']['return_pct'])}% | {f(A['selected_on_train']['test']['profit_factor'])} |",
f"| Teste, custos de estresse | 21 | {f(A['selected_on_train']['stress']['return_pct'])}% | {f(A['selected_on_train']['stress']['profit_factor'])} |", '',
'Uma segunda verificação usa entrada no primeiro fechamento de 1m após a previsão, em vez do preço inicial congelado: **21 trades, −0,78%**. O intervalo exploratório por reamostragem de dias para a média líquida por trade foi de −0,330% a +0,184%; são poucos dias ativos, não há correção por seleção múltipla e isso não demonstra vantagem positiva.', '',
'Também houve três testes sequenciais, selecionando novamente apenas no passado e avaliando no bloco seguinte:', '',
'| Bloco de teste | Escolha no passado | Trades | Retorno |',
'|---|---|---:|---:|']
for w in A['walk_forward']:
 s=w['selected'];lines.append(f"| {w['from'][:10]} a {w['to'][:10]} | {s['source']} {s['horizon']}, inversão={s['invert']}, limiar={s['threshold']} | {w['test']['trades']} | {f(w['test']['return_pct'])}% |")
lines+=['',
'Não houve teste de todas as estratégias possíveis, otimização de stops nem prova de impossibilidade de lucro. Procurar centenas de variações até encontrar um vencedor aumenta o risco de ajuste ao ruído. O arquivo de candidatos preserva os resultados, inclusive variantes que parecem boas no teste, sem escolhê-las retroativamente.', '',
'## Experimentos para melhorar as probabilidades', '',
'Foram ajustados somente no treino: calibração logística da probabilidade original (Platt), regressão logística regularizada com os cinco módulos de maior cobertura e a mesma regressão sem CRONOS. HERMES foi excluído pela baixa cobertura. Penalidade L2 fixa de 0,05, 600 passos, sem escolher hiperparâmetros olhando o teste. Amostragem sem sobreposição com margem temporal de dois minutos.', '',
'| Horizonte | N treino / teste independente | Brier original | Brier Platt | Brier cinco módulos | Brier sem CRONOS |',
'|---|---:|---:|---:|---:|---:|']
for z in C:lines.append(f"| {z['horizon']} | {z['train_n']} / {z['test_n']} | {f(z['original']['brier'],5)} | {f(z['platt']['brier'],5)} | {f(z['all_modules']['brier'],5)} | {f(z['without_macro']['brier'],5)} |")
lines+=['',
'Em 15m houve pequena redução do Brier, mas nenhuma dessas alternativas superou 0,25. Em 1h/4h/24h a previsão original foi melhor no teste. Em 24h existem só 11 resultados independentes de teste. **Nenhuma recalibração foi promovida.** Não se deve confundir melhora mínima de Brier com expectativa líquida de lucro.', '',
'## Problemas do sistema e correções prioritárias', '',
'1. **Monitoramento e cadência:** última execução por volta de 03:23, com mais de 16 horas de atraso no snapshot da auditoria. Intervalo mediano entre previsões de 15m: 19,93 minutos, já maior que o horizonte. Há 10 intervalos superiores a uma hora; o maior tem 4.150 minutos. Conferir o Agendador do Windows/cron, duração dos jobs e alertar por ausência de heartbeat. O motivo operacional da parada não foi comprovado.',
'2. **Stop independente de nova previsão:** `PaperTrader::runMode()` chama `riskExit()` dentro do laço que só processa previsão inédita e recente. Sem previsão nova, não verifica stop, alvo nem duração máxima, mesmo com preço disponível. Criar evento de risco separado, idempotente por candle, com controle transacional, que não exige prediction_id novo. Trades chegaram a 82,28 minutos para limite configurado de 60. O limite de preço não é garantido durante gaps.',
'3. **Restaurar o shadow ATHENA-50:** acrescentar as cinco colunas ausentes, validar schema antes de habilitar a coleta e verificar gravação/avaliação de novas linhas. Não apagar previsões nem fabricar backfill prospectivo. A correção deve preservar o isolamento do ensemble produtivo.',
'4. **Corrigir linhagem temporal:** `MarketCollector` sobrescreve `ingested_at` em cada atualização. Assim, o banco perde o instante da primeira disponibilidade e um replay histórico pode rejeitar candles realmente conhecidos naquele momento. Preservar primeira ingestão e registrar revisões/última ingestão separadamente; valores revisados também precisam de versionamento, não basta manter o timestamp antigo.',
'5. **Limitar tolerância do alvo:** `TargetPriceResolver` pega o primeiro candle depois do alvo sem atraso máximo. A base contém um gap de 3.652 minutos. Marcar alvo sem candle próximo como indisponível/inválido, armazenar identificação do candle e atraso; não substituir silenciosamente 15 minutos por horas ou dias.',
'6. **Separar vantagem de volatilidade:** `PaperStrategyEngine` usa distância de EMA/VWAP, ATR e largura das bandas como movimento esperado. Essas grandezas não medem, sozinhas, lucro futuro condicional. Aprender retorno líquido realizado por regra, regime e horizonte, usando apenas resultados vencidos, amostras sem sobreposição e limite inferior conservador; permitir decisão de não operar.',
'7. **Ajustar objetivo dos pesos e calibração:** `ProbabilityCalculator::calculate()` aplica uma logística com inclinação fixa de 1,65; não é calibração aprendida. `calibration()` mistura horizontes e lê as primeiras 5.000 linhas, deixando de refletir a amostra recente. `WeightOptimizer` usa acerto/Brier agregados, incluindo resultados sobrepostos, e o ciclo completo o chama após o avaliador já ter otimizado. Separar horizontes, manter versões históricas de pesos, validar cronologicamente e otimizar uma única vez por lote novo.',
'8. **Precisão do painel:** corrigir texto de sete para doze contas, explicitar janela de 720 snapshots, separar custos pagos em ordens de custos apenas em trades encerrados e identificar mudança de versão da estratégia. Não reiniciar saldos para ocultar perdas anteriores.', '',
'## Estratégia recomendada para a próxima fase', '',
'A decisão sustentada pelos testes é **não aumentar exposição nem afrouxar os filtros**. Caixa é o controle a ser batido. A próxima fase deve usar novas contas shadow versionadas, mantendo as contas e perdas existentes, após corrigir funcionamento e coleta.', '',
'Como hipóteses para pesquisa prospectiva, priorizar horizontes de 1h/4h, execução menos frequente e estimativa explícita de retorno líquido; acompanhar HEPHAESTUS e POSEIDON sem lhes atribuir peso maior automaticamente. Manter DCA/rebalanceamento como alocação spot separada de previsão direcional. Funding bias não é arbitragem de funding, pois não existem duas pernas hedgeadas nem fluxo de funding contabilizado.', '',
'Antes de aprovar um candidato: exigir resultado positivo em vários blocos futuros, comparação com caixa e BTC com mesma exposição, custos realistas, drawdown marcado a mercado, amostra suficiente para estimar incerteza e ausência de falhas temporais. Os 30 trades usados nesta triagem são apenas piso de pesquisa, não certificação estatística. Não há prazo ou rentabilidade garantidos.', '',
'## Reprodutibilidade e limitações', '',
'Scripts adicionados em `research/`: `export_audit.php`, `analyze.py`, `calibrate.py`, `verify.py`, `report.py`. Exports locais em `storage/research/`, ignorados pelo Git e bloqueados via `.htaccess` quando Apache permite esse controle. Nenhum script contém credenciais copiadas: o exportador lê a configuração existente e só aceita CLI.', '',
'```powershell',
'C:\\xampp\\php\\php.exe research\\export_audit.php',
'python research\\analyze.py',
'python research\\calibrate.py',
'python research\\verify.py',
'python research\\report.py',
'```', '',
'Saídas: `analysis.json`, `candidates.csv`, `candidates.json`, `calibration_experiments.json`, `verification.json`, schema e snapshots. Uma nova exportação substitui os snapshots locais e pode mudar os resultados se a base continuar recebendo dados. A análise não executa jobs de trading, chamadas à corretora, migrations ou atualização de pesos.', '',
'O período é curto, contém mudanças de código sem versionamento completo e tem forte sobreposição de resultados. Os testes usam sinais já gravados e candles atualmente disponíveis, sem alegar reconstrução completa do que era conhecido antes de revisões. Retornos de pesquisa usam custo percentual fixo; drawdowns dos candidatos são medidos nos encerramentos, não intrabar. Não foram inferidas taxas reais de corretora a partir desses custos simulados.', '',
'Referência metodológica: Bailey et al., [The Probability of Backtest Overfitting](https://www.davidhbailey.com/dhbpapers/backtest-prob.pdf), sobre o risco de seleção de estratégias após muitas tentativas. Todos os números financeiros deste relatório foram calculados exclusivamente da base local.', '']
path=ROOT/'docs/AUDITORIA_PROMETHEUS_2026-11-02.md';path.write_text('\n'.join(lines),encoding='utf-8');print(path)
