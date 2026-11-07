<?php
use TuRenta\{Adjuntos, Compras, Incidencias, ProveedoresFicha};

/**
 * Archivos privados de proveedores y compras (solo con sesión, siempre del mismo negocio):
 *   /?r=proveedor_archivo&tipo=documento|incidencia|factura|xml|comprobante&id=<id>
 * Documentos e incidencias los ve todo el equipo; facturas y comprobantes de pago, el coordinador no.
 */
$tipo = (string) ($_GET['tipo'] ?? '');
$id = (int) ($_GET['id'] ?? 0);
$nid = (int) $u['negocio_id'];
$dinero = in_array($tipo, ['factura', 'xml', 'comprobante'], true);
if ($dinero && $u['rol'] === 'coordinador') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Tu usuario no puede ver este archivo.';
    return;
}
[$archivo, $nombre] = match ($tipo) {
    'documento' => (function () use ($nid, $id) {
        $d = (new ProveedoresFicha(db()))->documento($nid, $id);
        return [$d['archivo'] ?? null, 'documento-' . $id];
    })(),
    'incidencia' => (function () use ($nid, $id) {
        $i = (new Incidencias(db()))->incidencia($nid, $id);
        return [$i['foto'] ?? null, 'incidencia-' . $id];
    })(),
    'factura', 'xml' => (function () use ($nid, $id, $tipo) {
        $c = (new Compras(db()))->compra($nid, $id);
        return [$c ? ($tipo === 'xml' ? $c['factura_xml'] : $c['factura_archivo']) : null, 'factura-' . ($c['factura_numero'] ?? $id)];
    })(),
    'comprobante' => (function () use ($nid, $id) {
        $p = (new Compras(db()))->pago($nid, $id);
        return [$p['comprobante'] ?? null, 'pago-proveedor-' . $id];
    })(),
    default => [null, ''],
};
$ruta = Adjuntos::ruta($archivo);
if ($ruta === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Archivo no encontrado.';
    return;
}
Adjuntos::enviar($ruta, $nombre);
