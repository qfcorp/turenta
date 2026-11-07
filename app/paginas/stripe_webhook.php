<?php
use TuRenta\Stripe;

/**
 * Aviso de Stripe (webhook) de un negocio: POST /?r=stripe_webhook&s=<slug>
 * En el panel de Stripe: Developers → Webhooks → Add endpoint con esta dirección y los eventos
 * checkout.session.completed, checkout.session.async_payment_succeeded y checkout.session.expired.
 * El secreto (whsec_…) se pega en Configuración → Stripe. Sin firma válida no se toca nada.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    return;
}
$cuerpo = (string) file_get_contents('php://input');
if (strlen($cuerpo) > 512 * 1024) {
    http_response_code(413);
    echo json_encode(['error' => 'Too large']);
    return;
}
try {
    $r = (new Stripe(db()))->webhook((string) ($_GET['s'] ?? ''), $cuerpo, (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''));
    echo json_encode(['ok' => true, 'resultado' => $r]);
} catch (RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
