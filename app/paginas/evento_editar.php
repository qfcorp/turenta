<?php
use TuRenta\{Eventos, Ventas};

/**
 * Editar los datos generales del evento (cliente, fecha, horas, invitados, espacios, montaje, presupuesto, notas, vendedor).
 * Si el evento ya está separado, el núcleo revisa que la nueva fecha/espacio siga libre.
 */
exigir(es_comercial($u), 'Solo el dueño y los vendedores pueden editar eventos.');
$nid = (int) $u['negocio_id'];
$id = (int) ($_GET['id'] ?? 0);
$ventas = new Ventas(db());
$eventos = new Eventos(db());
$ev = $eventos->obtener($nid, $id);
if (!$ev) {
    http_response_code(404);
    vista('error', ['mensaje' => 'No encontramos ese evento. Puede que lo hayan borrado o que el enlace esté mal.'], 'Evento · TuRenta');
    return;
}
$espacios = $ventas->espacios($nid, false);
// Un espacio desactivado que el evento ya usa sigue apareciendo para no perderlo
$activos = array_map('intval', array_column($espacios, 'id'));
foreach ($ev['espacios'] as $es) {
    if (!in_array((int) $es['id'], $activos, true) && ($fila = $ventas->espacio($nid, (int) $es['id']))) $espacios[] = $fila;
}

$d = [
    'modo_cliente' => 'existente', 'cliente_id' => (int) $ev['cliente_id'],
    'cliente_texto' => $ev['cliente'] . ($ev['cliente_telefono'] ? ' · ' . $ev['cliente_telefono'] : ''),
    'cliente_nombre' => '', 'cliente_telefono' => '', 'cliente_identificacion' => '', 'cliente_email' => '',
    'origen' => (string) $ev['origen'], 'tipo' => $ev['tipo'], 'nombre' => $ev['nombre'], 'fecha' => $ev['fecha'],
    'hora_inicio' => hora_corta($ev['hora_inicio']), 'hora_fin' => hora_corta($ev['hora_fin']), 'invitados' => (string) $ev['invitados'],
    'espacios' => array_map('intval', array_column($ev['espacios'], 'id')), 'montaje' => (string) $ev['montaje'],
    'presupuesto_cliente' => $ev['presupuesto_cliente'] !== null ? rtrim(rtrim(number_format((float) $ev['presupuesto_cliente'], 2, '.', ''), '0'), '.') : '',
    'notas' => (string) $ev['notas'], 'vendedor_id' => (int) $ev['vendedor_id'],
];
$error = null;
$choquesForm = [];

if (es_post()) {
    verificar_csrf();
    foreach ($d as $k => $_) {
        if ($k === 'espacios') $d[$k] = array_map('intval', (array) ($_POST['espacios'] ?? []));
        elseif (isset($_POST[$k]) && is_string($_POST[$k])) $d[$k] = trim($_POST[$k]);
    }
    try {
        $datos = Ventas::leerFormularioEvento($_POST);
        $datos['vendedor_id'] = $ventas->vendedorValido($nid, $datos['vendedor_id']);
        if (Ventas::fechaValida($datos['fecha']) && $datos['espacios'] && !in_array($ev['estado'], Eventos::BLOQUEAN, true)) {
            $choquesForm = Ventas::separarChoques($eventos->choques($nid, $datos['fecha'], $datos['hora_inicio'], $datos['hora_fin'], $datos['espacios'], $id));
            if ($choquesForm['bloqueados'] && empty($_POST['aceptar_choque'])) {
                throw new RuntimeException('Ese espacio no está libre a esa hora. Cambia la fecha, la hora o el espacio, o marca la casilla para guardarlo igual como consulta.');
            }
        }
        $antes = $ev;
        db()->beginTransaction();
        try {
            $clienteId = !empty($datos['cliente_id']) ? (int) $datos['cliente_id'] : $eventos->guardarCliente($nid, $datos['cliente']);
            if ($clienteId !== (int) $ev['cliente_id']) $ventas->cambiarCliente($nid, $id, $clienteId);
            $eventos->actualizar($nid, $id, $datos);
            db()->commit();
        } catch (Throwable $t) {
            if (db()->inTransaction()) db()->rollBack();
            throw $t;
        }
        $nuevo = $eventos->obtener($nid, $id);
        $cambios = [];
        if ($antes['fecha'] !== $nuevo['fecha']) $cambios[] = 'fecha ' . fecha_corta($antes['fecha']) . ' → ' . fecha_corta($nuevo['fecha']);
        if ($antes['hora_inicio'] !== $nuevo['hora_inicio'] || $antes['hora_fin'] !== $nuevo['hora_fin']) $cambios[] = 'horario ' . hora_corta($nuevo['hora_inicio']) . ' a ' . hora_corta($nuevo['hora_fin']);
        if ((int) $antes['invitados'] !== (int) $nuevo['invitados']) $cambios[] = 'invitados ' . $antes['invitados'] . ' → ' . $nuevo['invitados'];
        if ($antes['espacios_texto'] !== $nuevo['espacios_texto']) $cambios[] = 'espacios: ' . $nuevo['espacios_texto'];
        if ((int) $antes['cliente_id'] !== (int) $nuevo['cliente_id']) $cambios[] = 'cliente: ' . $nuevo['cliente'];
        registrar_cambio($nid, $id, 'Editó el evento' . ($cambios ? ' (' . implode('; ', $cambios) . ')' : ''));
        aviso('Cambios guardados.');
        redirigir('evento', ['id' => $id]);
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}

vista('evento_form', [
    'modo' => 'editar', 'd' => $d, 'error' => $error, 'espacios' => $espacios, 'choquesForm' => $choquesForm,
    'clientes' => $eventos->clientes($nid, '', 300), 'vendedores' => $ventas->usuariosVenta($nid), 'ev' => $ev,
], 'Editar ' . $ev['nombre'] . ' · TuRenta');
