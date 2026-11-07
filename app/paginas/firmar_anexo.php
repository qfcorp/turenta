<?php
use TuRenta\{Anexos, Eventos, Pdf, PdfDocumentos, WhatsApp};

/**
 * Firma de un anexo de contrato (pública, por enlace secreto): /?r=firmar_anexo&t=<token>   PDF: &pdf=1
 * Mismo flujo que el contrato: el cliente lee el anexo, escribe su nombre y cédula, firma con el dedo y acepta.
 * (Anexos por extras aprobados, migración 090.)
 */
$token = (string) ($_GET['t'] ?? '');
$anexos = new Anexos(db());
$k = $anexos->porToken($token);
if (!$k) {
    http_response_code(404);
    vista_publica('error_publico', ['mensaje' => 'Este enlace de firma no existe o está mal copiado. Pide al local que te lo vuelva a enviar.'], 'Firma del anexo · TuRenta');
    return;
}
$st = db()->prepare('SELECT * FROM negocios WHERE id = ?');
$st->execute([$k['negocio_id']]);
$negocio = $st->fetch();
$marca = ['nombre' => $negocio['nombre'], 'color' => $negocio['color'], 'slug' => $negocio['slug'], 'logo' => $negocio['logo']];

if (!empty($_GET['pdf'])) {
    Pdf::enviar(PdfDocumentos::contrato($negocio, $k, url_completa('verificar', ['c' => $k['codigo_verificacion']])), PdfDocumentos::nombreArchivo('anexo', $k));
    return;
}

$error = null;
$formulario = ['nombre' => $k['cliente'], 'identificacion' => (string) $k['cliente_identificacion']];
if (es_post()) {
    verificar_csrf();
    $formulario = ['nombre' => trim((string) ($_POST['nombre'] ?? '')), 'identificacion' => trim((string) ($_POST['identificacion'] ?? ''))];
    try {
        $anexos->firmar($token, $formulario['nombre'], $formulario['identificacion'], (string) ($_POST['firma'] ?? ''), !empty($_POST['acepto']),
                        ip_visitante(), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        aviso('¡Gracias! Tu anexo quedó firmado. Descarga tu copia en PDF.');
        header('Location: ' . url('firmar_anexo', ['t' => $token]));
        exit;
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
        $k = $anexos->porToken($token);
    }
}

$ev = (new Eventos(db()))->obtener((int) $k['negocio_id'], (int) $k['evento_id']);
$nombre = trim(explode(' ', trim((string) $k['cliente']))[0]);
$waDudas = WhatsApp::enlace($negocio['whatsapp'] ?: $negocio['telefono'], "Hola, soy $nombre. Tengo una duda sobre el {$k['titulo']} de {$k['evento']}.");
$portal = url_completa('portal', ['t' => $k['token_cliente']]);
vista_publica('firmar_anexo', compact('k', 'negocio', 'ev', 'error', 'formulario', 'waDudas', 'token', 'portal'), 'Firma del anexo · ' . $negocio['nombre'], $marca);
