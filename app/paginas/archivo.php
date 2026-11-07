<?php
use TuRenta\{Fotos, Pagos, Contratos};

/**
 * Archivos privados del negocio (solo con sesión): /?r=archivo&tipo=comprobante|firma|espacio|logo&id=<id>
 */
$tipo = (string) ($_GET['tipo'] ?? '');
$id = (int) ($_GET['id'] ?? 0);
$nid = (int) $u['negocio_id'];
$ruta = match ($tipo) {
    'comprobante' => (new Pagos(db()))->rutaComprobante($nid, $id),
    'firma'       => (new Contratos(db()))->rutaFirma($nid, $id),
    'espacio'     => (function () use ($nid, $id) {
        $st = db()->prepare('SELECT foto FROM espacios WHERE id = ? AND negocio_id = ?');
        $st->execute([$id, $nid]);
        return Fotos::ruta('espacios', (string) $st->fetchColumn());
    })(),
    'logo'        => (function () use ($nid) {
        $st = db()->prepare('SELECT logo FROM negocios WHERE id = ?');
        $st->execute([$nid]);
        return Fotos::ruta('logos', (string) $st->fetchColumn());
    })(),
    default => null,
};
if ($ruta === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Archivo no encontrado.';
    return;
}
Fotos::enviar($ruta);
