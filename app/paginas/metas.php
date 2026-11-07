<?php
use TuRenta\{Metas, Reportes};

/**
 * Metas de ventas: /?r=metas&anio=2026 (el dueño las pone).
 *   &csv=1&desde=AAAA-MM-DD&hasta=AAAA-MM-DD  descarga el reporte de metas para Excel (dueño, vendedor —solo lo suyo— y consulta).
 * POST accion=: local (meta de siempre + los 12 meses del año), vendedores (meta de siempre de cada uno),
 *               vendedor_mes (un mes distinto para un vendedor), quitar (una meta de un vendedor en un mes).
 */
exigir($u['rol'] !== 'coordinador', 'Las metas de ventas no están disponibles para el coordinador.');
$nid = (int) $u['negocio_id'];
$metas = new Metas(db());
$hoy = new DateTimeImmutable('today');

if (!empty($_GET['csv'])) {
    $per = Reportes::periodo('rango', (string) ($_GET['desde'] ?? ''), (string) ($_GET['hasta'] ?? ''), $hoy);
    [$encabezados, $filas] = $metas->exportar($nid, $per['desde'], $per['hasta'], $u['rol'] === 'vendedor' ? (int) $u['id'] : null);
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="metas_' . $per['desde'] . '_a_' . $per['hasta'] . '.csv"');
    header('Cache-Control: private, no-store');
    echo Reportes::csv($encabezados, $filas);
    return;
}

exigir(es_admin_total($u), 'Solo el dueño o un administrador pone las metas de ventas. Mira cómo vas en Reportes → Metas.');
$anio = (int) ($_GET['anio'] ?? $_POST['anio'] ?? $hoy->format('Y'));
if ($anio < 2000 || $anio > 2100) $anio = (int) $hoy->format('Y');

if (es_post()) {
    verificar_csrf();
    $accion = (string) ($_POST['accion'] ?? '');
    try {
        db()->beginTransaction();
        switch ($accion) {
            case 'local':
                $metas->guardar($nid, null, null, $_POST['monto'] ?? '', $_POST['eventos'] ?? '');
                for ($m = 1; $m <= 12; $m++) {
                    $mes = sprintf('%04d-%02d', $anio, $m);
                    $metas->guardar($nid, null, $mes, $_POST['mes_monto'][$mes] ?? '', $_POST['mes_eventos'][$mes] ?? '');
                }
                aviso('Metas del local guardadas.');
                break;
            case 'vendedores':
                foreach ($metas->vendedores($nid) as $v) {
                    $metas->guardar($nid, (int) $v['id'], null, $_POST['v_monto'][$v['id']] ?? '', $_POST['v_eventos'][$v['id']] ?? '');
                }
                aviso('Metas de los vendedores guardadas.');
                break;
            case 'vendedor_mes':
                $mes = (string) ($_POST['mes'] ?? '');
                $metas->guardar($nid, (int) ($_POST['vendedor_id'] ?? 0), $mes, $_POST['monto'] ?? '', $_POST['eventos'] ?? '');
                aviso('Meta del mes guardada.');
                $anio = (int) substr($mes, 0, 4) ?: $anio;
                break;
            case 'quitar':
                $metas->guardar($nid, (int) ($_POST['vendedor_id'] ?? 0), (string) ($_POST['mes'] ?? ''), '', '');
                aviso('Se quitó esa meta: ese mes vale la meta de siempre.');
                break;
            default:
                throw new RuntimeException('Acción no válida.');
        }
        db()->commit();
    } catch (RuntimeException $e) {
        if (db()->inTransaction()) db()->rollBack();
        aviso($e->getMessage(), 'error');
    }
    redirigir('metas', ['anio' => $anio]);
}

$todas = $metas->todas($nid);
$rep = $metas->reporte($nid, "$anio-01-01", "$anio-12-31");
vista('metas', ['anio' => $anio, 'todas' => $todas, 'rep' => $rep, 'vendedores' => $metas->vendedores($nid), 'hoy' => $hoy], 'Metas de ventas · TuRenta');
