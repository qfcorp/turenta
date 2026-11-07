<?php
use TuRenta\{Compras, DiaEvento, PdfCompras, Pdf, ProveedoresFicha};

/**
 * Portal del proveedor (público, ?t=<token del proveedor>, sin contraseña; módulo portal_proveedor):
 * sus próximos eventos (dirección, mapa, hora de llegada, bloques del cronograma, coordinador), confirmar que va,
 * "Ya llegué" el día del evento, sus órdenes de compra (confirmar o rechazar, PDF), subir su factura (PDF, foto o XML),
 * y sus pagos y saldo. Todo queda en la bitácora con origen "proveedor".
 */
$ficha = new ProveedoresFicha(db());
$compras = new Compras(db());
$token = (string) ($_GET['t'] ?? '');
$p = $ficha->porToken($token);
$negocio = null;
if ($p) {
    $st = db()->prepare('SELECT * FROM negocios WHERE id = ?');
    $st->execute([$p['negocio_id']]);
    $negocio = $st->fetch();
}
if (!$p || !$p['activo'] || !DiaEvento::publicoActivo(db(), $negocio, 'portal_proveedor')) {
    http_response_code(404);
    vista_publica('error_publico', ['mensaje' => 'Este enlace no es válido. Pide uno nuevo al local que te contrató.'], 'Portal del proveedor', $negocio ? DiaEvento::marca($negocio) : null);
    return;
}

// PDF de una orden (solo las suyas)
if (!empty($_GET['oc'])) {
    $c = $compras->compra((int) $p['negocio_id'], (int) $_GET['oc']);
    if (!$c || (int) $c['proveedor_id'] !== (int) $p['id'] || $c['estado'] === 'cotizacion') {
        http_response_code(404);
        vista_publica('error_publico', ['mensaje' => 'No se encontró esa orden.'], 'Portal del proveedor', DiaEvento::marca($negocio));
        return;
    }
    Pdf::enviar((new PdfCompras(db()))->orden((int) $p['negocio_id'], (int) $c['id'], url_completa('verificar_oc', ['c' => $c['codigo_verificacion']])), 'orden-de-compra-' . $c['codigo'] . '.pdf');
    return;
}

if (es_post()) {
    verificar_csrf();
    $accion = (string) ($_POST['accion'] ?? '');
    try {
        switch ($accion) {
            case 'confirmar_evento':
                $ep = $ficha->portalConfirmarEvento($p, (int) ($_POST['ep_id'] ?? 0));
                aviso('¡Gracias! Confirmaste que vas a ' . $ep['evento'] . '.');
                break;
            case 'llegue':
                $pu = $ficha->portalLlegue($p, (int) ($_POST['ep_id'] ?? 0));
                aviso('Listo, avisamos que llegaste a las ' . date('H:i') . '.' . ($pu && $pu['estado'] === 'a_tiempo' ? ' ¡A tiempo!' : ''));
                break;
            case 'confirmar_oc':
                $c = $compras->responderDesdePortal($p, (int) ($_POST['compra_id'] ?? 0), true, campo('comentario'));
                aviso('Confirmaste la orden ' . $c['codigo'] . '. ¡Gracias!');
                break;
            case 'rechazar_oc':
                $c = $compras->responderDesdePortal($p, (int) ($_POST['compra_id'] ?? 0), false, campo('comentario'));
                aviso('Avisamos que no puedes con la orden ' . $c['codigo'] . '.');
                break;
            case 'factura':
                $c = $compras->facturaDesdePortal($p, (int) ($_POST['compra_id'] ?? 0), $_FILES['factura'] ?? [], campo('numero'));
                aviso('Factura recibida para la orden ' . $c['codigo'] . '. ¡Gracias!');
                break;
            default:
                throw new RuntimeException('Acción no válida.');
        }
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
    }
    redirigir('portal_proveedor', ['t' => $token]);
}

$ordenes = $compras->delPortal($p);
$pagos = array_values(array_filter($compras->pagosDeProveedor((int) $p['negocio_id'], (int) $p['id']), fn($x) => $x['estado'] === 'valido'));
$st = db()->prepare("SELECT nombre, telefono FROM usuarios WHERE negocio_id = ? AND rol = 'dueno' AND activo ORDER BY id LIMIT 1");
$st->execute([$p['negocio_id']]);
vista_publica('portal_proveedor', [
    'p' => $p, 'negocio' => $negocio, 'dueno' => $st->fetch() ?: null, 'token' => $token,
    'eventosP' => $ficha->eventosPortal($p), 'ordenes' => $ordenes, 'pagos' => $pagos,
    'saldo' => round(array_sum(array_map(fn($c) => $c['saldo'], $ordenes)), 2),
    'mapa' => DiaEvento::mapa($negocio),
], 'Portal del proveedor · ' . $negocio['nombre'], DiaEvento::marca($negocio));
