<?php
use TuRenta\{Contratos, Pdf, PdfDocumentos};

/**
 * PDF de un contrato: /?r=pdf&tipo=contrato&id=<id del contrato>
 * Recibe $u, $negocio e $id (app/paginas/pdf.php). Solo dueño y vendedor.
 */
exigir(es_comercial($u), 'Los contratos los ve el dueño o el vendedor.');
$c = (new Contratos(db()))->obtener((int) $u['negocio_id'], $id);
if (!$c) throw new RuntimeException('No se encontró el contrato.');
$pdf = PdfDocumentos::contrato($negocio, $c, url_completa('verificar', ['c' => $c['codigo_verificacion']]));
Pdf::enviar($pdf, PdfDocumentos::nombreArchivo('contrato', $c));
