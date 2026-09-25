<?php
declare(strict_types=1);
// ETAPA 19 — data freshness no contrato do sinal.
chdir(dirname(__DIR__));
require 'bootstrap.php';

use Prometheus\core\Signal;
use Prometheus\intelligence\SignalNormalizer;

$pass = 0; $fail = 0; $failures = [];
function check19(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail, $failures;
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $name . ($detail ? " ({$detail})" : '') . PHP_EOL;
    if ($ok) { $pass++; } else { $fail++; $failures[] = $name; }
}

$n = new SignalNormalizer();

// 1. Dado fresco: fator ~1
$fresh = $n->normalize(new Signal('ATHENA', 0.5, 0.8, ['horizon' => '1h', 'data_age_seconds' => 30]));
check19('dado fresco mantém confiança', $fresh->confidence > 0.75, 'conf=' . $fresh->confidence);
check19('fator registrado', isset($fresh->metadata['freshness_factor']));

// 2. Dado com 15 min: meia-vida → conf cai ~metade
$stale15 = $n->normalize(new Signal('ATHENA', 0.5, 0.8, ['horizon' => '1h', 'data_age_seconds' => 900]));
check19('15 min → confiança ≈ metade (meia-vida 15 min)', abs($stale15->confidence - 0.4) < 0.03, 'conf=' . round($stale15->confidence, 4));

// 3. Dado com 1 hora: 4 meias-vidas → conf muito baixa mas > 0
$stale60 = $n->normalize(new Signal('ATHENA', 0.5, 0.8, ['horizon' => '1h', 'data_age_seconds' => 3600]));
check19('1 hora → confiança fortemente reduzida', $stale60->confidence < 0.1 && $stale60->confidence > 0.0, 'conf=' . round($stale60->confidence, 4));

// 4. Sem info de idade: confiança intacta (comportamento anterior preservado)
$noAge = $n->normalize(new Signal('ATHENA', 0.5, 0.8, ['horizon' => '1h']));
check19('sem idade declarada → confiança intacta', abs($noAge->confidence - 0.8) < 1e-9);

// 5. Inferência por last_candle_time (3 min atrás)
$c = $n->normalize(new Signal('ATHENA', 0.5, 0.8, ['horizon' => '1h', 'last_candle_time' => date('Y-m-d H:i:s', time() - 180)]));
check19('inferência por last_candle_time aplica decaimento', $c->confidence < 0.8 && $c->confidence > 0.6, 'conf=' . round($c->confidence, 4));

// 6. Sinal nunca é alterado pelo freshness (só a confiança)
check19('signal value preservado', abs($stale15->value - 0.5) < 1e-9);

echo "\nRESULTADO: {$pass} pass, {$fail} fail\n";
if ($failures) { echo 'Falhas: ' . implode('; ', $failures) . PHP_EOL; exit(1); }
exit(0);
