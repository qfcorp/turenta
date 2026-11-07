<?php
use TuRenta\{Eventos, Ventas, WhatsApp};

/**
 * Consulta desde la página pública: POST /?r=consulta&s=<slug>
 * Crea cliente + evento en "consulta" (origen página web) + actividad + aviso al negocio, y muestra la página de gracias
 * (GET /?r=consulta&s=<slug>) con un botón de WhatsApp al negocio.
 * Anti-robots: campo trampa "sitio_web" y máximo 5 consultas por hora por sesión y por IP.
 */
$ventas = new Ventas(db());
$slug = (string) ($_GET['s'] ?? '');
$neg = $ventas->negocioPorSlug($slug);
if (!$neg || !planes()->alDia($neg)) {
    http_response_code(404);
    vista_publica('sitio_no', [], 'Página no disponible', null);
    return;
}
$nid = (int) $neg['id'];
$marca = ['nombre' => $neg['nombre'], 'color' => $neg['color'], 'slug' => $neg['slug'], 'logo' => $neg['logo']];
$volver = fn() => header('Location: ' . url('sitio', ['s' => $neg['slug']]) . '#consulta');

if (es_post()) {
    verificar_csrf();
    $campos = ['nombre', 'telefono', 'email', 'tipo', 'fecha', 'invitados', 'espacio_id', 'mensaje', 'sitio_web'];
    $d = [];
    foreach ($campos as $k) $d[$k] = mb_substr(trim((string) ($_POST[$k] ?? '')), 0, 1000);
    try {
        $enSesion = array_values(array_filter($_SESSION['consultas_hechas'] ?? [], fn($t) => $t > time() - 3600));
        if (count($enSesion) >= Ventas::MAX_CONSULTAS_HORA) {
            throw new RuntimeException('Ya enviaste varias consultas. Si necesitas algo más, escríbenos por WhatsApp.');
        }
        $evId = $ventas->crearConsultaWeb($nid, $d, ip_visitante());
        $enSesion[] = time();
        $_SESSION['consultas_hechas'] = $enSesion;
        $_SESSION['consulta_ok'][$nid] = ['nombre' => $d['nombre'], 'tipo' => $d['tipo'], 'fecha' => $d['fecha'], 'invitados' => (int) $d['invitados']];
        header('Location: ' . url('consulta', ['s' => $neg['slug']]));
        exit;
    } catch (RuntimeException $e) {
        unset($d['sitio_web']);
        $_SESSION['consulta_web'][$nid] = ['datos' => $d, 'error' => $e->getMessage()];
        $volver();
        exit;
    }
}

// Página de gracias
$ok = $_SESSION['consulta_ok'][$nid] ?? null;
if (!$ok) {
    $volver();
    exit;
}
$primer = explode(' ', trim($ok['nombre']))[0];
$tipoTxt = Eventos::TIPOS[$ok['tipo']] ?? 'evento';
$wa = WhatsApp::enlace($neg['whatsapp'] ?: $neg['telefono'],
    "Hola, soy $primer. Acabo de enviar una consulta desde su página: " . mb_strtolower($tipoTxt) . ' para el ' . Ventas::fechaTexto($ok['fecha'])
    . ", {$ok['invitados']} invitados.");
vista_publica('consulta_gracias', ['neg' => $neg, 'ok' => $ok, 'wa' => $wa, 'tipoTxt' => $tipoTxt, 'primer' => $primer],
    '¡Gracias! · ' . $neg['nombre'], $marca);
