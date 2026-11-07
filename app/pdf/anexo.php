<?php
/** PDF de un anexo de contrato: /?r=pdf&tipo=anexo&id=<id del anexo>. Recibe $u, $negocio e $id. Solo dueño y vendedor (como el contrato). */
use TuRenta\{Anexos, Pdf, PdfDocumentos};

exigir(es_comercial($u), 'Los anexos de contrato los ve el dueño o el vendedor.');
$a = (new Anexos(db()))->obtener((int) $u['negocio_id'], $id);
if (!$a) throw new RuntimeException('No se encontró el anexo.');
Pdf::enviar(PdfDocumentos::contrato($negocio, $a, url_completa('verificar', ['c' => $a['codigo_verificacion']])), PdfDocumentos::nombreArchivo('anexo', $a));
