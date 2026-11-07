<?php
use TuRenta\{Cuestionarios, Encuestas, Eventos, Extras};

/**
 * Evento — pestaña "Formulario y extras" (migración 070): /?r=evento_formulario&id=<evento>
 *  - Formulario del evento (copia de la plantilla de su tipo): respuestas del cliente; el local las corrige, agrega o quita preguntas.
 *  - Extras que el cliente pidió desde su portal: aprobar (se suma a la cotización y al plan de pagos) o rechazar con nota.
 *  - Encuesta de satisfacción del cliente.
 * Permisos: dueño y vendedor cambian todo; el coordinador solo ve el formulario (sin dinero); consulta solo mira.
 * Acciones (POST accion=...): respuestas, pregunta_agregar, pregunta_borrar, recargar, aprobar, rechazar.
 */
$nid = (int) $u['negocio_id'];
$id = (int) ($_GET['id'] ?? 0);
$ev = (new Eventos(db()))->obtener($nid, $id);
if (!$ev) {
    http_response_code(404);
    vista('error', ['mensaje' => 'No encontramos ese evento. Puede que el enlace esté mal o que sea de otro negocio.'], 'Formulario · TuRenta');
    return;
}
$tab = 'evento_formulario';
$cues = new Cuestionarios(db());
$extras = new Extras(db());
$comercial = es_comercial($u);
$verDinero = $u['rol'] !== 'coordinador';

if (es_post()) {
    verificar_csrf();
    exigir(puede_editar($u), 'Tu usuario solo puede mirar.');
    $accion = (string) ($_POST['accion'] ?? '');
    $ancla = 'formulario';
    try {
        exigir($comercial, 'Solo el dueño o un vendedor pueden cambiar el formulario y responder los pedidos del cliente.');
        switch ($accion) {
            case 'respuestas':
                $resp = is_array($_POST['r'] ?? null) ? $_POST['r'] : [];
                $presentes = is_array($_POST['en'] ?? null) ? $_POST['en'] : [];
                $f = $cues->guardarRespuestas($nid, $id, $resp, $presentes, (string) $u['nombre'], false);
                aviso('Respuestas guardadas (' . $f['respondidas'] . ' de ' . $f['total'] . ').');
                break;
            case 'pregunta_agregar':
                $cues->agregarPreguntaEvento($nid, $id, $_POST, $u);
                aviso('Pregunta agregada al formulario de este evento. El cliente ya la ve en su portal.');
                $ancla = 'preguntas';
                break;
            case 'pregunta_borrar':
                $cues->borrarPreguntaEvento($nid, $id, (int) ($_POST['pregunta_id'] ?? 0), $u);
                aviso('Pregunta quitada del formulario de este evento.');
                $ancla = 'preguntas';
                break;
            case 'recargar':
                $cues->recargarPlantilla($nid, $id, $u);
                aviso('Se volvió a cargar el formulario del tipo de evento.');
                break;
            case 'aprobar':
            case 'rechazar':
                $ancla = 'extras';
                $pid = (int) ($_POST['pedido_id'] ?? 0);
                $p = $extras->pedido($nid, $pid);
                if (!$p || (int) $p['evento_id'] !== $id) throw new RuntimeException('No se encontró ese pedido.');
                if ($accion === 'aprobar') {
                    $r = $extras->aprobar($nid, $pid, $u, campo('nota'));
                    aviso('Extras aprobados: el evento sube ' . dinero($r['monto']) . ' (nuevo total ' . dinero($r['total']) . '). Se creó la cotización N.º '
                        . $r['numero'] . ' (versión ' . $r['version'] . ')' . ($r['cuota_id'] ? ' y una cuota nueva en el plan de pagos' : '') . '. '
                        . ($r['anexo_id'] ? 'Como el contrato ya está firmado, se preparó un anexo para que el cliente lo firme: envíale el enlace por WhatsApp.'
                           : ($r['contrato_sin_firmar'] ? 'Ojo: el contrato N.º ' . $r['contrato_sin_firmar'] . ' (sin firmar) no incluye estos extras; anúlalo y prepara uno nuevo para que los incluya.'
                              : 'El evento todavía no tiene contrato firmado: los extras entran en el contrato cuando lo generes.') . ' Avísale al cliente por WhatsApp.'));
                } else {
                    $extras->rechazar($nid, $pid, $u, campo('nota'));
                    aviso('Pedido no aprobado. El cliente ve tu nota en su portal; avísale por WhatsApp.');
                }
                break;
            default:
                throw new RuntimeException('Acción no válida.');
        }
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
        if ($accion === 'pregunta_agregar') $_SESSION['pt_pregunta_borrador'] = $_POST;
    }
    header('Location: ' . url('evento_formulario', ['id' => $id]) . '#' . $ancla);
    exit;
}

$form = $cues->deEvento($nid, $id);
$pedidos = $verDinero ? $extras->deEvento($nid, $id) : [];
$borradores = 0;
if ($verDinero) {
    $st = db()->prepare("SELECT count(*) FROM cotizaciones WHERE negocio_id = ? AND evento_id = ? AND estado IN ('borrador','enviada')");
    $st->execute([$nid, $id]);
    $borradores = (int) $st->fetchColumn();
}
$anexosPorPedido = [];   // anexo de contrato de cada pedido aprobado (090)
if ($verDinero) foreach ((new \TuRenta\Anexos(db()))->deEvento($nid, $id) as $a) if ($a['pedido_id']) $anexosPorPedido[(int) $a['pedido_id']] = $a;
$contratoFirmado = $verDinero && (new \TuRenta\Anexos(db()))->contratoFirmado($nid, $id) !== null;
$borradorPregunta = $_SESSION['pt_pregunta_borrador'] ?? null;
unset($_SESSION['pt_pregunta_borrador']);
vista('evento_formulario', [
    'ev' => $ev, 'tab' => $tab, 'form' => $form, 'comercial' => $comercial && puede_editar($u), 'verDinero' => $verDinero,
    'pedidos' => $pedidos, 'borradores' => $borradores, 'encuesta' => (new Encuestas(db()))->deEvento($nid, $id),
    'borradorPregunta' => $borradorPregunta, 'anexosPorPedido' => $anexosPorPedido, 'contratoFirmado' => $contratoFirmado,
], ($verDinero ? 'Formulario y extras · ' : 'Formulario · ') . $ev['nombre']);
