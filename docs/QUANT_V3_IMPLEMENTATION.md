# Implementação Quant V3

| Área | Entrega |
|---|---|
| Torneio | Temporadas explícitas, 15 contas isoladas, agenda UTC por família, execução e risco com cotação spot, idempotência e reconciliação decimal. |
| Dados | Candles registrados com corte pela ingestão real e contiguidade. Arquivos Binance spot e perpétuo em caches distintos, checksums e disponibilidade simulada identificada no histórico reconstruído. |
| Inferência | Regressão logística L2, imputação e escala de treino, Platt em janela separada e teste cronológico purgado. Artefato JSON por horizonte e paridade com PHP. |
| Magnitude | Referência empírica por classe aprendida no treino. A mistura usa a probabilidade direcional e retorna média bruta e quantis indicativos. |
| Shadow | Cadastro pela CLI com hash do artefato, snapshot imutável, deduplicação por modelo e janela, labels com referência ao candle e maturidade determinística. |
| Operação | Flag padrão desligada, cron existente, tela administrativa e documentação HostGator. |

O caminho de execução V3 é prospectivo. A pesquisa reconstruída usa timestamps de publicação simulados e pode validar hipóteses antes do shadow. As duas fontes geram resultados com rótulos próprios.

As entradas da aplicação são `cron.php`, `deploy/quant_v3.php` e `admin/quant_tournament.php`. A integração preserva `PaperStrategyEngine` para as regras técnicas e `HttpClient` para a coleta pública com timeout curto. O schema é criado pelas migrations 019 e 020. A pesquisa e os testes V3 estão em `research/quant_v3/`.

## Verificação local

- A integração com MariaDB 10.4.27 usou um schema descartável e executou as migrations duas vezes. Testou 100 ticks, isolamento de contas e temporadas, duas execuções concorrentes, rollback antes e depois da gravação do fill, retomada após interrupção, gaps, bloqueio por P&L negativo, shadow e painel.
- Os cinco testes Python cobriram purga, alterações futuras, métricas sem direção, os meses dos arquivos e paridade de features, probabilidade e magnitude com PHP 7.4.33. Também passaram os testes legados de paper trading e temporalidade.
- Um dump da fixture MariaDB foi restaurado em outro schema descartável e conferido. No Apache local, a página V3 exigiu login, enquanto a CLI web, os diagnósticos, a pesquisa e os caminhos privados retornaram HTTP 403. O endpoint público de cotação respondeu ao cliente PHP.
- O pacote HostGator passou pela verificação de conteúdo. A instância usada para ler os dados legados não recebeu as migrations nem iniciou temporada.

## Trabalho pendente

- Implementar execução perpétua com mark price, margem, funding, gaps e liquidação, junto com DCA, rebalanceamento e fills parciais na agenda V3. A versão atual executa o ciclo spot simples de entrada e saída.
- Construir políticas V3 específicas para as famílias de previsão e ligar magnitude, meta-label e stacker aos critérios de aprovação de estratégia. O modelo linear registrado produz previsões shadow. A magnitude atual é uma referência estatística sem uso para aprovar trades.
- Estender o registro de pesquisa para variantes das quinze famílias, ablações por módulo, LightGBM, champion–challenger, histórico as-of de pesos e detecção de drift. O ciclo atual registra os quatro trials lineares antes dos resultados.
- Completar comparação com a coorte legada em mesma janela, equity diária com exposição e múltiplos folds, leitura de filtros reais do mercado, verificação automática de promoção e integração do restante dos feeds. Uma avaliação de fechamento por candle está disponível como referência de pesquisa.
- Ativar coleta prospectiva em uma temporada de pesquisa após autorização. Dados locais de candles e cotações prospectivas têm proveniências diferentes e requerem avaliação separada.

Veja [linha de base](QUANT_V3_BASELINE.md), [torneio](QUANT_V3_TOURNAMENT.md), [backtest](QUANT_V3_BACKTEST_REPORT.md) e [operação](QUANT_V3_DEPLOY.md).
