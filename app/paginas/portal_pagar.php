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
