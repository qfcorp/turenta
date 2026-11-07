<?php
use TuRenta\{Eventos, Ventas, Pagos, Cotizaciones, Contratos};

/**
 * Evento — pestaña Resumen: datos del evento y del cliente, estado (con sus pasos), retención, choques, dinero,
 * cotización y contrato, enlace del portal, tareas, seguimiento, historial, coordinador, menú y detalles, duplicar.
 * Acciones (POST ?accion=...): estado, retener, tarea, tarea_marcar, actividad, actividad_marcar, coordinador, detalles, duplicar.
 */
$nid = (int) $u['negocio_id'];
$id = (int) ($_GET['id'] ?? 0);
$eventos = new Eventos(db());
$ventas = new Ventas(db());
$ev = $eventos->obtener($nid, $id);
if (!$ev) {
    http_response_code(404);
    vista('error', ['mensaje' => 'No encontramos ese evento. Puede que el enlace esté mal o que sea de otro negocio.'], 'Evento · TuRenta');
    return;
}
$puedeVender = es_comercial($u);

if (es_post()) {
    exigir($puedeVender, 'Tu usuario solo puede ver este evento. Pide al dueño o a un vendedor que haga el cambio.');
    verificar_csrf();
    $accion = (string) ($_GET['accion'] ?? '');
    $volver = ['id' => $id];
    try {
        switch ($accion) {
            case 'estado':
                $nuevo = (string) ($_POST['estado'] ?? '');
                $eventos->cambiarEstado($nid, $id, $nuevo, ['motivo' => (string) ($_POST['motivo'] ?? '')], $u);
                $mensajes = [
                    'cotizado' => 'Listo: el evento está cotizado y la fecha queda retenida unos días.',
                    'separado' => 'Listo: la fecha y el espacio quedan separados (bloqueados) para este evento.',
                    'confirmado' => '¡Evento confirmado!',
                    'realizado' => 'Evento marcado como realizado. Recuerda devolver la garantía.',
                    'perdido' => 'Evento marcado como perdido. La fecha queda libre.',
                    'cancelado' => 'Evento cancelado. La fecha queda libre.',
                ];
                aviso($mensajes[$nuevo] ?? 'Estado cambiado a ' . (Eventos::ESTADOS[$nuevo] ?? $nuevo) . '.');
                break;
            case 'retener':
                $hasta = $eventos->extenderRetencion($nid, $id, (int) ($_POST['dias'] ?? 0));
                registrar_cambio($nid, $id, 'Extendió la retención de la fecha hasta el ' . fecha_corta($hasta));
                aviso('La fecha queda retenida hasta el ' . fecha_corta($hasta) . '.');
                break;
            case 'tarea':
                $vence = (string) ($_POST['vence'] ?? '');
                if ($vence !== '' && !Ventas::fechaValida($vence)) throw new RuntimeException('Revisa la fecha de la tarea.');
                $eventos->agregarTarea($nid, $id, (string) ($_POST['titulo'] ?? ''), $vence ?: null);
                aviso('Tarea agregada.');
                $volver['#'] = 'tareas';
                break;
            case 'tarea_marcar':
                $hecha = ($_POST['hecha'] ?? '1') === '1';
                $st = db()->prepare('SELECT 1 FROM evento_tareas WHERE id = ? AND evento_id = ?');
                $st->execute([(int) ($_POST['tarea_id'] ?? 0), $id]);
                if (!$st->fetchColumn()) throw new RuntimeException('No se encontró la tarea.');
                $eventos->marcarTarea($nid, (int) $_POST['tarea_id'], $hecha);
                aviso($hecha ? 'Tarea hecha.' : 'Tarea marcada como pendiente.');
                break;
            case 'actividad':
                $tipo = (string) ($_POST['tipo'] ?? 'nota');
                if (!isset(Ventas::TIPOS_SEGUIMIENTO[$tipo])) $tipo = 'nota';
                $prog = trim((string) ($_POST['programada'] ?? ''));
                $eventos->agregarActividad($nid, $id, (int) $ev['cliente_id'], $tipo, (string) ($_POST['texto'] ?? ''), $prog ?: null, (int) $u['id']);
                aviso($prog ? 'Quedó agendado. Lo verás en Seguimiento.' : 'Seguimiento guardado.');
                break;
            case 'actividad_marcar':
                $st = db()->prepare('SELECT 1 FROM actividades WHERE id = ? AND evento_id = ? AND negocio_id = ?');
                $st->execute([(int) ($_POST['actividad_id'] ?? 0), $id, $nid]);
                if (!$st->fetchColumn()) throw new RuntimeException('No se encontró la actividad.');
                $eventos->marcarActividad($nid, (int) $_POST['actividad_id'], true);
                aviso('Marcado como hecho.');
                break;
            case 'coordinador':
                $nombre = $ventas->asignarCoordinador($nid, $id, (int) ($_POST['coordinador_id'] ?? 0) ?: null);
                registrar_cambio($nid, $id, $nombre ? "Asignó como coordinador a $nombre" : 'Quitó el coordinador');
                if ($nombre && (int) $_POST['coordinador_id'] !== (int) $u['id']) {
                    (new \TuRenta\Notificaciones(db()))->aUsuarios($nid, [(int) $_POST['coordinador_id']],
                        "Te asignaron como coordinador de {$ev['nombre']} ({$ev['codigo']}), " . fecha_corta($ev['fecha']) . '.', $id);
                }
                aviso($nombre ? "$nombre coordina este evento." : 'El evento quedó sin coordinador.');
                break;
            case 'detalles':
                $ventas->guardarDetalles($nid, $id, (string) ($_POST['menu_elegido'] ?? ''), (string) ($_POST['detalles_cliente'] ?? ''));
                registrar_cambio($nid, $id, 'Actualizó el menú elegido y los detalles del cliente');
                aviso('Detalles guardados.');
                break;
            case 'duplicar':
                $fecha = (string) ($_POST['fecha'] ?? '');
                if (!Ventas::fechaValida($fecha)) throw new RuntimeException('Elige la fecha del nuevo evento.');
                if ($fecha < date('Y-m-d')) throw new RuntimeException('Elige una fecha desde hoy.');
                $nuevoId = $eventos->duplicar($nid, $id, $fecha, (int) $u['id']);
                $nuevoEv = $eventos->obtener($nid, $nuevoId);
                registrar_cambio($nid, $nuevoId, "Creado como copia de {$ev['codigo']}");
                registrar_cambio($nid, $id, "Se copió a otra fecha: {$nuevoEv['codigo']} (" . fecha_corta($fecha) . ')');
                aviso('Evento copiado al ' . fecha_corta($fecha) . ' como consulta. Revisa los datos y arma su cotización.');
                redirigir('evento', ['id' => $nuevoId]);
            default:
                throw new RuntimeException('Acción no válida.');
        }
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
    }
    $ancla = $volver['#'] ?? '';
    unset($volver['#']);
    header('Location: ' . url('evento', $volver) . ($ancla ? "#$ancla" : ''));
    exit;
}

$hoy = new DateTimeImmutable('today');
$verDinero = $u['rol'] !== 'coordinador';
$datos = [
    'ev' => $ev, 'tab' => 'evento', 'puedeVender' => $puedeVender, 'verDinero' => $verDinero,
    'cliente' => $eventos->cliente($nid, (int) $ev['cliente_id']),
    'tareas' => $eventos->tareas($id), 'actividades' => $eventos->actividades($nid, $id),
    'historial' => $eventos->historial($nid, $id),
    'choques' => in_array($ev['estado'], Eventos::ACTIVOS, true)
        ? Ventas::separarChoques($eventos->choques($nid, $ev['fecha'], $ev['hora_inicio'], $ev['hora_fin'], array_column($ev['espacios'], 'id'), $id, $hoy))
        : ['bloqueados' => [], 'otros' => []],
    'capacidad' => $ventas->capacidad($nid, array_column($ev['espacios'], 'id'), $ev['montaje']),
    'equipo' => $ventas->usuariosEquipo($nid),
    'hoyTxt' => $hoy->format('Y-m-d'),
    'diasRetencion' => (int) db()->query('SELECT dias_retencion FROM negocios WHERE id = ' . $nid)->fetchColumn(),
];
if ($verDinero) {
    $datos['dinero'] = (new Pagos(db()))->resumen($id, $hoy);
    $cots = (new Cotizaciones(db()))->deEvento($nid, $id);
    $datos['cotizacion'] = $cots[0] ?? null;
    $datos['numCotizaciones'] = count($cots);
    $datos['contrato'] = (new Contratos(db()))->vigente($nid, $id);
}
vista('evento', $datos, $ev['nombre'] . ' · TuRenta');
