<?php
use TuRenta\{Altas, Usuarios};

/**
 * El super administrador crea una demo para un cliente (todos los módulos encendidos; si ya pagó, se registra el pago en su ficha).
 * Con ?pedido=<id> se llena con un pedido de demo y, al crearla, el pedido queda "demo creada".
 */
$d = ['negocio' => '', 'nombre' => '', 'telefono' => '', 'email' => '', 'ciudad' => 'Quito', 'plan' => 'quinta'];
$error = null;
$pedidosDemo = new TuRenta\PedidosDemo(db());
$pedido = !empty($_REQUEST['pedido']) ? $pedidosDemo->obtener((int) $_REQUEST['pedido']) : null;
if ($pedido && $pedido['estado'] === 'demo_creada') $pedido = null;
if ($pedido) $d = ['negocio' => $pedido['negocio'], 'nombre' => $pedido['nombre'], 'telefono' => $pedido['whatsapp'], 'email' => $pedido['email'],
                   'ciudad' => $pedido['ciudad'] ?: 'Quito', 'plan' => 'quinta'];
if (es_post()) {
    verificar_csrf();
    foreach ($d as $k => $_) $d[$k] = trim((string) ($_POST[$k] ?? ''));
    $clave = Usuarios::claveTemporal();
    try {
        $alta = (new Altas(db()))->crearNegocioConDueno($d['negocio'], $d['nombre'], $d['telefono'], $d['email'], $clave, $d['plan'], $d['ciudad'], $pedido ? 'demo_pedida' : 'super');
        if ($pedido) $pedidosDemo->demoCreada((int) $pedido['id'], $alta['negocio_id']);
        db()->prepare('UPDATE usuarios SET clave_temporal = true WHERE id = ?')->execute([$alta['usuario_id']]);
        registrar_admin('Negocio creado desde el panel (' . $d['plan'] . ')' . ($pedido ? ' · pedido de demo N.º ' . $pedido['id'] : ''), $alta['negocio_id']);
        $_SESSION['clave_nueva_' . $alta['negocio_id']] = ['email' => strtolower($d['email']), 'clave' => $clave, 'nombre' => $d['nombre']];
        aviso('Demo creada con todos los módulos y datos de ejemplo. Si ya pagó, registra el pago abajo.');
        redirigir('admin_negocio', ['id' => $alta['negocio_id']]);
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}
$listaPlanes = planes()->lista();
vista_admin('admin_nuevo', compact('d', 'error', 'listaPlanes', 'pedido'), 'Nueva demo · Panel Tukán');
