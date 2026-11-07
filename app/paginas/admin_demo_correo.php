<?php
use TuRenta\CorreoDemo;

/**
 * «Enviar por correo» desde la pantalla de la demo lista (solo POST con CSRF, solo el súper usuario: $deAdmin).
 * Llega el sello (datos de la demo cifrados, ver src/CorreoDemo.php) y el correo del cliente. Se manda desde
 * info@tukansoftware.com con src/tukan_correo.php, queda en registro_admin SIN la contraseña y se muestra el resultado.
 */
if (!es_post()) redirigir('admin_negocios');
verificar_csrf();
$admin = superadmin();
$s = CorreoDemo::abrir(campo('sello'), (int) $admin['id'], $_SESSION);
$nid = $s ? (int) ($s['d']['negocio_id'] ?? 0) : 0;
$negocio = $nid ? (new TuRenta\PanelTukan(db()))->negocio($nid) : null;
if (!$s || !$negocio) {
    http_response_code(410);
    vista_admin('admin_demo_correo', ['negocio' => null, 'correoDemo' => null,
        'caducado' => 'Este envío ya caducó (pasaron más de 30 minutos o se cerró la sesión). La contraseña de la demo se muestra una sola vez: si hace falta mandarla otra vez, crea una contraseña nueva para el dueño en la ficha del negocio.'],
        'Envío caducado · Panel Tukán');
    return;
}
$d = $s['d'];
$para = mb_substr(campo('para'), 0, 254);
$cd = ['listo' => CorreoDemo::listo(), 'para' => $para, 'sello' => campo('sello'), 'error' => null, 'estado' => null];
$mostrar = function (array $cd, int $http = 200) use ($negocio): void {
    http_response_code($http);
    vista_admin('admin_demo_correo', ['negocio' => $negocio, 'correoDemo' => $cd, 'caducado' => null], 'Demo lista · Panel Tukán');
};
if ($s['usado']) {
    $mostrar(['estado' => 'usado'] + $cd);
    return;
}
if (!tukan_correo_valido($para)) {
    $mostrar(['error' => 'Escribe un correo válido (ej. nombre@negocio.com).'] + $cd, 422);
    return;
}
if (!$cd['listo']) {
    $mostrar($cd, 503);
    return;
}
if (CorreoDemo::enviar($d, $para, $error)) {
    CorreoDemo::gastar($s['n'], $_SESSION);
    registrar_admin("Envió la demo por correo a $para", $nid);
    $mostrar(['estado' => 'enviado'] + $cd);
    return;
}
registrar_admin("No se pudo enviar la demo por correo a $para: " . mb_substr((string) $error, 0, 200), $nid);
$mostrar(['error' => 'No se pudo enviar: ' . $error] + $cd, 502);
