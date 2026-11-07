<?php
use TuRenta\{Eventos, Ventas};

/**
 * Seguimiento comercial: actividades pendientes (vencidas, hoy, 7 días), consultas sin atender,
 * cotizaciones sin respuesta, fechas retenidas que vencen y agenda de visitas/degustaciones de la semana.
 * Acciones (POST ?accion=): hecha, reagendar.   Filtro: ?mias=1 (solo lo mío).
 */
$nid = (int) $u['negocio_id'];
$eventos = new Eventos(db());
$ventas = new Ventas(db());

if (es_post()) {
    exigir(es_comercial($u), 'Tu usuario solo puede ver el seguimiento.');
    verificar_csrf();
    $accion = (string) ($_GET['accion'] ?? '');
    $actId = (int) ($_POST['actividad_id'] ?? 0);
    try {
        if ($accion === 'hecha') {
            $eventos->marcarActividad($nid, $actId, true);
            aviso('Marcado como hecho.');
        } elseif ($accion === 'reagendar') {
            $ventas->reagendar($nid, $actId, (string) ($_POST['cuando'] ?? ''));
            aviso('Reagendado.');
        } else {
            throw new RuntimeException('Acción no válida.');
        }
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
    }
    redirigir('seguimiento', !empty($_GET['mias']) ? ['mias' => 1] : []);
}

$mias = !empty($_GET['mias']);
$hoy = new DateTimeImmutable('today');
$pend = $ventas->pendientesAgrupados($nid, $mias ? (int) $u['id'] : null, $hoy);
vista('seguimiento', [
    'pend' => $pend, 'mias' => $mias, 'puedeEditar' => es_comercial($u),
    'sinAtender' => $ventas->consultasSinAtender($nid, 3, $hoy),
    'sinRespuesta' => $ventas->cotizacionesSinRespuesta($nid),
    'retenciones' => $ventas->retencionesPorVencer($nid, 3, $hoy),
    'visitas' => $ventas->agendaVisitas($nid, $hoy),
    'hoyTxt' => $hoy->format('Y-m-d'),
], 'Seguimiento · TuRenta');
