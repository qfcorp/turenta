<?php
use TuRenta\{Adjuntos, Compras, Cotizaciones, FacturaXml, ProveedoresFicha};

/**
 * Compras a proveedores: /?r=compras
 *   Lista con filtros (estado, proveedor, evento, búsqueda), nueva compra o pedido de precio (?nueva=1&proveedor=&evento=&estado=cotizacion),
 *   subir la factura electrónica (XML del SRI) sin orden previa, y comparar precios (?comparar=1&evento=&categoria=).
 * Solo el dueño y el vendedor crean o cambian; la cuenta de consulta solo mira. El coordinador no entra (rutas del coordinador).
 */
$nid = (int) $u['negocio_id'];
$compras = new Compras(db());
$ficha = new ProveedoresFicha(db());
$puede = es_comercial($u);

/** Ítems del formulario: items[i][descripcion|cantidad|precio_unitario|con_iva] */
$itemsForm = function (): array {
    $res = [];
    foreach ((array) ($_POST['items'] ?? []) as $it) {
        if (!is_array($it)) continue;
        $res[] = ['descripcion' => (string) ($it['descripcion'] ?? ''), 'cantidad' => (string) ($it['cantidad'] ?? ''),
                  'precio_unitario' => (string) ($it['precio_unitario'] ?? ''), 'con_iva' => ($it['con_iva'] ?? '1') === '1'];
    }
    return $res;
};

if (es_post()) {
    verificar_csrf();
    exigir($puede, 'Solo el dueño y los vendedores pueden registrar compras.');
    $accion = (string) ($_POST['accion'] ?? '');
    try {
        switch ($accion) {
            case 'crear':
                $id = $compras->crear($nid, [
                    'proveedor_id' => (int) ($_POST['proveedor_id'] ?? 0), 'evento_id' => (int) ($_POST['evento_id'] ?? 0), 'estado' => campo('estado', 'pedida'),
                    'categoria' => campo('categoria'), 'fecha' => campo('fecha'), 'vence' => campo('vence'), 'anticipo' => campo('anticipo'), 'notas' => campo('notas'),
                ], $itemsForm(), $u);
                $c = $compras->compra($nid, $id);
                aviso(($c['estado'] === 'cotizacion' ? 'Pedido de precio ' : 'Compra ') . $c['codigo'] . ' creada. Envíasela al proveedor por WhatsApp o en PDF.');
                if ($m = $compras->avisoCupo($nid, (int) $c['proveedor_id'])) aviso($m, 'error');
                redirigir('compra', ['id' => $id]);
            case 'xml_leer':
                $archivo = $_FILES['xml'] ?? null;
                if (!Adjuntos::llego($archivo)) throw new RuntimeException('Elige el archivo XML de la factura.');
                $g = Adjuntos::guardar($archivo, ['xml']);
                try {
                    $f = FacturaXml::leer((string) Adjuntos::leer($g['archivo']));
                } catch (Throwable $e) {
                    Adjuntos::borrar($g['archivo']);
                    throw $e;
                }
                if (!empty($_SESSION['xml_factura']['archivo'])) Adjuntos::borrar($_SESSION['xml_factura']['archivo']);
                $_SESSION['xml_factura'] = ['archivo' => $g['archivo'], 'f' => $f];
                redirigir('compras', ['xml' => 1]);
            case 'xml_cancelar':
                if (!empty($_SESSION['xml_factura']['archivo'])) Adjuntos::borrar($_SESSION['xml_factura']['archivo']);
                unset($_SESSION['xml_factura']);
                aviso('Listo, no se guardó la factura.');
                redirigir('compras');
            case 'xml_guardar':
                $x = $_SESSION['xml_factura'] ?? null;
                if (!$x || !($contenido = Adjuntos::leer($x['archivo']))) throw new RuntimeException('La factura que subiste ya no está. Súbela otra vez.');
                $f = $x['f'];
                db()->beginTransaction();
                try {
                    $pid = (int) ($_POST['proveedor_id'] ?? 0);
                    if (!$pid) {
                        $pid = $ficha->crearDesdeFactura($nid, $f, campo('servicio', 'otros'), campo('telefono') ?: null, $u);
                        aviso('Se creó el proveedor ' . ($f['nombre_comercial'] ?: $f['razon_emisor']) . ' con los datos de la factura.');
                    }
                    $destino = (int) ($_POST['compra_id'] ?? 0);
                    if ($destino) {
                        $avisos = $compras->importarXml($nid, $destino, $contenido, $u, 'negocio', $x['archivo']);
                        $id = $destino;
                    } else {
                        $id = $compras->crearDesdeFactura($nid, $pid, $f, $contenido, (int) ($_POST['evento_id'] ?? 0) ?: null, $u);
                        $avisos = [];
                        Adjuntos::borrar($x['archivo']);   // crearDesdeFactura guarda su propia copia del XML
                    }
                    db()->commit();
                } catch (Throwable $e) {
                    if (db()->inTransaction()) db()->rollBack();
                    throw $e;
                }
                unset($_SESSION['xml_factura']);
                aviso('Factura ' . $f['numero'] . ' guardada en ' . $compras->compra($nid, $id)['codigo'] . '.');
                foreach ($avisos as $a) aviso($a, 'error');
                redirigir('compra', ['id' => $id]);
            case 'elegir':
                $id = (int) ($_POST['compra_id'] ?? 0);
                $n = $compras->elegirCotizacion($nid, $id, $u);
                aviso('Elegiste ' . $compras->compra($nid, $id)['codigo'] . '. ' . ($n ? "Los otros $n pedidos de precio quedaron cancelados. " : '') . 'Envíale la orden al proveedor.');
                redirigir('compra', ['id' => $id]);
            default:
                throw new RuntimeException('Acción no válida.');
        }
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
        if ($accion === 'crear') {
            $_SESSION['form_compra'] = $_POST;
            redirigir('compras', ['nueva' => 1]);
        }
        redirigir('compras', in_array($accion, ['xml_guardar'], true) ? ['xml' => 1] : []);
    }
}

$hoy = new DateTimeImmutable('today');
$previo = $_SESSION['form_compra'] ?? null;
unset($_SESSION['form_compra']);
$filtros = ['estado' => (string) ($_GET['estado'] ?? ''), 'proveedor_id' => (int) ($_GET['proveedor'] ?? 0), 'evento_id' => (int) ($_GET['evento'] ?? 0),
            'q' => trim((string) ($_GET['q'] ?? ''))];
$puntajes = $ficha->puntajes($nid, $hoy);
$st = db()->prepare('SELECT id, nombre, servicio, preferido, credito_dias, anticipo_pct FROM proveedores WHERE negocio_id = ? AND activo ORDER BY preferido DESC, nombre');
$st->execute([$nid]);
$provs = $st->fetchAll();
$st = db()->prepare("SELECT id, codigo, nombre, fecha FROM eventos WHERE negocio_id = ? AND estado NOT IN ('perdido','cancelado')
                        AND (fecha >= ?::date - 60 OR id = ?) ORDER BY fecha LIMIT 300");
$st->execute([$nid, $hoy->format('Y-m-d'), (int) ($_GET['evento'] ?? 0)]);
$eventosSel = $st->fetchAll();

$comparar = null;
if (!empty($_GET['comparar'])) {
    $cat = (string) ($_GET['categoria'] ?? '');
    $evId = (int) ($_GET['evento'] ?? 0);
    $st = db()->prepare('SELECT id, codigo, nombre, fecha FROM eventos WHERE id = ? AND negocio_id = ?');
    $st->execute([$evId, $nid]);
    $evC = $st->fetch();
    if ($evC && isset(Cotizaciones::CATEGORIAS[$cat])) $comparar = ['evento' => $evC, 'categoria' => $cat, 'lista' => $compras->comparables($nid, $evId, $cat)];
}
$xml = !empty($_GET['xml']) && !empty($_SESSION['xml_factura']) ? $_SESSION['xml_factura'] : null;
$xmlInfo = null;
if ($xml) {
    $prov = $ficha->porRuc($nid, (string) $xml['f']['ruc_emisor']);
    $abiertas = $prov ? array_values(array_filter($compras->lista($nid, ['proveedor_id' => (int) $prov['id'], 'estado' => 'activas']), fn($c) => !$c['factura_numero'])) : [];
    $xmlInfo = ['f' => $xml['f'], 'proveedor' => $prov, 'abiertas' => $abiertas];
}
vista('compras', [
    'lista' => $compras->lista($nid, $filtros, $hoy), 'filtros' => $filtros, 'provs' => $provs, 'puntajes' => $puntajes, 'eventosSel' => $eventosSel,
    'grupos' => $compras->gruposComparables($nid), 'comparar' => $comparar, 'xmlInfo' => $xmlInfo, 'puede' => $puede, 'previo' => $previo,
    'abrirNueva' => !empty($_GET['nueva']) || $previo !== null, 'ivaPct' => (float) $u['iva_pct'],
    'pre' => ['proveedor' => (int) ($_GET['proveedor'] ?? 0), 'evento' => (int) ($_GET['evento'] ?? 0), 'estado' => (string) ($_GET['estado_nueva'] ?? ''),
              'categoria' => (string) ($_GET['categoria'] ?? '')],
], 'Compras · TuRenta');
