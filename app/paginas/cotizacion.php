<?php
use TuRenta\{Catalogo, Cotizaciones, Eventos, Notificaciones, WhatsApp};

/**
 * Cotización del evento: /?r=cotizacion&id=<evento>[&c=<cotización>][&comparar=1]
 * Versiones, crear (desde un paquete, vacía o copia de la anterior), editor de líneas con totales en vivo,
 * enviar (con enlace para el cliente y WhatsApp), PDF, aceptar en nombre del cliente, rechazar y comparar versiones.
 * Solo el dueño y los vendedores cambian algo; el usuario de consulta solo mira. Ganancia y costos: solo el dueño.
 */
$nid = (int) $u['negocio_id'];
$id = (int) ($_GET['id'] ?? 0);
$eventos = new Eventos(db());
$ev = $eventos->obtener($nid, $id);
if (!$ev) {
    http_response_code(404);
    vista('error', ['mensaje' => 'No se encontró el evento. Puede que lo hayan borrado o que sea de otro negocio.'], 'Cotización · TuRenta');
    return;
}
$cot = new Cotizaciones(db());
$cat = new Catalogo(db());
$puede = es_comercial($u);
$dueno = es_admin_total($u);

/** Las versiones anteriores que seguían abiertas quedan reemplazadas por la nueva (su enlace avisa al cliente). */
$reemplazarAnteriores = function (int $nuevaId) use ($id, $nid): void {
    db()->prepare("UPDATE cotizaciones SET estado = 'reemplazada' WHERE evento_id = ? AND negocio_id = ? AND id <> ? AND estado IN ('borrador','enviada')")
        ->execute([$id, $nid, $nuevaId]);
};
/** Cotización del evento (o null). */
$deEste = function (int $cid) use ($cot, $nid, $id): ?array {
    $c = $cot->obtener($nid, $cid);
    return ($c && (int) $c['evento_id'] === $id) ? $c : null;
};

/** Lee las líneas del editor y completa lo que el vendedor no ve (costos). */
$leerLineas = function (array $c) use ($dueno, $nid): array {
    $previas = [];
    foreach ($c['items'] as $i) $previas[(int) $i['id']] = $i;
    $costos = [];
    $st = db()->prepare('SELECT id, costo FROM productos WHERE negocio_id = ?');
    $st->execute([$nid]);
    foreach ($st->fetchAll() as $p) $costos[(int) $p['id']] = (float) $p['costo'];
    $res = [];
    foreach ((array) ($_POST['lineas'] ?? []) as $k => $l) {
        if (!is_array($l)) continue;
        $desc = trim((string) ($l['descripcion'] ?? ''));
        if ($desc === '') continue;
        $previa = $previas[(int) ($l['id'] ?? 0)] ?? null;
        $pid = (int) ($l['producto_id'] ?? 0) ?: null;
        if ($pid !== null && !isset($costos[$pid])) $pid = null;
        $costo = $dueno && isset($l['costo_unitario'])
            ? (numero($l['costo_unitario']) ?? 0.0)
            : ($previa ? (float) $previa['costo_unitario'] : ($pid ? $costos[$pid] : 0.0));
        $res[(string) $k] = [
            'producto_id' => $pid, 'categoria' => (string) ($l['categoria'] ?? 'otros'), 'descripcion' => $desc,
            'unidad' => (string) ($l['unidad'] ?? 'evento'), 'cantidad' => numero($l['cantidad'] ?? '') ?? 0.0,
            'precio_unitario' => numero($l['precio_unitario'] ?? '') ?? 0.0, 'costo_unitario' => $costo,
            'con_iva' => !empty($l['con_iva']),
        ];
    }
    return $res;
};

/** Mensaje listo para WhatsApp con el enlace de la cotización. */
$mensaje = function (array $c) use ($ev, $u): string {
    $nombre = trim(explode(' ', trim((string) $ev['cliente']))[0]);
    return "Hola $nombre, te comparto la cotización de " . $ev['nombre'] . ' (' . fecha_corta($ev['fecha']) . ') para ' . (int) $c['invitados']
         . ' invitados: ' . dinero($c['total']) . ' con IVA (' . dinero($c['por_persona']) . " por persona).\n\n"
         . 'Aquí puedes ver el detalle, descargarla en PDF y aceptarla: ' . url_completa('cot', ['t' => $c['token']]) . "\n\n"
         . 'Es válida hasta el ' . fecha_corta($c['valida_hasta']) . '. Cualquier duda, me escribes. ' . $u['nombre'] . ' · ' . $u['negocio'];
};

if (es_post()) {
    verificar_csrf();
    exigir($puede, 'Solo el dueño y los vendedores pueden cambiar cotizaciones.');
    $accion = (string) ($_GET['accion'] ?? '');
    $cid = (int) ($_POST['cotizacion_id'] ?? 0);
    $ir = ['id' => $id] + ($cid ? ['c' => $cid] : []);
    try {
        switch ($accion) {
            case 'crear':
                $modo = campo('modo', 'paquete');
                if ($modo === 'paquete' && !modulo_activo($u, 'paquetes')) $modo = 'vacia';   // Anexo B: sin el módulo de paquetes
                $paq = $modo === 'paquete' ? (int) ($_POST['paquete_id'] ?? 0) : 0;
                if ($modo === 'paquete' && !$paq) throw new RuntimeException('Elige el paquete.');
                $nueva = $cot->crear($nid, $id, $paq ?: null, (int) $u['id']);
                if ($modo === 'vacia') {
                    // En blanco: solo el alquiler de los espacios del evento
                    $st = db()->prepare('SELECT es.nombre, es.precio_base FROM evento_espacios ee JOIN espacios es ON es.id = ee.espacio_id WHERE ee.evento_id = ? ORDER BY es.orden');
                    $st->execute([$id]);
                    $lineas = array_map(fn($e) => ['categoria' => 'espacio', 'descripcion' => 'Alquiler de ' . $e['nombre'], 'unidad' => 'evento',
                                                   'cantidad' => 1, 'precio_unitario' => (float) $e['precio_base'], 'con_iva' => true], $st->fetchAll());
                    $cot->guardar($nid, $nueva, $lineas, ['descuento' => 0, 'notas' => '']);
                }
                $reemplazarAnteriores($nueva);
                $v = $cot->obtener($nid, $nueva);
                registrar_cambio($nid, $id, 'Preparó la cotización N.º ' . $v['numero'] . ' (versión ' . $v['version'] . ')');
                aviso(((int) $v['version'] > 1 ? 'Nueva versión lista' : 'Cotización lista') . '. Revisa las líneas y, cuando esté bien, envíala al cliente.');
                redirigir('cotizacion', ['id' => $id, 'c' => $nueva]);

            case 'guardar':
                $c = $deEste($cid);
                if (!$c) throw new RuntimeException('No se encontró la cotización.');
                $despues = campo('despues');
                $lineas = $leerLineas($c);
                // Subir, bajar o quitar (también sin JavaScript)
                $orden = array_keys($lineas);
                if (isset($_POST['quitar'])) {
                    unset($lineas[(string) $_POST['quitar']]);
                } elseif (isset($_POST['mover']) && preg_match('/^(\w+):(arriba|abajo)$/', (string) $_POST['mover'], $m)) {
                    $pos = array_search($m[1], array_map('strval', $orden), true);
                    $otro = $m[2] === 'arriba' ? $pos - 1 : $pos + 1;
                    if ($pos !== false && isset($orden[$otro])) {
                        [$orden[$pos], $orden[$otro]] = [$orden[$otro], $orden[$pos]];
                        $lineas = array_replace(array_flip($orden), $lineas);
                    }
                }
                $invitados = (int) campo('invitados', (string) $c['invitados']);
                if ($invitados !== (int) $c['invitados'] && $invitados > 0) {
                    foreach ($lineas as &$l) {
                        if ($l['unidad'] === 'persona' && abs($l['cantidad'] - (int) $c['invitados']) < 0.001) $l['cantidad'] = $invitados;
                    }
                    unset($l);
                }
                $valida = campo('valida_hasta', (string) $c['valida_hasta']);
                $cot->guardar($nid, $cid, array_values($lineas), [
                    'invitados' => $invitados, 'descuento' => numero($_POST['descuento'] ?? '') ?? 0,
                    'notas' => campo('notas'), 'condiciones' => campo('condiciones'), 'valida_hasta' => $valida,
                ]);
                if ($despues === 'producto') {
                    $prod = (int) ($_POST['producto_agregar'] ?? 0);
                    if (!$prod) throw new RuntimeException('Elige el producto que quieres agregar.');
                    $cot->agregarProducto($nid, $cid, $prod);
                    aviso('Producto agregado.');
                } elseif ($despues === 'paquete') {
                    exigir(modulo_activo($u, 'paquetes'), 'Los paquetes no están en tu plan. Agrégalos en Mi plan.');
                    $paq = (int) ($_POST['paquete_agregar'] ?? 0);
                    if (!$paq) throw new RuntimeException('Elige el paquete que quieres agregar.');
                    $cot->aplicarPaquete($nid, $cid, $paq);
                    aviso('Se agregaron los productos del paquete.');
                } elseif ($despues === 'enviar' || $despues === 'whatsapp') {
                    $eraBorrador = $c['estado'] === 'borrador';
                    $cot->enviar($nid, $cid, $u);
                    if ($eraBorrador) registrar_cambio($nid, $id, 'Envió la cotización N.º ' . $c['numero'] . ' al cliente');
                    if ($despues === 'whatsapp') {
                        $wa = WhatsApp::enlace($ev['cliente_telefono'], $mensaje($deEste($cid)));
                        if ($wa) {
                            header('Location: ' . $wa);
                            exit;
                        }
                        aviso('Cotización enviada. El cliente no tiene celular: copia el enlace y mándaselo por donde puedas.', 'error');
                        break;
                    }
                    aviso('Cotización marcada como enviada. ' . ($ev['estado'] === 'consulta' ? 'El evento pasó a "Cotizado" y la fecha queda retenida unos días.' : 'Mándale el enlace al cliente.'));
                } else {
                    aviso(isset($_POST['quitar']) ? 'Línea quitada.' : (isset($_POST['mover']) ? 'Orden cambiado.' : 'Cotización guardada.'));
                }
                break;

            case 'agregar':
                $c = $deEste($cid);
                if (!$c) throw new RuntimeException('No se encontró la cotización.');
                $cot->agregarProducto($nid, $cid, (int) ($_POST['producto_id'] ?? 0));
                aviso('Producto agregado.');
                break;

            case 'aceptar':
                $c = $deEste($cid);
                if (!$c) throw new RuntimeException('No se encontró la cotización.');
                if (empty($_POST['confirmo'])) throw new RuntimeException('Marca la casilla para confirmar que el cliente aceptó.');
                $cot->aceptar($nid, $cid, campo('nombre'), ip_visitante(), $u);
                aviso('Cotización aceptada. El evento ya tiene su valor (' . dinero($c['total']) . ') y su plan de pagos. Siguiente paso: cobrar la reserva y preparar el contrato.');
                break;

            case 'rechazar':
                $c = $deEste($cid);
                if (!$c) throw new RuntimeException('No se encontró la cotización.');
                $cot->rechazar($nid, $cid, campo('motivo'));
                registrar_cambio($nid, $id, 'Marcó como rechazada la cotización N.º ' . $c['numero'] . (campo('motivo') ? ' (' . campo('motivo') . ')' : ''));
                aviso('Cotización marcada como rechazada. Si el cliente pide cambios, crea una nueva versión.');
                break;

            default:
                throw new RuntimeException('Acción no válida.');
        }
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
    }
    redirigir('cotizacion', $ir);
}

$versiones = $cot->deEvento($nid, $id);
$actual = null;
if (!empty($_GET['c'])) {
    $actual = $deEste((int) $_GET['c']);
    if (!$actual) aviso('Esa cotización no es de este evento.', 'error');
}
if (!$actual && $versiones) $actual = $cot->obtener($nid, (int) $versiones[0]['id']);
$editable = $actual && $puede && in_array($actual['estado'], ['borrador', 'enviada'], true);
$vencida = $actual && in_array($actual['estado'], ['borrador', 'enviada'], true) && $actual['valida_hasta'] < date('Y-m-d');
$paquetes = modulo_activo($u, 'paquetes') ? $cat->paquetes($nid, false) : [];
// Primero los paquetes del mismo tipo de evento
usort($paquetes, fn($a, $b) => [($a['tipo_evento'] ?? '') !== $ev['tipo'], $a['nombre']] <=> [($b['tipo_evento'] ?? '') !== $ev['tipo'], $b['nombre']]);
$catalogo = $editable ? $cat->paraBuscador($nid, $dueno) : [];
$enlace = $actual ? url_completa('cot', ['t' => $actual['token']]) : null;
$wa = $actual ? WhatsApp::enlace($ev['cliente_telefono'], $mensaje($actual)) : null;
$comparar = !empty($_GET['comparar']) && count($versiones) > 1;
$todas = $comparar ? array_map(fn($v) => $cot->obtener($nid, (int) $v['id']), array_reverse($versiones)) : [];
$tab = 'cotizacion';

vista('cotizacion', compact('ev', 'tab', 'versiones', 'actual', 'editable', 'vencida', 'paquetes', 'catalogo', 'enlace', 'wa', 'puede', 'dueno', 'comparar', 'todas'),
      'Cotización · ' . $ev['nombre'] . ' · TuRenta');
