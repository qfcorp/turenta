<?php
use TuRenta\{Eventos, Pagos};

/**
 * Pagos del cliente desde su portal (público): /?r=portal_pagar&t=<token_cliente>&accion=comprobante|tarjeta (POST)
 *  - comprobante: sube la foto de la transferencia o DeUna (queda por revisar).
 *  - tarjeta: prepara Payphone y muestra la cajita oficial de pago. Payphone devuelve a /?r=payphone.
 */
$token = (string) ($_GET['t'] ?? '');
$ev = (new Eventos(db()))->porToken('token_cliente', $token);
if (!$ev) {
    http_response_code(404);
    vista_publica('error_publico', ['mensaje' => 'Este enlace no es válido o ya no existe. Pide uno nuevo al local donde contrataste tu evento.'], 'Pagar · TuRenta');
    return;
}
if (!es_post()) {
    redirigir('portal', ['t' => $token]);
}
// TuRenta (migración 200): pago con tarjeta por Stripe Checkout (depósito o todo lo que falta). El valor lo calcula el servidor.
if (($_GET['accion'] ?? '') === 'stripe') {
    verificar_csrf_en();
    try {
        $m = (new \TuRenta\Reservas(db()))->montos((int) $ev['id']);
        $opcion = (string) ($_POST['opcion'] ?? 'total');
        $monto = $opcion === 'deposito' && $m['deposito'] > 0 ? $m['deposito'] : $m['total'];
        if ($monto <= 0) throw new RuntimeException('Nothing left to pay. Thank you!');
        $pagado = (new Pagos(db()))->resumen((int) $ev['id'])['pagado'];
        $concepto = $opcion === 'deposito' && $m['deposito'] > 0 && $m['deposito'] < $m['total'] ? 'Deposit' : ($pagado > 0 ? 'Balance' : 'Full payment');
        $urlStripe = (new \TuRenta\Stripe(db()))->crearCheckout((int) $ev['id'], $monto, $concepto, url_completa('stripe'),
                                                                url_completa('portal', ['t' => $token, 'pago' => 'cancelado']), $ev['cliente_email'] ?: null);
        header('Location: ' . $urlStripe, true, 303);
        exit;
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
        header('Location: ' . url('portal', ['t' => $token]) . '#pay');
        exit;
    }
}
verificar_csrf();
$st = db()->prepare('SELECT nombre, color, slug, logo FROM negocios WHERE id = ?');
$st->execute([$ev['negocio_id']]);
$marca = $st->fetch();
$pagos = new Pagos(db());
$accion = (string) ($_GET['accion'] ?? '');
$monto = numero($_POST['monto'] ?? '') ?? 0.0;

try {
    if ($accion === 'comprobante') {
        $pagos->subirComprobante((int) $ev['id'], $_FILES['comprobante'] ?? ['error' => UPLOAD_ERR_NO_FILE], $monto,
                                 (string) ($_POST['metodo'] ?? 'transferencia'), campo('referencia'));
        aviso('¡Recibimos tu comprobante! ' . $marca['nombre'] . ' lo revisará y, cuando lo confirme, se sumará a tus pagos.');
        header('Location: ' . url('portal', ['t' => $token]) . '#pagos');
        exit;
    }
    if ($accion === 'tarjeta') {
        if (in_array($ev['estado'], ['perdido', 'cancelado'], true)) throw new RuntimeException('Este evento ya no está activo.');
        $payphone = $pagos->prepararPayphone((int) $ev['id'], $monto);
        $payphone['responseUrl'] = url_completa('payphone');
        vista_publica('portal_pagar', ['ev' => $ev, 'token' => $token, 'payphone' => $payphone, 'marca' => $marca], 'Pagar con tarjeta · ' . $marca['nombre'], $marca);
        return;
    }
    throw new RuntimeException('Acción no válida.');
} catch (RuntimeException $e) {
    aviso($e->getMessage(), 'error');
    header('Location: ' . url('portal', ['t' => $token]) . '#pagar');
    exit;
}
