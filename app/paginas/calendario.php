<?php
use TuRenta\{Eventos, Ventas};

/**
 * Calendario: vista del mes (lunes a domingo) con los eventos por estado, días cerrados y hoy;
 * al tocar un día se ve la disponibilidad por espacio. Vista "Próximos sábados" para vender.
 *   /?r=calendario&a=2026&m=11&espacio=3&dia=2026-11-14   ·   /?r=calendario&vista=sabados
 */
$nid = (int) $u['negocio_id'];
$ventas = new Ventas(db());
$eventos = new Eventos(db());
$hoy = new DateTimeImmutable('today');

$vistaCal = ($_GET['vista'] ?? '') === 'sabados' ? 'sabados' : 'mes';
$dia = (string) ($_GET['dia'] ?? '');
if (!Ventas::fechaValida($dia)) $dia = '';
// Si llega un día sin mes, se abre el mes de ese día
if ($dia !== '' && !isset($_GET['m'])) {
    [$anio, $mes] = [(int) substr($dia, 0, 4), (int) substr($dia, 5, 2)];
} else {
    [$anio, $mes] = Ventas::mesValido($_GET['a'] ?? null, $_GET['m'] ?? null, $hoy);
}
$espacios = $ventas->espacios($nid, false);
$espacioId = (int) ($_GET['espacio'] ?? 0);
if (!in_array($espacioId, array_map('intval', array_column($espacios, 'id')), true)) $espacioId = 0;

$primero = new DateTimeImmutable(sprintf('%04d-%02d-01', $anio, $mes));
$anterior = $primero->modify('-1 month');
$siguiente = $primero->modify('+1 month');

$datos = [
    'vistaCal' => $vistaCal, 'anio' => $anio, 'mes' => $mes, 'espacios' => $espacios, 'espacioId' => $espacioId,
    'anterior' => $anterior, 'siguiente' => $siguiente, 'hoyTxt' => $hoy->format('Y-m-d'), 'dia' => $dia,
    'puedeCrear' => es_comercial($u),
];
if ($vistaCal === 'sabados') {
    $datos['sabados'] = $ventas->proximosSabados($nid, 12, $hoy);
} else {
    $datos['cal'] = $ventas->calendario($nid, $anio, $mes, $espacioId ?: null);
    $datos['disponibilidad'] = $dia !== '' ? $eventos->disponibilidad($nid, $dia, $hoy) : null;
    if ($dia !== '') {
        $st = db()->prepare('SELECT fc.motivo, es.nombre AS espacio FROM fechas_cerradas fc LEFT JOIN espacios es ON es.id = fc.espacio_id
                              WHERE fc.negocio_id = ? AND fc.fecha = ?::date');
        $st->execute([$nid, $dia]);
        $datos['cierresDia'] = $st->fetchAll();
    }
}
vista('calendario', $datos, 'Calendario · TuRenta');
