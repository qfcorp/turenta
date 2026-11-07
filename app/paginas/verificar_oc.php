<?php
use TuRenta\Compras;

/**
 * Verificación pública de una orden de compra (el QR del PDF): /?r=verificar_oc&c=<código>
 * Muestra solo lo básico: quién la emitió, número, fecha, proveedor, estado y total.
 */
$codigo = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($_GET['c'] ?? '')));
$oc = $codigo !== '' ? (new Compras(db()))->verificar($codigo) : null;
$marca = null;
if ($oc) {
    $st = db()->prepare('SELECT nombre, color, slug, logo, ruc, razon_social FROM negocios WHERE id = ?');
    $st->execute([$oc['negocio_id']]);
    $n = $st->fetch();
    $marca = ['nombre' => $n['nombre'], 'color' => $n['color'], 'slug' => $n['slug'], 'logo' => $n['logo']];
    $oc['negocio_ruc'] = $n['ruc'];
    $oc['negocio_razon'] = $n['razon_social'];
} elseif ($codigo !== '') {
    http_response_code(404);
}
vista_publica('verificar_oc', ['codigo' => $codigo, 'oc' => $oc], 'Verificar orden de compra · TuRenta', $marca);
