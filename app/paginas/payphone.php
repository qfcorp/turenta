<?php
use TuRenta\Pagos;

/**
 * Payphone devuelve aquí al cliente después de pagar con tarjeta (público):
 *   /?r=payphone&id=...&clientTransactionId=...
 * Se confirma con Payphone y se vuelve al portal del cliente con el resultado.
 */
$id = (int) ($_GET['id'] ?? 0);
$tx = (string) ($_GET['clientTransactionId'] ?? '');
try {
    [$estado, $token] = (new Pagos(db()))->confirmarPayphone($id, $tx);
    aviso(match ($estado) {
        'aprobado'  => '¡Pago con tarjeta aprobado! Ya se sumó a tus pagos.',
        'cancelado' => 'Cancelaste el pago con tarjeta. Puedes intentarlo de nuevo o pagar por transferencia.',
        default     => 'El pago con tarjeta no se aprobó. Puedes intentarlo de nuevo o pagar por transferencia.',
    }, $estado === 'aprobado' ? 'ok' : 'error');
    header('Location: ' . url('portal', ['t' => $token]) . ($estado === 'aprobado' ? '#pagos' : '#pagar'));
    exit;
} catch (RuntimeException $e) {
    http_response_code(400);
    vista_publica('error_publico', ['mensaje' => 'No reconocimos este pago. Si se te cobró, escríbele al local con la captura de Payphone para que lo revisen.'],
                  'Pago · TuRenta');
}
