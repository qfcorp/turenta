<?php
/** Plano del salón: /?r=pdf&tipo=plano&id=<plano>. Recibe $u, $negocio, $id. */
use TuRenta\{Eventos, Pdf, PdfPlano, Planos};

$planos = new Planos(db());
$p = $planos->plano((int) $u['negocio_id'], $id);   // de otro negocio: "No se encontró el plano."
$ev = $p['tipo'] === 'evento' ? (new Eventos(db()))->obtener((int) $u['negocio_id'], (int) $p['evento_id']) : null;
Pdf::enviar(PdfPlano::plano($negocio, $planos->datos((int) $u['negocio_id'], $id), $ev),
            'plano-' . ($ev ? $ev['codigo'] : preg_replace('/[^A-Za-z0-9]+/', '-', $p['nombre'])) . '.pdf');
