<?php
use TuRenta\Reservas;

/**
 * Reserva en línea (público, en inglés): /?r=reservar&s=<slug>[&p=<paquete>][&fecha=Y-m-d]
 *  - GET &json=horas&p=<paquete>&fecha=Y-m-d -> {fecha, horas: [{hora, texto}]}
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
    $horas = $paq ? array_map(fn($h) => ['hora' => $h['hora'], 'texto' => $h['texto']], $res->horarios($neg, $paq, $fecha)) : [];
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
    'df' => $datosForm, 'slug' => $slug,
], 'Book your party · ' . $neg['nombre'], $marcaEn);
