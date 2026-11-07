<?php
/** Estado de cuenta con un proveedor: /?r=pdf&tipo=estado_proveedor&id=<proveedor>. Recibe $u, $negocio, $id. */
use TuRenta\{Pdf, PdfCompras};

exigir($u['rol'] !== 'coordinador', 'El estado de cuenta del proveedor lo ven el dueño, los vendedores y el usuario de consulta.');
Pdf::enviar((new PdfCompras(db()))->estadoCuenta((int) $u['negocio_id'], $id), 'estado-de-cuenta-proveedor-' . $id . '.pdf');
