<?php
use TuRenta\{Contratos, Eventos, Pdf, PdfDocumentos, WhatsApp};

/**
 * Firma del contrato (pública, por enlace secreto): /?r=firmar&t=<token>   PDF: &pdf=1
 * El cliente lee el contrato, escribe su nombre y cédula, firma con el dedo y acepta.
 */
$token = (string) ($_GET['t'] ?? '');
$con = new Contratos(db());
$k = $con->porToken($token);
if (!$k) {
    http_response_code(404);
    vista_publica('error_publico', ['mensaje' => 'Este enlace de firma no existe o está mal copiado. Pide al local que te lo vuelva a enviar.'], 'Firma del contrato · TuRenta');
    return;
}
$st = db()->prepare('SELECT * FROM negocios WHERE id = ?');
$st->execute([$k['negocio_id']]);
$negocio = $st->fetch();
$marca = ['nombre' => $negocio['nombre'], 'color' => $negocio['color'], 'slug' => $negocio['slug'], 'logo' => $negocio['logo']];

if (!empty($_GET['pdf'])) {
    $pdf = PdfDocumentos::contrato($negocio, $k, url_completa('verificar', ['c' => $k['codigo_verificacion']]));
    Pdf::enviar($pdf, PdfDocumentos::nombreArchivo('contrato', $k));
    return;
}

$error = null;
$formulario = ['nombre' => $k['cliente'], 'identificacion' => (string) $k['cliente_identificacion']];
if (es_post()) {
    verificar_csrf();
    $formulario = ['nombre' => trim((string) ($_POST['nombre'] ?? '')), 'identificacion' => trim((string) ($_POST['identificacion'] ?? ''))];
    try {
        $con->firmar($token, $formulario['nombre'], $formulario['identificacion'], (string) ($_POST['firma'] ?? ''), !empty($_POST['acepto']),
                     ip_visitante(), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        aviso('¡Gracias! Tu contrato quedó firmado. Descarga tu copia en PDF.');
        header('Location: ' . url('firmar', ['t' => $token]));
        exit;
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
        $k = $con->porToken($token);
    }
}

$ev = (new Eventos(db()))->obtener((int) $k['negocio_id'], (int) $k['evento_id']);
$tel = $negocio['whatsapp'] ?: $negocio['telefono'];
$nombre = trim(explode(' ', trim((string) $k['cliente']))[0]);
$waDudas = WhatsApp::enlace($tel, "Hola, soy $nombre. Tengo una duda sobre el contrato de {$k['evento']} (" . fecha_corta($k['evento_fecha']) . ').');
$portal = $ev ? url_completa('portal', ['t' => $ev['token_cliente']]) : null;

vista_publica('firmar', compact('k', 'negocio', 'ev', 'error', 'formulario', 'waDudas', 'token', 'portal'), 'Firma del contrato · ' . $negocio['nombre'], $marca);
