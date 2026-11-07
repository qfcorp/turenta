<?php
/** Hoja del evento (orden de servicio para el equipo): /?r=pdf&tipo=hoja&id=<evento>. Recibe $u, $negocio, $id. */
use TuRenta\{DiaEvento, Pdf, PdfDia};

$dia = new DiaEvento(db());
$h = $dia->hoja((int) $u['negocio_id'], $id);
$h['enlace_vivo'] = url_completa('vivo', ['t' => $h['evento']['token_vivo']]);
$h['formulario'] = (new \TuRenta\Cuestionarios(db()))->respondidas((int) $u['negocio_id'], $id);   // formulario del evento (070)
Pdf::enviar(PdfDia::hoja($h), 'hoja-' . $h['evento']['codigo'] . '.pdf');
