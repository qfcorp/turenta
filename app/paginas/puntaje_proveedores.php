<?php
use TuRenta\{ProveedoresFicha, PuntajeProveedores};

/**
 * Proveedores → Cómo se calcula el puntaje: /?r=puntaje_proveedores
 * El dueño cambia los pesos, la tolerancia, lo que resta cada incidencia, el tope con incidencias graves, los niveles,
 * el periodo y el mínimo de eventos, con vista previa de 3 proveedores reales antes de guardar (&json=1 para la vista previa en vivo).
 * El resto del equipo ve la explicación de la fórmula (solo lectura).
 */
$nid = (int) $u['negocio_id'];
$pp = new PuntajeProveedores(db());
$ficha = new ProveedoresFicha(db());
$dueno = es_admin_total($u);

/**
 * Vista previa: los 3 proveedores con más datos, con el puntaje de ahora y el que tendrían con $cfg,
 * y su puesto en el ranking antes y después.
 */
$vistaPrevia = function (array $cfg) use ($ficha, $nid, $pp): array {
    $antes = $ficha->ranking($nid);
    $despues = $ficha->ranking($nid, null, true, $cfg);
    $puesto = function (array $lista): array {
        $res = []; $n = 0;
        foreach ($lista as $r) if ($r['puntaje'] !== null) $res[$r['proveedor_id']] = ++$n;
        return $res;
    };
    $pa = $puesto($antes); $pd = $puesto($despues);
    $porId = array_column($despues, null, 'proveedor_id');
    $datos = fn($r) => $r['eventos'] + $r['medidas'] + $r['bloques'] + $r['calificaciones'] + $r['evaluados'] + $r['incidencias'];
    $conDatos = array_values(array_filter($antes, fn($r) => $datos($r) > 0));
    usort($conDatos, fn($a, $b) => [$datos($b), $a['nombre']] <=> [$datos($a), $b['nombre']]);
    $res = [];
    foreach (array_slice($conDatos, 0, 3) as $r) {
        $d = $porId[$r['proveedor_id']];
        $res[] = ['nombre' => $r['nombre'], 'antes' => $r, 'despues' => $d, 'puesto_antes' => $pa[$r['proveedor_id']] ?? null, 'puesto_despues' => $pd[$r['proveedor_id']] ?? null];
    }
    return ['filas' => $res, 'cambia' => $cfg != $pp->config($nid), 'con_puntaje' => count($pd)];
};

if (es_post()) {
    verificar_csrf();
    $json = !empty($_GET['json']);
    if (!$dueno) {
        if ($json) { http_response_code(403); header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => 'Solo el dueño o un administrador cambia cómo se calcula el puntaje.']); return; }
        exigir(false, 'Solo el dueño o un administrador cambia cómo se calcula el puntaje.');
    }
    $accion = (string) ($_POST['accion'] ?? '');
    try {
        switch ($accion) {
            case 'vista_previa':
                $cfg = PuntajeProveedores::validar($_POST);
                $previa = $vistaPrevia($cfg);
                if ($json) {
                    ob_start();
                    require RAIZ . '/app/vistas/_pv_vista_previa.php';
                    $html = ob_get_clean();
                    header('Content-Type: application/json');
                    echo json_encode(['ok' => true, 'html' => $html]);
                    return;
                }
                vista('puntaje_proveedores', ['cfg' => $pp->config($nid), 'propuesta' => $cfg, 'previa' => $previa, 'dueno' => true, 'historial' => $pp->historial($nid),
                                              'recomendada' => $pp->esRecomendada($nid)], 'Cómo se calcula el puntaje · TuRenta');
                return;
            case 'guardar':
                $cambios = $pp->guardar($nid, $_POST, $u);
                aviso($cambios ? 'Listo: el puntaje de tus proveedores ya se calcula así (' . count($cambios) . (count($cambios) === 1 ? ' cambio' : ' cambios') . ').' : 'No cambiaste nada.');
                break;
            case 'recomendados':
                $cambios = $pp->recomendados($nid, $u);
                aviso($cambios ? 'Listo: volviste a los valores recomendados.' : 'Ya tenías los valores recomendados.');
                break;
            default:
                throw new RuntimeException('Acción no válida.');
        }
    } catch (RuntimeException $e) {
        // Vista previa: 200 con ok=false (es un aviso para la pantalla, no un fallo)
        if ($json) { header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => $e->getMessage()]); return; }
        aviso($e->getMessage(), 'error');
        $_SESSION['form_puntaje'] = $_POST;
    }
    redirigir('puntaje_proveedores');
}

$cfg = $pp->config($nid);
$previo = $_SESSION['form_puntaje'] ?? null;
unset($_SESSION['form_puntaje']);
vista('puntaje_proveedores', [
    'cfg' => $cfg, 'propuesta' => $previo, 'previa' => $dueno ? $vistaPrevia($cfg) : null, 'dueno' => $dueno,
    'historial' => $pp->historial($nid), 'recomendada' => $pp->esRecomendada($nid),
], 'Cómo se calcula el puntaje · TuRenta');
