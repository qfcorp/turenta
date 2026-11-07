<?php
use TuRenta\Reportes;

/**
 * Descarga de tablas para Excel: /?r=exportar&tipo=eventos|pagos|clientes|cuotas|gastos&desde=AAAA-MM-DD&hasta=AAAA-MM-DD
 * CSV con BOM UTF-8 y separador ";" para que Excel en español lo abra con tildes y columnas bien.
 */
exigir($u['rol'] !== 'coordinador', 'Las descargas de ventas y cobros no están disponibles para el coordinador.');
$tipo = (string) ($_GET['tipo'] ?? '');
if (!isset(Reportes::EXPORTES[$tipo])) {
    http_response_code(404);
    vista('error', ['mensaje' => 'Ese reporte no existe. Vuelve a Reportes y elige uno de la lista.'], 'Exportar · TuRenta');
    return;
}
$hoy = new DateTimeImmutable('today');
$per = Reportes::periodo('rango', (string) ($_GET['desde'] ?? ''), (string) ($_GET['hasta'] ?? ''), $hoy);
[$encabezados, $filas] = (new Reportes(db()))->exportar((int) $u['negocio_id'], $tipo, $per['desde'], $per['hasta'], $hoy);
$nombre = $tipo . '_' . $per['desde'] . '_a_' . $per['hasta'] . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $nombre . '"');
header('Cache-Control: private, no-store');
echo Reportes::csv($encabezados, $filas);
