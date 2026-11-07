<?php
use TuRenta\Contratos;

/**
 * Verificación pública de un contrato: /?r=verificar&c=<código>   (también con el formulario para escribir el código)
 * Muestra si el contrato existe, quién lo firmó y si el texto sigue íntegro (la huella SHA-256 coincide).
 */
$codigo = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($_GET['c'] ?? '')));
$k = null;
$marca = null;
if ($codigo !== '') {
    $k = (new Contratos(db()))->verificar($codigo) ?? (new \TuRenta\Anexos(db()))->verificar($codigo);   // también anexos (090)
    if ($k) {
        $st = db()->prepare('SELECT nombre, color, slug, logo, ciudad, ruc, razon_social FROM negocios WHERE id = ?');
        $st->execute([$k['negocio_id']]);
        $n = $st->fetch();
        $marca = ['nombre' => $n['nombre'], 'color' => $n['color'], 'slug' => $n['slug'], 'logo' => $n['logo']];
        $k['negocio_ruc'] = $n['ruc'];
        $k['negocio_razon'] = $n['razon_social'];
    } else {
        http_response_code(404);
    }
}
vista_publica('verificar', ['codigo' => $codigo, 'k' => $k], 'Verificar contrato · TuRenta', $marca);
