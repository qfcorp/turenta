<?php
use TuRenta\{Pagos, Cobranza};

/**
 * Tablero de cobranza del negocio: /?r=cobros
 * Comprobantes por revisar, cuotas vencidas y por vencer, cobrado del mes, por cobrar y garantías por devolver.
 * Solo el dueño, los vendedores y el usuario de consulta (este último solo mira).
 */
exigir($u['rol'] !== 'coordinador', 'Los cobros los ven el dueño y los vendedores.');
$nid = (int) $u['negocio_id'];
$pagos = new Pagos(db());
$cob = new Cobranza(db());
$puede = es_comercial($u);

if (es_post()) {
    verificar_csrf();
    exigir($puede, 'Solo el dueño y los vendedores pueden registrar o confirmar pagos.');
    $accion = (string) ($_GET['accion'] ?? '');
    try {
        if ($accion === 'pago') {
            $eventoId = (int) ($_POST['evento_id'] ?? 0);
            $st = db()->prepare('SELECT estado, codigo FROM eventos WHERE id = ? AND negocio_id = ?');
            $st->execute([$eventoId, $nid]);
            $evRow = $st->fetch();
            if (!$evRow) throw new RuntimeException('No se encontró el evento.');
            $pid = $pagos->registrar($nid, $eventoId, ['monto' => numero($_POST['monto'] ?? '') ?? 0, 'metodo' => campo('metodo'),
                                                       'referencia' => campo('referencia'), 'tipo' => 'evento'], $u);
            $msg = $cob->mensajeSeparacion($nid, $eventoId, $evRow['estado']);
            aviso('Pago de ' . $evRow['codigo'] . ' registrado (recibo N.º ' . (int) db()->query('SELECT numero_recibo FROM pagos WHERE id = ' . $pid)->fetchColumn() . ').'
                . ($msg ? ' ' . $msg : ''), $msg && str_contains($msg, 'no se pudo') ? 'error' : 'ok');
        } elseif ($accion === 'confirmar') {
            $msg = $pagos->confirmar($nid, (int) ($_POST['pago_id'] ?? 0), $u);
            aviso('Comprobante confirmado: ya suma a lo pagado.' . ($msg ? ' ' . $msg : ''), $msg && str_contains($msg, 'no se pudo') ? 'error' : 'ok');
        } elseif ($accion === 'rechazar') {
            $pagos->rechazar($nid, (int) ($_POST['pago_id'] ?? 0), campo('motivo'), $u);
            aviso('Comprobante rechazado.');
        } else {
            throw new RuntimeException('Acción no válida.');
        }
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
    }
    redirigir('cobros');
}

$t = $cob->tablero($nid);
$filtro = (int) ($_GET['dias'] ?? 30);
if (!in_array($filtro, [7, 15, 30], true)) $filtro = 30;
$porVencer = array_values(array_filter($t['por_vencer'], fn($c) => $c['dias'] <= $filtro));
$mensajesHoy = $cob->cuantosMensajes($nid);
vista('cobros', compact('t', 'filtro', 'porVencer', 'puede', 'mensajesHoy'), 'Cobros · TuRenta');
