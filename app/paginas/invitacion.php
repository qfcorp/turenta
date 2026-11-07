<?php
use TuRenta\{DiaEvento, Invitados, Qr};

/**
 * Invitación digital (pública, ?t=token del invitado): datos del evento, confirmar asistencia y QR de ingreso.
 * &qr=1 devuelve el código QR en SVG (contiene el enlace de "ingreso").
 */
$inv = new Invitados(db());
$token = (string) ($_GET['t'] ?? '');
$i = $inv->porToken($token);
$negocio = null; $ev = null;
if ($i) {
    $st = db()->prepare('SELECT * FROM eventos WHERE id = ?');
    $st->execute([$i['evento_id']]);
    $ev = $st->fetch();
    $st = db()->prepare('SELECT * FROM negocios WHERE id = ?');
    $st->execute([$ev['negocio_id']]);
    $negocio = $st->fetch();
}
if (!$i || !DiaEvento::publicoActivo(db(), $negocio, 'invitados') || in_array($ev['estado'], ['perdido', 'cancelado'], true)) {
    if (!empty($_GET['qr'])) { http_response_code(404); return; }
    http_response_code(404);
    vista_publica('error_publico', ['mensaje' => 'Esta invitación no es válida o el evento fue cancelado. Escribe a quien te invitó.'], 'Invitación', $negocio ? DiaEvento::marca($negocio) : null);
    return;
}
$enlaceIngreso = url_completa('ingreso', ['t' => $i['token']]);
if (!empty($_GET['qr'])) {
    Qr::enviarSvg($enlaceIngreso);
    return;
}

$hoy = date('Y-m-d');
if (es_post()) {
    verificar_csrf();
    try {
        $asiste = ($_POST['respuesta'] ?? '') === 'si';
        $inv->responder($token, $asiste, (int) ($_POST['cuantos'] ?? 0), campo('restricciones'), campo('mensaje'));
        aviso($asiste ? '¡Gracias por confirmar! Guarda esta página: tu código QR es tu entrada.' : 'Gracias por avisar. ¡Te vamos a extrañar!');
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
    }
    redirigir('invitacion', ['t' => $token]);
}

$i = $inv->porToken($token);
vista_publica('invitacion', [
    'i' => $i, 'ev' => $ev, 'negocio' => $negocio,
    'mapa' => DiaEvento::mapa($negocio),
    'qrSvg' => $i['estado'] === 'confirmado' ? Qr::svg($enlaceIngreso, null, '#1F1A1C', 'Código QR de ingreso de ' . $i['nombre']) : null,
    'qrUrl' => url('invitacion', ['t' => $token, 'qr' => 1]),
    'paso' => $ev['fecha'] < $hoy,
    'esHoy' => $ev['fecha'] === $hoy,
    'cambiar' => !empty($_GET['cambiar']),
    'cerrado' => $ev['confirmar_hasta'] && $ev['confirmar_hasta'] < $hoy && $i['estado'] === 'pendiente',
], $ev['nombre'] . ' · Invitación', DiaEvento::marca($negocio));
