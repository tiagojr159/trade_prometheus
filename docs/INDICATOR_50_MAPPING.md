# Mapa de indicadores do Bitcoin Pulse 50

## Auditoria inicial — 28/09/2026

Fonte auditada: `C:\xampp\htdocs\trade_indicador\indicadores.php` e `coletar_indicadores.php`. O projeto calcula 50 scores discretos limitados a -2..+2, em cinco grupos de dez. A coleta grava o score e o nome de cada feature em `indicador_historico`; não grava os candles e as observações de mercado que formaram cada score. Os quatro primeiros grupos vêm principalmente de candles spot Binance. O quinto combina livro spot e dados USD-M Futures.

### Inventário e redundância

| # | Indicador | Fonte no projeto antigo | Equivalente PROMETHEUS / responsável | Redundância e informação incremental | Recomendação |
|---:|---|---|---|---|---|
| 1 | EMA 9 × EMA 21 | Candles spot | ATHENA: EMA 20/50 | Alta redundância de tendência | Candidato shadow; normalizar por grupo |
| 2 | EMA 50 × EMA 200 | Candles spot | ATHENA: EMA 20/50 | Alta; janelas diferentes, mesmo fator | Shadow, medir incremento |
| 3 | Preço × EMA 200 | Candles spot | ATHENA: tendência EMA | Alta | Shadow, medir incremento |
| 4 | SMA 20 × SMA 50 | Candles spot | ATHENA: EMA e tendência | Alta, variante de média | Shadow, medir incremento |
| 5 | Inclinação EMA 21 | Candles spot | ATHENA: tendência | Alta | Shadow, medir incremento |
| 6 | Inclinação EMA 50 | Candles spot | ATHENA: tendência | Alta | Shadow, medir incremento |
| 7 | ADX + DMI | Candles spot | ATHENA: regime técnico | Média; força/direção de tendência não explícita hoje | Bom candidato incremental |
| 8 | Supertrend proxy ATR | Candles spot | ATHENA: ATR/EMA | Alta; proxy atual é preço vs EMA21 com bandas ATR informativas | Revisar implementação; shadow |
| 9 | Ichimoku Tenkan/Kijun | Candles spot | ATHENA: tendência | Média/alta | Shadow, medir incremento |
| 10 | Donchian 55 | Candles spot | ATHENA: tendência/estrutura | Média | Shadow, medir incremento |
| 11 | RSI 14 | Candles spot | ATHENA: RSI | Alta | Não duplicar peso; comparação shadow |
| 12 | RSI 7 | Candles spot | ATHENA: RSI | Alta, outra janela | Group normalization |
| 13 | MACD histograma | Candles spot | ATHENA: MACD | Alta | Não duplicar peso; comparação shadow |
| 14 | Stochastic %K/%D | Candles spot | ATHENA: RSI/mean reversion | Média | Shadow, medir incremento |
| 15 | Stoch RSI | Candles spot | ATHENA: RSI | Alta | Group normalization |
| 16 | ROC 12 | Candles spot | ATHENA: tendência/momentum | Alta | Group normalization |
| 17 | CCI 20 | Candles spot | ATHENA: RSI/momentum | Média/alta | Shadow, medir incremento |
| 18 | Williams %R | Candles spot | ATHENA: RSI/mean reversion | Alta | Group normalization |
| 19 | Momentum 10 | Candles spot | ATHENA: tendência/momentum | Alta | Group normalization |
| 20 | PPO 12/26 | Candles spot | ATHENA: MACD | Alta | Não duplicar peso; comparação shadow |
| 21 | Volume relativo | Volume de candles spot | ATHENA: Volume | Alta | Comparar definições e horizonte |
| 22 | OBV | Volume de candles spot | ATHENA: Volume | Média; fluxo acumulado adicional | Shadow, medir incremento |
| 23 | MFI 14 | Preço e volume spot | ATHENA: RSI/Volume | Média/alta | Shadow, medir incremento |
| 24 | Chaikin Money Flow | Preço e volume spot | ATHENA: Volume | Média | Shadow, medir incremento |
| 25 | VWAP 50 | Preço e volume spot | ATHENA: preço/Volume | Média; benchmark de preço ponderado | Shadow, medir incremento |
| 26 | Accumulation/Distribution | Preço e volume spot | ATHENA: Volume | Média/alta | Group normalization |
| 27 | Price Volume Trend | Preço e volume spot | ATHENA: Volume | Média/alta | Group normalization |
| 28 | Force Index | Preço e volume spot | ATHENA: Volume | Média | Shadow, medir incremento |
| 29 | Taker Buy Ratio | Taker buy volume de candles Binance | HEPHAESTUS / fluxo de mercado | Baixa duplicação direta; dado de fluxo spot novo | Shadow, validar disponibilidade |
| 30 | Confirmação Preço × Volume | Preço e volume spot | ATHENA: Volume | Alta | Group normalization |
| 31 | Bollinger Position | Fechamentos spot | ATHENA: Bollinger | Alta | Não duplicar peso; comparação shadow |
| 32 | Bollinger Bandwidth | Fechamentos spot | ATHENA: Bollinger/ATR | Alta para volatilidade | Group normalization |
| 33 | ATR 14 % | Candles spot | ATHENA: ATR | Alta | Não duplicar peso; comparação shadow |
| 34 | Keltner Position | Candles spot, EMA/ATR | ATHENA: ATR/EMA | Alta | Group normalization |
| 35 | Estrutura HH/HL | Máximas e mínimas spot | ATHENA: tendência | Média; price action estruturado | Shadow, medir incremento |
| 36 | Choppiness Index | Candles spot | ATHENA/RegimeDetector | Média; classificação de lateralidade | Candidato incremental por regime |
| 37 | Volatilidade Realizada | Retornos de fechamento spot | RegimeDetector | Média; horizonte/escala diferentes | Comparar por regime |
| 38 | Impulso do Candle | OHLC spot | ATHENA: tendência/Volume | Média | Shadow; excluir candles abertos |
| 39 | Posição no Range 20 | OHLC spot | ATHENA: Bollinger/estrutura | Média/alta | Group normalization |
| 40 | Alinhamento 3 × 12 candles | Retornos spot | ATHENA: multi-horizonte implícito | Média; alinhamento intrassérie | Shadow, medir incremento |
| 41 | Order Book Imbalance | Livro spot Binance, top 20 | Sem equivalente direto | Informação microestrutural; snapshot efêmero | Shadow só com timestamp/latência registrados |
| 42 | Bid/Ask Spread | Melhor bid/ask spot | Sem equivalente direto | Serve principalmente ao custo de execução, não direção | Usar como custo se houver observação temporal válida |
| 43 | Walls Top 5 | Livro spot Binance | Sem equivalente direto | Sobrepõe parcialmente imbalance; risco de spoofing | Manter separado no experimento de microestrutura |
| 44 | Depth ±0,25% | Livro spot Binance | Sem equivalente direto | Sobrepõe imbalance/walls; profundidade local | Grupo único de microestrutura |
| 45 | Funding Rate | Binance USD-M Futures premium index | HEPHAESTUS: funding_rate | Duplicação direta | Não somar; medir em substituição controlada |
| 46 | Open Interest + Preço | Open Interest histórico USD-M + spot | HEPHAESTUS: open_interest | Duplicação direta com interação preço/OI | Integrar como feature HEPHAESTUS apenas após validação |
| 47 | Open Interest Trend | Open Interest histórico USD-M | HEPHAESTUS: open_interest | Duplicação direta | Não somar; medir variante temporal |
| 48 | Basis Mark × Index | Mark e index USD-M | HEPHAESTUS: sem feature explícita de basis | Derivativo novo, correlacionado a funding | Candidato dentro de HEPHAESTUS |
| 49 | Global Long/Short Ratio | Binance USD-M global long/short | HEPHAESTUS: long_short_ratio | Duplicação direta | Não somar; comparar fonte/frequência |
| 50 | Top Traders Position Ratio | Binance USD-M top trader ratio | HEPHAESTUS: long_short_ratio | Duplicação parcial; população de traders diferente | Candidato dentro de HEPHAESTUS |

## Semântica e limites observados

- Os indicadores do projeto antigo são scores heurísticos discretos, não probabilidades calibradas. O painel primeiro agrega por grupo e depois pelos cinco grupos; incorporar cada score como voto independente duplicaria fatores correlacionados.
- A tabela guarda um score e nome por indicador, intervalo escolhido, preço BTC/ETH e timestamp de gravação. Ela não guarda a disponibilidade/ingestão dos candles nem os dados brutos usados no cálculo, o que limita reconstituição causal e atribuição de disponibilidade histórica.
- `indicadores.php` solicita candles Binance spot, ticker 24h, depth, premium index e séries USD-M. A última linha de klines é usada para os scores sem verificar `close_time`; a última vela pode estar aberta. O livro/ticker são snapshots, sem persistência do timestamp de observação por feature.
- O coletor suporta 15m/30m/1h/2h/4h e intervalos de gravação configuráveis. O predictor histórico aprende analogias com vetor de 50 scores e rótulos futuros. O policy tem taxa e slippage explícitos, `minEffective`, margem de incerteza e limites stop/take/horizonte; essas definições não são equivalentes à previsão binária probabilística do PROMETHEUS.
- Arquivos centrais auditados: `indicadores.php`, `coletar_indicadores.php`, `previsao_motor.php`, `trade_policy.php`, `trade_exec.php`, `trade_simulado.php`, `criar_tabela_indicadores.sql` e testes sob `tests/`.

## Decisão experimental

Não adicionar 50 módulos nem alterar os seis módulos de produção. Comparar classic e shadow sobre timestamps, alvos, horizontes e disponibilidade comuns; agrupar features por cinco domínios; relatar amostra, correlação, walk-forward e custos. Funding/OI/long-short permanecem sob HEPHAESTUS em qualquer candidato futuro.

**Status:** inventário estático concluído; valor incremental não testado. Não há conclusão de que ATHENA-50 melhora o PROMETHEUS.
