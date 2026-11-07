<?php
use TuRenta\{Eventos, Planos, Ventas};

/**
 * Plano visual del salón (módulo plano).
 *   /?r=plano                    distribuciones (plantillas) de los espacios · &espacio=<id> para elegir el espacio
 *   /?r=plano&p=<plano>          editor de una distribución (el dueño la cambia; los demás miran)
 *   /?r=plano&id=<evento>        plano del evento, pestaña "Plano" (dueño, vendedor y coordinador lo cambian; consulta mira)
 *   &json=1                      los datos del plano en JSON
 * POST accion=: crear_plantilla, datos, duplicar, borrar, evento_crear, evento_borrar (formularios)
 *               guardar (lo manda el editor con fetch: csrf, version, elementos = JSON) -> responde JSON
 */
$nid = (int) $u['negocio_id'];
$planos = new Planos(db());
$eventoId = (int) ($_GET['id'] ?? 0);
$planoId = (int) ($_GET['p'] ?? 0);
$editaEventos = in_array($u['rol'], ['dueno', 'admin', 'vendedor', 'coordinador'], true);
$editaPlantillas = es_admin_total($u);

$responderJson = function (array $datos, int $codigo = 200): never {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
};

// ------------------------------------------------------------------ Evento
$ev = null;
if ($eventoId) {
    $ev = (new Eventos(db()))->obtener($nid, $eventoId);
    if (!$ev) {
        http_response_code(404);
        vista('error', ['mensaje' => 'No se encontró el evento.'], 'Plano · TuRenta');
        return;
    }
}
$plano = null;
if ($planoId) {
    try {
        $plano = $planos->plano($nid, $planoId);
    } catch (RuntimeException $e) {
        if (es_post() && ($_POST['accion'] ?? '') === 'guardar') $responderJson(['ok' => false, 'error' => $e->getMessage()], 404);
        http_response_code(404);
        vista('error', ['mensaje' => 'No se encontró el plano. Puede que lo hayan borrado.'], 'Plano · TuRenta');
        return;
    }
    if ($plano['tipo'] === 'evento' && (!$ev || (int) $ev['id'] !== (int) $plano['evento_id'])) {
        $ev = (new Eventos(db()))->obtener($nid, (int) $plano['evento_id']);
    } elseif ($plano['tipo'] === 'plantilla') {
        $ev = null;
    }
} elseif ($ev) {
    $plano = $planos->delEvento($nid, $eventoId);
}
$tab = 'plano';


$puedeEditarPlano = fn(?array $p) => $p && puede_editar($u) && ($p['tipo'] === 'evento' ? $editaEventos : $editaPlantillas);

// ------------------------------------------------------------------ POST
if (es_post()) {
    $accion = (string) ($_POST['accion'] ?? '');
    if ($accion === 'guardar') {
        // Lo manda el editor (fetch). Siempre responde JSON.
        if (!hash_equals(token_csrf(), (string) ($_POST['csrf'] ?? ''))) {
            $responderJson(['ok' => false, 'error' => 'La página caducó. Recarga para seguir (tus últimos cambios no se guardaron).'], 400);
        }
        if (!$puedeEditarPlano($plano)) {
            $responderJson(['ok' => false, 'error' => $plano && $plano['tipo'] === 'plantilla' ? 'Solo el dueño o un administrador puede cambiar las distribuciones de los espacios.' : 'Tu usuario solo puede mirar el plano.'], 403);
        }
        $elementos = json_decode((string) ($_POST['elementos'] ?? ''), true);
        $version = isset($_POST['version']) && ctype_digit((string) $_POST['version']) ? (int) $_POST['version'] : null;
        try {
            $r = $planos->guardar($nid, (int) $plano['id'], $elementos, $version, $u);
            $responderJson(['ok' => true] + $r);
        } catch (RuntimeException $e) {
            $conflicto = str_contains($e->getMessage(), 'Otra persona');
            $responderJson(['ok' => false, 'error' => $e->getMessage(), 'conflicto' => $conflicto], $conflicto ? 409 : 422);
        }
    }

    verificar_csrf();
    exigir(puede_editar($u), 'Tu usuario solo puede mirar.');
    $volver = $ev ? ['id' => $ev['id']] : ($plano ? ['p' => $plano['id']] : []);
    try {
        switch ($accion) {
            case 'crear_plantilla':
                exigir($editaPlantillas, 'Solo el dueño o un administrador puede crear distribuciones de los espacios.');
                $nuevo = $planos->crearPlantilla($nid, (int) ($_POST['espacio_id'] ?? 0), campo('nombre'), campo('ancho'), campo('largo'), (int) $u['id'], campo('ejemplo'));
                aviso('Distribución creada. Agrega y mueve los elementos; se guarda solo.');
                redirigir('plano', ['p' => $nuevo]);
            case 'datos':
                exigir($puedeEditarPlano($plano), 'No puedes cambiar este plano.');
                $planos->editarDatos($nid, (int) $plano['id'], campo('nombre'), campo('ancho'), campo('largo'));
                aviso('Medidas guardadas.');
                break;
            case 'duplicar':
                exigir($editaPlantillas && $plano && $plano['tipo'] === 'plantilla', 'Solo el dueño o un administrador puede copiar distribuciones.');
                $nuevo = $planos->duplicarPlantilla($nid, (int) $plano['id'], (int) $u['id']);
                aviso('Copia creada. Cámbiale el nombre y lo que necesites.');
                redirigir('plano', ['p' => $nuevo]);
            case 'borrar':
                exigir($editaPlantillas && $plano && $plano['tipo'] === 'plantilla', 'Solo el dueño o un administrador puede borrar distribuciones.');
                $planos->borrar($nid, (int) $plano['id'], $u);
                aviso('Distribución borrada. Los planos de los eventos que la usaron no cambian.');
                redirigir('plano', ['espacio' => $plano['espacio_id']]);
            case 'evento_crear':
                exigir($ev && $editaEventos, 'Tu usuario no puede armar el plano de este evento.');
                $planos->crearParaEvento($nid, (int) $ev['id'], (int) ($_POST['plantilla_id'] ?? 0) ?: null, campo('ancho'), campo('largo'), $u);
                aviso('Plano listo. Las mesas del plano son las mesas de los invitados: lo que cambies aquí se ve allá.');
                redirigir('plano', ['id' => $ev['id']]);
            case 'evento_borrar':
                exigir($ev && $plano && $editaEventos, 'Tu usuario no puede borrar el plano de este evento.');
                $planos->borrar($nid, (int) $plano['id'], $u);
                aviso('Plano borrado. Las mesas y los invitados sentados se mantienen.');
                redirigir('plano', ['id' => $ev['id']]);
            default:
                throw new RuntimeException('Acción no válida.');
        }
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
        if ($accion === 'crear_plantilla') {
            $_SESSION['form_plano'] = $_POST;
            redirigir('plano', ['espacio' => (int) ($_POST['espacio_id'] ?? 0)]);
        }
        if ($accion === 'evento_crear') $_SESSION['form_plano'] = $_POST;
    }
    redirigir('plano', $volver);
}

// ------------------------------------------------------------------ JSON
if (!empty($_GET['json'])) {
    if (!$plano) $responderJson(['ok' => false, 'error' => 'Este evento todavía no tiene plano.'], 404);
    $responderJson(['ok' => true] + $planos->datos($nid, (int) $plano['id']));
}

// ------------------------------------------------------------------ Pantallas
$previo = $_SESSION['form_plano'] ?? null;
unset($_SESSION['form_plano']);

if ($ev && !$plano) {
    // El evento todavía no tiene plano: elegir distribución o empezar desde cero
    $plantillas = $planos->plantillasParaEvento($nid, (int) $ev['id']);
    $vistas = [];
    foreach ($plantillas as $p) $vistas[(int) $p['id']] = Planos::svg($planos->datos($nid, (int) $p['id']), ['id' => 'pv' . $p['id'], 'clase' => 'pl-mini-svg']);
    $st = db()->prepare('SELECT count(*) FROM mesas WHERE evento_id = ?');
    $st->execute([$ev['id']]);
    vista('plano_evento_nuevo', ['ev' => $ev, 'tab' => $tab, 'plantillas' => $plantillas, 'vistas' => $vistas, 'mesasHay' => (int) $st->fetchColumn(),
        'puedeArmar' => $editaEventos && puede_editar($u), 'previo' => $previo], 'Plano · ' . $ev['nombre']);
    return;
}

if ($plano) {
    $datos = $planos->datos($nid, (int) $plano['id']);
    $editable = $puedeEditarPlano($plano);
    vista('plano_editor', [
        'ev' => $ev, 'tab' => $tab, 'plano' => $plano, 'planoDatos' => $datos, 'editable' => $editable,
        'puedeBorrar' => $plano['tipo'] === 'evento' ? $editaEventos && puede_editar($u) : $editaPlantillas,
        'config' => [
            'editable' => $editable, 'guardar' => url('plano', ['p' => $plano['id']]), 'csrf' => token_csrf(),
            'invitados' => $ev ? url('invitados', ['id' => $ev['id'], 'ver' => 'mesas']) : null,
            'tipos' => array_map(fn($t) => ['nombre' => $t['nombre'], 'corto' => $t['corto'], 'an' => $t['ancho'], 'al' => $t['alto'], 'puestos' => $t['puestos'] ?? null], Planos::TIPOS),
            'colores' => Planos::COLORES,
        ],
    ], ($ev ? 'Plano · ' . $ev['nombre'] : 'Distribución · ' . $plano['nombre']));
    return;
}

// Lista de distribuciones por espacio
$ventas = new Ventas(db());
$espacios = array_values(array_filter($ventas->espacios($nid), fn($e) => $e['activo']));
$lista = $planos->plantillas($nid);
$vistas = [];
foreach ($lista as $p) $vistas[(int) $p['id']] = Planos::svg($planos->datos($nid, (int) $p['id']), ['id' => 'pv' . $p['id'], 'clase' => 'pl-mini-svg']);
vista('planos', ['espacios' => $espacios, 'lista' => $lista, 'vistas' => $vistas, 'editaPlantillas' => $editaPlantillas,
    'espacioElegido' => (int) ($_GET['espacio'] ?? ($previo['espacio_id'] ?? 0)), 'previo' => $previo], 'Planos del salón · TuRenta');
