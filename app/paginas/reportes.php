<?php
use TuRenta\Reportes;

/**
 * Reportes: ventas, cobrado, por cobrar, ocupación, embudo y conversión, rentabilidad y comisiones.
 * Dueño y vendedor (y la cuenta de consulta). El coordinador no entra (no ve dinero).
 * El vendedor ve las ventas de todos (para comparar), pero la comisión solo la suya.
 */
exigir($u['rol'] !== 'coordinador', 'Los reportes de ventas y cobros no están disponibles para el coordinador.');
$nid = (int) $u['negocio_id'];
$hoy = new DateTimeImmutable('today');
$per = Reportes::periodo((string) ($_GET['p'] ?? 'mes'), (string) ($_GET['desde'] ?? ''), (string) ($_GET['hasta'] ?? ''), $hoy);
$rep = new Reportes(db());

$ventas = $rep->ventas($nid, $per['desde'], $per['hasta']);
$cobrado = $rep->cobrado($nid, $per['desde'], $per['hasta']);
$porCobrar = $rep->porCobrar($nid, $hoy);
$ocupacion = $rep->ocupacion($nid, $hoy);
$conversion = $rep->conversion($nid, $per['desde'], $per['hasta']);
$rentabilidad = $rep->rentabilidad($nid, $per['desde'], $per['hasta']);
$comisiones = $rep->comisiones($nid, $per['desde'], $per['hasta']);
if ($u['rol'] === 'vendedor') {
    $comisiones['lista'] = array_values(array_filter($comisiones['lista'], fn($c) => (int) $c['vendedor_id'] === (int) $u['id']));
    $comisiones['total'] = round(array_sum(array_column($comisiones['lista'], 'comision')), 2);
}
$enlaceExportar = fn(string $tipo, ?string $desde = null, ?string $hasta = null) =>
    url('exportar', ['tipo' => $tipo, 'desde' => $desde ?? $per['desde'], 'hasta' => $hasta ?? $per['hasta']]);
vista('reportes', compact('per', 'ventas', 'cobrado', 'porCobrar', 'ocupacion', 'conversion', 'rentabilidad', 'comisiones', 'enlaceExportar', 'hoy'),
      'Reportes · TuRenta');
