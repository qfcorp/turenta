<?php
use TuRenta\{Fotos, Ventas};

/**
 * Espacios (salón, jardín, terraza…) y fechas cerradas.
 * Acciones (POST ?accion=): guardar (crear/editar, con foto), quitar_foto, activar, desactivar, cerrar, abrir.
 *   /?r=espacios&editar=<id>   ·   /?r=espacios&nuevo=1
 * Lo cambia el dueño; los demás solo ven.
 */
$nid = (int) $u['negocio_id'];
$ventas = new Ventas(db());
$puedeEditar = es_admin_total($u);
$error = null;
$editarId = (int) ($_GET['editar'] ?? 0);
$form = null;

if (es_post()) {
    exigir($puedeEditar, 'Solo el dueño o un administrador puede cambiar los espacios y las fechas cerradas.');
    verificar_csrf();
    $accion = (string) ($_GET['accion'] ?? '');
    $id = (int) ($_POST['id'] ?? 0) ?: null;
    try {
        switch ($accion) {
            case 'guardar':
                $nuevo = $id === null;
                $id = $ventas->guardarEspacio($nid, $_POST, $id);
                $archivo = $_FILES['foto'] ?? null;
                if ($archivo && ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    try {
                        $nombre = Fotos::guardarImagen($archivo, Fotos::carpeta('espacios'));
                        $vieja = $ventas->ponerFotoEspacio($nid, $id, $nombre);
                        if ($vieja) Fotos::borrar('espacios', $vieja);
                    } catch (RuntimeException $e) {
                        aviso('El espacio se guardó, pero la foto no: ' . $e->getMessage(), 'error');
                        redirigir('espacios', ['editar' => $id]);
                    }
                }
                aviso($nuevo ? 'Espacio creado.' : 'Espacio guardado.');
                redirigir('espacios');
            case 'quitar_foto':
                $vieja = $ventas->ponerFotoEspacio($nid, (int) $id, null);
                if ($vieja) Fotos::borrar('espacios', $vieja);
                aviso('Foto quitada.');
                redirigir('espacios', ['editar' => $id]);
            case 'activar':
            case 'desactivar':
                $ventas->activarEspacio($nid, (int) $id, $accion === 'activar');
                aviso($accion === 'activar' ? 'Espacio activado.' : 'Espacio desactivado. Ya no aparece para nuevos eventos ni en tu página.');
                break;
            case 'cerrar':
                $fecha = (string) ($_POST['fecha'] ?? '');
                $espacioId = (int) ($_POST['espacio_id'] ?? 0) ?: null;
                $afectados = $ventas->agregarFechaCerrada($nid, $fecha, $espacioId, (string) ($_POST['motivo'] ?? ''));
                $msg = 'Fecha cerrada: ' . fecha_corta($fecha) . '.';
                if ($afectados) {
                    $msg .= ' Ojo: ese día ya hay ' . count($afectados) . ' ' . (count($afectados) === 1 ? 'evento' : 'eventos') . ' ('
                          . implode(', ', array_column($afectados, 'codigo')) . '). No se borran: avisa a esos clientes o cámbialos de fecha.';
                }
                aviso($msg, $afectados ? 'error' : 'ok');
                header('Location: ' . url('espacios') . '#cerradas');
                exit;
            case 'abrir':
                $ventas->borrarFechaCerrada($nid, (int) ($_POST['cerrada_id'] ?? 0));
                aviso('La fecha vuelve a estar abierta.');
                break;
            default:
                throw new RuntimeException('Acción no válida.');
        }
    } catch (RuntimeException $e) {
        if ($accion === 'guardar') {
            $error = $e->getMessage();
            $form = array_map(fn($v) => is_string($v) ? $v : '', $_POST) + ['nombre' => '', 'descripcion' => '', 'capacidad_banquete' => '',
                     'capacidad_coctel' => '', 'precio_base' => '', 'color' => '#6B2D3C', 'orden' => ''];
            $editarId = $id ?? 0;
        } else {
            aviso($e->getMessage(), 'error');
        }
    }
    if ($error === null) redirigir('espacios');
}

$espacios = $ventas->espacios($nid);
$activos = count(array_filter($espacios, fn($e) => $e['activo']));
$max = $ventas->maxEspacios($nid);
$puedeAgregar = planes()->puedeAgregarEspacio($nid);
if ($form === null) {
    if ($editarId && ($esp = $ventas->espacio($nid, $editarId))) {
        $form = $esp;
    } elseif (isset($_GET['nuevo']) && $puedeAgregar) {
        $form = ['id' => '', 'nombre' => '', 'descripcion' => '', 'capacidad_banquete' => '', 'capacidad_coctel' => '', 'precio_base' => '',
                 'color' => '#3E7C59', 'orden' => $activos + 1, 'foto' => null];
    } else {
        $editarId = 0;
    }
} else {
    $form['foto'] = $editarId ? ($ventas->espacio($nid, $editarId)['foto'] ?? null) : null;
    $form['id'] = $editarId ?: '';
}

vista('espacios', [
    'espacios' => $espacios, 'activos' => $activos, 'max' => $max, 'puedeAgregar' => $puedeAgregar, 'puedeEditar' => $puedeEditar,
    'form' => $puedeEditar ? $form : null, 'error' => $error, 'cerradas' => $ventas->fechasCerradas($nid),
    'hoyTxt' => date('Y-m-d'),
], 'Espacios · TuRenta');
