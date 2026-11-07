<?php
/** Búsqueda rápida de la barra lateral: /?r=buscar&q=texto  -> JSON */
header('Content-Type: application/json; charset=utf-8');
$q = trim((string) ($_GET['q'] ?? ''));
$res = [];
if (mb_strlen($q) >= 2) {
    $nid = (int) $u['negocio_id'];
    $like = '%' . $q . '%';
    $st = db()->prepare("SELECT e.id, e.codigo, e.nombre, e.fecha, e.estado, c.nombre AS cliente FROM eventos e JOIN clientes c ON c.id = e.cliente_id
                          WHERE e.negocio_id = ? AND (e.nombre ILIKE ? OR e.codigo ILIKE ? OR c.nombre ILIKE ? OR c.telefono ILIKE ?)
                          ORDER BY e.fecha DESC LIMIT 8");
    $st->execute([$nid, $like, $like, $like, $like]);
    foreach ($st->fetchAll() as $e) {
        $res[] = ['titulo' => $e['nombre'], 'detalle' => $e['codigo'] . ' · ' . fecha_corta($e['fecha']) . ' · ' . \TuRenta\Eventos::ESTADOS[$e['estado']],
                  'enlace' => url('evento', ['id' => $e['id']])];
    }
    if ($u['rol'] !== 'coordinador') {
        $st = db()->prepare('SELECT id, nombre, telefono FROM clientes WHERE negocio_id = ? AND (nombre ILIKE ? OR telefono ILIKE ? OR identificacion ILIKE ?) ORDER BY nombre LIMIT 5');
        $st->execute([$nid, $like, $like, $like]);
        foreach ($st->fetchAll() as $c) {
            $res[] = ['titulo' => $c['nombre'], 'detalle' => 'Cliente' . ($c['telefono'] ? ' · ' . $c['telefono'] : ''), 'enlace' => url('cliente', ['id' => $c['id']])];
        }
    }
}
echo json_encode(['resultados' => $res], JSON_UNESCAPED_UNICODE);
