<?php
use TuRenta\{Cotizaciones, Eventos, Notificaciones, Pdf, PdfDocumentos, WhatsApp};

/**
 * Cotización para el cliente (pública, por enlace secreto): /?r=cot&t=<token>   PDF: &pdf=1
 * La ve con la marca del negocio, la descarga y la acepta con su nombre. "Tengo dudas" abre el WhatsApp del negocio.
 */
$token = (string) ($_GET['t'] ?? '');
$cotz = new Cotizaciones(db());
$c = $cotz->porToken($token);
if (!$c) {
    http_response_code(404);
    vista_publica('error_publico', ['mensaje' => 'Este enlace de cotización no existe o está mal copiado. Pide al local que te lo vuelva a enviar.'], 'Cotización · TuRenta');
    return;
}
$st = db()->prepare('SELECT * FROM negocios WHERE id = ?');
$st->execute([$c['negocio_id']]);
$negocio = $st->fetch();
$marca = ['nombre' => $negocio['nombre'], 'color' => $negocio['color'], 'slug' => $negocio['slug'], 'logo' => $negocio['logo']];
$ev = (new Eventos(db()))->obtener((int) $c['negocio_id'], (int) $c['evento_id']);

if (!empty($_GET['pdf'])) {
    $pdf = PdfDocumentos::cotizacion($negocio, $c, url_completa('cot', ['t' => $c['token']]), (string) ($ev['espacios_texto'] ?? ''));
    Pdf::enviar($pdf, PdfDocumentos::nombreArchivo('cotizacion', $c));
    return;
}

if (es_post()) {
    verificar_csrf();
    try {
        if (empty($_POST['acepto'])) throw new RuntimeException('Marca la casilla para aceptar la cotización.');
        $nombre = trim((string) ($_POST['nombre'] ?? ''));
        $cotz->aceptar((int) $c['negocio_id'], (int) $c['id'], $nombre, ip_visitante());
        (new Notificaciones(db()))->alNegocio((int) $c['negocio_id'],
            "$nombre aceptó la cotización de {$c['evento_codigo']} ({$c['evento']}) por " . dinero($c['total']) . '. Ya puedes cobrar la reserva y preparar el contrato.',
            (int) $c['evento_id']);
        aviso('¡Listo! Aceptaste la cotización. El local te escribirá para separar la fecha con la reserva.');
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
    }
    header('Location: ' . url('cot', ['t' => $token]));
    exit;
}

$hoy = date('Y-m-d');
$vencida = $c['estado'] === 'vencida' || (in_array($c['estado'], ['borrador', 'enviada'], true) && $c['valida_hasta'] < $hoy);
$telNegocio = $negocio['whatsapp'] ?: $negocio['telefono'];
$nombreCliente = trim(explode(' ', trim((string) $c['cliente']))[0]);
$waDudas = WhatsApp::enlace($telNegocio, "Hola, soy $nombreCliente. Tengo unas dudas sobre la cotización N.º {$c['numero']} de {$c['evento']} (" . fecha_corta($c['evento_fecha']) . ').');
$waActualizar = WhatsApp::enlace($telNegocio, "Hola, soy $nombreCliente. Mi cotización N.º {$c['numero']} de {$c['evento']} ya venció. ¿Me pueden enviar una actualizada, por favor?");
$portal = url_completa('portal', ['t' => $c['token_cliente']]);
// Si fue reemplazada: ¿hay una versión más nueva enviada o aceptada? (su enlace lo manda el local)
$hayNueva = false;
if ($c['estado'] === 'reemplazada') {
    $st = db()->prepare("SELECT 1 FROM cotizaciones WHERE evento_id = ? AND version > ? AND estado IN ('enviada','aceptada') LIMIT 1");
    $st->execute([$c['evento_id'], $c['version']]);
    $hayNueva = (bool) $st->fetchColumn();
}

vista_publica('cot', compact('c', 'negocio', 'ev', 'vencida', 'waDudas', 'waActualizar', 'portal', 'token', 'hayNueva'),
    'Cotización · ' . $negocio['nombre'], $marca);
