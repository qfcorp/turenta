<?php
declare(strict_types=1);

/**
 * Tareas automáticas de TuRenta. Se corre CADA HORA con cron:
 *   5 * * * * php /var/www/turenta/bin/tareas.php >> /var/log/turenta-tareas.log 2>&1   (crontab de www-data)
 *
 * Hace: vence cotizaciones, libera fechas retenidas, avisa cuotas por cobrar (8:00), revisar precio,
 * eventos en 7 días, pasa a "realizado" los eventos que ya pasaron (y avisa devolver la garantía),
 * el reporte del mes (día 1 desde las 7:00), los seguimientos del día por Telegram (8:00) y la papelera del Panel Tukán Central
 * (borra de verdad los negocios con más de 30 días en la papelera y las copias de más de 1 año).
 * Cada cosa se envía una sola vez aunque el cron corra muchas veces.
 *
 * Para probar con otra fecha:  php bin/tareas.php "2026-11-01 08:00"
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

define('RAIZ', dirname(__DIR__));

$envFile = RAIZ . '/config/.env';
if (is_file($envFile)) {
    foreach (parse_ini_file($envFile, false, INI_SCANNER_RAW) ?: [] as $k => $v) {
        if (getenv($k) === false) putenv("$k=$v");
    }
}
date_default_timezone_set(getenv('TURENTA_ZONA') ?: 'America/Guayaquil');
spl_autoload_register(function (string $c): void {
    if (str_starts_with($c, 'TuRenta\\') && is_file($f = RAIZ . '/src/' . substr($c, 8) . '.php')) require $f;
});
if (is_file(RAIZ . '/vendor/autoload.php')) require RAIZ . '/vendor/autoload.php';

// Evita que dos tareas corran a la vez
// (el nombre lleva el usuario: antes corría con el usuario normal y ahora con www-data, que no puede abrir el candado viejo)
$candado = fopen(sys_get_temp_dir() . '/turenta-tareas-' . md5(RAIZ . (getenv('TURENTA_DB_NAME') ?: '')) . (function_exists('posix_geteuid') ? '-' . posix_geteuid() : '') . '.lock', 'c');
if (!$candado || !flock($candado, LOCK_EX | LOCK_NB)) { echo "Ya hay una tarea corriendo.\n"; exit(0); }

try {
    $ahora = new DateTimeImmutable($argv[1] ?? 'now');
} catch (Exception $e) {
    fwrite(STDERR, "Fecha no valida. Ejemplo: php bin/tareas.php \"2026-11-01 08:00\"\n");
    exit(1);
}
try {
    $db = TuRenta\Db::conectar();
    $r = (new TuRenta\Automaticas($db))->correr($ahora);
} catch (Throwable $e) {
    fwrite(STDERR, $ahora->format('Y-m-d H:i') . ' ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}
echo TuRenta\Automaticas::resumen($ahora, $r) . "\n";
exit($r['errores'] ? 1 : 0);
