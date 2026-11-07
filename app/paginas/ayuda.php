<?php
use TuRenta\Ia;

/**
 * Ayuda (todos los planes, también el coordinador): «¿Qué necesita hacer?».
 * Responde con una pregunta frecuente guardada, con la IA y el manual (y queda guardada para todos) o con el manual tal cual.
 * POST con CSRF, máximo 10 preguntas por minuto por usuario. Nunca dice por qué no usó la IA.
 */
$nid = (int) $u['negocio_id'];
$pregunta = '';
$respuesta = null;
$error = null;
if (es_post()) {
    verificar_csrf();
    $pregunta = trim((string) preg_replace('/\s+/u', ' ', (string) ($_POST['pregunta'] ?? '')));
    $marcas = is_array($_SESSION['ayuda_marcas'] ?? null) ? $_SESSION['ayuda_marcas'] : [];
    if ($pregunta === '') {
        $error = 'Escriba su pregunta, por ejemplo: «¿Cómo registro un pago?».';
    } elseif (mb_strlen($pregunta) > Ia::MAX_PREGUNTA) {
        $error = 'La pregunta es muy larga: máximo ' . Ia::MAX_PREGUNTA . ' letras.';
    } elseif (!Ia::dentroDelRitmo($marcas)) {
        http_response_code(429);
        $error = 'Hizo muchas preguntas seguidas. Espere un minuto y vuelva a preguntar.';
    } else {
        $respuesta = ia()->ayuda($nid, $pregunta);
    }
    $_SESSION['ayuda_marcas'] = $marcas;
}
vista('ayuda', ['pregunta' => $pregunta, 'respuesta' => $respuesta, 'error' => $error], 'Ayuda · TuRenta');
