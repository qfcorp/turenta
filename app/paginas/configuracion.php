<?php
use TuRenta\{Configuracion, Eventos};

/**
 * Configuración del negocio (solo el dueño), por pestañas:
 *   datos (negocio, logo, color) · reglas (comerciales) · cobros (transferencia, DeUna, Payphone) · pagina (enlace y QR)
 *   tareas (plantilla por tipo de evento) · calendario (Google Calendar) · otros (contratos, transmisiones, primeros pasos)
 */
exigir(es_admin_total($u), 'Solo el dueño o un administrador puede cambiar la configuración del negocio.');
$nid = (int) $u['negocio_id'];
$config = new Configuracion(db());
$tabs = ['datos' => 'Negocio', 'reglas' => 'Reglas', 'cobros' => 'Cobros en línea', 'reserva' => 'Reserva en línea y Stripe', 'pagina' => 'Página pública',
         'tareas' => 'Tareas', 'calendario' => 'Google Calendar', 'otros' => 'Más'];
if (!modulo_activo($u, 'exportar')) unset($tabs['calendario']);   // Anexo B: Google Calendar es del módulo "Exportar"
$tab = (string) ($_GET['tab'] ?? 'datos');
if (!isset($tabs[$tab])) $tab = 'datos';

if (es_post()) {
    verificar_csrf();
    $accion = (string) ($_GET['accion'] ?? '');
    $volver = $tab;
    try {
        switch ($accion) {
            case 'datos':
                $config->guardarDatos($nid, $_POST);
                aviso('Datos del negocio guardados. Ya salen en tus cotizaciones, contratos y en tu página.');
                break;
            case 'logo':
                if (($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) throw new RuntimeException('Elige la foto de tu logo.');
                if (($_FILES['logo']['error'] ?? 0) === UPLOAD_ERR_INI_SIZE || ($_FILES['logo']['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE) {
                    throw new RuntimeException('La foto pesa demasiado. Usa una de menos de 10 MB.');
                }
                $config->guardarLogo($nid, $_FILES['logo']);
                aviso('Logo guardado.');
                break;
            case 'quitar_logo':
                $config->quitarLogo($nid);
                aviso('Logo quitado.');
                break;
            case 'reglas':
                $config->guardarReglas($nid, $_POST);
                aviso('Reglas guardadas. Se aplican a las cotizaciones y planes de pago nuevos.');
                break;
            case 'cobros':
                $config->guardarCobros($nid, $_POST);
                aviso('Datos de cobro guardados. Tus clientes los ven en su portal de pagos.');
                break;
            case 'reserva':
                $config->guardarReserva($nid, $_POST);
                aviso('Reserva en línea y Stripe guardados.');
                break;
            case 'viaje':
                $config->guardarViaje($nid, $_POST);
                aviso('Tiempo de viaje guardado. Las horas de la reserva en línea ya cuentan el viaje hasta cada dirección.');
                break;
            case 'tarea':
                $id = (int) ($_POST['id'] ?? 0);
                $config->guardarTarea($nid, $_POST, $id ?: null);
                aviso($id ? 'Tarea actualizada.' : 'Tarea agregada. Se creará en los eventos nuevos.');
                break;
            case 'borrar_tarea':
                $config->borrarTarea($nid, (int) ($_POST['id'] ?? 0));
                aviso('Tarea quitada de la plantilla. Los eventos que ya la tienen la conservan.');
                break;
            case 'ical':
                exigir(modulo_activo($u, 'exportar'), 'Google Calendar no está en tu plan. Agrégalo en Mi plan.');
                $config->cambiarTokenIcal($nid);
                aviso('Listo: creamos un enlace nuevo. El anterior ya no funciona; vuelve a agregarlo en Google Calendar.');
                break;
            case 'transmision':
                exigir(modulo_activo($u, 'boda_vivo'), 'La boda en vivo no está en tu plan. Agrégala en Mi plan.');
                $config->guardarTransmision($nid, campo('whatsapp_transmision'));
                aviso('WhatsApp de transmisiones guardado.');
                break;
            case 'ver_pasos':
                $config->ocultarPasos($nid, false);
                aviso('Los primeros pasos vuelven a aparecer en el Inicio.');
                redirigir('inicio');
            case 'precios_revisados':
                $config->marcarPreciosRevisados($nid);
                aviso('¡Bien! Marcamos tus precios como revisados.');
                break;
            default:
                throw new RuntimeException('Acción no válida.');
        }
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
        if (in_array($accion, ['datos', 'reglas', 'cobros', 'reserva', 'viaje'], true)) $_SESSION['config_form'] = [$accion => $_POST];
    }
    redirigir('configuracion', ['tab' => $volver]);
}

$negocio = $config->negocio($nid);
// Si el último guardado falló, se vuelve a mostrar lo que escribió (no se pierde lo escrito)
$previo = $_SESSION['config_form'] ?? [];
unset($_SESSION['config_form']);
foreach ($previo as $grupo => $valores) {
    foreach ((array) $valores as $k => $v) {
        if (array_key_exists($k, $negocio) && !in_array($k, ['payphone_token', 'stripe_secret_key', 'stripe_webhook_secret', 'google_maps_key', 'csrf', 'id', 'token_ical'], true)) $negocio[$k] = $v;
    }
}
$tareas = $tab === 'tareas' ? $config->tareas($nid) : [];
$enlacePagina = url_completa('sitio', ['s' => $negocio['slug']]);
$enlaceIcal = url_completa('ical', ['t' => $negocio['token_ical']]);
vista('configuracion', compact('negocio', 'tab', 'tabs', 'tareas', 'enlacePagina', 'enlaceIcal'), 'Configuración · TuRenta');
