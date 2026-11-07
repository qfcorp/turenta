<?php
use TuRenta\DiaEvento;

/**
 * "Mis eventos" del personal (público, ?t=token de la persona): próximos eventos con horario, rol, lugar y mapa,
 * contacto del coordinador, confirmar asistencia y pagos recibidos / pendientes.
 */
$dia = new DiaEvento(db());
$token = (string) ($_GET['t'] ?? '');
$p = $dia->personaPorToken($token);
$negocio = null;
if ($p) {
    $st = db()->prepare('SELECT * FROM negocios WHERE id = ?');
    $st->execute([$p['negocio_id']]);
    $negocio = $st->fetch();
}
if (!$p || !$p['activo'] || in_array($negocio['estado'], ['suspendido', 'cancelado'], true)) {
    http_response_code(404);
    vista_publica('error_publico', ['mensaje' => 'Este enlace no es válido. Pide uno nuevo a quien te contrató.'], 'Mis eventos', $negocio ? DiaEvento::marca($negocio) : null);
    return;
}

if (es_post()) {
    verificar_csrf();
    try {
        $va = ($_POST['respuesta'] ?? '') === 'si';
        $a = $dia->responderStaff($token, (int) ($_POST['asig_id'] ?? 0), $va);
        aviso($va ? '¡Gracias! Confirmaste para ' . $a['evento'] . '.' : 'Listo, avisamos que no puedes ir a ' . $a['evento'] . '.');
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
    }
    redirigir('staff', ['t' => $token]);
}

$st = db()->prepare("SELECT nombre, telefono FROM usuarios WHERE negocio_id = ? AND rol = 'dueno' AND activo ORDER BY id LIMIT 1");
$st->execute([$p['negocio_id']]);
$dueno = $st->fetch() ?: null;
vista_publica('staff', [
    'p' => $p, 'negocio' => $negocio, 'dueno' => $dueno,
    'proximos' => $dia->eventosDePersona((int) $p['id']),
    'pagos' => $dia->pagosDePersona((int) $p['id']),
    'mapa' => DiaEvento::mapa($negocio),
], 'Mis eventos · ' . $negocio['nombre'], DiaEvento::marca($negocio));
