<?php
use TuRenta\{DiaEvento, Invitados, Qr};

/**
 * Lo que abre la cámara al escanear el QR de una invitación (pública, ?t=token del invitado).
 * - Si este celular abrió el modo puerta del evento (o es un usuario del negocio con sesión): registra el ingreso
 *   y muestra pantalla grande verde (entra) o roja (ya entró / no es de este evento).
 * - Si no: muestra el QR para que lo escaneen en la entrada.
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
if (!$i || !DiaEvento::publicoActivo(db(), $negocio, 'invitados')) {
    http_response_code(404);
    vista_publica('ingreso', ['estado' => 'invalido', 'ev' => null, 'negocio' => $negocio, 'i' => null, 'r' => null], 'Ingreso', $negocio ? DiaEvento::marca($negocio) : null);
    return;
}
$eventoId = (int) $i['evento_id'];
$u = usuario();
$deLaPuerta = !empty($_SESSION['puerta'][$eventoId]) || ($u && (int) $u['negocio_id'] === (int) $ev['negocio_id'] && $u['rol'] !== 'consulta');
$datos = ['ev' => $ev, 'negocio' => $negocio, 'i' => $i, 'r' => null];

if (!$deLaPuerta) {
    $datos['estado'] = 'mostrar';
    $datos['qrSvg'] = Qr::svg(url_completa('ingreso', ['t' => $i['token']]), null, '#1F1A1C', 'Código QR de ingreso');
} elseif (in_array($ev['estado'], ['perdido', 'cancelado'], true)) {
    $datos['estado'] = 'rojo';
    $datos['mensaje'] = 'Este evento fue cancelado.';
} elseif (es_post()) {
    // Cuántos entran (cuando el invitado tiene varios pases y llega solo una parte)
    verificar_csrf();
    $r = $inv->registrarIngreso((int) $i['id'], max(1, (int) ($_POST['cuantos'] ?? 1)), $eventoId);
    $_SESSION['ingreso_resultado'] = $r['ok'] ? ['ok' => true, 'entraron' => $r['entraron']] : ['ok' => false, 'mensaje' => $r['mensaje']];
    redirigir('ingreso', ['t' => $token]);
} else {
    $previo = $_SESSION['ingreso_resultado'] ?? null;
    unset($_SESSION['ingreso_resultado']);
    $faltan = (int) $i['pases'] - (int) $i['ingresaron'];
    if ($previo) {
        $datos['estado'] = $previo['ok'] ? 'verde' : 'rojo';
        $datos['mensaje'] = $previo['ok'] ? 'Entraron ' . (int) $previo['entraron'] : $previo['mensaje'];
    } elseif ($faltan <= 0) {
        $datos['estado'] = 'rojo';
        $datos['mensaje'] = 'Ya ingresó completo (' . (int) $i['ingresaron'] . ' de ' . (int) $i['pases'] . '). Este código ya se usó.';
    } elseif ((int) $i['pases'] > 1 && empty($_GET['todos'])) {
        // Varios pases: se pregunta cuántos llegan (lo normal: todos)
        $datos['estado'] = 'cuantos';
        $datos['faltan'] = $faltan;
    } else {
        $r = $inv->registrarIngreso((int) $i['id'], null, $eventoId);
        $datos['estado'] = $r['ok'] ? 'verde' : 'rojo';
        $datos['mensaje'] = $r['ok'] ? 'Entraron ' . (int) $r['entraron'] : $r['mensaje'];
    }
    $datos['i'] = $inv->porToken($token);
    $datos['enlacePuerta'] = url('puerta', ['t' => $ev['token_puerta']]);
}
vista_publica('ingreso', $datos, 'Ingreso · ' . $ev['nombre'], DiaEvento::marca($negocio));
