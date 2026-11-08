<?php
use TuRenta\{Modulos, PanelTukan};

/**
 * Mi plan (dueño): sus módulos encendidos, lo que paga al mes por SU plan, los módulos y planes que puede pedir y sus pagos.
 * Regla del dueño de Tukán: el precio de lo que el cliente NO tiene nunca se muestra; siempre se pide cotización.
 * Planes de $20 y $45 (migración 112): en Básico el cliente elige 3 módulos incluidos; «Tu plan trae 3 módulos a tu elección».
 * "Pedir cotización" (de un módulo o de un plan) crea un pedido (solicitud cambio_modulos) que llega al Panel Tukán Central.
 * También se abre con la demo terminada o el plan vencido.
 */
const NO_EN_APP = 'Desde la app solo se eligen los módulos incluidos en tu plan.';
exigir(es_admin_total($u), 'Solo el dueño o un administrador puede ver y cambiar el plan de TuRenta.');
$nid = (int) $u['negocio_id'];
// «Cancelar mi plan» (módulo común src/TukanCancelarPlan.php, 7 nov 2026)
require_once dirname(__DIR__, 2) . '/src/TukanCancelarPlan.php';
require_once dirname(__DIR__, 2) . '/src/TukanLegal.php';
if (es_post() && ($_POST['accion'] ?? '') === 'aceptar_terminos_tukan') {   // aviso de arriba (vistas/_diseno.php)
    verificar_csrf();
    $okTl = TukanLegal::procesar(db(), (string) $nid, $u['nombre'] . ' <' . $u['email'] . '>');
    aviso($okTl ? 'Gracias: aceptaste los términos y la política de privacidad.' : 'Para seguir, marca «Leí y acepto los términos y la política de privacidad».', $okTl ? 'ok' : 'error');
    header('Location: ' . url('inicio'));
    exit;
}
if (es_post() && in_array($_POST['accion'] ?? '', ['cancelar_plan', 'seguir_plan'], true)) {
    verificar_csrf();
    try {
        if (en_app_android()) throw new RuntimeException('En la app cancelas en Google Play → Pagos y suscripciones.');
        if ($_POST['accion'] === 'seguir_plan') {
            TukanCancelarPlan::seguir(db(), (string) $nid, (string) $u['negocio'], (string) $u['email']);
            aviso('¡Qué bueno! Tu plan sigue como siempre.');
        } else {
            if (empty($_POST['confirmo'])) throw new RuntimeException('Para cancelar, marca «Sí, quiero cancelar mi plan».');
            $rTc = TukanCancelarPlan::pedir(db(), (string) $nid, (string) $u['negocio'], $u['activo_hasta'] ?? null, (string) ($_POST['motivo'] ?? ''),
                $u['nombre'] . ' <' . $u['email'] . '>', (string) $u['email']);
            aviso('Listo: tu plan no se renovará.' . ($rTc['hasta'] ? ' Sigues usando todo hasta el ' . date('d/m/Y', strtotime($rTc['hasta'])) . '.' : '')
                . ' Tus datos no se borran. Si cambias de idea, toca «Seguir con mi plan».');
        }
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
    }
    header('Location: ' . url('planes') . '#cancelar-plan');
    exit;
}
if (!es_post()) TukanCancelarPlan::reintentar(db(), (string) $nid, (string) $u['email']);
$cancelacion = TukanCancelarPlan::estado(db(), (string) $nid);
if (es_post()) {
    verificar_csrf();
    try {
        $accion = (string) ($_POST['accion'] ?? '');
        // App de Google Play: no se piden cotizaciones ni planes (sería comprar el software por fuera de Google Play)
        if (en_app_android() && $accion === 'pedir_plan') throw new RuntimeException(NO_EN_APP);
        if ($accion === 'pedir_plan') {
            // Cotizar otro plan: se piden los módulos de ese plan que hoy no tiene (mismo pedido cambio_modulos del Panel Central)
            $codigo = (string) ($_POST['plan'] ?? '');
            $pm = modulos()->planes()[$codigo] ?? null;
            if (!$pm || $codigo === (string) $u['plan_codigo']) throw new RuntimeException('Ese plan no existe.');
            $faltan = array_values(array_diff($pm['todos'] ? array_keys(modulos()->catalogo()) : $pm['modulos'], modulos()->estado($nid)['pagados']));
            if (!$faltan) throw new RuntimeException('Ese plan no te suma módulos. Escríbenos por WhatsApp y te ayudamos.');
            $nombrePlan = $pm['nombre'];
            modulos()->pedir($nid, (int) $u['id'], $faltan, trim('Pide cotización del plan ' . $nombrePlan . '. ' . campo('nota')));
            aviso('Listo: pediste la cotización del plan ' . $nombrePlan . '. Tukán te escribe con el valor; tus datos no cambian.');
            header('Location: ' . url('planes') . '#mp-t-planes');
            exit;
        }
        if ($accion !== 'pedir') throw new RuntimeException('Acción no válida.');
        $mid = (string) ($_POST['modulo'] ?? '');
        if (!Modulos::existe($mid)) throw new RuntimeException('Ese módulo no existe.');
        // Planes de $20 y $45 (migración 112): si su plan aún tiene módulos por elegir, es uno de esos (incluido en su plan)
        $eligeIncluido = modulos()->estado($nid)['por_elegir'] > 0 && !in_array($mid, Modulos::FUERA_DEL_CUPO, true);
        if (en_app_android() && !$eligeIncluido) throw new RuntimeException(NO_EN_APP);   // en la app solo se eligen los incluidos
        modulos()->pedir($nid, (int) $u['id'], [$mid], trim(($eligeIncluido ? 'Lo elige entre los módulos incluidos en su plan. ' : '') . campo('nota')));
        aviso($eligeIncluido
            ? 'Listo: elegiste ' . Modulos::nombre($mid) . ' entre los módulos de tu plan. Tukán lo enciende y te avisa; tus datos no cambian.'
            : 'Listo: pediste la cotización de ' . Modulos::nombre($mid) . '. Tukán te escribe con el valor y para activarlo; tus datos no cambian.');
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
    }
    header('Location: ' . url('planes') . '#mod-' . rawurlencode((string) ($_POST['modulo'] ?? '')));
    exit;
}
$p = planes();
$mods = modulos();
$estadoMod = $mods->estado($nid);
$catalogo = $mods->catalogo();
$planesMod = $mods->planes();
$precios = [];
foreach (['mensual', 'semestral', 'anual'] as $per) $precios[$per] = $p->cotizarNegocio($nid, (string) $u['plan_codigo'], $per);
$estadoCuenta = $p->estadoCuenta($u);
$pagos = (new PanelTukan(db()))->pagos($nid, 30);
$pedidos = $mods->solicitudesDe($nid);
$pedidosPendientes = [];
foreach ($pedidos as $s) if ($s['estado'] === 'pendiente') foreach (Modulos::parse($s['encender']) as $m) $pedidosPendientes[$m] = true;
$st = db()->prepare("SELECT (SELECT count(*) FROM espacios WHERE negocio_id = ? AND activo) AS espacios,
                            (SELECT count(*) FROM usuarios WHERE negocio_id = ? AND activo AND rol <> 'admin') AS usuarios");   // los administradores no cuentan en el plan
$st->execute([$nid, $nid]);
$uso = $st->fetch();
$listaPlanes = $p->lista();
vista('planes', compact('cancelacion', 'estadoMod', 'catalogo', 'planesMod', 'precios', 'estadoCuenta', 'pagos', 'pedidos', 'pedidosPendientes', 'uso', 'listaPlanes'),
      'Mi plan · TuRenta');
