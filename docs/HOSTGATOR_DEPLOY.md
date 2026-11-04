# Publicação na HostGator (somente PHP)

O aplicativo em produção usa PHP, MySQL/MariaDB e cron. Não chama Python. Os arquivos `research/*.py` servem apenas à auditoria local e são excluídos do pacote de publicação.

## Preparar o pacote

No computador local, gere o pacote de publicação:

```powershell
C:\xampp\php\php.exe deploy\package.php
```

O ZIP é criado em `storage/backups/`. Ele exclui credenciais locais, snapshots, backups, testes e scripts Python. Confira o nome do ZIP retornado pelo comando e extraia seu conteúdo no diretório da aplicação na hospedagem. Crie `config/database.php` no servidor a partir de `deploy/database.php.example`, preenchendo o nome, usuário e senha do MySQL do cPanel. O banco da hospedagem terá nomes próprios do cPanel; `localhost` só funciona se o MySQL estiver no mesmo servidor.

Envie o ZIP gerado e extraia os arquivos sobre a aplicação existente. Para aplicar o torneio em uma base que ainda não recebeu as migrations 013–016, rode o instalador abaixo uma vez pelo Terminal do cPanel. Ele aplica 013, 014, 015 e 016 nessa ordem, grava um backup de esquema e dados em `storage/backups/` e verifica as 12 contas. A 013 renomeia os modos antigos para `LEGACY_*`, preservando as linhas e os lançamentos; a 015 cria cinco contas novas de US$ 100.

```sh
/opt/cpanel/ea-php82/root/usr/bin/php /home/USUARIO/public_html/trade_prometheus/deploy/upgrade_tournament.php
```

Substitua `USUARIO`, o diretório real e o executável PHP pela versão disponível na conta. O banco deve já ter a base do paper trading criada pela migration 011 e o shadow pela 012. O instalador aceita somente PHP CLI. Guarde o caminho do backup e confira a mensagem `Upgrade complete` antes de encerrar.

## Agendar pelo cPanel

Em hospedagem **compartilhada**, configure **um** Trabalho Cron a cada 15 minutos (`*/15 * * * *`) com:

```sh
/opt/cpanel/ea-php82/root/usr/bin/php /home/USUARIO/public_html/trade_prometheus/cron.php all
```

Não configure jobs adicionais com intervalo inferior a 15 minutos no plano compartilhado. Nessa modalidade, os stops e alvos do paper trading só são verificados quando o cron roda, de modo que uma saída pode atrasar ao menos 15 minutos, além do tempo de coleta. O horizonte de previsão de 15 minutos não oferece execução rápida nesse plano. Para controle por minuto, use VPS ou servidor dedicado e agende `cron.php tick` a cada minuto (`* * * * *`). O comando `tick` distribui tarefas por cadência e executa paper trading em cada ciclo.

Se o ciclo completo ultrapassar 15 minutos ou for interrompido por limite de recursos da hospedagem, use VPS/dedicado. Não acione `cron.php` por URL pública. Verifique `paper_trading_heartbeat.last_finished_at`, `system_logs` e o status "Simulador" no painel após a publicação. Os locks impedem dois ciclos simultâneos; um ciclo lento pode fazer o próximo ser ignorado.

## Requisitos

PHP 8.1+ recomendado, extensões `pdo_mysql`, `curl`, `openssl`, `mbstring` e `json`; MySQL/MariaDB com permissão para `ALTER TABLE` na migração. Diretórios `storage/cache`, `storage/locks`, `storage/logs` e `storage/backups` precisam ser graváveis pelo PHP CLI. O arquivo `storage/.htaccess` bloqueia acesso HTTP aos dados internos. Configure fuso `America/Fortaleza` de modo coerente no PHP web, no PHP CLI e no banco; a aplicação grava timestamps de mercado em horário local e a linhagem de ingestão em UTC.

A criação do cron e a instalação na HostGator dependem do acesso à conta da hospedagem; essas etapas não são feitas pelo pacote local.

Referências da própria HostGator: [caminhos PHP para cron](https://suporte.hostgator.com.br/hc/pt-br/articles/30812660878867-Quais-s%C3%A3o-os-caminhos-para-a-execu%C3%A7%C3%A3o-de-comandos-em-um-Cron-Job), [limite de 15 minutos nos planos compartilhados](https://suporte.hostgator.com.br/hc/pt-br/articles/30814729836435-Como-funciona-um-trabalho-cron).
