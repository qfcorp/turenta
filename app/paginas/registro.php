<?php
/**
 * "Todo se pide demo": /?r=registro ya no crea una cuenta. Es el formulario para PEDIR UNA DEMO
 * (nombre, negocio, ciudad, WhatsApp, correo, qué necesita y los módulos que le interesan). Queda como solicitud
 * demo_pedida en el Panel Tukán Central; Tukán crea la demo y le manda los accesos.
 */
use TuRenta\{Modulos, PedidosDemo};

if (usuario()) {
    redirigir('inicio');
}
$errores = [];
$listo = false;
$d = ['nombre' => '', 'negocio' => '', 'ciudad' => '', 'whatsapp' => '', 'email' => '', 'necesita' => '', 'modulos' => []];
if (es_post()) {
    verificar_csrf();
    foreach (['nombre', 'negocio', 'ciudad', 'whatsapp', 'email', 'necesita'] as $k) $d[$k] = trim((string) ($_POST[$k] ?? ''));
    $d['modulos'] = array_values(array_filter((array) ($_POST['modulos'] ?? []), fn($m) => is_string($m) && Modulos::existe($m)));
    if (!empty($_POST['sitio_web'])) {
        $errores[] = 'No se pudo enviar el pedido.';   // trampa para robots
    } else {
        try {
            (new PedidosDemo(db()))->crear($d, ip_visitante());
            $listo = true;
        } catch (RuntimeException $e) {
            $errores[] = $e->getMessage();
        }
    }
}
vista('registro', ['errores' => $errores, 'd' => $d, 'listo' => $listo, 'catalogo' => modulos()->catalogo()], 'Solicitar demo · TuRenta');
