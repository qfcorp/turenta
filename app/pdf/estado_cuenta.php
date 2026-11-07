<?php
/**
 * Estado de cuenta del evento: /?r=pdf&tipo=estado_cuenta&id=<evento_id>
 * Recibe $u, $negocio e $id desde app/paginas/pdf.php.
 */
exigir($u['rol'] !== 'coordinador', 'El estado de cuenta lo ven el dueño, los vendedores y el usuario de consulta.');
$pdf = (new \TuRenta\PdfCobros(db()))->estadoCuenta((int) $u['negocio_id'], $id);
$st = db()->prepare('SELECT codigo FROM eventos WHERE id = ? AND negocio_id = ?');
$st->execute([$id, $u['negocio_id']]);
\TuRenta\Pdf::enviar($pdf, 'estado-de-cuenta-' . $st->fetchColumn() . '.pdf');
