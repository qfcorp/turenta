<?php
use TuRenta\DiaEvento;

/** Mobiliario y menaje (módulo Mobiliario): sillas, mesas, mantelería, vajilla... con su uso en los próximos eventos. */
$nid = (int) $u['negocio_id'];
$dia = new DiaEvento(db());
$verId = (int) ($_GET['id'] ?? 0);

if (es_post()) {
    verificar_csrf();
    exigir(puede_editar($u), 'Tu usuario solo puede mirar.');
    $accion = (string) ($_POST['accion'] ?? '');
    $mid = (int) ($_POST['mobiliario_id'] ?? 0);
    try {
        $datos = ['nombre' => campo('nombre'), 'categoria' => campo('categoria', 'mobiliario'),
                  'cantidad_total' => (int) campo('cantidad_total', '0'), 'costo_reposicion' => numero(campo('costo_reposicion')) ?? 0];
        if ($accion === 'crear') {
            $dia->guardarMobiliario($nid, null, $datos);
            aviso("Listo, se agregó {$datos['nombre']}.");
            redirigir('mobiliario');
        } elseif ($accion === 'editar') {
            $dia->guardarMobiliario($nid, $mid, $datos + ['activo' => !empty($_POST['activo'])]);
            aviso('Cambios guardados.');
            redirigir('mobiliario');
        }
        throw new RuntimeException('Acción no válida.');
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
        $_SESSION['form_mobiliario'] = $_POST;
        redirigir('mobiliario', $mid ? ['id' => $mid] : []);
    }
}

$previo = $_SESSION['form_mobiliario'] ?? null;
unset($_SESSION['form_mobiliario']);
$mueble = $verId ? $dia->mueble($nid, $verId) : null;
if ($verId && !$mueble) {
    http_response_code(404);
    vista('error', ['mensaje' => 'No se encontró ese mobiliario.'], 'Mobiliario · TuRenta');
    return;
}
$lista = $dia->mobiliarioLista($nid);
$uso = $dia->usoProximo($nid);
// Faltantes: para cada fecha con eventos, si lo reservado ese día pasa del total
$faltantes = [];
foreach ($uso as $mid => $filas) {
    $porDia = [];
    foreach ($filas as $f) if (in_array($f['estado'], DiaEvento::RESERVAN, true)) $porDia[$f['fecha']] = ($porDia[$f['fecha']] ?? 0) + (int) $f['cantidad'];
    foreach ($porDia as $fecha => $cant) $faltantes[$mid][$fecha] = $cant;
}
vista('mobiliario', compact('lista', 'mueble', 'uso', 'faltantes', 'previo'), 'Mobiliario · TuRenta');
