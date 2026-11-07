<?php
use TuRenta\Encuestas;

/**
 * Encuesta de satisfacción (pública, con el enlace secreto de la encuesta): /?r=encuesta&t=<token_encuesta>
 *  - GET: la encuesta (o "gracias" si ya respondió). POST: guarda la respuesta (una sola por evento).
 *  - Al terminar: si recomienda con 9 o 10 y el local tiene enlace de reseñas, botón grande para dejar la reseña en Google.
 * Anti-abuso: campo trampa "sitio_web", una respuesta por evento y máximo 10 por hora desde la misma IP.
 */
$token = (string) ($_GET['t'] ?? '');
$enc = new Encuestas(db());
$ev = $enc->porToken($token);
if (!$ev) {
    http_response_code(404);
    vista_publica('error_publico', ['mensaje' => 'Este enlace no es válido. Revisa que esté completo o pide uno nuevo al local de tu evento.'], 'Encuesta · TuRenta');
    return;
}
$marca = ['nombre' => $ev['negocio'], 'color' => $ev['color'], 'slug' => $ev['slug'], 'logo' => $ev['logo']];

if (es_post()) {
    verificar_csrf();
    try {
        $enc->responder($token, $_POST, ip_visitante());
        $_SESSION['encuesta_gracias'] = $token;
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
        $_SESSION['encuesta_borrador'][$token] = array_intersect_key($_POST, array_flip(['nps', 'comida', 'atencion', 'instalaciones', 'organizacion', 'comentario']));
    }
    redirigir('encuesta', ['t' => $token]);
}

$respuesta = $enc->deEvento((int) $ev['negocio_id'], (int) $ev['id']);
$borrador = $_SESSION['encuesta_borrador'][$token] ?? [];
unset($_SESSION['encuesta_borrador'][$token]);
$recien = ($_SESSION['encuesta_gracias'] ?? '') === $token;
$primerNombre = trim(explode(' ', trim((string) $ev['cliente']))[0]);
vista_publica('encuesta', [
    'ev' => $ev, 'token' => $token, 'respuesta' => $respuesta, 'borrador' => $borrador, 'recien' => $recien,
    'cerrada' => $respuesta ? null : Encuestas::motivoCerrada($ev), 'primerNombre' => $primerNombre,
    'resenas' => trim((string) $ev['resenas_url']),
], '¿Cómo te fue? · ' . $ev['negocio'], $marca);
