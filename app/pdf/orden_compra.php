<?php
/** Orden de compra (o pedido de precio) en PDF con QR para verificarla: /?r=pdf&tipo=orden_compra&id=<compra>. Recibe $u, $negocio, $id. */
use TuRenta\{Compras, Pdf, PdfCompras};

exigir($u['rol'] !== 'coordinador', 'Las órdenes de compra las ven el dueño, los vendedores y el usuario de consulta.');
$c = (new Compras(db()))->compra((int) $u['negocio_id'], $id);
if (!$c) throw new RuntimeException('No se encontró esa compra.');
$pdf = (new PdfCompras(db()))->orden((int) $u['negocio_id'], $id, url_completa('verificar_oc', ['c' => $c['codigo_verificacion']]));
Pdf::enviar($pdf, ($c['estado'] === 'cotizacion' ? 'pedido-de-precio-' : 'orden-de-compra-') . $c['codigo'] . '.pdf');
