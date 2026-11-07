<?php
/** Formulario del evento (respuestas del cliente): /?r=pdf&tipo=formulario&id=<evento>. Recibe $u, $negocio, $id. Ambos planes; también el coordinador. */
use TuRenta\{Cuestionarios, Eventos, Pdf, PdfFormulario};

$ev = (new Eventos(db()))->obtener((int) $u['negocio_id'], $id);
if (!$ev) throw new RuntimeException('No se encontró el evento.');
$cues = new Cuestionarios(db());
$form = $cues->deEvento((int) $u['negocio_id'], $id);
Pdf::enviar(PdfFormulario::documento($negocio, $ev, $form, $cues->respondidas((int) $u['negocio_id'], $id)), 'formulario-' . $ev['codigo'] . '.pdf');
