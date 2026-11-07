<?php
use TuRenta\{Configuracion, Eventos, Notificaciones, Pagos, Reportes};

/**
 * Inicio: el panel del día. El dueño y el vendedor ven ventas y cobros; el coordinador, solo los eventos;
 * la cuenta "consulta" ve todo sin botones para cambiar.
 */
$nid = (int) $u['negocio_id'];
$hoy = new DateTimeImmutable('today');
$eventos = new Eventos(db());
$config = new Configuracion(db());
$comercial = es_comercial($u) || $u['rol'] === 'consulta';   // ve dinero
$editar = puede_editar($u);

if (es_post()) {
    verificar_csrf();
    $accion = (string) ($_GET['accion'] ?? '');
    try {
        exigir($editar, 'Tu usuario es solo de consulta: puede ver, pero no cambiar.');
        switch ($accion) {
            case 'seguimiento':
                exigir(es_comercial($u));
                $eventos->marcarActividad($nid, (int) ($_POST['id'] ?? 0));
                aviso('Seguimiento marcado como hecho.');
                break;
            case 'tarea':
                $eventos->marcarTarea($nid, (int) ($_POST['id'] ?? 0), true);
                aviso('Tarea marcada como hecha.');
                break;
            case 'precios_revisados':
                exigir(es_admin_total($u));
                $config->marcarPreciosRevisados($nid);
                aviso('¡Bien! Un paso menos.');
                break;
            case 'ocultar_pasos':
                exigir(es_admin_total($u));
                $config->ocultarPasos($nid);
                aviso('Listo. Puedes volver a ver estos pasos en Configuración.');
                break;
            default:
                throw new RuntimeException('Acción no válida.');
        }
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
    }
    redirigir('inicio');
}

$negocio = $config->negocio($nid);
$reportes = new Reportes(db());
$cifras = $reportes->cifrasInicio($nid, $hoy);

// Avisos sin leer (los 5 más recientes)
$noLeidos = array_slice(array_values(array_filter((new Notificaciones(db()))->recientes((int) $u['id'], 30), fn($n) => !$n['leida'])), 0, 5);

// Próximos 7 días: vendidos y fechas retenidas, con saldo
$proximos = array_values(array_filter(
    $eventos->lista($nid, ['desde' => $hoy->format('Y-m-d'), 'hasta' => $hoy->modify('+7 days')->format('Y-m-d')], 30),
    fn($e) => in_array($e['estado'], ['cotizado', 'separado', 'confirmado'], true)
));

// Seguimientos de hoy (el vendedor ve los suyos; el dueño y la consulta, todos)
$seguimientos = [];
if ($u['rol'] !== 'coordinador') {
    $seguimientos = $eventos->pendientes($nid, $hoy->format('Y-m-d') . ' 23:59:59', $u['rol'] === 'vendedor' ? (int) $u['id'] : null);
}
$tareas = $eventos->tareasPorVencer($nid, $hoy->modify('+7 days')->format('Y-m-d'));

$cuotas = $comercial ? (new Pagos(db()))->porCobrar($nid, 7, $hoy) : [];
$retenidas = $u['rol'] !== 'coordinador' ? $reportes->retenidasPorVencer($nid, 2, $hoy) : [];
$consultas = $u['rol'] !== 'coordinador' ? array_slice($reportes->consultasSinAtender($nid), 0, 5) : [];

// Primeros pasos para una cuenta nueva (se marcan solos)
$pasos = [];
if (es_admin_total($u) && !$negocio['pasos_ocultos']) {
    $pasos = $config->primerosPasos($nid, (int) $u['id']);
    if (!in_array(false, array_column($pasos, 'hecho'), true)) $pasos = [];
}

$st = db()->prepare('SELECT clave_temporal FROM usuarios WHERE id = ?');
$st->execute([$u['id']]);
$claveTemporal = (bool) $st->fetchColumn() && empty($_SESSION['admin_viendo']);

vista('inicio', compact('claveTemporal', 'negocio', 'cifras', 'noLeidos', 'proximos', 'seguimientos', 'tareas', 'cuotas', 'retenidas', 'consultas',
                        'pasos', 'comercial', 'editar', 'hoy'), 'Inicio · TuRenta');
