<?php
use TuRenta\{Compras, Incidencias, ProveedoresFicha};

/**
 * Ficha del proveedor: /?r=proveedor&id=<id>&ver=resumen|cuenta|eventos|documentos|precios|incidencias|bitacora
 * Datos, estado de cuenta (compras, pagos, saldo), eventos donde trabajó, documentos con vencimiento,
 * historial de precios, puntaje, incidencias y la bitácora de todo lo que pasó.
 * El coordinador la ve sin dinero (sin cuenta, precios ni banco).
 */
$nid = (int) $u['negocio_id'];
$id = (int) ($_GET['id'] ?? 0);
$ficha = new ProveedoresFicha(db());
$p = $ficha->proveedor($nid, $id);
if (!$p) {
    http_response_code(404);
    vista('error', ['mensaje' => 'No se encontró ese proveedor.'], 'Proveedor · TuRenta');
    return;
}
$verDinero = $u['rol'] !== 'coordinador';
$comercial = es_comercial($u);
$secciones = ['resumen' => 'Resumen', 'cuenta' => 'Cuenta', 'eventos' => 'Eventos', 'documentos' => 'Documentos', 'precios' => 'Precios',
              'incidencias' => 'Incidencias', 'bitacora' => 'Bitácora'];
if (!$verDinero) unset($secciones['cuenta'], $secciones['precios']);
$ver = (string) ($_GET['ver'] ?? 'resumen');
if (!isset($secciones[$ver])) $ver = 'resumen';

if (es_post()) {
    verificar_csrf();
    exigir(puede_editar($u), 'Tu usuario solo puede mirar.');
    $accion = (string) ($_POST['accion'] ?? '');
    $volver = (string) ($_POST['ver'] ?? $ver);
    try {
        switch ($accion) {
            case 'documento':
                $ficha->agregarDocumento($nid, $id, ['tipo' => campo('tipo'), 'nombre' => campo('nombre_doc'), 'vence' => campo('vence')], $_FILES['archivo'] ?? null, $u);
                aviso('Documento guardado.' . (campo('vence') ? ' Te avisaremos 30 días antes de que venza.' : ''));
                $volver = 'documentos';
                break;
            case 'documento_borrar':
                $ficha->borrarDocumento($nid, (int) ($_POST['documento_id'] ?? 0), $u);
                aviso('Documento quitado.');
                $volver = 'documentos';
                break;
            case 'nuevo_enlace':
                exigir($comercial, 'Solo el dueño o un vendedor cambian el enlace del portal.');
                $ficha->nuevoToken($nid, $id, $u);
                aviso('Listo: el enlace anterior ya no sirve. Envíale el nuevo al proveedor.');
                $volver = 'resumen';
                break;
            default:
                throw new RuntimeException('Acción no válida.');
        }
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
    }
    redirigir('proveedor', ['id' => $id, 'ver' => isset($secciones[$volver]) ? $volver : 'resumen']);
}

$st = db()->prepare('SELECT * FROM negocios WHERE id = ?');
$st->execute([$nid]);
$negocio = $st->fetch();
$compras = new Compras(db());
$datosVista = [
    'p' => $p, 'secciones' => $secciones, 'ver' => $ver, 'verDinero' => $verDinero, 'comercial' => $comercial, 'negocio' => $negocio,
    'puntaje' => $ficha->puntaje($nid, $id),
    'toleranciaProv' => $ficha->tolerancia($nid),
    'documentos' => $ficha->documentos($nid, $id),
    'portal' => modulo_activo($nid, 'portal_proveedor') ? url_completa('portal_proveedor', ['t' => $p['token']]) : null,
];
$datosVista['cuenta'] = $verDinero ? $compras->cuentaProveedor($nid, $id) : null;
if ($ver === 'cuenta') {
    $datosVista['pagos'] = $compras->pagosDeProveedor($nid, $id);
    $st = db()->prepare('SELECT g.*, e.codigo, e.nombre AS evento FROM gastos g LEFT JOIN eventos e ON e.id = g.evento_id WHERE g.negocio_id = ? AND g.proveedor_id = ? ORDER BY g.fecha DESC LIMIT 100');
    $st->execute([$nid, $id]);
    $datosVista['gastosProv'] = $st->fetchAll();
}
if ($ver === 'eventos' || $ver === 'resumen') $datosVista['eventosProv'] = $ficha->eventosDe($nid, $id);
if ($ver === 'precios') $datosVista['precios'] = $ficha->historialPrecios($nid, $id);
if ($ver === 'incidencias') $datosVista['incidenciasProv'] = (new Incidencias(db()))->lista($nid, ['proveedor_id' => $id]);
if ($ver === 'bitacora') $datosVista['bitacora'] = $ficha->bitacora($nid, $id);
vista('proveedor', $datosVista, $p['nombre'] . ' · Proveedores · TuRenta');
