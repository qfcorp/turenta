<?php
/** Lista de invitados por grupo con casilla de ingreso: /?r=pdf&tipo=invitados&id=<evento>. Recibe $u, $negocio, $id. */
use TuRenta\{DiaEvento, Invitados, Pdf, PdfDia};

$inv = new Invitados(db());
$ev = (new DiaEvento(db()))->evento((int) $u['negocio_id'], $id);
$nid = (int) $u['negocio_id'];
Pdf::enviar(PdfDia::invitados($negocio, $ev, $inv->lista($nid, $id, ['orden' => 'grupo']), $inv->contadores($nid, $id)), 'invitados-' . $ev['codigo'] . '.pdf');
