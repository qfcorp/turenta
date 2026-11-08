<?php
use TuRenta\{Reservas, Ventas};

/**
 * Reserva en línea (público, en inglés): /?r=reservar&s=<slug>[&p=<paquete>][&fecha=Y-m-d]
 *  - GET &json=horas&p=<paquete>&fecha=Y-m-d[&direccion=&zip=] -> {fecha, horas: [{hora, texto}], mensaje?, falta_direccion?}
 *    (201) Con viaje por mapa las horas ya cuentan el viaje desde la base hasta esa dirección.
 *  - GET: formulario (paquete, fecha, hora, datos de la fiesta y de contacto)
 *  - POST: crea la reserva y manda al portal del cliente para pagar el depósito o el total con tarjeta (Stripe).
 * La página web del negocio (ej. zombieice.com) enlaza aquí con ?p=<paquete> desde cada botón "Book".
 */
$res = new Reservas(db());
$slug = (string) ($_GET['s'] ?? '');
$neg = $res->negocio($slug);
$json = ($_GET['json'] ?? '') === 'horas';
if (!$neg || !planes()->alDia($neg)) {
    http_response_code(404);
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Online booking is not available.']);
        return;
    }
    vista_publica_en('reservar_no', [], 'Booking not available', null);
    return;
}
$nid = (int) $neg['id'];
$marcaEn = ['nombre' => $neg['nombre'], 'color' => $neg['color'], 'logo_url' => $neg['logo_url'], 'sitio_externo' => $neg['sitio_externo'],
            'telefono' => $neg['telefono'] ?: $neg['whatsapp']];

if ($json) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $paq = $res->paquete($nid, (string) ($_GET['p'] ?? ''));
    $fecha = (string) ($_GET['fecha'] ?? '');
    // (201) Con viaje por mapa, las horas dependen de la dirección de la fiesta
    $viajeMin = 0;
    if ($res->usaMapa($neg)) {
        $dir = mb_substr(trim((string) ($_GET['direccion'] ?? '')), 0, 240);
        $zip = (string) preg_replace('/\D/', '', (string) ($_GET['zip'] ?? ''));
        if (mb_strlen($dir) < 6 || strlen($zip) !== 5) {
            echo json_encode(['fecha' => $fecha, 'horas' => [], 'falta_direccion' => true,
                              'mensaje' => 'Write the party address and ZIP code above to see open times.']);
            return;
        }
        try {
            $viajeMin = $res->viaje($neg, $dir, $zip, Ventas::fechaValida($fecha) ? $fecha : null)['min'];
        } catch (RuntimeException $e) {
            echo json_encode(['fecha' => $fecha, 'horas' => [], 'mensaje' => $e->getMessage()]);
            return;
        }
    }
    $horas = $paq ? array_map(fn($h) => ['hora' => $h['hora'], 'texto' => $h['texto']], $res->horarios($neg, $paq, $fecha, null, $viajeMin)) : [];
    echo json_encode(['fecha' => $fecha, 'horas' => $horas]);
    return;
}

if (es_post()) {
    verificar_csrf_en();
    $campos = ['paquete', 'fecha', 'hora', 'nombre', 'telefono', 'email', 'direccion', 'zip', 'ninos', 'ocasion', 'homenajeado', 'notas', 'acepto', 'sitio_web'];
    $d = [];
    foreach ($campos as $k) $d[$k] = mb_substr(trim((string) ($_POST[$k] ?? '')), 0, 1000);
    try {
        $evId = $res->crear($neg, $d, ip_visitante());
        $st = db()->prepare('SELECT token_cliente FROM eventos WHERE id = ?');
        $st->execute([$evId]);
        unset($_SESSION['reserva_en'][$nid]);
        aviso('Almost done! Your time is held until the end of today. Pay the deposit or the full amount to lock your date.');
        redirigir('portal', ['t' => $st->fetchColumn()]);
    } catch (RuntimeException $e) {
        unset($d['sitio_web']);
        $_SESSION['reserva_en'][$nid] = ['datos' => $d, 'error' => $e->getMessage()];
        redirigir('reservar', ['s' => $slug, 'p' => $d['paquete'], 'fecha' => $d['fecha']]);
    }
}

$guardado = $_SESSION['reserva_en'][$nid] ?? null;
unset($_SESSION['reserva_en'][$nid]);
if ($guardado) aviso($guardado['error'], 'error');
$datosForm = $guardado['datos'] ?? [];
$paquetes = $res->paquetes($nid);
$elegido = (string) ($datosForm['paquete'] ?? ($_GET['p'] ?? ''));
if (!$res->paquete($nid, $elegido)) $elegido = $paquetes[0]['slug'] ?? '';
$fechaElegida = (string) ($datosForm['fecha'] ?? ($_GET['fecha'] ?? ''));
$primerDia = $res->primerDia($neg);
if ($fechaElegida !== '' && $fechaElegida < $primerDia) $fechaElegida = '';
vista_publica_en('reservar_en', [
    'neg' => $neg, 'paquetes' => $paquetes, 'elegido' => $elegido, 'fechaElegida' => $fechaElegida, 'primerDia' => $primerDia,
    'df' => $datosForm, 'slug' => $slug, 'usaMapa' => $res->usaMapa($neg),
],'Book your party · ' . $neg['nombre'], $marcaEn);
