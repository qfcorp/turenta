<?php
use TuRenta\{Eventos, Ventas};

/**
 * Disponibilidad en JSON.
 *  - Público (página del negocio): /?r=disponible&s=<slug>&fecha=Y-m-d
 *      -> {fecha, espacios: [{espacio, capacidad, estado: libre|ocupado|consultar}]}  (sin datos de otros clientes)
 *  - Interno (con sesión, sin s): /?r=disponible&fecha&hora_inicio&hora_fin&espacios[]&excluir
 *      -> {fecha, choques: [{nivel, texto, codigo, nombre, espacio, evento_id}], bloqueado: bool}
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$salida = function (array $d, int $codigo = 200): void {
    http_response_code($codigo);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
};
$ventas = new Ventas(db());
$fecha = (string) ($_GET['fecha'] ?? '');

if (isset($_GET['s'])) {
    $neg = $ventas->negocioPorSlug((string) $_GET['s']);
    if (!$neg || !planes()->alDia($neg)) {
        $salida(['error' => 'Esta página no está disponible.'], 404);
        return;
    }
    if (!Ventas::fechaValida($fecha)) {
        $salida(['error' => 'Elige una fecha.'], 400);
        return;
    }
    if ($fecha < date('Y-m-d')) {
        $salida(['error' => 'Esa fecha ya pasó. Elige una fecha desde hoy.', 'fecha' => $fecha, 'espacios' => []], 200);
        return;
    }
    $salida(['fecha' => $fecha, 'espacios' => $ventas->disponibilidadPublica((int) $neg['id'], $fecha)]);
    return;
}

// Modo interno: solo con sesión (esta ruta es pública en el enrutador)
$u = usuario();
if (!$u) {
    $salida(['error' => 'Inicia sesión.'], 401);
    return;
}
if (!planes()->alDia($u) && !(!empty($_SESSION['admin_viendo']) && superadmin())) {
    $salida(['error' => 'Tu plan no está al día.'], 403);
    return;
}
if (!Ventas::fechaValida($fecha)) {
    $salida(['fecha' => $fecha, 'choques' => [], 'bloqueado' => false]);
    return;
}
$horaOk = fn($h) => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', substr((string) $h, 0, 5)) ? substr((string) $h, 0, 5) : null;
$hi = $horaOk($_GET['hora_inicio'] ?? '18:00') ?? '18:00';
$hf = $horaOk($_GET['hora_fin'] ?? '02:00') ?? '02:00';
$espacios = array_values(array_filter(array_map('intval', (array) ($_GET['espacios'] ?? []))));
$excluir = (int) ($_GET['excluir'] ?? 0) ?: null;
$choques = $hi === $hf ? [] : (new Eventos(db()))->choques((int) $u['negocio_id'], $fecha, $hi, $hf, $espacios, $excluir);
$res = [];
foreach ($choques as $c) {
    $res[] = ['nivel' => $c['nivel'], 'texto' => Ventas::textoChoque($c), 'codigo' => $c['codigo'], 'nombre' => $c['nombre'],
              'espacio' => $c['espacio'], 'evento_id' => $c['id'] ? (int) $c['id'] : null,
              'enlace' => $c['id'] ? url('evento', ['id' => $c['id']]) : null];
}
$salida(['fecha' => $fecha, 'choques' => $res, 'bloqueado' => (bool) array_filter($res, fn($c) => $c['nivel'] === 'bloqueado')]);
