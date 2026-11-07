<?php
/** Plano de mesas: /?r=pdf&tipo=mesas&id=<evento>. Recibe $u, $negocio, $id. */
use TuRenta\{DiaEvento, Invitados, Pdf, PdfDia};

$ev = (new DiaEvento(db()))->evento((int) $u['negocio_id'], $id);
Pdf::enviar(PdfDia::mesas($negocio, $ev, (new Invitados(db()))->plano((int) $u['negocio_id'], $id)), 'mesas-' . $ev['codigo'] . '.pdf');
