<?php
use TuRenta\{DiaEvento, Eventos, Invitados};

/**
 * Modo puerta (público, ?t=token_puerta): el personal de la entrada busca al invitado y marca su ingreso.
 * Al abrirlo, la sesión queda con permiso de puerta para ese evento: así "ingreso" registra al escanear el QR con la cámara.
 * &json=1 -> contadores (se actualiza cada 15 s) · &q=texto&json=1 -> búsqueda
 */
$token = (string) ($_GET['t'] ?? '');
$ev = (new Eventos(db()))->porToken('token_puerta', $token);
$negocio = null;
if ($ev) {
    $st = db()->prepare('SELECT * FROM negocios WHERE id = ?');
    $st->execute([$ev['negocio_id']]);
    $negocio = $st->fetch();
}
if (!$ev || !DiaEvento::publicoActivo(db(), $negocio, 'invitados') || in_array($ev['estado'], ['perdido', 'cancelado'], true)) {
    http_response_code(404);
    if (!empty($_GET['json'])) { header('Content-Type: application/json'); echo json_encode(['error' => 'Enlace no válido']); return; }
    vista_publica('error_publico', ['mensaje' => 'Este enlace de la puerta no es válido o el evento fue cancelado.'], 'Puerta', $negocio ? DiaEvento::marca($negocio) : null);
    return;
}
$id = (int) $ev['id'];
$nid = (int) $ev['negocio_id'];
$_SESSION['puerta'][$id] = true;
$inv = new Invitados(db());

if (!empty($_GET['json'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $c = $inv->contadores($nid, $id);
    $res = ['ingresaron' => $c['ingresaron'], 'esperados' => $c['esperados'], 'pases' => $c['pases'], 'confirmados' => $c['confirmados']];
    if (isset($_GET['q'])) {
        $res['resultados'] = array_map(fn($x) => [
            'id' => (int) $x['id'], 'nombre' => $x['nombre'], 'grupo' => $x['grupo'], 'mesa' => $x['mesa'], 'pases' => (int) $x['pases'],
            'ingresaron' => (int) $x['ingresaron'], 'estado' => $x['estado'], 'confirmados' => $x['confirmados'] === null ? null : (int) $x['confirmados'],
        ], $inv->buscarPuerta($id, (string) $_GET['q']));
    }
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    return;
}

$resultado = null;
if (es_post()) {
    verificar_csrf();
    $q = (string) ($_POST['q'] ?? '');
    try {
        $accion = (string) ($_POST['accion'] ?? 'ingreso');
        $iid = (int) ($_POST['invitado_id'] ?? 0);
        if ($accion === 'deshacer') {
            $inv->deshacerIngreso($id, $iid, 1);
            aviso('Se restó 1 ingreso.');
        } else {
            $r = $inv->registrarIngreso($iid, max(1, (int) ($_POST['cuantos'] ?? 1)), $id);
            $_SESSION['puerta_resultado'] = ['ok' => $r['ok'], 'mensaje' => $r['mensaje'], 'nombre' => $r['invitado']['nombre'],
                'mesa' => $r['invitado']['mesa'], 'entraron' => $r['entraron'], 'ingresaron' => (int) $r['invitado']['ingresaron'],
                'pases' => (int) $r['invitado']['pases'], 'id' => (int) $r['invitado']['id']];
        }
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
    }
    redirigir('puerta', ['t' => $token] + ($q !== '' ? ['q' => $q] : []));
}
$resultado = $_SESSION['puerta_resultado'] ?? null;
unset($_SESSION['puerta_resultado']);

$q = trim((string) ($_GET['q'] ?? ''));
vista_publica('puerta', [
    'ev' => $ev, 'negocio' => $negocio, 'token' => $token, 'q' => $q, 'resultado' => $resultado,
    'cont' => $inv->contadores($nid, $id),
    'lista' => $q !== '' ? $inv->buscarPuerta($id, $q) : [],
    'ultimos' => $inv->ultimosIngresos($id),
    'esHoy' => $ev['fecha'] === date('Y-m-d') || $ev['fecha'] === date('Y-m-d', strtotime('-1 day')),
], 'Puerta · ' . $ev['nombre'], DiaEvento::marca($negocio));
