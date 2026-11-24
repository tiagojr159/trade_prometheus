# Operação da Quant V3

A V3 funciona com PHP 7.4+, PDO MySQL e BCMath no cron do torneio. Python 3.11+ roda somente na pesquisa offline. Confira a extensão com `php -m` no executável usado pelo cron. O pacote segue [a instalação HostGator](HOSTGATOR_DEPLOY.md).

1. Em uma instância descartável, confirme o backup, a restauração e as permissões de leitura do banco. As migrations novas criam somente tabelas InnoDB `quant_*`.
2. Depois de autorizar a instalação no ambiente de destino, execute `php deploy/quant_v3.php schema`. As migrations 019 e 020 são aditivas e protegidas pelos locks do cron e do banco.
3. Quando houver autorização para a nova coorte, execute `php deploy/quant_v3.php start-season ID-DA-TEMPORADA` com ID em minúsculas. O registro de modelo é opcional e usa `php deploy/quant_v3.php register-shadow MODELO.json` com o artefato privado. Até dois modelos shadow por horizonte são avaliados na mesma rodada. Use `php deploy/quant_v3.php retire-shadow ID` para encerrar a produção de previsões de um modelo específico.
4. Defina `PROMETHEUS_QUANT_V3=1` no ambiente do cron existente. `php cron.php tick` chama a rotina V3 logo após a coleta de mercado. Previsões shadow e avaliação de labels são tarefas próprias a cada cinco minutos. Os diagnósticos CLI individuais são `quant_tick`, `quant_shadow` e `quant_evaluate`.
5. Verifique a página autenticada `admin/quant_tournament.php` e o status retornado pelo cron. `WAITING_DATA` indica falta de cotação utilizável ou ausência de novos eventos. `PAUSED_RISK` indica suspensão por risco.

Para desativar, remova a flag ou use `PROMETHEUS_QUANT_V3=0`. O cron V3 fica desabilitado e o histórico permanece disponível. Como isso também interrompe a avaliação de risco da V3, reconcilie posições virtuais antes de desativar uma coorte com exposição. Guarde modelos e pesquisa em local privado. O pacote exclui `research/`, snapshots locais, credenciais e backups. As rotas de instalação e cron exigem CLI.

## Comandos de verificação

```text
php research/quant_v3/integration_test.php
php admin/paper_trading_v2_tests.php
php admin/temporal_hardening_tests.php
python -B -m unittest research.quant_v3.test_quant -v
php deploy/package.php
```

O teste de integração usa MariaDB em loopback, cria um schema aleatório `quant_v3_test_*` e remove esse schema ao terminar. Pode receber `QUANT_TEST_PORT`, `QUANT_TEST_USER` e `QUANT_TEST_PASSWORD` no ambiente. Se `php` estiver fora do PATH, configure `PROMETHEUS_TEST_PHP` para a paridade Python/PHP. Em Windows, grave a exportação NDJSON em UTF-8 ao redirecionar o PHP. Windows PowerShell 5.1 usa UTF-16 em redirecionamentos comuns.
