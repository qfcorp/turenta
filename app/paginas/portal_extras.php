<?php
use TuRenta\{Eventos, Extras, Fotos};

/**
 * Extras que el cliente pide desde su portal (público, con el enlace del portal): /?r=portal_extras&t=<token_cliente>
 *  - GET: los extras que ofrece el local (foto, texto, precio con IVA) y el estado de sus pedidos.  &foto=<producto_id>: la foto.
 *  - POST accion=pedir (cant[<producto_id>]=cantidad, nota) o accion=cancelar (pedido_id).
 * Freno: máximo 3 pedidos esperando respuesta por evento (Extras::MAX_PENDIENTES) y 10 envíos por hora por sesión.
 */
$token = (string) ($_GET['t'] ?? '');
$ev = (new Eventos(db()))->porToken('token_cliente', $token);
$negocio = null;
if ($ev) {
    $st = db()->prepare('SELECT * FROM negocios WHERE id = ?');
    $st->execute([$ev['negocio_id']]);
    $negocio = $st->fetch();
}
if (!$ev || $negocio['estado'] === 'cancelado') {
    http_response_code(404);
    vista_publica('error_publico', ['mensaje' => 'Este enlace no es válido o ya no existe. Pide uno nuevo al local donde contrataste tu evento.'], 'Extras · TuRenta');
    return;
}
$nid = (int) $ev['negocio_id'];
$eid = (int) $ev['id'];
$extras = new Extras(db());

if (isset($_GET['foto'])) {
    $ruta = $extras->rutaFoto($nid, (int) $_GET['foto']);
    if ($ruta === null) {
        http_response_code(404);
        exit;
    }
    Fotos::enviar($ruta, false);
    return;
}

if (es_post()) {
    verificar_csrf();
    $accion = (string) ($_POST['accion'] ?? '');
    try {
        if ($accion === 'pedir') {
            $recientes = array_values(array_filter($_SESSION['extras_pedidos'] ?? [], fn($t) => $t > time() - 3600));
            if (count($recientes) >= 10) throw new RuntimeException('Enviaste varios pedidos seguidos. Espera un momento o escríbele al local por WhatsApp.');
            $cant = is_array($_POST['cant'] ?? null) ? array_slice($_POST['cant'], 0, 100, true) : [];
            $extras->pedir($nid, $eid, $cant, campo('nota'), ip_visitante());
            $recientes[] = time();
            $_SESSION['extras_pedidos'] = $recientes;
            aviso('¡Listo! Enviamos tu pedido a ' . $negocio['nombre'] . '. Te avisaremos cuando lo revisen; aquí verás la respuesta.');
            header('Location: ' . url('portal_extras', ['t' => $token]) . '#mis-pedidos');
            exit;
        }
        if ($accion === 'cancelar') {
            $extras->cancelar($nid, $eid, (int) ($_POST['pedido_id'] ?? 0));
            aviso('Cancelaste el pedido.');
            header('Location: ' . url('portal_extras', ['t' => $token]) . '#mis-pedidos');
            exit;
        }
        throw new RuntimeException('Acción no válida.');
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
        if ($accion === 'pedir') $_SESSION['extras_borrador'][$eid] = ['cant' => $_POST['cant'] ?? [], 'nota' => campo('nota')];
    }
    redirigir('portal_extras', ['t' => $token]);
}

$borrador = $_SESSION['extras_borrador'][$eid] ?? null;
unset($_SESSION['extras_borrador'][$eid]);
$marca = ['nombre' => $negocio['nombre'], 'color' => $negocio['color'], 'slug' => $negocio['slug'], 'logo' => $negocio['logo']];
vista_publica('portal_extras', [
    'ev' => $ev, 'negocio' => $negocio, 'token' => $token, 'borrador' => $borrador,
    'ofrecidos' => $extras->ofrecidos($nid), 'motivo' => $extras->motivoNoDisponible($nid, $eid),
    'pedidos' => $extras->deEvento($nid, $eid), 'ivaPct' => (float) $negocio['iva_pct'],
], 'Extras · ' . $ev['nombre'], $marca);
