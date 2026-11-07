<?php
use TuRenta\Ventas;

/**
 * Página pública del negocio (para compartir en Instagram y WhatsApp): /?r=sitio&s=<slug>
 * Logo, descripción, ubicación, redes, espacios con foto y capacidad, tipos de evento,
 * "¿Está libre mi fecha?" y formulario de consulta (va a /?r=consulta).
 */
$ventas = new Ventas(db());
$slug = (string) ($_GET['s'] ?? '');
$neg = $ventas->negocioPorSlug($slug);
if (!$neg || !planes()->alDia($neg)) {
    http_response_code(404);
    vista_publica('sitio_no', [], 'Página no disponible', null);
    return;
}
$marca = ['nombre' => $neg['nombre'], 'color' => $neg['color'], 'slug' => $neg['slug'], 'logo' => $neg['logo']];
// Lo que el visitante escribió y un error de la consulta (si volvió desde /?r=consulta)
$previo = $_SESSION['consulta_web'][$neg['id']] ?? null;
unset($_SESSION['consulta_web'][$neg['id']]);

vista_publica('sitio', [
    'neg' => $neg, 'espacios' => $ventas->espaciosPublicos((int) $neg['id']),
    'd' => $previo['datos'] ?? [], 'error' => $previo['error'] ?? null,
    'fechaInicial' => Ventas::fechaValida($_GET['fecha'] ?? null) ? (string) $_GET['fecha'] : '',
    'indexar' => true,
], $neg['nombre'] . ' · ' . $neg['ciudad'] . ' — Salón de eventos', $marca);
