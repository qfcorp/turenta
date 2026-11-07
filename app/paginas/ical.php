<?php
use TuRenta\Reportes;

/**
 * Calendario para Google Calendar / iPhone: /?r=ical&t=<token_ical del negocio>
 * Enlace secreto (se cambia en Configuración → Google Calendar). Sin montos de dinero.
 */
$rep = new Reportes(db());
$negocio = $rep->negocioPorTokenIcal((string) ($_GET['t'] ?? ''));
if (!$negocio) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Este enlace de calendario no existe o fue cambiado.\nPide el enlace nuevo en TuRenta -> Configuracion -> Google Calendar.\n";
    return;
}
$base = rtrim((string) (getenv('TURENTA_URL') ?: ''), '/') ?: preg_replace('#/\?.*$#', '', url_completa('inicio'));
$ics = $rep->calendarioIcs((int) $negocio['id'], $base);
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="turenta.ics"');
header('Cache-Control: private, max-age=900');
echo $ics;
