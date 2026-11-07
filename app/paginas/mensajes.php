<?php
use TuRenta\Cobranza;

/**
 * Mensajes por WhatsApp de hoy: /?r=mensajes
 * Recordatorios listos para mandar con un toque. Al marcar "enviado" no vuelve a salir.
 */
exigir($u['rol'] !== 'coordinador', 'Los mensajes a clientes los manda el dueño o un vendedor.');
$nid = (int) $u['negocio_id'];
$cob = new Cobranza(db());

if (es_post()) {
    verificar_csrf();
    exigir(puede_editar($u), 'Tu usuario solo puede mirar.');
    $accion = (string) ($_GET['accion'] ?? '');
    $tipo = (string) ($_POST['tipo'] ?? '');
    $ref = (int) ($_POST['ref_id'] ?? 0);
    try {
        if (!isset(Cobranza::MENSAJES[$tipo])) throw new RuntimeException('Tipo de mensaje no válido.');
        if ($accion === 'enviado') {
            $cob->marcarEnviado($nid, $tipo, $ref, $u);
            aviso('Listo, marcado como enviado.');
        } elseif ($accion === 'deshacer') {
            $cob->desmarcarEnviado($nid, $tipo, $ref);
            aviso('Se volvió a poner en la lista.');
        } else {
            throw new RuntimeException('Acción no válida.');
        }
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
    }
    header('Location: ' . url('mensajes') . '#' . ($tipo !== '' ? 'm-' . preg_replace('/[^a-z]/', '', $tipo) : ''));
    exit;
}

$grupos = $cob->mensajesDelDia($nid);
$enviados = $cob->enviadosHoy($nid);
$total = array_sum(array_map('count', $grupos));
$st = db()->prepare('SELECT resenas_url FROM negocios WHERE id = ?');
$st->execute([$nid]);
$sinResenas = trim((string) $st->fetchColumn()) === '';
vista('mensajes', compact('grupos', 'enviados', 'total', 'sinResenas'), 'Mensajes por WhatsApp · TuRenta');
