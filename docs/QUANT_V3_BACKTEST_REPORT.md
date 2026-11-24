# Avaliação Quant V3

**Decisão do ciclo · nenhum candidato promovido.** A análise preditiva e as simulações de fechamento servem como linha de base de pesquisa. Uma comparação de patrimônio prospectivo entre as estratégias V2 e V3 na mesma janela ainda está pendente.

## Janelas, modelos e métricas

O modelo de referência usa seis features de candles spot, regressão logística L2 e calibrador Platt. O treino e a calibração usam intervalos que não se sobrepõem entre si e removem labels que ainda não estavam maduros no corte. O holdout final permanece fora do ajuste. As contagens de observações sem sobreposição ajudam a interpretar a amostra. Dependência serial ainda pode existir entre esses eventos.

| Fonte | Horizonte | Labels de teste | Sem sobreposição | Acurácia | Brier bruto | Brier calibrado | Brier frequência de treino |
|---|---|---:|---:|---:|---:|---:|---:|
| Registrada | 15m | 90 | 89 | 54,44% | 0,254401 | 0,249079 | 0,249817 |
| Registrada | 1h | 124 | 41 | 45,97% | 0,261431 | 0,265745 | 0,257791 |
| Registrada | 4h | 124 | 14 | 55,65% | 0,381164 | 0,259743 | 0,293362 |
| Registrada | 24h | — | 3 | — | — | — | — |
| Reconstruída | 15m | 26.245 | 6.568 | 50,17% | 0,249446 | 0,250118 | 0,249999 |
| Reconstruída | 1h | 26.267 | 2.022 | 49,74% | 0,249725 | 0,250003 | 0,250030 |
| Reconstruída | 4h | 26.263 | 537 | 47,78% | 0,250034 | 0,252209 | 0,249878 |
| Reconstruída | 24h | 26.205 | 91 | 46,30% | 0,258851 | 0,254903 | 0,250638 |

O Brier de referência com probabilidade constante de 50% é 0,25. O recorte registrado teve holdouts de cerca de 2,5 dias, entre 17 e 20 de novembro de 2026 UTC. O histórico reconstruído usou 525.600 candles spot de novembro de 2025 a outubro de 2026, com holdout entre o fim de julho/início de agosto e o fim de outubro. O SHA-256 do manifesto reconstruído é `7c220ef6b737e4f5c7decc2fc8718e7224eddd8023b1ceb04a4ba8283e52e1cd`. O arquivo de 24h registrado teve apenas quatro eventos de treino, um de calibração e três de teste sem sobreposição, então nenhum modelo foi exportado para esse horizonte nessa fonte.

Nos dados registrados, a faixa indicativa entre q10 e q90 cobriu 72,22% dos retornos em 15m, 80,65% em 1h e 78,23% em 4h. O walk-forward registrado teve três folds descritivos para 15m e três inconclusivos para cada um dos demais horizontes. A cobertura é uma medida da dispersão individual e tem uso de pesquisa.

## Sensibilidade econômica por preço de fechamento

A regra exploratória compra spot com 25% do patrimônio quando P(alta) excede 0,5 e encerra no alvo do horizonte. A amostra usa intervalos sem sobreposição. Custos de referência são 24 bps ida e volta. Estas referências usam fechamentos e não medem execução prospectiva por bid/ask, política do torneio ou fila. Caixa rende zero por construção.

| Fonte | Horizonte | Saídas | P&L sem custo | Líquido 1× | Líquido 1,5× | Líquido 2× | Custos 1× | Turnover 1× | Drawdown 1× | Buy and hold 1× |
|---|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Registrada | 15m | 11 | 0,11 | −0,55 | −0,88 | −1,20 | 0,66 | 548,11 | 0,64% | −4,40 |
| Registrada | 1h | 41 | −0,33 | −2,76 | −3,95 | −5,12 | 2,43 | 2.028,17 | 2,78% | −4,78 |
| Registrada | 4h | 1 | −0,38 | −0,44 | −0,47 | −0,50 | 0,06 | 50,00 | 0,44% | −3,95 |
| Reconstruída | 15m | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0% | 23,17 |
| Reconstruída | 1h | 21 | −0,49 | −1,74 | −2,35 | −2,97 | 1,25 | 1.041,46 | 1,80% | 22,90 |
| Reconstruída | 4h | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0% | 22,71 |
| Reconstruída | 24h | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0% | 22,91 |

Valores monetários em USDT para 100 USDT de capital inicial. O calibrador alterou a direção escolhida em vários horizontes e piorou o Brier em 15m, 1h e 4h no histórico longo. O cenário de 1h reconstruído ficou abaixo de caixa com e sem custos.

## Reprodução offline

```text
php research/quant_v3/export_candles.php > storage/research/quant-candles.ndjson
python -B -m research.quant_v3.train storage/research/quant-candles.ndjson storage/research/quant-recorded-run
python -B -m research.quant_v3.archive storage/research/quant_archive spot 2025-11 2026-10
python -B -m research.quant_v3.archive storage/research/quant_archive perpetual 2025-11 2026-10
python -B -m research.quant_v3.train storage/research/quant_archive/spot/1m storage/research/quant-archive-run --archive
```

Use diretórios de saída novos. Os artefatos incluem `report.json`, `trials.ndjson` e modelos por horizonte. Foram verificados 72 arquivos mensais de spot e perpétuo nos intervalos 1m, 5m e 15m. Para repetir os demais intervalos, use `--interval 5m` ou `--interval 15m` com os comandos de download. O treino histórico analisou apenas spot de 1 minuto. A documentação oficial descreve a mudança de timestamps spot para microssegundos e a convenção dos arquivos. [Binance Public Data](https://github.com/binance/binance-public-data)

Acrescente `--walk-forward` ao treino para calcular três folds ancorados de desenvolvimento. Cada fold limita treino e calibração às respectivas janelas de disponibilidade. Esses resultados ficam separados do holdout final e não alteram os parâmetros do modelo final.
