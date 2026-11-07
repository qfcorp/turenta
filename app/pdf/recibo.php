<?php
/**
 * Recibo de un pago: /?r=pdf&tipo=recibo&id=<pago_id>
 * Recibe $u, $negocio e $id desde app/paginas/pdf.php.
 */
exigir($u['rol'] !== 'coordinador', 'Los recibos de pago los ven el dueño, los vendedores y el usuario de consulta.');
$pdf = (new \TuRenta\PdfCobros(db()))->recibo((int) $u['negocio_id'], $id);
$num = (int) db()->query('SELECT numero_recibo FROM pagos WHERE id = ' . (int) $id)->fetchColumn();
\TuRenta\Pdf::enviar($pdf, 'recibo-' . str_pad((string) $num, 5, '0', STR_PAD_LEFT) . '.pdf');
