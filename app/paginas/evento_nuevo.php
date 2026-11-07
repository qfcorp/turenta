<?php
use TuRenta\{Eventos, Ventas};

/**
 * Nuevo evento: cliente existente o nuevo, fecha, horas, invitados, espacios, montaje, presupuesto, vendedor.
 * Muestra los choques en vivo (mod_ventas.js consulta la ruta "disponible") y avisa si los invitados superan la capacidad.
 *   /?r=evento_nuevo&fecha=2026-11-14&espacio=3&cliente_id=5
 */
exigir(es_comercial($u), 'Solo el dueño y los vendedores pueden crear eventos.');
$nid = (int) $u['negocio_id'];
$ventas = new Ventas(db());
$eventos = new Eventos(db());
$espacios = $ventas->espacios($nid, false);

$d = [
    'modo_cliente' => 'nuevo', 'cliente_id' => (int) ($_GET['cliente_id'] ?? 0),
    'cliente_nombre' => '', 'cliente_telefono' => '', 'cliente_identificacion' => '', 'cliente_email' => '',
    'origen' => '', 'tipo' => 'boda', 'nombre' => '',
    'fecha' => Ventas::fechaValida($_GET['fecha'] ?? null) ? (string) $_GET['fecha'] : '',
    'hora_inicio' => '18:00', 'hora_fin' => '02:00', 'invitados' => '',
    'espacios' => isset($_GET['espacio']) ? [(int) $_GET['espacio']] : (count($espacios) === 1 ? [(int) $espacios[0]['id']] : []),
    'montaje' => '', 'presupuesto_cliente' => '', 'notas' => '', 'vendedor_id' => (int) $u['id'],
];
if ($d['cliente_id'] && ($cli = $eventos->cliente($nid, $d['cliente_id']))) {
    $d['modo_cliente'] = 'existente';
    $d['cliente_texto'] = $cli['nombre'] . ($cli['telefono'] ? ' · ' . $cli['telefono'] : '');
} else {
    $d['cliente_id'] = 0;
}
$error = null;
$choquesForm = [];

if (es_post()) {
    verificar_csrf();
    foreach ($d as $k => $_) {
        if ($k === 'espacios') $d[$k] = array_map('intval', (array) ($_POST['espacios'] ?? []));
        elseif (isset($_POST[$k])) $d[$k] = is_string($_POST[$k]) ? trim($_POST[$k]) : $d[$k];
    }
    $d['cliente_texto'] = trim((string) ($_POST['cliente_texto'] ?? ''));
    try {
        $datos = Ventas::leerFormularioEvento($_POST);
        $datos['vendedor_id'] = $ventas->vendedorValido($nid, $datos['vendedor_id']);
        if (!empty($datos['cliente_id']) && !$eventos->cliente($nid, (int) $datos['cliente_id'])) {
            throw new RuntimeException('No se encontró el cliente. Búscalo otra vez.');
        }
        // Si el espacio ya está separado ese día, se pide confirmar (puede quedar como consulta en lista de espera)
        if (Ventas::fechaValida($datos['fecha']) && $datos['espacios']) {
            $choquesForm = Ventas::separarChoques($eventos->choques($nid, $datos['fecha'], $datos['hora_inicio'], $datos['hora_fin'], $datos['espacios']));
            if ($choquesForm['bloqueados'] && empty($_POST['aceptar_choque'])) {
                throw new RuntimeException('Ese espacio no está libre a esa hora. Cambia la fecha, la hora o el espacio. Si igual quieres guardarlo como consulta (por ejemplo, en lista de espera), marca la casilla de abajo.');
            }
        }
        $id = $eventos->crear($nid, $datos, (int) $u['id']);
        $ev = $eventos->obtener($nid, $id);
        registrar_cambio($nid, $id, 'Creó el evento ' . $ev['codigo'] . ' para el ' . fecha_corta($ev['fecha']));
        aviso('Evento creado: ' . $ev['nombre'] . '. Siguiente paso: arma la cotización.');
        redirigir('evento', ['id' => $id]);
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}

vista('evento_form', [
    'modo' => 'nuevo', 'd' => $d, 'error' => $error, 'espacios' => $espacios, 'choquesForm' => $choquesForm,
    'clientes' => $eventos->clientes($nid, '', 300), 'vendedores' => $ventas->usuariosVenta($nid), 'ev' => null,
], 'Nuevo evento · TuRenta');
