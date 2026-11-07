<?php
use TuRenta\{Eventos, Ia, Ventas};

/**
 * Cotización a partir de un mensaje (módulo «Asistente IA»): el usuario pega el texto de WhatsApp del cliente
 * («boda de 150 personas el 14 de febrero con DJ…») y la IA propone el evento y la cotización con los espacios,
 * paquetes y productos del catálogo del negocio (JSON validado en Ia::validarPropuesta). Se muestra como BORRADOR:
 * el usuario lo revisa, corrige y guarda (crea el evento en consulta y la cotización en borrador).
 * Si no se puede usar la IA (lo que sea), se muestra tukan_ia_mensaje_neutro() con el enlace para hacerlo a mano.
 *   POST accion=leer (mensaje) · POST accion=guardar (borrador revisado)
 */
exigir(es_comercial($u) && puede_editar($u), 'Solo el dueño y los vendedores pueden crear eventos.');
$nid = (int) $u['negocio_id'];
$aMano = url_completa('evento_nuevo');
$mensaje = '';
$propuesta = null;
$aviso = null;
$error = null;
$catalogo = ia()->catalogo($nid);

/** Borrador desde lo que mandó el formulario (para volver a mostrarlo si hay un error al guardar). */
$desdePost = function () use ($catalogo): array {
    $lineas = [];
    foreach ((array) ($_POST['linea'] ?? []) as $pid => $l) {
        if (!is_array($l) || !isset($catalogo['productos'][(int) $pid])) continue;
        $lineas[] = ['producto_id' => (int) $pid, 'cantidad' => (float) TuRenta\Cotizaciones::num($l['cantidad'] ?? 0), 'usar' => !empty($l['usar'])];
    }
    return [
        'cliente' => ['nombre' => campo('cliente_nombre'), 'telefono' => campo('cliente_telefono'), 'email' => campo('cliente_email')],
        'evento' => ['tipo' => campo('tipo', 'otro'), 'fecha' => campo('fecha'), 'hora_inicio' => campo('hora_inicio', '18:00'), 'hora_fin' => campo('hora_fin', '02:00'),
                     'invitados' => (int) campo('invitados'), 'espacio_ids' => array_map('intval', (array) ($_POST['espacios'] ?? [])), 'notas' => campo('notas'),
                     'nombre' => campo('nombre')],
        'paquete_id' => (int) campo('paquete_id') ?: null,
        'lineas' => $lineas,
        'dudas' => array_values(array_filter(array_map(fn($x) => is_string($x) ? mb_substr(trim($x), 0, 200) : '', (array) ($_POST['dudas'] ?? [])))),
    ];
};

if (es_post()) {
    verificar_csrf();
    $accion = (string) ($_POST['accion'] ?? '');
    $mensaje = mb_substr(trim((string) ($_POST['mensaje'] ?? '')), 0, Ia::MAX_MENSAJE + 1);
    try {
        if ($accion === 'leer') {
            $r = ia()->proponerCotizacion($nid, $mensaje, $aMano);
            if ($r['ok']) {
                $propuesta = $r['propuesta'];
            } else {
                $aviso = $r['aviso'];
                error_log('TuRenta IA cotizacion_mensaje (negocio ' . $nid . '): ' . ($r['motivo'] ?? ''));
            }
        } elseif ($accion === 'guardar') {
            $propuesta = $desdePost();
            [$datos, $paquete, $lineas] = ia()->leerBorrador($nid, $_POST);
            $datos['vendedor_id'] = (int) $u['id'];
            if (Ventas::fechaValida($datos['fecha']) && $datos['espacios']) {
                $ch = Ventas::separarChoques((new Eventos(db()))->choques($nid, $datos['fecha'], $datos['hora_inicio'], $datos['hora_fin'], $datos['espacios']));
                if ($ch['bloqueados'] && empty($_POST['aceptar_choque'])) {
                    throw new RuntimeException('Ese espacio no está libre a esa hora. Cambie la fecha, la hora o el espacio. Si igual quiere guardarlo como consulta (lista de espera), marque la casilla de abajo.');
                }
            }
            [$evId, $cotId] = ia()->guardarBorrador($nid, $datos, $paquete, $lineas, (int) $u['id']);
            $ev = (new Eventos(db()))->obtener($nid, $evId);
            registrar_cambio($nid, $evId, 'Creó el evento ' . $ev['codigo'] . ' y su cotización desde un mensaje del cliente (Asistente IA)');
            aviso('Evento y cotización creados desde el mensaje. Revise la cotización y envíela cuando esté lista.');
            redirigir('cotizacion', ['id' => $evId, 'c' => $cotId]);
        } else {
            throw new RuntimeException('Acción no válida.');
        }
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}

vista('ia_cotizacion', ['mensaje' => $mensaje, 'propuesta' => $propuesta, 'aviso' => $aviso, 'error' => $error, 'aMano' => $aMano,
                        'catalogo' => $catalogo], 'Cotización desde un mensaje · TuRenta');
