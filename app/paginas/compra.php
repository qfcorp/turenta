<?php
use TuRenta\{Adjuntos, Compras, DiaEvento, Incidencias};

/**
 * Una compra: /?r=compra&id=<id>
 * Estado (pedida → confirmada → entregada, o cancelada), ítems, condiciones (fecha límite, anticipo, retención),
 * factura del proveedor (a mano, PDF/foto o XML del SRI), pagos (con anulación) y su historia.
 * Cambiar: dueño y vendedor. Consulta solo mira. Coordinador no entra.
 */
$nid = (int) $u['negocio_id'];
$id = (int) ($_GET['id'] ?? 0);
$compras = new Compras(db());
$c = $compras->compra($nid, $id);
if (!$c) {
    http_response_code(404);
    vista('error', ['mensaje' => 'No se encontró esa compra. Puede que sea de otro negocio.'], 'Compra · TuRenta');
    return;
}
$puede = es_comercial($u);

if (es_post()) {
    verificar_csrf();
    exigir($puede, 'Solo el dueño y los vendedores pueden cambiar compras y registrar pagos.');
    $accion = (string) ($_POST['accion'] ?? '');
    $ancla = '';
    try {
        switch ($accion) {
            case 'estado':
                $nuevo = campo('nuevo');
                $msg = $compras->cambiarEstado($nid, $id, $nuevo, $u, campo('motivo'));
                aviso(match ($nuevo) {
                    'pedida' => 'Listo: quedó como pedida. Envíale la orden al proveedor.',
                    'confirmada' => 'Compra confirmada.' . ($c['evento_id'] ? ' El proveedor quedó confirmado en el día del evento.' : ''),
                    'entregada' => '¡Bien! El proveedor cumplió con la entrega.',
                    'cancelada' => 'Compra cancelada. Ya no suma a la deuda ni al costo del evento.',
                    default => 'Listo.',
                });
                if ($msg) aviso($msg, 'error');
                break;
            case 'items':
                $items = [];
                foreach ((array) ($_POST['items'] ?? []) as $it) {
                    if (is_array($it)) $items[] = ['descripcion' => (string) ($it['descripcion'] ?? ''), 'cantidad' => (string) ($it['cantidad'] ?? ''),
                                                   'precio_unitario' => (string) ($it['precio_unitario'] ?? ''), 'con_iva' => ($it['con_iva'] ?? '1') === '1'];
                }
                $compras->guardarItems($nid, $id, $items, $u);
                aviso('Detalle guardado.');
                $ancla = 'detalle';
                break;
            case 'editar':
                $compras->editar($nid, $id, ['evento_id' => (int) ($_POST['evento_id'] ?? 0), 'categoria' => campo('categoria'), 'fecha' => campo('fecha'),
                    'vence' => campo('vence'), 'anticipo' => campo('anticipo'), 'retencion' => campo('retencion'), 'notas' => campo('notas')], $u);
                aviso('Cambios guardados.');
                $ancla = 'condiciones';
                break;
            case 'factura':
                $compras->registrarFactura($nid, $id, ['numero' => campo('factura_numero'), 'fecha' => campo('factura_fecha'), 'total' => campo('factura_total')],
                                           $_FILES['archivo'] ?? null, $u);
                aviso('Factura guardada.');
                $ancla = 'factura';
                break;
            case 'xml':
                $arch = $_FILES['xml'] ?? null;
                if (!Adjuntos::llego($arch)) throw new RuntimeException('Elige el archivo XML de la factura.');
                if (($arch['error'] ?? 0) !== UPLOAD_ERR_OK || Adjuntos::tipo($arch) !== 'xml') throw new RuntimeException('El archivo debe ser el XML de la factura electrónica.');
                if (filesize($arch['tmp_name']) > Adjuntos::MAX_XML) throw new RuntimeException('El XML pesa demasiado. Debe ser el de una sola factura.');
                $avisos = $compras->importarXml($nid, $id, (string) file_get_contents($arch['tmp_name']), $u);
                aviso('Factura electrónica leída: ' . $compras->compra($nid, $id)['factura_numero'] . '.');
                foreach ($avisos as $a) aviso($a, 'error');
                $ancla = 'factura';
                break;
            case 'pago':
                $compras->registrarPago($nid, $id, ['monto' => campo('monto'), 'fecha' => campo('fecha'), 'metodo' => campo('metodo'), 'referencia' => campo('referencia'),
                                                    'cheque_fecha' => campo('metodo') === 'cheque' ? campo('cheque_fecha') : '', 'notas' => campo('notas')],
                                        $_FILES['comprobante'] ?? null, $u);
                $saldo = $compras->compra($nid, $id)['saldo'];
                aviso('Pago registrado. ' . ($saldo > 0 ? 'Saldo: ' . dinero($saldo) . '.' : '¡Compra pagada por completo!'));
                $ancla = 'pagos';
                break;
            case 'anular_pago':
                $compras->anularPago($nid, (int) ($_POST['pago_id'] ?? 0), campo('motivo'), $u);
                aviso('Pago anulado. Queda en la lista como anulado.');
                $ancla = 'pagos';
                break;
            default:
                throw new RuntimeException('Acción no válida.');
        }
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
    }
    header('Location: ' . url('compra', ['id' => $id]) . ($ancla ? "#$ancla" : ''));
    exit;
}

$st = db()->prepare('SELECT * FROM negocios WHERE id = ?');
$st->execute([$nid]);
$negocio = $st->fetch();
$items = $compras->items($id);
$portal = DiaEvento::publicoActivo(db(), $negocio, 'portal_proveedor') ? url_completa('portal_proveedor', ['t' => $c['proveedor_token']]) : null;
$st = db()->prepare("SELECT id, codigo, nombre, fecha FROM eventos WHERE negocio_id = ? AND estado NOT IN ('perdido','cancelado') AND (fecha >= CURRENT_DATE - 60 OR id = ?) ORDER BY fecha LIMIT 300");
$st->execute([$nid, (int) $c['evento_id']]);
$st2 = db()->prepare('SELECT m.*, u.nombre AS usuario FROM proveedor_movimientos m LEFT JOIN usuarios u ON u.id = m.usuario_id WHERE m.negocio_id = ? AND m.compra_id = ? ORDER BY m.creado_en DESC, m.id DESC');
$st2->execute([$nid, $id]);
vista('compra', [
    'c' => $c, 'items' => $items, 'pagos' => $compras->pagos($nid, $id), 'negocio' => $negocio, 'puede' => $puede, 'eventosSel' => $st->fetchAll(),
    'historia' => $st2->fetchAll(), 'incidenciasCompra' => array_values(array_filter((new Incidencias(db()))->lista($nid, ['proveedor_id' => (int) $c['proveedor_id']]), fn($i) => (int) $i['compra_id'] === $id)),
    'wa' => DiaEvento::wa($c['proveedor_telefono'], Compras::mensajeOrden($c, $items, $negocio, $portal)),
    'portal' => $portal,
], $c['codigo'] . ' · ' . $c['proveedor'] . ' · TuRenta');
