<?php
use TuRenta\{Eventos, Ventas};

/**
 * Ficha del cliente: datos editables, sus eventos, actividades, WhatsApp, total contratado y pagado.
 * Acciones (POST ?accion=): guardar, actividad, actividad_marcar.  Sin id -> lleva a crear en "Clientes".
 */
$nid = (int) $u['negocio_id'];
$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) redirigir('clientes', ['nuevo' => 1]);
$eventos = new Eventos(db());
$ventas = new Ventas(db());
$cliente = $eventos->cliente($nid, $id);
if (!$cliente) {
    http_response_code(404);
    vista('error', ['mensaje' => 'No encontramos ese cliente.'], 'Cliente · TuRenta');
    return;
}
$puedeEditar = es_comercial($u);
$error = null;
$d = $cliente;

if (es_post()) {
    exigir($puedeEditar, 'Tu usuario solo puede ver la ficha.');
    verificar_csrf();
    $accion = (string) ($_GET['accion'] ?? 'guardar');
    try {
        if ($accion === 'guardar') {
            foreach (['nombre', 'telefono', 'identificacion', 'email', 'direccion', 'origen', 'notas'] as $k) $d[$k] = campo($k);
            $ventas->editarCliente($nid, $id, $d);
            aviso('Ficha guardada.');
            redirigir('cliente', ['id' => $id]);
        }
        if ($accion === 'actividad') {
            $tipo = (string) ($_POST['tipo'] ?? 'nota');
            if (!isset(Ventas::TIPOS_SEGUIMIENTO[$tipo])) $tipo = 'nota';
            $eventoId = (int) ($_POST['evento_id'] ?? 0) ?: null;
            if ($eventoId) {
                $evc = $eventos->obtener($nid, $eventoId);
                if (!$evc || (int) $evc['cliente_id'] !== $id) throw new RuntimeException('Ese evento no es de este cliente.');
            }
            $prog = trim((string) ($_POST['programada'] ?? ''));
            $eventos->agregarActividad($nid, $eventoId, $id, $tipo, (string) ($_POST['texto'] ?? ''), $prog ?: null, (int) $u['id']);
            aviso($prog ? 'Quedó agendado.' : 'Seguimiento guardado.');
        } elseif ($accion === 'actividad_marcar') {
            $st = db()->prepare('SELECT 1 FROM actividades WHERE id = ? AND cliente_id = ? AND negocio_id = ?');
            $st->execute([(int) ($_POST['actividad_id'] ?? 0), $id, $nid]);
            if (!$st->fetchColumn()) throw new RuntimeException('No se encontró la actividad.');
            $eventos->marcarActividad($nid, (int) $_POST['actividad_id'], true);
            aviso('Marcado como hecho.');
        } elseif ($accion !== 'guardar') {
            throw new RuntimeException('Acción no válida.');
        }
        redirigir('cliente', ['id' => $id]);
    } catch (RuntimeException $e) {
        if ($accion === 'guardar') {
            $error = $e->getMessage();
        } else {
            aviso($e->getMessage(), 'error');
            redirigir('cliente', ['id' => $id]);
        }
    }
}

vista('cliente', [
    'cliente' => $cliente, 'd' => $d, 'error' => $error, 'puedeEditar' => $puedeEditar,
    'eventosCliente' => $eventos->lista($nid, ['cliente_id' => $id, 'orden' => 'recientes'], 100),
    'actividades' => $ventas->actividadesCliente($nid, $id),
    'resumen' => $ventas->resumenCliente($nid, $id),
], $cliente['nombre'] . ' · TuRenta');
