<?php
use TuRenta\{Compras, Incidencias};

/**
 * Incidencias (problemas): /?r=incidencias[&estado=abiertas|resuelta|todas][&evento=<id>][&proveedor=<id>]
 * Cualquiera del equipo (también el coordinador el día del evento) reporta y actualiza. El costo y el descuento
 * al proveedor solo los pone el dueño o el vendedor. La cuenta de consulta solo mira.
 * Desde el día del evento se manda volver=evento_dia para regresar allí.
 */
$nid = (int) $u['negocio_id'];
$inc = new Incidencias(db());
$conDinero = es_comercial($u);

/** A dónde volver después de guardar (solo pantallas conocidas). */
$volver = function () use ($nid): never {
    $v = (string) ($_POST['volver'] ?? '');
    $vid = (int) ($_POST['volver_id'] ?? 0);
    if ($v === 'evento_dia' && $vid) redirigir('evento_dia', ['id' => $vid, 'ver' => 'incidencias']);
    if ($v === 'proveedor' && $vid) redirigir('proveedor', ['id' => $vid, 'ver' => 'incidencias']);
    if ($v === 'compra' && $vid) redirigir('compra', ['id' => $vid]);
    redirigir('incidencias', array_filter(['estado' => (string) ($_POST['f_estado'] ?? ''), 'evento' => (int) ($_POST['f_evento'] ?? 0) ?: null]));
};

if (es_post()) {
    verificar_csrf();
    exigir(puede_editar($u), 'Tu usuario solo puede mirar.');
    $accion = (string) ($_POST['accion'] ?? '');
    try {
        if ($accion === 'crear') {
            $d = ['evento_id' => (int) ($_POST['evento_id'] ?? 0), 'proveedor_id' => (int) ($_POST['proveedor_id'] ?? 0), 'area' => campo('area'),
                  'gravedad' => campo('gravedad', 'leve'), 'descripcion' => campo('descripcion'), 'responsable' => campo('responsable')];
            if ($conDinero) $d += ['compra_id' => (int) ($_POST['compra_id'] ?? 0), 'costo' => campo('costo'), 'descuento' => campo('descuento')];
            $inc->crear($nid, $d, $_FILES['foto'] ?? null, $u);
            aviso(campo('gravedad') === 'grave' ? 'Incidencia grave registrada. Le llegó un aviso al dueño.' : 'Incidencia registrada.');
        } elseif ($accion === 'actualizar') {
            $d = ['estado' => campo('estado'), 'gravedad' => campo('gravedad'), 'resolucion' => campo('resolucion'), 'responsable' => campo('responsable')];
            if ($conDinero) $d += ['costo' => campo('costo'), 'descuento' => campo('descuento'), 'compra_id' => (int) ($_POST['compra_id'] ?? 0)];
            $inc->actualizar($nid, (int) ($_POST['incidencia_id'] ?? 0), $d, $u, $conDinero);
            aviso(campo('estado') === 'resuelta' ? 'Incidencia resuelta. 👍' : 'Cambios guardados.');
        } else {
            throw new RuntimeException('Acción no válida.');
        }
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
    }
    $volver();
}

$estado = (string) ($_GET['estado'] ?? 'abiertas');
$filtros = ['estado' => $estado === 'todas' ? '' : $estado, 'evento_id' => (int) ($_GET['evento'] ?? 0), 'proveedor_id' => (int) ($_GET['proveedor'] ?? 0)];
$st = db()->prepare("SELECT id, codigo, nombre, fecha FROM eventos WHERE negocio_id = ? AND estado NOT IN ('perdido','cancelado')
                        AND fecha BETWEEN CURRENT_DATE - 120 AND CURRENT_DATE + 365 ORDER BY abs(fecha - CURRENT_DATE), fecha LIMIT 120");
$st->execute([$nid]);
$eventosSel = $st->fetchAll();
usort($eventosSel, fn($a, $b) => $a['fecha'] <=> $b['fecha']);
$st = db()->prepare('SELECT id, nombre FROM proveedores WHERE negocio_id = ? AND activo ORDER BY nombre');
$st->execute([$nid]);
$provs = $st->fetchAll();
$comprasSel = $conDinero ? array_values(array_filter((new Compras(db()))->lista($nid, ['estado' => 'activas', 'limite' => 300]), fn($c) => $c['saldo'] > 0)) : [];
vista('incidencias', [
    'lista' => $inc->lista($nid, $filtros), 'estadoF' => $estado, 'filtros' => $filtros, 'eventosSel' => $eventosSel, 'provs' => $provs, 'comprasSel' => $comprasSel,
    'conDinero' => $conDinero, 'editable' => puede_editar($u),
    'pre' => ['evento' => (int) ($_GET['evento'] ?? 0), 'proveedor' => (int) ($_GET['proveedor'] ?? 0)],
], 'Incidencias · TuRenta');
