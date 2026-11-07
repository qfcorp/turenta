<?php
use TuRenta\{Extras, Fotos};

/**
 * Extras del portal: /?r=extras
 *  - Pedidos de extras de los clientes que esperan respuesta (se aprueban en el evento → Formulario y extras).
 *  - Qué productos del catálogo se ofrecen en el portal del cliente, con texto y foto.
 *  - Últimos pedidos respondidos.
 * Dueño y vendedor cambian; la cuenta de consulta solo mira; el coordinador no entra (no ve dinero).
 * Acciones (POST accion=producto): en_portal, portal_texto, portal_foto, quitar_foto.  GET &foto=<producto_id>: la foto (con sesión).
 */
exigir($u['rol'] !== 'coordinador', 'Los extras del portal los maneja el dueño o un vendedor.');
$nid = (int) $u['negocio_id'];
$extras = new Extras(db());
$editar = es_comercial($u);

if (isset($_GET['foto'])) {
    $ruta = $extras->rutaFoto($nid, (int) $_GET['foto'], false);
    if ($ruta === null) {
        http_response_code(404);
        exit;
    }
    Fotos::enviar($ruta);
    return;
}

if (es_post()) {
    verificar_csrf();
    exigir($editar, 'Solo el dueño o un vendedor pueden cambiar los extras del portal.');
    $pid = (int) ($_POST['producto_id'] ?? 0);
    try {
        if (($_POST['accion'] ?? '') !== 'producto') throw new RuntimeException('Acción no válida.');
        $extras->configurarProducto($nid, $pid, !empty($_POST['en_portal']), campo('portal_texto'), $_FILES['portal_foto'] ?? null, !empty($_POST['quitar_foto']));
        aviso(!empty($_POST['en_portal']) ? 'Listo: tus clientes ya lo pueden pedir desde su portal.' : 'Guardado. Este producto no sale en el portal.');
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
    }
    header('Location: ' . url('extras') . '#prod-' . $pid);
    exit;
}

vista('extras', [
    'pendientes' => $extras->pendientes($nid), 'catalogo' => $extras->catalogo($nid), 'recientes' => $extras->recientes($nid, 20), 'editar' => $editar,
], 'Extras del portal · TuRenta');
