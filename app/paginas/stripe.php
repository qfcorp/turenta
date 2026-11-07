<?php
use TuRenta\Stripe;

/**
 * Stripe devuelve aquí al cliente después de pagar (público): /?r=stripe&session_id=cs_...
 * Se pregunta a Stripe por la sesión y se vuelve al portal del cliente con el resultado.
 * El webhook (/?r=stripe_webhook) confirma igual aunque el cliente cierre la página antes de volver.
 */
try {
    [$estado, $token] = (new Stripe(db()))->confirmarSesionPorId((string) ($_GET['session_id'] ?? ''));
    aviso(match ($estado) {
        'pagado'    => 'Payment received — thank you! Your receipt is on its way to your email.',
        'pendiente' => 'We are confirming your payment with the bank. This page updates in a few minutes.',
        default     => 'The card payment did not go through. Nothing was charged — you can try again.',
    }, $estado === 'no_pagado' ? 'error' : 'ok');
    header('Location: ' . url('portal', ['t' => $token]) . ($estado === 'pagado' ? '#payments' : '#pay'));
    exit;
} catch (RuntimeException $e) {
    http_response_code(400);
    vista_publica_en('reservar_no', [], 'Payment', null);
}
