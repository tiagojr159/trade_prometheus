# Estratégias inspiradas nos bots da Binance

## Seleção

O torneio já tinha sete contas paper. Foram adicionadas cinco para completar doze, escolhidas por cobrirem comportamentos diferentes e por usarem fontes que o PROMETHEUS já coleta: candles spot, volume, funding, open interest e razão long/short.

| Nova estratégia | Ideia do bot | Regra implementada no paper trading |
| --- | --- | --- |
| `SPOT_GRID` | Spot Grid | Em regime lateral, compra perto da banda inferior e encerra ao retornar ao centro; a conta não abre short sintético. |
| `SPOT_DCA` | Spot DCA | Compra-base após confirmação da EMA 26; permite até dois reforços após desvios baseados em ATR, atualizando preço médio e custos. |
| `FUNDING_BIAS` | Funding Rate Arbitrage | Combina funding, razão long/short e z-score de open interest num viés contrarian de preço. É um sinal direcional de comparação, sem pernas hedgeadas. |
| `REBALANCE` | Rebalancing Bot | Mantém a exposição simulada em BTC perto de `allocation_pct`, com banda de cinco pontos percentuais; registra compras e reduções parciais. O saldo restante representa USDT. |
| `VWAP_TREND` | Volume / execução algorítmica | Exige desvio de 0,1% da VWAP de 60 candles, volume recente igual ou maior que a média, tendência de 15m e previsão na mesma direção. |

A seleção cobre faixa lateral, acumulação, derivativos, alocação de portfólio e fluxo de volume. Grid foi escolhido para mercado lateral; DCA para compras em etapas; rebalanceamento para exposição BTC/USDT; funding para os dados de derivativos já coletados; VWAP para volume e confirmação intradiária. A escolha é por diversidade funcional, não por um ranking de rentabilidade ainda inexistente.

As cinco regras são contas de decisão independentes sobre as previsões atuais. Elas não mudam `probability_up` nem os pesos do ensemble PROMETHEUS; seus resultados prospectivos permitem comparar as regras antes de promover sinais ao modelo central.

## Limites da adaptação

Os bots da Binance executam ordens em contas e mercados da corretora. Este projeto mantém somente um simulador paper local e não envia ordens à Binance.

- A arbitragem de funding verdadeira precisa manter duas posições opostas, spot e futuros, em tamanhos equivalentes e considerar basis, funding recebido/pago e custos das duas pernas. O ledger atual não mantém esse par nem coleta uma série de preços futuros; `FUNDING_BIAS` por isso só usa os snapshots já guardados pelo HEPHAESTUS para formar um sinal direcional. O PnL desta conta não contabiliza funding.
- Rebalanceamento limita-se a BTC e ao saldo em dinheiro; não é uma carteira com várias moedas. A faixa-alvo vem de `allocation_pct` e usa uma banda fixa de cinco pontos percentuais.
- DCA simula uma compra-base e até dois reforços por preço médio. O reforço usa o tamanho normal de alocação da conta e fica limitado ao dinheiro disponível.
- VWAP é um filtro de decisão derivado de candles spot. Não divide ordens em parcelas nem estima impacto de livro de ofertas como um TWAP/POV de execução.
- Spot Grid usa bandas de Bollinger e regime lateral como aproximação da grade de níveis parametrizada da Binance; não mantém várias ordens limite simultâneas.

As estratégias usam candles fechados disponíveis no instante da previsão. Funding e métricas de derivativos são lidos do snapshot HEPHAESTUS daquela previsão, em vez de buscar o valor mais recente no momento atual. Taxas e slippage continuam contabilizados pelo simulador. A classificação de desempenho deve vir do histórico prospectivo do torneio, depois de amostra suficiente.

## Referências oficiais Binance

- [Spot Grid: parâmetros e funcionamento](https://www.binance.com/en-GB/support/faq/binance-spot-grid-trading-parameters-688ff6ff08734848915de76a07b953dd)
- [Spot DCA: funcionamento](https://www.binance.com/en-IN/support/faq/detail/27713d3ddb3c406da52f36b9aaaa1360)
- [Funding Rate Arbitrage Bot](https://www.binance.com/en-NZ/support/faq/detail/f330e17d6fc04679b9b21d6f9350e787)
- [Rebalancing Bot](https://www.binance.com/en-AU/support/faq/detail/29bbbd2e7fc24085be7a8a7d02779457)
- [TWAP e POV / participação de volume](https://www.binance.com/en/support/faq/detail/91d7e7d0633846adb0ef9020037cc391)
- [Visão geral dos bots Binance](https://www.binance.com/en/academy/articles/your-guide-to-binance-trading-bots)
