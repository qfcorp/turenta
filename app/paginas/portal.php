<?php
use TuRenta\{Eventos, Pagos, Contratos, Cotizaciones, PdfCobros, PdfDocumentos, Pdf};

/**
 * Portal del cliente (público, con el enlace secreto): /?r=portal&t=<token_cliente>
 *  - Siempre: datos del evento y pagos. Con cada módulo encendido (Anexo B): cotización, contrato, invitados,
 *    cronograma (día del evento) y transmisión en vivo.
 *  - &recibo=<pago_id>: recibo en PDF de uno de sus pagos.  &contrato=pdf: su contrato firmado en PDF.
 */
$token = (string) ($_GET['t'] ?? '');
$ev = (new Eventos(db()))->porToken('token_cliente', $token);
if (!$ev) {
    http_response_code(404);
    vista_publica('error_publico', ['mensaje' => 'Este enlace no es válido o ya no existe. Revisa que esté completo o pide uno nuevo al local donde contrataste tu evento.'],
                  'Portal del cliente · TuRenta');
    return;
}
$nid = (int) $ev['negocio_id'];
$eid = (int) $ev['id'];
$st = db()->prepare('SELECT * FROM negocios WHERE id = ?');
$st->execute([$nid]);
$negocio = $st->fetch();
$marca = ['nombre' => $negocio['nombre'], 'color' => $negocio['color'], 'slug' => $negocio['slug'], 'logo' => $negocio['logo']];
$pagos = new Pagos(db());
$contratos = new Contratos(db());

// Recibo en PDF de uno de sus pagos
if (isset($_GET['recibo'])) {
    $pagoId = (int) $_GET['recibo'];
    $p = $pagos->pago($nid, $pagoId);
    try {
        if (!$p || (int) $p['evento_id'] !== $eid) throw new RuntimeException('No se encontró ese recibo.');
        $pdf = (new PdfCobros(db()))->recibo($nid, $pagoId);
        Pdf::enviar($pdf, 'recibo-' . str_pad((string) $p['numero_recibo'], 5, '0', STR_PAD_LEFT) . '.pdf');
        return;
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
        redirigir('portal', ['t' => $token]);
    }
}
// Contrato firmado en PDF
if (($_GET['contrato'] ?? '') === 'pdf' && modulo_activo($nid, 'contratos')) {
    $c = $contratos->vigente($nid, $eid);
    if ($c && $c['estado'] === 'firmado' && class_exists(PdfDocumentos::class)) {
        $pdf = PdfDocumentos::contrato($negocio, $c, url_completa('verificar', ['c' => $c['codigo_verificacion']]));
        Pdf::enviar($pdf, PdfDocumentos::nombreArchivo('contrato', $c));
        return;
    }
    aviso('Tu contrato todavía no está firmado.', 'error');
    redirigir('portal', ['t' => $token]);
}

$mod = [];
foreach (['contratos', 'invitados', 'dia_evento', 'boda_vivo'] as $m) $mod[$m] = modulo_activo($nid, $m);
$activo = !in_array($ev['estado'], ['perdido', 'cancelado'], true);
$res = $pagos->resumen($eid);
$historial = array_values(array_filter($pagos->pagos($nid, $eid), fn($p) => in_array($p['estado'], ['confirmado', 'por_revisar', 'rechazado'], true)));
$cotizacion = (new Cotizaciones(db()))->aceptadaDe($eid);
if (!$cotizacion) {
    $st = db()->prepare("SELECT id FROM cotizaciones WHERE evento_id = ? AND estado = 'enviada' ORDER BY version DESC LIMIT 1");
    $st->execute([$eid]);
    $cid = $st->fetchColumn();
    $cotizacion = $cid ? (new Cotizaciones(db()))->obtener($nid, (int) $cid) : null;
}
$contrato = $mod['contratos'] ? $contratos->vigente($nid, $eid) : null;
if ($contrato && $contrato['estado'] === 'borrador') $contrato = null;
$payphone = $activo && $res['saldo'] > 0 && $pagos->payphoneListo($nid);
$banco = trim((string) $negocio['pago_banco']);
$deuna = trim((string) $negocio['pago_deuna']);

$invitados = null;
$cronograma = [];
if ($mod['invitados']) {
    $st = db()->prepare("SELECT count(*) AS registros, COALESCE(sum(pases),0) AS pases,
                                COALESCE(sum(COALESCE(confirmados, pases)) FILTER (WHERE estado = 'confirmado'),0) AS confirmados,
                                count(*) FILTER (WHERE estado = 'pendiente') AS pendientes,
                                count(*) FILTER (WHERE estado = 'no_asiste') AS no_asisten
                           FROM invitados WHERE evento_id = ?");
    $st->execute([$eid]);
    $invitados = $st->fetch();
}
if ($mod['dia_evento']) {
    $st = db()->prepare('SELECT hora, duracion_min, actividad FROM cronograma WHERE evento_id = ? ORDER BY hora, orden');
    $st->execute([$eid]);
    $cronograma = $st->fetchAll();
    // Si el evento pasa de medianoche, las horas de la madrugada van al final
    if ($ev['hora_fin'] <= $ev['hora_inicio']) {
        $corte = date('H:i:s', strtotime($ev['hora_inicio']) - 4 * 3600);
        usort($cronograma, fn($a, $b) => [($a['hora'] < $corte), $a['hora']] <=> [($b['hora'] < $corte), $b['hora']]);
    }
}
$telNegocio = $negocio['whatsapp'] ?: $negocio['telefono'];
$primerNombre = trim(explode(' ', trim((string) $ev['cliente']))[0]);
$waNegocio = \TuRenta\WhatsApp::enlace($telNegocio, "Hola, soy $primerNombre ({$ev['codigo']} · {$ev['nombre']}). Te escribo desde mi portal del evento.");
vista_publica('portal', compact('ev', 'negocio', 'token', 'mod', 'activo', 'res', 'historial', 'cotizacion', 'contrato', 'payphone', 'banco', 'deuna',
                                 'invitados', 'cronograma', 'waNegocio', 'primerNombre'),
              'Mi evento · ' . $negocio['nombre'], $marca);
