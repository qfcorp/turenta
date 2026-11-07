<?php
use TuRenta\{DiaEvento, Eventos};

/**
 * Cronograma en vivo (público, ?t=token_vivo) para el coordinador, el DJ y el fotógrafo:
 * qué va ahora, qué sigue, hora planificada vs real y atraso. Botón "Empezar siguiente" y "Deshacer".
 * &json=1 -> estado para actualizarse solo cada 20 s.
 */
$token = (string) ($_GET['t'] ?? '');
$ev = (new Eventos(db()))->porToken('token_vivo', $token);
$negocio = null;
if ($ev) {
    $st = db()->prepare('SELECT * FROM negocios WHERE id = ?');
    $st->execute([$ev['negocio_id']]);
    $negocio = $st->fetch();
}
if (!$ev || !DiaEvento::publicoActivo(db(), $negocio, 'dia_evento') || in_array($ev['estado'], ['perdido', 'cancelado'], true)) {
    http_response_code(404);
    if (!empty($_GET['json'])) { header('Content-Type: application/json'); echo json_encode(['error' => 'Enlace no válido']); return; }
    vista_publica('error_publico', ['mensaje' => 'Este enlace del cronograma no es válido o el evento fue cancelado.'], 'Cronograma en vivo', $negocio ? DiaEvento::marca($negocio) : null);
    return;
}
$id = (int) $ev['id'];
$dia = new DiaEvento(db());

if (es_post()) {
    verificar_csrf();
    try {
        // Si alguien más ya avanzó, no se avanza dos veces por el mismo toque
        $antes = (string) ($_POST['version'] ?? '');
        if ($antes !== '' && $antes !== $dia->vivo($id)['version']) {
            aviso('Otra persona acaba de actualizar el cronograma. Revisa antes de tocar otra vez.', 'error');
        } elseif (($_POST['accion'] ?? '') === 'deshacer') {
            $dia->deshacerVivo($id);
            aviso('Se deshizo el último paso.');
        } else {
            $empezo = $dia->empezarSiguiente($id);
            aviso($empezo ? "Empezó: $empezo" : '¡Cronograma terminado!');
        }
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
    }
    redirigir('vivo', ['t' => $token]);
}

$v = $dia->vivo($id);
$hora = fn(?DateTimeImmutable $d) => $d ? $d->format('H:i') : '';
if (!empty($_GET['json'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $b = fn(?int $k) => $k === null ? null : [
        'actividad' => $v['bloques'][$k]['actividad'], 'responsable' => $v['bloques'][$k]['responsable'],
        'plan' => $hora($v['bloques'][$k]['plan']), 'real' => $v['bloques'][$k]['inicio_real'] ? substr((string) $v['bloques'][$k]['inicio_real'], 11, 5) : null,
        'duracion' => (int) $v['bloques'][$k]['duracion_min'],
    ];
    echo json_encode(['version' => $v['version'], 'atraso' => $v['atraso'], 'prueba' => $v['prueba'], 'texto_atraso' => DiaEvento::textoAtraso($v), 'actual' => $b($v['actual']), 'siguiente' => $b($v['siguiente']),
                      'min_en_bloque' => $v['min_en_bloque'], 'min_restantes' => $v['min_restantes'], 'terminado' => $v['terminado'],
                      'hora' => date('H:i')], JSON_UNESCAPED_UNICODE);
    return;
}
vista_publica('vivo', ['ev' => $ev, 'negocio' => $negocio, 'v' => $v, 'token' => $token, 'hora' => $hora],
    'En vivo · ' . $ev['nombre'], DiaEvento::marca($negocio));
