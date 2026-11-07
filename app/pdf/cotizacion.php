<?php
use TuRenta\{Cotizaciones, Eventos, Pdf, PdfDocumentos};

/**
 * PDF de una cotización: /?r=pdf&tipo=cotizacion&id=<id de la cotización>
 * Recibe $u, $negocio e $id (app/paginas/pdf.php). Solo dueño y vendedor.
 */
exigir(es_comercial($u), 'Las cotizaciones las ve el dueño o el vendedor.');
$cot = (new Cotizaciones(db()))->obtener((int) $u['negocio_id'], $id);
if (!$cot) throw new RuntimeException('No se encontró la cotización.');
$ev = (new Eventos(db()))->obtener((int) $u['negocio_id'], (int) $cot['evento_id']);
$pdf = PdfDocumentos::cotizacion($negocio, $cot, url_completa('cot', ['t' => $cot['token']]), (string) ($ev['espacios_texto'] ?? ''));
Pdf::enviar($pdf, PdfDocumentos::nombreArchivo('cotizacion', $cot));
