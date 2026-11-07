<?php
declare(strict_types=1);

/**
 * Cuenta de prueba para que Google revise la app de Android "TuRenta" sin ver datos reales.
 * Arma un NEGOCIO DE EJEMPLO aparte («Quinta Ejemplo Google Play», en demo por 1 año, sin pagos de Tukán) con espacios,
 * catálogo, paquetes y 6 eventos de ejemplo, y su dueño revisor.google@tukanec.com. No toca ningún otro negocio.
 *
 *   Crear (o volver a crear con fechas de hoy y contraseña nueva):  php /var/www/turenta/deploy/demo_google.php crear
 *   Borrar (cuando Google ya aprobó la app):                        php /var/www/turenta/deploy/demo_google.php borrar
 * La contraseña sale UNA sola vez: cópiala en Play Console → Contenido de la app → Acceso a la app.
 * Los mensajes van sin tildes a propósito (se leen bien por SSH desde el celular).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/app/bootstrap.php';

use TuRenta\DemoGoogle;

$accion = $argv[1] ?? '';
try {
    if ($accion === 'crear') {
        $r = (new DemoGoogle(db()))->crear();
        echo "\nListo: negocio de ejemplo \"" . DemoGoogle::NEGOCIO . "\" (N. " . $r['negocio_id'] . ") con " . $r['eventos'] . " eventos de ejemplo.\n";
        echo "Es una demo aparte (1 ano, sin pagos): no toca ningun otro negocio.\n";
        echo "\nCopia esto en Google Play Console -> Contenido de la app -> Acceso a la app:\n";
        echo "  Usuario:    " . DemoGoogle::CORREO . "\n";
        echo "  Contrasena: " . $r['clave'] . "\n";
        echo "\n(La contrasena no se guarda en ningun lado: si la pierdes, corre otra vez 'crear'.)\n";
        echo "Pruebala entrando en " . (getenv('TURENTA_URL') ?: 'https://turenta.tukanec.com') . "\n\n";
        exit(0);
    }
    if ($accion === 'borrar') {
        $n = (new DemoGoogle(db()))->borrar();
        echo $n ? "Listo: se borro el negocio de ejemplo y el usuario " . DemoGoogle::CORREO . ".\n" : "No habia negocio de ejemplo. Nada que borrar.\n";
        exit(0);
    }
} catch (Throwable $e) {
    fwrite(STDERR, "\nNo se pudo: " . $e->getMessage() . "\n");
    exit(1);
}
echo "Uso: php /var/www/turenta/deploy/demo_google.php crear | borrar\n";
exit(1);
