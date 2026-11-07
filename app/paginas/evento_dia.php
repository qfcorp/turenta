<?php
use TuRenta\{DiaEvento, Eventos, Invitados, WhatsApp};

/**
 * Día del evento (módulo dia_evento; proveedores, incidencias, mobiliario y boda en vivo con su propio módulo): cronograma con modo en vivo, personal, proveedores, mobiliario,
 * montaje y menú, boda en vivo y hoja del evento.  /?r=evento_dia&id=<evento>&ver=<seccion>
 */
$nid = (int) $u['negocio_id'];
$id = (int) ($_GET['id'] ?? 0);
$ev = (new Eventos(db()))->obtener($nid, $id);
if (!$ev) {
    http_response_code(404);
    vista('error', ['mensaje' => 'No se encontró el evento.'], 'Día del evento · TuRenta');
    return;
}
$tab = 'evento_dia';
$secciones = ['cronograma' => 'Cronograma', 'personal' => 'Personal', 'proveedores' => 'Proveedores', 'mobiliario' => 'Mobiliario', 'detalles' => 'Montaje y menú', 'vivo' => 'Boda en vivo'];
$secciones = array_slice($secciones, 0, 3, true) + ['incidencias' => 'Incidencias'] + array_slice($secciones, 3, null, true);   // 060
// Anexo B: cada sección de otro módulo solo sale con ese módulo encendido
foreach (['proveedores' => 'proveedores', 'incidencias' => 'incidencias', 'mobiliario' => 'mobiliario', 'vivo' => 'boda_vivo'] as $sec => $mod) {
    if (!modulo_activo($u, $mod)) unset($secciones[$sec]);
}
$ver = (string) ($_GET['ver'] ?? 'cronograma');
if (!isset($secciones[$ver])) $ver = 'cronograma';
$dia = new DiaEvento(db());

if (es_post()) {
    verificar_csrf();
    exigir(puede_editar($u), 'Tu usuario solo puede mirar.');
    $accion = (string) ($_POST['accion'] ?? '');
    $moduloAccion = match (true) {
        str_starts_with($accion, 'prov_') => 'proveedores',
        in_array($accion, ['mobiliario', 'danados'], true) => 'mobiliario',
        in_array($accion, ['transmision', 'pedir_transmision'], true) => 'boda_vivo',
        default => '',
    };
    exigir(modulo_activo($u, $moduloAccion), 'Ese módulo no está en tu plan. Agrégalo en Mi plan.');
    $volver = (string) ($_POST['ver'] ?? $ver);
    if (!isset($secciones[$volver])) $volver = 'cronograma';
    try {
        switch ($accion) {
            // Cronograma
            case 'bloque_agregar':
                $dia->guardarBloque($nid, $id, null, $_POST);
                aviso('Se agregó ' . campo('actividad') . ' al cronograma.');
                break;
            case 'bloque_editar':
                $dia->guardarBloque($nid, $id, (int) ($_POST['bloque_id'] ?? 0), $_POST);
                aviso('Cambios guardados.');
                break;
            case 'bloque_borrar':
                $dia->borrarBloque($nid, $id, (int) ($_POST['bloque_id'] ?? 0));
                aviso('Se quitó del cronograma.');
                break;
            case 'bloque_subir':
            case 'bloque_bajar':
                $dia->moverBloque($nid, $id, (int) ($_POST['bloque_id'] ?? 0), $accion === 'bloque_subir' ? -1 : 1);
                break;
            case 'ordenar':
                $dia->ordenarPorHora($nid, $id);
                aviso('Cronograma ordenado por hora.');
                break;
            case 'ejemplo':
                $n = $dia->cargarEjemplo($nid, $id, campo('plantilla') ?: null, !empty($_POST['reemplazar']));
                aviso("Listo: se cargaron $n momentos desde la hora de inicio. Ajusta las horas y los responsables a lo que pidió el cliente.");
                registrar_cambio($nid, $id, 'Cargó el cronograma de ejemplo');
                break;
            case 'vivo_reiniciar':
                $dia->reiniciarVivo($nid, $id);
                aviso('Se borraron las horas reales del modo en vivo.');
                break;
            // Personal
            case 'asignar':
                $dia->asignar($nid, $id, (int) ($_POST['personal_id'] ?? 0), [
                    'rol' => campo('rol'), 'hora_entrada' => campo('hora_entrada'), 'hora_salida' => campo('hora_salida'),
                    'pago' => numero(campo('pago')),
                ], $u);
                aviso('Asignado. Mándale el aviso por WhatsApp para que confirme.');
                break;
            case 'meseros_auto':
                $n = $dia->asignarMeserosFaltantes($nid, $id, $u);
                aviso($n === 1 ? 'Se asignó 1 mesero libre.' : "Se asignaron $n meseros libres.");
                break;
            case 'asig_editar':
                $dia->editarAsignacion($nid, (int) ($_POST['asig_id'] ?? 0), ['rol' => campo('rol'), 'hora_entrada' => campo('hora_entrada'),
                    'hora_salida' => campo('hora_salida'), 'pago' => numero(campo('pago')) ?? 0]);
                aviso('Cambios guardados.');
                break;
            case 'marcar':
                $campoM = campo('campo');
                $valor = campo('valor');
                $dia->marcar($nid, (int) ($_POST['asig_id'] ?? 0), $campoM, $valor === '' ? null : $valor === '1', $u);
                break;
            case 'asig_quitar':
                $dia->quitarAsignacion($nid, (int) ($_POST['asig_id'] ?? 0), $u);
                aviso('Se quitó del evento.');
                break;
            case 'pagar_todos':
                $n = 0;
                foreach ($dia->personalDeEvento($nid, $id) as $p) {
                    if (!$p['pagado'] && $p['asistio'] !== false) { $dia->marcar($nid, (int) $p['id'], 'pagado', true, $u); $n++; }
                }
                aviso($n ? "Se marcó como pagado a $n " . ($n === 1 ? 'persona.' : 'personas.') : 'No había pagos pendientes.');
                break;
            // Proveedores
            case 'prov_agregar':
                $d = $_POST;
                if (($d['origen_prov'] ?? 'lista') === 'cliente') { unset($d['proveedor_id']); $d['lo_trae_cliente'] = 1; }
                else { unset($d['nombre'], $d['lo_trae_cliente']); if (empty($d['proveedor_id'])) throw new RuntimeException('Elige el proveedor de tu lista.'); }
                $dia->agregarProveedorEvento($nid, $id, $d);
                aviso('Proveedor agregado al evento.');
                break;
            case 'prov_editar':
                $dia->editarProveedorEvento($nid, (int) ($_POST['prov_id'] ?? 0), $_POST);
                aviso('Cambios guardados.');
                break;
            case 'prov_confirmar':
                $dia->confirmarProveedorEvento($nid, (int) ($_POST['prov_id'] ?? 0), campo('valor') === '1');
                break;
            case 'prov_quitar':
                $dia->quitarProveedorEvento($nid, (int) ($_POST['prov_id'] ?? 0));
                aviso('Proveedor quitado del evento.');
                break;
            // Cumplimiento del proveedor (060): llegada real y evaluación después del evento
            case 'prov_llegada':
                if (!campo('ahora') && campo('hora_real') === '') throw new RuntimeException('Escribe la hora en que llegó, o toca "Llegó ahora".');
                $pu = (new \TuRenta\ProveedoresFicha(db()))->marcarLlegada($nid, (int) ($_POST['prov_id'] ?? 0), campo('ahora') ? null : campo('hora_real'), $u);
                aviso('Llegada anotada' . ($pu ? ': ' . mb_strtolower($pu['texto']) : '') . '.');
                break;
            case 'prov_sin_llegada':
                (new \TuRenta\ProveedoresFicha(db()))->quitarLlegada($nid, (int) ($_POST['prov_id'] ?? 0), $u);
                aviso('Se borró la hora de llegada.');
                break;
            case 'prov_evaluar':
                (new \TuRenta\ProveedoresFicha(db()))->evaluar($nid, (int) ($_POST['prov_id'] ?? 0), ['cumplimiento' => campo('cumplimiento'),
                    'calificacion' => campo('calificacion'), 'comentario' => campo('comentario')], $u);
                aviso('Evaluación guardada. Cuenta para el puntaje del proveedor.');
                break;
            // Mobiliario
            case 'mobiliario':
                $faltan = $dia->reservarMobiliario($nid, $id, array_map('intval', (array) ($_POST['cant'] ?? [])));
                if ($faltan) {
                    aviso('Se guardó, pero no alcanza: ' . implode(', ', array_map(fn($f) => "faltan {$f['faltan']} {$f['nombre']} (hay {$f['disponible']} libres ese día)", $faltan))
                        . '. Consigue o alquila lo que falta.', 'error');
                } else {
                    aviso('Mobiliario reservado. Alcanza para todo.');
                }
                break;
            case 'danados':
                $valor = $dia->registrarDanados($nid, $id, array_map('intval', (array) ($_POST['danados'] ?? [])), $u);
                aviso($valor > 0 ? 'Daños registrados: ' . dinero($valor) . ' a cobrar. Descuéntalo de la garantía en la pestaña Pagos.' : 'Listo: no hubo daños.');
                break;
            // Transmisión
            case 'transmision':
                $dia->guardarTransmision($nid, $id, campo('transmision_url'), campo('transmision_estado', 'no'), $u);
                aviso('Transmisión guardada. El enlace aparece en el portal del cliente y en las invitaciones.');
                break;
            case 'pedir_transmision':
                header('Location: ' . $dia->pedirTransmision($nid, $id, $u));
                exit;
            default:
                throw new RuntimeException('Acción no válida.');
        }
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
    }
    redirigir('evento_dia', ['id' => $id, 'ver' => $volver]);
}

$st = db()->prepare('SELECT * FROM negocios WHERE id = ?');
$st->execute([$nid]);
$negocio = $st->fetch();
$cronograma = $dia->cronograma($nid, $id);
$personalEv = $dia->personalDeEvento($nid, $id);
$resumen = $dia->resumenPersonal($nid, $id, $personalEv);
$proveedoresEv = $dia->proveedoresDeEvento($nid, $id);
$mobiliario = $dia->disponibilidadMobiliario($nid, $ev['fecha'], $id);
$conteos = [
    'cronograma' => count($cronograma), 'personal' => count($personalEv), 'proveedores' => count($proveedoresEv),
    'mobiliario' => count(array_filter($mobiliario, fn($m) => $m['reservado'] > 0)),
];
$datos = compact('ev', 'tab', 'secciones', 'ver', 'negocio', 'cronograma', 'personalEv', 'resumen', 'proveedoresEv', 'mobiliario', 'conteos');
$datos['enlaceVivo'] = url_completa('vivo', ['t' => $ev['token_vivo']]);
$st = db()->prepare('SELECT telefono FROM usuarios WHERE id = ? AND negocio_id = ?');
$st->execute([(int) $ev['coordinador_id'], $nid]);
$datos['coordTel'] = $st->fetchColumn() ?: null;
$datos['vivo'] = $dia->vivo($id);
if ($ver === 'personal') {
    $datos['personalLista'] = $dia->personalLista($nid, true);
    $datos['ocupados'] = $dia->ocupadosEseDia($nid, $ev['fecha'], $id);
}
if ($ver === 'proveedores') $datos['catalogoProv'] = $dia->proveedores($nid, true);
// Proveedores y compras (060): puntajes, compras unidas, incidencias del evento
$fichaProv = new \TuRenta\ProveedoresFicha(db());
$datos['puntajesProv'] = in_array($ver, ['proveedores'], true) ? $fichaProv->puntajes($nid) : [];
$datos['toleranciaProv'] = $fichaProv->tolerancia($nid);   // minutos de tolerancia del negocio (091)
$datos['incidenciasEv'] = (new \TuRenta\Incidencias(db()))->deEvento($nid, $id);
$datos['conteos']['incidencias'] = count(array_filter($datos['incidenciasEv'], fn($i) => $i['estado'] !== 'resuelta'));
$datos['comprasEv'] = $u['rol'] !== 'coordinador' ? array_column((new \TuRenta\Compras(db()))->deEvento($nid, $id), null, 'id') : [];
if ($ver === 'mobiliario') $datos['valorDanos'] = $dia->valorDanos($id);
if ($ver === 'detalles') $datos['restricciones'] = (new Invitados(db()))->restricciones($nid, $id);
vista('evento_dia', $datos, 'Día del evento · ' . $ev['nombre']);
