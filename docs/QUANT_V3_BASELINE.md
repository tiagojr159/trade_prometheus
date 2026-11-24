# Linha de base Quant V3

Inventário de 24/11/2026. Código de referência `bce6e445c00fe932bee068fd24424f14620c11d6`. PHP CLI 7.4.33, MariaDB 10.4.27, PDO MySQL e BCMath disponíveis no XAMPP. As consultas do legado foram feitas em modo somente leitura. Os horários legados desta amostra usam America/Fortaleza.

- A configuração possui **15 estratégias oficiais**. A tabela local tem 17 contas, incluindo `LEGACY_DIRECTIONAL` e `LEGACY_STRATEGY`. As duas estratégias 10x pertencem à coorte legada.
- 2.276 previsões, de 09/11/2026 19:23:34 a 19/11/2026 22:43:50. O banco tinha 61.067 candles de todas as séries com qualidade `INGESTION_ONLY`. O recorte BTCUSDT spot de 1 minuto contém 15.119 candles de 09/11/2026 11:21 a 19/11/2026 23:19.
- HERMES aparece como `ERROR` nas 569 previsões de cada um dos quatro horizontes; cobertura disponível de 0% nesse recorte.
- ATHENA-50 tem 2.272 linhas no banco local e já possui as quatro colunas de ablação e candidatos. A reprodução equivalente da verificação de preço-alvo da suíte legada encontrou zero divergências.
- 550 saídas registradas entre 10/11/2026 02:22:59 e 19/11/2026 22:42:59. Resultado bruto implícito −1,7803, custos 31,5432 e resultado líquido −33,3235 nas unidades do legado.

## Previsões legadas no banco local

| Horizonte | Previsões | Labels binários v2 | Cobertura avaliada | Acurácia | Brier |
|---|---:|---:|---:|---:|---:|
| 15m | 569 | 568 | 99,82% | 52,289% | 0,249540 |
| 1h | 569 | 566 | 99,47% | 55,654% | 0,247247 |
| 4h | 569 | 560 | 98,42% | 62,143% | 0,242546 |
| 24h | 569 | 514 | 90,33% | 80,156% | 0,213435 |

Acurácia e quantidade por regime (contagens sujeitas a sobreposição temporal).

| Horizonte | BEARISH | BULLISH | HIGH_VOLATILITY | SIDEWAYS |
|---|---:|---:|---:|---:|
| 15m | 37,50% · 88 | 53,95% · 76 | 58,55% · 152 | 53,17% · 252 |
| 1h | 56,32% · 87 | 54,67% · 75 | 55,92% · 152 | 55,56% · 252 |
| 4h | 50,57% · 87 | 73,97% · 73 | 54,36% · 149 | 67,33% · 251 |
| 24h | 63,41% · 82 | 86,76% · 68 | 78,83% · 137 | 85,02% · 227 |

| Estratégia com saídas | Saídas | Custos | P&L líquido |
|---|---:|---:|---:|
| ADAPTIVE | 17 | 1,0150 | −0,9241 |
| FUNDING_BIAS | 50 | 2,9474 | −3,4404 |
| INVERSE | 197 | 10,9514 | −14,6840 |
| MEAN_REVERSION | 20 | 1,1898 | −1,7023 |
| MOMENTUM | 44 | 2,6021 | −2,6559 |
| PROMETHEUS | 185 | 10,6354 | −8,1918 |
| SPOT_GRID | 2 | 0,1199 | −0,0958 |
| VWAP_TREND | 35 | 2,0823 | −1,6292 |

As demais sete famílias oficiais não tinham saídas no recorte.

## Origem dos arquivos

| Artefato | SHA-256 |
|---|---|
| Exportação histórica `storage/research/analysis.json` | `2b0c74dd0a89bde8c506ba5f069ece6e49836abed97b7c1a8676f8343f6f84bc` |
| Exportação histórica `storage/research/predictions.json` | `45cd32051fec0e50a45a48ecd49dde032e0ae0580dbe306f98f854b0a245032f` |
| Exportação histórica `storage/research/trades.json` | `4bc1d8976bc2a09d8bc540dfb6b2336751934357b6ac22164f9fd88ca2ecc31f` |
| Exportação registrada usada no treino V3 | `f6d9726398953fc3a7da57c63b490708c2edb5bf6eb0b2b8d6a1b59babce969b` |

A auditoria de 02/11 cobre 23/09 a 02/11 e reporta 11.787 previsões, 11.659 elegíveis e 215 saídas. Trata-se de uma janela anterior com filtros próprios, conforme [auditoria histórica](AUDITORIA_PROMETHEUS_2026-11-02.md). A linha de base atual usa os resultados v2 gravados na instância local. O replay preditivo V3 usa um subconjunto que exige disponibilidade efetiva, janela contínua, maturidade e correspondência do candle-alvo. Uma mudança em relação à tabela acima pode refletir a diferença de amostra.

Para reproduzir a tabela preditiva legada em uma sessão MariaDB de leitura:

```sql
START TRANSACTION READ ONLY;
SELECT p.horizon, COUNT(*) forecasts, COUNT(r.id) binary_n,
       AVG(r.directional_hit) accuracy,
       AVG(POW(p.probability_up-(r.actual_direction='UP'),2)) brier
FROM predictions p
LEFT JOIN prediction_results r
  ON r.prediction_id=p.id AND r.evaluation_version=2 AND r.actual_direction IN ('UP','DOWN')
GROUP BY p.horizon;
ROLLBACK;
```
