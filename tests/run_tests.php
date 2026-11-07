<?php
declare(strict_types=1);

/**
 * Corre TODAS las pruebas de lógica (tests/logica_*.php) en una base de prueba.
 * Uso:
 *   TURENTA_DB_NAME=turenta_prueba TURENTA_DB_USER=turenta TURENTA_DB_PASS=prueba php tests/run_tests.php
 * Para correr solo una: php tests/run_tests.php logica_10_pagos
 */
require __DIR__ . '/base.php';

$filtro = $argv[1] ?? '';
$archivos = glob(__DIR__ . '/logica_*.php') ?: [];
sort($archivos);
foreach ($archivos as $archivo) {
    if ($filtro !== '' && !str_contains(basename($archivo), $filtro)) continue;
    echo "\n==================== " . basename($archivo, '.php') . " ====================\n";
    try {
        (function () use ($archivo) { require $archivo; })();
    } catch (\Throwable $e) {
        $GLOBALS['fallos'][] = basename($archivo) . ' se detuvo';
        echo "  FALLA el archivo se detuvo: " . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

echo "\n----------------------------------------\n";
echo "Correctas: {$GLOBALS['ok']}  ·  Fallas: " . count($GLOBALS['fallos']) . "\n";
foreach ($GLOBALS['fallos'] as $f) echo "  - $f\n";
exit($GLOBALS['fallos'] ? 1 : 0);
