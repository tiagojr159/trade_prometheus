<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
date_default_timezone_set('America/Fortaleza');
if (!class_exists(ZipArchive::class)) throw new RuntimeException('PHP ZipArchive é necessário apenas para criar o pacote local.');
$root=dirname(__DIR__);
$out=$root.'/storage/backups/PROMETHEUS_hostgator_'.date('Ymd_His').'.zip';
if (!is_dir(dirname($out)) && !mkdir(dirname($out),0775,true)) throw new RuntimeException('Diretório de saída indisponível.');
$zip=new ZipArchive();if ($zip->open($out,ZipArchive::CREATE|ZipArchive::EXCL)!==true) throw new RuntimeException('Falha ao criar ZIP.');
$include=['.htaccess','bootstrap.php','cron.php','index.php','performance.php','simulation.php',
    'config/config.php','storage/.htaccess','deploy/.htaccess','deploy/migrate.php','deploy/upgrade_tournament.php','deploy/database.php.example','docs/HOSTGATOR_DEPLOY.md','README.md'];
foreach (['api','assets','collectors','core','cron','evaluation','indicators','intelligence','modules','prediction','sql'] as $dir) {
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir,FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isLink() || !$file->isFile()) continue;
        $include[]=str_replace('\\','/',substr($file->getPathname(),strlen($root)+1));
    }
}
foreach (['AdminGuard.php','dashboard.php','login.php','setup.php','create_admin.php','modules.php','performance.php','predictions.php','settings.php','simulation.php'] as $file) $include[]='admin/'.$file;
foreach (array_unique($include) as $relative) {
    $path=$root.'/'.$relative;
    if (!is_file($path) || !$zip->addFile($path,$relative)) throw new RuntimeException('Arquivo do pacote ausente: '.$relative);
}
foreach (['storage/','storage/backups/','storage/cache/','storage/locks/','storage/logs/'] as $dir) $zip->addEmptyDir($dir);
$count=$zip->numFiles;$zip->close();
// Verify the archive itself before presenting it for upload.
$check=new ZipArchive();
if ($check->open($out)!==true) throw new RuntimeException('Pacote não pôde ser reaberto.');
$names=[];
for ($i=0;$i<$check->numFiles;$i++) {
    $name=$check->getNameIndex($i);$names[$name]=true;
    if (preg_match('~(^research/|\.py$|^config/(database|api_keys)\.php$|^storage/(backups|research)/[^/]+$)~i',$name)) throw new RuntimeException('Arquivo não permitido no pacote: '.$name);
}
foreach (['cron.php','core/PaperTrader.php','deploy/migrate.php','deploy/upgrade_tournament.php','sql/migrations/013_paper_strategy_tournament.sql','sql/migrations/014_paper_tournament_allow_sample_orders.sql','sql/migrations/015_paper_trading_twelve_strategies.sql','sql/migrations/016_paper_heartbeat_waiting_data.sql','storage/.htaccess','docs/HOSTGATOR_DEPLOY.md'] as $required) if (!isset($names[$required])) throw new RuntimeException('Arquivo obrigatório ausente: '.$required);
$check->close();
echo json_encode(['package'=>$out,'files'=>$count,'contains_python'=>false,'contains_local_database_credentials'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
