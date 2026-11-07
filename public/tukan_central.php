<?php
declare(strict_types=1);

/**
 * Conector con el Panel Tukán Central. No pasa por el enrutador de index.php.
 * Protocolo v1 (GET, token en ?t=):
 *   /tukan_central.php?accion=sso&t=<token>     entra como super usuario y abre el panel de TuRenta
 *                                               (con "ir" en el token: abre esa pantalla, ej. /?r=admin_negocio&id=3)
 *   /tukan_central.php?accion=estado&t=<token>  cifras de TuRenta en JSON (solo lectura)
 * Protocolo v2 (POST con cuerpo JSON y el token en la cabecera X-Tukan-Token): capacidades, clientes, cliente, cotizar,
 *   planes, solicitudes, solicitud, finanzas y ejecutar (src/TukanCentralV2.php).
 * Sin TUKAN_CENTRAL_ID o TUKAN_CENTRAL_SECRET en config/.env responde 404 y no hace nada más.
 * Todos los errores traen "codigo" (protocolo v2 §3.2); en el SSO va en la cabecera X-Tukan-Codigo.
 */

use TuRenta\{TukanCentral, TukanCentralRechazo, TukanCentralV2};

$accion = (string) ($_GET['accion'] ?? '');
if ($accion !== 'sso') define('TUKAN_SIN_SESION', true);   // solo el SSO abre sesión
require dirname(__DIR__) . '/app/bootstrap.php';

header('Cache-Control: no-store');
$json = function (int $http, array $datos, array $cabeceras = []): never {
    http_response_code($http);
    foreach ($cabeceras as $k => $v) header("$k: $v");
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    exit;
};

if (!TukanCentral::config()) {
    http_response_code(404);
    header('X-Tukan-Codigo: sin_configurar');
    if ($accion === 'sso' || $accion === '') {
        header('Content-Type: text/plain; charset=utf-8');
        echo "No encontrado\n";
        exit;
    }
    $json(404, ['ok' => false, 'protocolo' => 2, 'codigo' => 'sin_configurar', 'error' => 'El conector con el Panel Tukán Central no está configurado.']);
}

// ---------------------------------------------------------------------
// Protocolo v2: todo lo que no es sso ni estado
// ---------------------------------------------------------------------
if (!in_array($accion, ['sso', 'estado'], true)) {
    $cuerpo = (string) file_get_contents('php://input', false, null, 0, TukanCentralV2::MAX_CUERPO + 1);
    [$http, $datos, $cabeceras] = (new TukanCentralV2(db()))->procesar(
        $accion, (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), (string) ($_SERVER['CONTENT_TYPE'] ?? ($_SERVER['HTTP_CONTENT_TYPE'] ?? '')),
        $cuerpo, (string) ($_SERVER['HTTP_X_TUKAN_TOKEN'] ?? ''), ip_visitante());
    $json($http, $datos, $cabeceras);
}

// ---------------------------------------------------------------------
// Protocolo v1: sso y estado
// ---------------------------------------------------------------------
/** Corta con un error: JSON para el estado, página sencilla para el SSO (con la cabecera X-Tukan-Codigo). */
$fallar = function (int $http, string $mensaje, string $codigo, array $cabeceras = []) use ($accion, $json): never {
    if ($accion === 'sso') {
        http_response_code($http);
        foreach ($cabeceras as $k => $v) header("$k: $v");
        header('X-Tukan-Codigo: ' . $codigo);
        vista_admin('central_error', ['mensaje' => $mensaje, 'urlCentral' => TukanCentral::urlCentral()], 'No se pudo entrar · Panel Tukán');
        exit;
    }
    $json($http, ['ok' => false, 'protocolo' => 2, 'codigo' => $codigo, 'error' => $mensaje], $cabeceras);
};

try {
    $central = new TukanCentral(db());
    if (!$central->dentroDelLimite(ip_visitante())) {
        $fallar(429, 'Demasiados pedidos. Espera un minuto y vuelve a intentar.', 'limite', ['Retry-After' => '60']);
    }
    try {
        $p = $central->validar((string) ($_GET['t'] ?? ''), $accion);
    } catch (TukanCentralRechazo $e) {
        if ($e->http === 401) $central->anotarRechazo(ip_visitante());
        throw $e;
    }

    if ($accion === 'estado') {
        $json(200, $central->estado());
    }

    // SSO: igual que el ingreso normal del super usuario (admin_entrar); con "ir" abre esa pantalla del panel
    $ir = array_key_exists('ir', $p) ? (TukanCentral::irValido($p['ir']) ? (string) $p['ir'] : false) : null;
    $id = $central->superUsuario($p, ip_visitante(), $ir);
    session_regenerate_id(true);
    $_SESSION['admin_id'] = $id;
    $_SESSION['desde_central'] = 1;
    unset($_SESSION['intentos_admin']);
    if (is_string($ir)) {
        header('Location: ' . $ir);
        exit;
    }
    redirigir('admin');
} catch (TukanCentralRechazo $e) {
    $fallar($e->http, $e->getMessage(), $e->codigo);
} catch (Throwable $e) {
    error_log('TuRenta central: ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    $fallar(500, 'Algo falló en TuRenta. Intenta de nuevo en un momento.', 'interno');
}
