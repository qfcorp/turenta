<?php
use TuRenta\{Eventos, Pagos, Cobranza, WhatsApp};

/**
 * Pagos del evento: /?r=evento_pagos&id=<evento>
 * Resumen, plan de cuotas, pagos, comprobantes por revisar, garantía, gastos y ganancia.
 * Solo el dueño y los vendedores registran o anulan (el usuario de consulta solo mira).
 */
$nid = (int) $u['negocio_id'];
$id = (int) ($_GET['id'] ?? 0);
$eventos = new Eventos(db());
$ev = $eventos->obtener($nid, $id);
if (!$ev) {
    http_response_code(404);
    vista('error', ['mensaje' => 'No se encontró el evento. Puede que lo hayan borrado o que sea de otro negocio.'], 'Pagos · TuRenta');
    return;
}
$pagos = new Pagos(db());
$cob = new Cobranza(db());
$puede = es_comercial($u);

if (es_post()) {
    verificar_csrf();
    exigir($puede, 'Solo el dueño y los vendedores pueden registrar o cambiar pagos.');
    $accion = (string) ($_GET['accion'] ?? '');
    $ancla = '';
    try {
        switch ($accion) {
            case 'pago':
                $antes = $ev['estado'];
                $pid = $pagos->registrar($nid, $id, [
                    'monto' => numero($_POST['monto'] ?? '') ?? 0, 'metodo' => campo('metodo'), 'fecha' => campo('fecha'),
                    'referencia' => campo('referencia'), 'notas' => campo('notas'), 'tipo' => 'evento',
                ], $u, $_FILES['foto'] ?? null);
                $msg = $cob->mensajeSeparacion($nid, $id, $antes);
                aviso('Pago registrado (recibo N.º ' . (int) db()->query('SELECT numero_recibo FROM pagos WHERE id = ' . $pid)->fetchColumn() . ').'
                    . ($msg ? ' ' . $msg : ''), $msg && str_contains($msg, 'no se pudo') ? 'error' : 'ok');
                $_SESSION['ultimo_pago'] = $pid;
                $ancla = 'pagos';
                break;
            case 'confirmar':
                $msg = $pagos->confirmar($nid, (int) ($_POST['pago_id'] ?? 0), $u, campo('referencia') ?: null);
                aviso('Comprobante confirmado: ya suma a lo pagado.' . ($msg ? ' ' . $msg : ''), $msg && str_contains($msg, 'no se pudo') ? 'error' : 'ok');
                $ancla = 'pagos';
                break;
            case 'rechazar':
                $pagos->rechazar($nid, (int) ($_POST['pago_id'] ?? 0), campo('motivo'), $u);
                aviso('Comprobante rechazado. Avísale al cliente para que suba otro o te escriba.');
                $ancla = 'revisar';
                break;
            case 'anular':
                $pagos->anular($nid, (int) ($_POST['pago_id'] ?? 0), campo('motivo'), $u);
                aviso('Pago anulado. Las cuotas se recalcularon.');
                $ancla = 'pagos';
                break;
            case 'regenerar':
                $pagos->generarPlan($nid, $id, (int) ($_POST['cuotas'] ?? 3), numero($_POST['reserva_pct'] ?? '') ?? null);
                aviso('Plan de pagos armado de nuevo. Lo que ya pagó el cliente se volvió a repartir.');
                $ancla = 'plan';
                break;
            case 'plan':
                $filas = [];
                foreach ((array) ($_POST['concepto'] ?? []) as $i => $concepto) {
                    $filas[] = ['concepto' => (string) $concepto, 'vence' => (string) ($_POST['vence'][$i] ?? ''),
                                'monto' => (string) (numero($_POST['monto_cuota'][$i] ?? '') ?? '')];
                }
                $pagos->guardarPlan($nid, $id, $filas);
                registrar_cambio($nid, $id, 'Cambió el plan de pagos a mano (' . count(array_filter($filas, fn($f) => $f['monto'] !== '')) . ' pagos)', $u);
                aviso('Plan de pagos guardado.');
                $ancla = 'plan';
                break;
            case 'garantia_monto':
                $cob->cambiarGarantia($nid, $id, numero($_POST['garantia'] ?? '') ?? 0, $u);
                aviso('Valor de la garantía guardado.');
                $ancla = 'garantia';
                break;
            case 'garantia_recibir':
            case 'garantia_devolver':
            case 'garantia_retener':
                $tipo = ['garantia_recibir' => 'garantia', 'garantia_devolver' => 'devolucion_garantia', 'garantia_retener' => 'cargo_danos'][$accion];
                if ($tipo === 'cargo_danos' && mb_strlen(campo('notas')) < 3) throw new RuntimeException('Escribe qué se dañó (por ejemplo: "3 copas rotas").');
                $pagos->registrar($nid, $id, ['monto' => numero($_POST['monto'] ?? '') ?? 0, 'metodo' => campo('metodo', 'efectivo'), 'fecha' => campo('fecha'),
                                              'referencia' => campo('referencia'), 'notas' => campo('notas'), 'tipo' => $tipo], $u);
                aviso(match ($tipo) { 'garantia' => 'Garantía recibida.', 'devolucion_garantia' => 'Devolución de la garantía registrada.', default => 'Se registró lo retenido por daños.' });
                $ancla = 'garantia';
                break;
            case 'gasto':
                $cob->registrarGasto($nid, $id, ['concepto' => campo('concepto'), 'monto' => numero($_POST['monto'] ?? '') ?? 0, 'categoria' => campo('categoria'),
                                                 'fecha' => campo('fecha'), 'proveedor_id' => (int) ($_POST['proveedor_id'] ?? 0)], $u);
                aviso('Gasto registrado.');
                $ancla = 'gastos';
                break;
            case 'gasto_borrar':
                $cob->borrarGasto($nid, (int) ($_POST['gasto_id'] ?? 0), $u);
                aviso('Gasto borrado.');
                $ancla = 'gastos';
                break;
            default:
                throw new RuntimeException('Acción no válida.');
        }
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
    }
    header('Location: ' . url('evento_pagos', ['id' => $id]) . ($ancla ? "#$ancla" : ''));
    exit;
}

$res = $pagos->resumen($id);
$lista = array_values(array_filter($pagos->pagos($nid, $id), fn($p) => $p['estado'] !== 'pendiente'));
$porRevisar = array_values(array_filter($lista, fn($p) => $p['estado'] === 'por_revisar'));
$ganancia = $cob->ganancia($nid, $id);
$gastos = $cob->gastos($nid, $id);
$proveedores = $cob->proveedores($nid);
$portal = url_completa('portal', ['t' => $ev['token_cliente']]);
$recordatorio = null;
if ($res['proxima'] && in_array($ev['estado'], ['cotizado', 'separado', 'confirmado', 'realizado'], true)) {
    $texto = Pagos::textoRecordatorio($ev['cliente'], $u['negocio'], $res['proxima']['concepto'], (float) $res['proxima']['saldo'], $res['proxima']['vence'], $portal);
    $recordatorio = WhatsApp::enlace($ev['cliente_telefono'], $texto);
}
$waPortal = WhatsApp::enlace($ev['cliente_telefono'], 'Hola ' . trim(explode(' ', trim($ev['cliente']))[0]) . ', te saludamos de ' . $u['negocio']
    . '. Este es tu portal del evento, donde ves tu plan de pagos, tus documentos y puedes subir tus comprobantes: ' . $portal);
$st = db()->prepare('SELECT reserva_pct FROM negocios WHERE id = ?');
$st->execute([$nid]);
$reservaPct = rtrim(rtrim((string) $st->fetchColumn(), '0'), '.');
$ultimoPago = (int) ($_SESSION['ultimo_pago'] ?? 0);
unset($_SESSION['ultimo_pago']);
$tab = 'evento_pagos';
$comprasEv = (new \TuRenta\Compras(db()))->deEvento($nid, $id);   // compras a proveedores del evento (060)
vista('evento_pagos', compact('ev', 'tab', 'res', 'lista', 'porRevisar', 'ganancia', 'gastos', 'proveedores', 'reservaPct', 'portal', 'recordatorio', 'waPortal', 'puede', 'ultimoPago', 'comprasEv'),
      'Pagos · ' . $ev['nombre'] . ' · TuRenta');
