<?php
use TuRenta\{Modulos, PanelTukan, Planes};

$id = (int) ($_GET['id'] ?? 0);
$panel = new PanelTukan(db());
$planes = planes();
$negocio = $panel->negocio($id);
if (!$negocio) { aviso('Negocio no encontrado.', 'error'); redirigir('admin_negocios'); }
$claveNueva = null;
if (!empty($_SESSION['clave_nueva_' . $id])) {   // recién creado desde "Nuevo negocio": se muestra una vez
    $claveNueva = $_SESSION['clave_nueva_' . $id];
    unset($_SESSION['clave_nueva_' . $id]);
}

if (es_post()) {
    verificar_csrf();
    $accion = (string) ($_GET['accion'] ?? ($_POST['accion'] ?? ''));
    try {
        switch ($accion) {
            case 'pago':
                $r = $panel->registrarPago($id, $_POST);
                registrar_admin('Pago registrado: ' . $r['plan'] . ' ' . $r['periodo'] . ' ' . dinero($r['monto']) . ' (' . (PanelTukan::METODOS[$r['metodo']] ?? $r['metodo']) . ')', $id);
                aviso('Pago registrado. El negocio quedó activo.');
                break;
            case 'anular_pago':
                $pagoId = (int) ($_POST['pago'] ?? 0);
                $st = db()->prepare('SELECT 1 FROM suscripciones WHERE id = ? AND negocio_id = ?');
                $st->execute([$pagoId, $id]);
                if (!$st->fetchColumn()) throw new RuntimeException('Ese pago no es de este negocio.');
                $planes->anularPago($pagoId);
                registrar_admin('Pago anulado N.º ' . $pagoId, $id);
                aviso('Pago anulado.');
                break;
            case 'plan':
                $panel->cambiarPlan($id, (string) ($_POST['plan'] ?? ''));
                registrar_admin('Plan cambiado a ' . $_POST['plan'], $id);
                aviso('Plan cambiado.');
                break;
            case 'estado':
                $panel->cambiarEstado($id, (string) ($_POST['estado'] ?? ''));
                registrar_admin('Estado cambiado a ' . $_POST['estado'], $id);
                aviso('Estado cambiado.');
                break;
            case 'prueba':
                $hasta = $panel->extenderPrueba($id, (int) ($_POST['dias'] ?? 0));
                registrar_admin('Demo extendida hasta ' . $hasta, $id);
                aviso('Demo hasta el ' . date('d/m/Y', strtotime($hasta)) . '.');
                break;
            case 'notas':
                $panel->guardarNotas($id, (string) ($_POST['notas'] ?? ''));
                registrar_admin('Notas internas actualizadas', $id);
                aviso('Notas guardadas.');
                break;
            case 'restaurar':   // Anexo A: sacar de la papelera (dentro de los 30 días)
                if (empty($negocio['eliminado_en'])) throw new RuntimeException('Este negocio no está en la papelera.');
                if (TuRenta\Papelera::fechaPurga($negocio['eliminado_en']) <= date('Y-m-d')) throw new RuntimeException('Ya pasaron los 30 días de la papelera.');
                (new TuRenta\Papelera(db()))->restaurar($id);
                registrar_admin('Restaurado de la papelera', $id);
                aviso('Negocio restaurado: vuelve tal como estaba.');
                break;
            case 'modulos':   // Anexo B: encender y apagar módulos, precio acordado y módulos de la demo
                $mods = modulos();
                $antes = $mods->estado($id);
                $marcados = array_values(array_filter(array_keys((array) ($_POST['modulo'] ?? [])), fn($m) => is_string($m) && Modulos::existe($m)));
                $base = $antes['pagados'];
                $acordadoTxt = trim((string) ($_POST['precio_acordado'] ?? ''));
                if ($acordadoTxt !== '' && !is_numeric(str_replace(',', '.', $acordadoTxt))) throw new RuntimeException('Escribe el precio acordado con números (vacío = el calculado).');
                $acordado = $acordadoTxt === '' ? null : round((float) str_replace(',', '.', $acordadoTxt), 2);
                $r = $mods->cambiar($id, array_values(array_diff($marcados, $base)), array_values(array_diff($base, $marcados)), $acordado);
                if ($antes['demo']) $mods->elegirDemo($id, !empty($_POST['demo_igual']) ? $mods->estado($id)['pagados'] : null);
                registrar_admin('Módulos: ' . $r['texto'] . ' · ' . dinero($mods->estado($id)['mensual']) . ' al mes', $id);
                aviso($r['sin_cambios'] ? 'Sin cambios en los módulos.' : 'Módulos guardados. ' . $r['texto'] . '.');
                break;
            case 'solicitud_modulos':
                $sid = (int) ($_POST['solicitud'] ?? 0);
                $sol = modulos()->solicitud($sid);
                if (!$sol || (int) $sol['negocio_id'] !== $id) throw new RuntimeException('Ese pedido no es de este negocio.');
                $resultado = ($_POST['resultado'] ?? '') === 'aprobada' ? 'aprobada' : 'rechazada';
                $r = modulos()->atender($sid, $resultado, (string) ($_POST['respuesta'] ?? ''), (superadmin()['nombre'] ?? 'Panel Tukán'));
                registrar_admin('Pedido de módulos N.º ' . $sid . ' ' . $resultado . ($r ? ': ' . $r['texto'] : ''), $id);
                aviso($resultado === 'aprobada' ? 'Pedido aprobado: ' . $r['texto'] . '.' : 'Pedido rechazado.');
                break;
            case 'clave':
                $_SESSION['clave_nueva_' . $id] = $panel->nuevaClaveDueno($id);
                registrar_admin('Nueva contraseña para el dueño', $id);
                aviso('Contraseña nueva creada. La anterior ya no funciona.');
                break;
            default:
                throw new RuntimeException('Acción no válida.');
        }
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
    }
    redirigir('admin_negocio', ['id' => $id]);
}
// «Enviar por correo» la demo recién creada (LEEME_CORREO.md): la contraseña va SELLADA (cifrada) en el formulario, no se guarda
$correoDemo = null;
if ($claveNueva && in_array($negocio['estado_cuenta'], ['prueba', 'prueba_vencida'], true)) {
    $correoDemo = ['listo' => TuRenta\CorreoDemo::listo(), 'para' => (string) $claveNueva['email'], 'error' => null, 'estado' => null,
                   'sello' => TuRenta\CorreoDemo::sellar([
                       'programa' => 'TuRenta', 'enlace' => url_completa('login'), 'usuario' => (string) $claveNueva['email'],
                       'clave' => (string) $claveNueva['clave'], 'hasta' => (string) ($negocio['prueba_hasta'] ?? ''),
                       'contacto' => (string) $claveNueva['nombre'], 'negocio' => (string) $negocio['nombre'], 'negocio_id' => $id,
                   ], (int) superadmin()['id'], $_SESSION)];
}
$pagos = $panel->pagos($id);
$registro = $panel->registro($id, 20);
$listaPlanes = $planes->lista();
$cotizaciones = [];
foreach ($listaPlanes as $p) foreach (array_keys(Planes::PERIODOS_NOMBRE) as $per) $cotizaciones[$p['codigo']][$per] = $planes->cotizarNegocio($id, $p['codigo'], $per);
$inicioSugerido = $planes->inicioSugerido($id)->format('Y-m-d');
$modulosEstado = modulos()->estado($id);
$catalogo = modulos()->catalogo();
$pedidosModulos = modulos()->solicitudesDe($id);
vista_admin('admin_negocio', compact('negocio', 'pagos', 'registro', 'listaPlanes', 'cotizaciones', 'inicioSugerido', 'claveNueva', 'correoDemo',
                                     'modulosEstado', 'catalogo', 'pedidosModulos'),
            $negocio['nombre'] . ' · Panel Tukán');
