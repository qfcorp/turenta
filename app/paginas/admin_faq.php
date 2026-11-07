<?php
/**
 * Panel Tukán · Ayuda → Preguntas frecuentes (IA): las respuestas que la Ayuda guardó (automáticas) y las escritas a mano.
 * Listar con filtro (Por revisar primero), aprobar, editar (queda aprobada y «manual»), borrar (con confirmación) y agregar.
 * Todo POST con CSRF y en registro_admin. Solo el súper usuario (el router lo exige: $deAdmin).
 */
$faq = ia()->faq();
$filtros = ['sin_aprobar' => 'Por revisar', '' => 'Todas', 'automatica' => 'Automáticas', 'manual' => 'Escritas a mano'];
$filtro = (string) ($_GET['f'] ?? 'sin_aprobar');
if (!isset($filtros[$filtro])) $filtro = 'sin_aprobar';
$editar = null;
$error = null;
$form = ['pregunta' => '', 'respuesta' => ''];

if (es_post()) {
    verificar_csrf();
    $accion = (string) ($_POST['accion'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $pregunta = trim((string) preg_replace('/\s+/u', ' ', (string) ($_POST['pregunta'] ?? '')));
    $respuesta = trim(str_replace("\r\n", "\n", (string) ($_POST['respuesta'] ?? '')));
    try {
        $existe = $id > 0 ? $faq->obtener($id) : null;
        if (in_array($accion, ['aprobar', 'editar', 'borrar'], true) && !$existe) throw new RuntimeException('Esa pregunta ya no existe.');
        if (in_array($accion, ['editar', 'agregar'], true)) {
            if (mb_strlen($pregunta) < 5 || mb_strlen($pregunta) > 500) throw new RuntimeException('La pregunta debe tener entre 5 y 500 letras.');
            if (mb_strlen($respuesta) < 5 || mb_strlen($respuesta) > 4000) throw new RuntimeException('La respuesta debe tener entre 5 y 4000 letras.');
        }
        switch ($accion) {
            case 'aprobar':
                $faq->aprobar($id);
                registrar_admin('Preguntas frecuentes (IA): aprobó N.º ' . $id . ' «' . mb_substr($existe['pregunta'], 0, 120) . '»');
                aviso('Pregunta aprobada.');
                break;
            case 'editar':
                $faq->editar($id, $pregunta, $respuesta);
                registrar_admin('Preguntas frecuentes (IA): editó N.º ' . $id . ' «' . mb_substr($pregunta, 0, 120) . '»');
                aviso('Pregunta guardada y aprobada. Desde ahora se responde así a todos.');
                break;
            case 'borrar':
                if (($_POST['confirmar'] ?? '') !== 'si') throw new RuntimeException('Confirma que quieres borrar la pregunta.');
                $faq->borrar($id);
                registrar_admin('Preguntas frecuentes (IA): borró N.º ' . $id . ' «' . mb_substr($existe['pregunta'], 0, 120) . '»');
                aviso('Pregunta borrada.');
                break;
            case 'agregar':
                $nuevo = $faq->agregar($pregunta, $respuesta);
                registrar_admin('Preguntas frecuentes (IA): agregó N.º ' . $nuevo . ' «' . mb_substr($pregunta, 0, 120) . '»');
                aviso('Pregunta agregada (aprobada).');
                break;
            default:
                throw new RuntimeException('Acción no válida.');
        }
        redirigir('admin_faq', ['f' => $filtro]);
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
        if ($accion === 'agregar') $form = ['pregunta' => $pregunta, 'respuesta' => $respuesta];
        if ($accion === 'editar' && $existe) $editar = ['id' => $id, 'pregunta' => $pregunta, 'respuesta' => $respuesta] + $existe;
    }
}
if (!$editar && !empty($_GET['editar'])) $editar = $faq->obtener((int) $_GET['editar']);
$lista = $faq->listar($filtro);
$resumen = $faq->resumen();
$estadoIa = tukan_ia_estado();
vista_admin('admin_faq', compact('lista', 'resumen', 'filtros', 'filtro', 'editar', 'error', 'form', 'estadoIa'), 'Preguntas frecuentes (IA) · Panel Tukán');
