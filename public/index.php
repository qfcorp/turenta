<?php
declare(strict_types=1);

// App de Android (Google Play): /.well-known/assetlinks.json sale de src/AppAndroid.php (nginx lo manda aquí; sin sesión)
if (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) === '/.well-known/assetlinks.json') {
    define('TUKAN_SIN_SESION', true);
    require dirname(__DIR__) . '/app/bootstrap.php';
    \TuRenta\AppAndroid::responder();
}
require dirname(__DIR__) . '/app/bootstrap.php';

/*
 * Rutas de TuRenta: /?r=<ruta>. Cada ruta es un archivo app/paginas/<ruta>.php
 */

// Páginas públicas (sin iniciar sesión): acceso, página del negocio, enlaces secretos del cliente, invitados y personal
$publicas = [
    'login', 'registro', 'privacidad', 'admin_entrar', 'telegram',
    'sitio', 'consulta', 'disponible', 'imagen_publica',      // página pública del negocio
    'cot', 'firmar', 'verificar',                              // cotización, firma y verificación del contrato
    'portal', 'portal_pagar', 'payphone', 'portal_invitados', // portal del cliente: pagos y lista de invitados
    'invitacion', 'puerta', 'ingreso',                         // invitados: confirmación, control de ingreso con QR
    'vivo', 'staff', 'ical',                                   // cronograma en vivo, portal del personal, calendario externo
];
// Páginas del sistema (con sesión)
$privadas = [
    'inicio', 'calendario', 'eventos', 'evento', 'evento_nuevo', 'evento_editar',
    'cotizacion', 'contrato', 'evento_pagos', 'evento_dia', 'invitados',
    'clientes', 'cliente', 'seguimiento', 'cobros', 'mensajes',
    'catalogo', 'paquetes', 'espacios', 'personal', 'proveedores', 'mobiliario',
    'reportes', 'exportar', 'configuracion', 'usuarios', 'mi_cuenta', 'planes', 'prueba_terminada',
    'notificaciones', 'archivo', 'pdf', 'buscar', 'mas', 'salir',
];
// Lo que puede abrir un coordinador (el día del evento); lo demás lo hace el dueño o el vendedor
$deCoordinador = ['inicio', 'calendario', 'eventos', 'evento', 'evento_dia', 'invitados', 'personal', 'proveedores', 'mobiliario', 'espacios',
                  'mi_cuenta', 'notificaciones', 'archivo', 'pdf', 'buscar', 'mas', 'salir', 'prueba_terminada'];
// Plano del salón (el coordinador también lo arma) y metas de ventas — módulo 080
array_push($privadas, 'plano', 'metas');
$deCoordinador[] = 'plano';
// Panel del super administrador (Tukán): sesión aparte
$deAdmin = ['admin', 'admin_negocios', 'admin_negocio', 'admin_nuevo', 'admin_planes', 'admin_ver', 'admin_volver', 'admin_salir'];
// Portal del cliente, segunda parte (migración 070): formulario del evento, extras pedidos en línea y encuesta de satisfacción
array_push($publicas, 'portal_cuestionario', 'portal_extras', 'encuesta');
array_push($privadas, 'evento_formulario', 'cuestionarios', 'extras');
$deCoordinador[] = 'evento_formulario';   // el coordinador ve las respuestas del formulario (útil el día del evento)
$publicas[] = 'firmar_anexo';             // anexo de contrato por extras aprobados (migración 090)
// TuRenta (migración 200): reserva en línea en inglés y cobro con Stripe (regreso del cliente y aviso de Stripe)
array_push($publicas, 'reservar', 'stripe', 'stripe_webhook');
// Proveedores y compras (migración 060): portal del proveedor, verificación de la orden de compra, ficha, compras, por pagar e incidencias
array_push($publicas, 'portal_proveedor', 'verificar_oc');
array_push($privadas, 'proveedor', 'compras', 'compra', 'por_pagar', 'incidencias', 'proveedor_archivo');
array_push($deCoordinador, 'proveedor', 'incidencias', 'proveedor_archivo');   // ficha sin dinero, incidencias y documentos
// Cómo se calcula el puntaje de proveedores (091): el dueño lo cambia; los demás ven la explicación
$privadas[] = 'puntaje_proveedores'; $deCoordinador[] = 'puntaje_proveedores';
// Ayuda e inteligencia artificial (110): la Ayuda es para todos (también el coordinador); la cotización desde un mensaje es
// del módulo «Asistente IA»; el súper usuario revisa las preguntas frecuentes y manda la demo por correo
array_push($privadas, 'ayuda', 'ia_cotizacion'); $deCoordinador[] = 'ayuda';
array_push($deAdmin, 'admin_faq', 'admin_demo_correo');

$ruta = (string) ($_GET['r'] ?? 'inicio');
if (in_array($ruta, $deAdmin, true) && !superadmin()) {
    redirigir('admin_entrar');
}
if (!in_array($ruta, array_merge($publicas, $privadas, $deAdmin), true) || !is_file(RAIZ . "/app/paginas/$ruta.php")) {
    http_response_code(404);
    $ruta = usuario() ? 'inicio' : 'login';
}

// Anexo A: las páginas públicas y portales de un negocio pausado dicen "temporalmente no disponible" (503);
// las de un negocio en la papelera no existen (404). Cada página pública está en Papelera::RUTAS_PUBLICAS o PUBLICAS_SIN_NEGOCIO.
if (in_array($ruta, $publicas, true) && !in_array($ruta, \TuRenta\Papelera::PUBLICAS_SIN_NEGOCIO, true)) {
    $papelera = new \TuRenta\Papelera(db());
    $nidPublico = $papelera->negocioDeRuta($ruta, $_GET);
    $estadoPublico = $nidPublico === null ? 'normal' : $papelera->estadoPublico($nidPublico);
    if ($estadoPublico !== 'normal') {
        $borrado = $estadoPublico === 'eliminado';
        http_response_code($borrado ? 404 : 503);
        header('Cache-Control: no-store');
        if (!$borrado) header('Retry-After: 3600');
        $texto = $borrado ? 'Esta página no está disponible.' : 'Temporalmente no disponible. Intenta más tarde.';
        if ($ruta === 'disponible' || !empty($_GET['json'])) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => $texto, 'disponible' => false], JSON_UNESCAPED_UNICODE);
        } elseif ($ruta === 'ical') {
            header('Content-Type: text/plain; charset=utf-8');
            echo $texto . "\n";
        } elseif ($ruta !== 'imagen_publica' && empty($_GET['qr']) && empty($_GET['foto'])) {   // imágenes y QR: solo el código
            vista_publica($borrado ? 'sitio_no' : 'no_disponible', [], $borrado ? 'Página no disponible' : 'Temporalmente no disponible', null);
        }
        exit;
    }
    // Anexo B: la página pública de un módulo que el negocio no tiene encendido dice "no disponible" (los datos no se tocan)
    $moduloPublico = \TuRenta\Modulos::RUTAS[$ruta] ?? '';
    if ($nidPublico !== null && $moduloPublico !== '' && !in_array($ruta, \TuRenta\Modulos::SIN_CORTE, true) && !modulo_activo($nidPublico, $moduloPublico)) {
        http_response_code(403);
        header('Cache-Control: no-store');
        if (!empty($_GET['json'])) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'No disponible.', 'disponible' => false], JSON_UNESCAPED_UNICODE);
        } elseif ($ruta === 'ical') {
            header('Content-Type: text/plain; charset=utf-8');
            echo "No disponible.\n";
        } elseif (empty($_GET['qr']) && empty($_GET['foto'])) {
            vista_publica('modulo_no_disponible', [], 'No disponible', null);
        }
        exit;
    }
}

if (in_array($ruta, $privadas, true)) {
    $u = requiere_sesion();
    if ($u['rol'] === 'coordinador' && !in_array($ruta, $deCoordinador, true)) {
        redirigir('inicio');
    }
    // Prueba vencida, plan vencido o cuenta suspendida: solo puede ver cómo pagar
    if (!in_array($ruta, ['prueba_terminada', 'salir', 'planes'], true)
        && !planes()->alDia($u) && !(!empty($_SESSION['admin_viendo']) && superadmin())) {
        redirigir('prueba_terminada');
    }
    // Anexo B: cada página es de la base o de un módulo; si el módulo está apagado, se explica cómo agregarlo
    $moduloRuta = \TuRenta\Modulos::RUTAS[$ruta] ?? '';
    if ($moduloRuta !== '' && !modulo_activo($u, $moduloRuta)) {
        http_response_code(403);
        vista('modulo_apagado', ['modulo' => $moduloRuta], \TuRenta\Modulos::nombre($moduloRuta) . ' · TuRenta');
        exit;
    }
}

try {
    require RAIZ . "/app/paginas/$ruta.php";
} catch (Throwable $e) {
    error_log('TuRenta: ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) http_response_code(500);
    if (in_array($ruta, ['disponible', 'buscar', 'stripe_webhook'], true)) {
        header('Content-Type: application/json');
        echo json_encode(['error' => true]);
    } elseif (in_array($ruta, $publicas, true)) {
        vista_publica('error_publico', ['mensaje' => 'Algo falló al abrir esta página. Intenta de nuevo en un momento.'], 'TuRenta');
    } else {
        vista('error', ['mensaje' => 'Algo falló al procesar esta página. Intenta de nuevo; si sigue pasando, escríbenos por WhatsApp.']);
    }
}
