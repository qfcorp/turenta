<?php
use TuRenta\{DiaEvento, Eventos, Invitados};

/**
 * Invitados del evento (módulo invitados): lista, pegar lista, mesas, invitaciones por WhatsApp,
 * enlace del modo puerta, PDF y CSV.  /?r=invitados&id=<evento>&ver=lista|mesas|invitacion  ·  &csv=1
 */
$nid = (int) $u['negocio_id'];
$id = (int) ($_GET['id'] ?? 0);
$ev = (new Eventos(db()))->obtener($nid, $id);
if (!$ev) {
    http_response_code(404);
    vista('error', ['mensaje' => 'No se encontró el evento.'], 'Invitados · TuRenta');
    return;
}
$tab = 'invitados';
$inv = new Invitados(db());
$st = db()->prepare('SELECT * FROM negocios WHERE id = ?');
$st->execute([$nid]);
$negocio = $st->fetch();

if (!empty($_GET['csv'])) {
    while (ob_get_level() > 0) ob_end_clean();
    $archivo = 'invitados-' . preg_replace('/[^A-Za-z0-9-]+/', '-', $ev['codigo']) . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $archivo . '"');
    header('Cache-Control: private, no-store');
    echo $inv->csv($nid, $id);
    return;
}

$verInv = (string) ($_GET['ver'] ?? 'lista');
if (!in_array($verInv, ['lista', 'mesas', 'invitacion'], true)) $verInv = 'lista';

if (es_post()) {
    verificar_csrf();
    exigir(puede_editar($u), 'Tu usuario solo puede mirar.');
    $accion = (string) ($_POST['accion'] ?? '');
    $volver = in_array($_POST['ver'] ?? '', ['lista', 'mesas', 'invitacion'], true) ? $_POST['ver'] : $verInv;
    try {
        if ($accion === 'enviar_wa') {
            // Marca la invitación como enviada y abre WhatsApp con el mensaje listo
            $i = $inv->invitado($nid, (int) ($_POST['invitado_id'] ?? 0));
            if (!$i || (int) $i['evento_id'] !== $id) throw new RuntimeException('No se encontró al invitado.');
            $inv->marcarInvitacionEnviada($nid, (int) $i['id']);
            header('Location: ' . DiaEvento::wa($i['telefono'], Invitados::mensajeInvitacion($i, $ev, $negocio, url_completa('invitacion', ['t' => $i['token']]))));
            exit;
        }
        foreach ($inv->accion($nid, $id, $accion, $_POST) as [$texto, $tipo]) aviso($texto, $tipo);
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
        if (in_array($accion, ['agregar', 'importar'], true)) $_SESSION['form_invitados'] = $_POST;
    }
    $params = ['id' => $id, 'ver' => $volver];
    foreach (['buscar', 'estado', 'grupo', 'mesa'] as $k) if (!empty($_POST['f_' . $k])) $params[$k] = $_POST['f_' . $k];
    redirigir('invitados', $params);
}

$f = ['buscar' => trim((string) ($_GET['buscar'] ?? '')), 'estado' => (string) ($_GET['estado'] ?? ''), 'grupo' => (string) ($_GET['grupo'] ?? ''),
      'mesa_id' => (string) ($_GET['mesa'] ?? ''), 'orden' => 'grupo'];
$previo = $_SESSION['form_invitados'] ?? null;
unset($_SESSION['form_invitados']);
vista('invitados', [
    'ev' => $ev, 'tab' => $tab, 'negocio' => $negocio, 'verInv' => $verInv, 'f' => $f, 'previo' => $previo,
    'lista' => $inv->lista($nid, $id, $f), 'cont' => $inv->contadores($nid, $id), 'mesas' => $inv->mesas($nid, $id),
    'grupos' => $inv->grupos($nid, $id), 'plano' => $verInv === 'mesas' ? $inv->plano($nid, $id) : null,
    'enlacePuerta' => url_completa('puerta', ['t' => $ev['token_puerta']]),
    'enlacePortal' => url_completa('portal_invitados', ['t' => $ev['token_cliente']]),
    'editable' => puede_editar($u), 'esNegocio' => true,
    'ruta' => fn(array $extra = []) => url('invitados', ['id' => $id] + $extra),
    'oculto' => ['r' => 'invitados', 'id' => $id],
], 'Invitados · ' . $ev['nombre']);
