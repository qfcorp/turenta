<?php
use TuRenta\Telegram;

/**
 * Webhook de Telegram: el bot llama aquí cuando alguien le escribe.
 * Solo se acepta si trae el texto secreto configurado (TELEGRAM_WEBHOOK_SECRET).
 */
header('Content-Type: application/json');
$secreto = (string) getenv('TELEGRAM_WEBHOOK_SECRET');
$recibido = (string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
if (!es_post() || $secreto === '' || !hash_equals($secreto, $recibido)) {
    http_response_code(403);
    echo '{"ok":false}';
    return;
}
$update = json_decode((string) file_get_contents('php://input'), true);
if (is_array($update)) {
    (new Telegram(db()))->procesar($update);
}
echo '{"ok":true}';
