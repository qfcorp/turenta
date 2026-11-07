<?php
use TuRenta\{Cuestionarios, Eventos};

/**
 * Formulario del evento para el cliente (público, con el enlace del portal): /?r=portal_cuestionario&t=<token_cliente>
 *  - GET: el formulario por secciones, con el avance (x de y) y la fecha de cierre. Después del cierre, solo se ven las respuestas.
 *  - POST accion=guardar (también lo manda el guardado automático con ajax=1, que responde JSON) o accion=terminar.
 * Disponible en los dos planes, para eventos separados o confirmados.
 */
$token = (string) ($_GET['t'] ?? '');
$ev = (new Eventos(db()))->porToken('token_cliente', $token);
$negocio = null;
if ($ev) {
    $st = db()->prepare('SELECT * FROM negocios WHERE id = ?');
    $st->execute([$ev['negocio_id']]);
    $negocio = $st->fetch();
}
$ajax = es_post() && !empty($_POST['ajax']);
$responderJson = function (array $datos, int $codigo = 200): never {
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
};
if (!$ev || in_array($negocio['estado'], ['cancelado'], true)) {
    if ($ajax) $responderJson(['ok' => false, 'mensaje' => 'Este enlace no es válido.'], 404);
    http_response_code(404);
    vista_publica('error_publico', ['mensaje' => 'Este enlace no es válido o ya no existe. Pide uno nuevo al local donde contrataste tu evento.'], 'Formulario del evento · TuRenta');
    return;
}
$nid = (int) $ev['negocio_id'];
$eid = (int) $ev['id'];
$marca = ['nombre' => $negocio['nombre'], 'color' => $negocio['color'], 'slug' => $negocio['slug'], 'logo' => $negocio['logo']];
$cues = new Cuestionarios(db());
$disponible = in_array($ev['estado'], Cuestionarios::ESTADOS_CLIENTE, true);
$form = $disponible ? $cues->deEvento($nid, $eid) : null;

if (es_post()) {
    if (!hash_equals(token_csrf(), (string) ($_POST['csrf'] ?? ''))) {
        if ($ajax) $responderJson(['ok' => false, 'mensaje' => 'La página caducó. Recárgala para seguir.'], 400);
        verificar_csrf();
    }
    $accion = (string) ($_POST['accion'] ?? 'guardar');
    try {
        if (!$form || !$form['total']) throw new RuntimeException('El formulario todavía no está disponible para tu evento.');
        if (in_array($negocio['estado'], ['suspendido'], true)) throw new RuntimeException('Por ahora no se pueden guardar cambios. Escríbele al local.');
        $resp = is_array($_POST['r'] ?? null) ? $_POST['r'] : [];
        $presentes = is_array($_POST['en'] ?? null) ? array_slice($_POST['en'], 0, Cuestionarios::MAX_PREGUNTAS) : [];
        $form = $cues->guardarRespuestas($nid, $eid, $resp, $presentes, 'Cliente', true);
        if ($ajax) $responderJson(['ok' => true, 'respondidas' => $form['respondidas'], 'total' => $form['total'], 'pct' => $form['pct'], 'mensaje' => 'Guardado']);
        if ($accion === 'terminar') {
            $primera = $cues->terminar($nid, $eid);
            aviso($primera ? '¡Gracias! Enviamos tus respuestas a ' . $negocio['nombre'] . '. Puedes volver y cambiar algo hasta el ' . fecha_corta($form['cierre']) . '.'
                           : 'Cambios guardados. ' . $negocio['nombre'] . ' ya tiene tus respuestas actualizadas.');
            redirigir('portal', ['t' => $token]);
        }
        aviso('Tus respuestas quedaron guardadas (' . $form['respondidas'] . ' de ' . $form['total'] . ').');
    } catch (RuntimeException $e) {
        if ($ajax) $responderJson(['ok' => false, 'mensaje' => $e->getMessage()], 422);
        aviso($e->getMessage(), 'error');
    }
    redirigir('portal_cuestionario', ['t' => $token]);
}

$primerNombre = trim(explode(' ', trim((string) $ev['cliente']))[0]);
vista_publica('portal_cuestionario', compact('ev', 'negocio', 'token', 'form', 'disponible', 'primerNombre'), 'Formulario · ' . $ev['nombre'], $marca);
