<?php
use TuRenta\{DiaEvento, Eventos, Invitados};

/**
 * Portal de invitados para los novios u organizadores (público, ?t=token_cliente, módulo invitados):
 * arman su lista, las mesas y mandan las invitaciones desde el celular.
 */
$token = (string) ($_GET['t'] ?? '');
$ev = (new Eventos(db()))->porToken('token_cliente', $token);
$negocio = null;
if ($ev) {
    $st = db()->prepare('SELECT * FROM negocios WHERE id = ?');
    $st->execute([$ev['negocio_id']]);
    $negocio = $st->fetch();
}
if (!$ev || !DiaEvento::publicoActivo(db(), $negocio, 'invitados') || in_array($ev['estado'], ['perdido', 'cancelado'], true)) {
    http_response_code(404);
    vista_publica('error_publico', ['mensaje' => 'Este enlace no es válido o ya no está activo. Pide uno nuevo al lugar del evento.'], 'Invitados', $negocio ? DiaEvento::marca($negocio) : null);
    return;
}
$nid = (int) $ev['negocio_id'];
$id = (int) $ev['id'];
$inv = new Invitados(db());
$editable = $ev['fecha'] >= date('Y-m-d');
// Plano del salón en PDF (solo mirar): /?r=portal_invitados&t=…&plano=pdf
if (($_GET['plano'] ?? '') === 'pdf') {
    $plPlano = (new \TuRenta\Planos(db()))->delEvento($nid, $id);
    if (!$plPlano) {
        http_response_code(404);
        vista_publica('error_publico', ['mensaje' => 'Este evento todavía no tiene plano del salón.'], 'Invitados', DiaEvento::marca($negocio));
        return;
    }
    \TuRenta\Pdf::enviar(\TuRenta\PdfPlano::plano($negocio, (new \TuRenta\Planos(db()))->datos($nid, (int) $plPlano['id']), $ev), 'plano-' . $ev['codigo'] . '.pdf');
    return;
}
$verInv = (string) ($_GET['ver'] ?? 'lista');
if (!in_array($verInv, ['lista', 'mesas', 'invitacion'], true)) $verInv = 'lista';

if (es_post()) {
    verificar_csrf();
    $accion = (string) ($_POST['accion'] ?? '');
    $volver = in_array($_POST['ver'] ?? '', ['lista', 'mesas', 'invitacion'], true) ? $_POST['ver'] : $verInv;
    try {
        if (!$editable) throw new RuntimeException('El evento ya pasó: la lista ya no se puede cambiar.');
        if ($accion === 'enviar_wa') {
            $i = $inv->invitado($nid, (int) ($_POST['invitado_id'] ?? 0));
            if (!$i || (int) $i['evento_id'] !== $id) throw new RuntimeException('No se encontró al invitado.');
            $inv->marcarInvitacionEnviada($nid, (int) $i['id']);
            header('Location: ' . DiaEvento::wa($i['telefono'], Invitados::mensajeInvitacion($i, $ev, $negocio, url_completa('invitacion', ['t' => $i['token']]))));
            exit;
        }
        $antes = $inv->contadores($nid, $id)['invitados'];
        foreach ($inv->accion($nid, $id, $accion, $_POST, false) as [$texto, $tipo]) aviso($texto, $tipo);
        if (in_array($accion, ['agregar', 'importar'], true) && $antes === 0) {
            registrar_cambio($nid, $id, 'El cliente empezó su lista de invitados', null, 'Cliente');
        }
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
        if (in_array($accion, ['agregar', 'importar'], true)) $_SESSION['form_invitados'] = $_POST;
    }
    $params = ['t' => $token, 'ver' => $volver];
    foreach (['buscar', 'estado', 'grupo', 'mesa'] as $k) if (!empty($_POST['f_' . $k])) $params[$k] = $_POST['f_' . $k];
    redirigir('portal_invitados', $params);
}

$f = ['buscar' => trim((string) ($_GET['buscar'] ?? '')), 'estado' => (string) ($_GET['estado'] ?? ''), 'grupo' => (string) ($_GET['grupo'] ?? ''),
      'mesa_id' => (string) ($_GET['mesa'] ?? ''), 'orden' => 'grupo'];
$previo = $_SESSION['form_invitados'] ?? null;
unset($_SESSION['form_invitados']);
vista_publica('portal_invitados', [
    'ev' => $ev, 'negocio' => $negocio, 'verInv' => $verInv, 'f' => $f, 'previo' => $previo,
    'lista' => $inv->lista($nid, $id, $f), 'cont' => $inv->contadores($nid, $id), 'mesas' => $inv->mesas($nid, $id),
    'grupos' => $inv->grupos($nid, $id), 'plano' => $verInv === 'mesas' ? $inv->plano($nid, $id) : null,
    'editable' => $editable, 'esNegocio' => false, 'token' => $token,
    'ruta' => fn(array $extra = []) => url('portal_invitados', ['t' => $token] + $extra),
    'oculto' => ['r' => 'portal_invitados', 't' => $token],
], 'Invitados · ' . $ev['nombre'], DiaEvento::marca($negocio));
