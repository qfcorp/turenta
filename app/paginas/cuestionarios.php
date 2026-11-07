<?php
use TuRenta\{Cuestionarios, Eventos};

/**
 * Formularios del evento (plantillas por tipo de evento): /?r=cuestionarios&tipo=<tipo>
 * Solo el dueño los cambia (la cuenta de consulta solo mira). Los cambios valen para los eventos nuevos:
 * los formularios ya copiados a un evento no cambian.
 * Acciones (POST accion=...): ajustes, plantilla, pregunta, pregunta_borrar, subir, bajar, restaurar.
 */
exigir(es_admin_total($u) || $u['rol'] === 'consulta', 'Solo el dueño o un administrador configura los formularios del evento.');
$nid = (int) $u['negocio_id'];
$editar = es_admin_total($u);
$cues = new Cuestionarios(db());
$plantillas = $cues->plantillas($nid);
$porTipo = array_column($plantillas, null, 'tipo_evento');
$tipo = (string) ($_GET['tipo'] ?? 'boda');
if (!isset($porTipo[$tipo])) $tipo = array_key_first($porTipo) ?? 'boda';

if (es_post()) {
    verificar_csrf();
    exigir($editar, 'Solo el dueño o un administrador puede cambiar los formularios del evento.');
    $accion = (string) ($_POST['accion'] ?? '');
    $plId = (int) ($porTipo[$tipo]['id'] ?? 0);
    $ancla = 'preguntas';
    try {
        switch ($accion) {
            case 'ajustes':
                $cues->guardarAjustes($nid, (int) ($_POST['dias_cierre'] ?? 7), (int) ($_POST['dias_aviso'] ?? 30));
                aviso('Listo: el cliente puede llenar el formulario hasta ' . (int) $_POST['dias_cierre'] . ' días antes del evento.');
                $ancla = 'ajustes';
                break;
            case 'plantilla':
                $cues->guardarPlantilla($nid, $plId, campo('nombre'), !empty($_POST['activo']));
                aviso(!empty($_POST['activo']) ? 'Formulario guardado.' : 'Formulario desactivado: los eventos de este tipo usarán el formulario de "Otro".');
                $ancla = 'plantilla';
                break;
            case 'pregunta':
                $pid = (int) ($_POST['pregunta_id'] ?? 0) ?: null;
                $nuevo = $cues->guardarPregunta($nid, $plId, $pid, $_POST);
                aviso($pid ? 'Pregunta guardada.' : 'Pregunta agregada.');
                $ancla = 'q' . $nuevo;
                break;
            case 'pregunta_borrar':
                $cues->borrarPregunta($nid, (int) ($_POST['pregunta_id'] ?? 0));
                aviso('Pregunta borrada.');
                break;
            case 'subir':
            case 'bajar':
                $cues->moverPregunta($nid, (int) ($_POST['pregunta_id'] ?? 0), $accion === 'subir' ? -1 : 1);
                $ancla = 'q' . (int) $_POST['pregunta_id'];
                break;
            case 'restaurar':
                $cues->restaurarEjemplo($nid, $plId);
                aviso('Se pusieron otra vez las preguntas de ejemplo.');
                break;
            default:
                throw new RuntimeException('Acción no válida.');
        }
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
        if ($accion === 'pregunta') $_SESSION['pt_plantilla_borrador'] = $_POST;
    }
    header('Location: ' . url('cuestionarios', ['tipo' => $tipo]) . '#' . $ancla);
    exit;
}

$borrador = $_SESSION['pt_plantilla_borrador'] ?? null;
unset($_SESSION['pt_plantilla_borrador']);
vista('cuestionarios', [
    'plantillas' => $plantillas, 'tipo' => $tipo, 'plantilla' => $cues->plantilla($nid, (int) $porTipo[$tipo]['id']),
    'ajustes' => $cues->ajustes($nid), 'editar' => $editar, 'borrador' => $borrador,
], 'Formularios del evento · TuRenta');
