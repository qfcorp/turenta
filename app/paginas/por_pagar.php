<?php
use TuRenta\{Compras, Reportes};

/**
 * Cuentas por pagar a proveedores: /?r=por_pagar[&evento=<id>][&csv=1]
 * Total adeudado, vencido, lo que vence esta semana, por proveedor, antigüedad de saldos, próximos pagos,
 * cheques posfechados por cobrar y proveedores sobre su cupo. Descarga para Excel.
 * Lo ven el dueño, el vendedor y la consulta (el coordinador no entra).
 */
exigir($u['rol'] !== 'coordinador', 'Las cuentas por pagar las ven el dueño y los vendedores.');
$nid = (int) $u['negocio_id'];
$compras = new Compras(db());
$hoy = new DateTimeImmutable('today');
$eventoId = (int) ($_GET['evento'] ?? 0) ?: null;
if ($eventoId) {
    $st = db()->prepare('SELECT id, codigo, nombre, fecha FROM eventos WHERE id = ? AND negocio_id = ?');
    $st->execute([$eventoId, $nid]);
    $evento = $st->fetch() ?: null;
    if (!$evento) $eventoId = null;
}
if (!empty($_GET['csv'])) {
    [$encabezados, $filas] = $compras->exportarPorPagar($nid, $eventoId, $hoy);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="por_pagar_' . $hoy->format('Y-m-d') . ($eventoId ? '_evento_' . $eventoId : '') . '.csv"');
    header('Cache-Control: private, no-store');
    echo Reportes::csv($encabezados, $filas);
    return;
}
$st = db()->prepare("SELECT DISTINCT e.id, e.codigo, e.nombre, e.fecha FROM eventos e JOIN compras c ON c.evento_id = e.id
                      WHERE e.negocio_id = ? AND c.estado IN ('pedida','confirmada','entregada') ORDER BY e.fecha DESC LIMIT 200");
$st->execute([$nid]);
vista('por_pagar', ['pp' => $compras->porPagar($nid, $eventoId, $hoy), 'eventoId' => $eventoId, 'evento' => $evento ?? null, 'eventosCompras' => $st->fetchAll(),
                    'hoy' => $hoy], 'Por pagar · TuRenta');
