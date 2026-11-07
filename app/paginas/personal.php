<?php
use TuRenta\{DiaEvento, WhatsApp};

/** Personal para eventos: meseros, cocina, seguridad... con su enlace "Mis eventos". ?id=<persona> para editar. */
$nid = (int) $u['negocio_id'];
$dia = new DiaEvento(db());
$verId = (int) ($_GET['id'] ?? 0);

if (es_post()) {
    verificar_csrf();
    exigir(puede_editar($u), 'Tu usuario solo puede mirar.');
    $accion = (string) ($_POST['accion'] ?? '');
    $pid = (int) ($_POST['personal_id'] ?? 0);
    try {
        $datos = [
            'nombre' => campo('nombre'), 'identificacion' => campo('identificacion'), 'telefono' => campo('telefono'),
            'rol' => campo('rol', 'mesero'), 'pago_evento' => numero(campo('pago_evento')) ?? 0, 'notas' => campo('notas'),
        ];
        switch ($accion) {
            case 'crear':
                $pid = $dia->guardarPersona($nid, null, $datos);
                aviso("Listo, {$datos['nombre']} está en tu personal. Mándale su enlace por WhatsApp para que vea sus eventos.");
                redirigir('personal', ['id' => $pid]);
            case 'editar':
                $dia->guardarPersona($nid, $pid, $datos + ['activo' => !empty($_POST['activo'])]);
                aviso('Cambios guardados.');
                redirigir('personal', ['id' => $pid]);
            default:
                throw new RuntimeException('Acción no válida.');
        }
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
        $_SESSION['form_personal'] = $_POST;
        redirigir('personal', $pid ? ['id' => $pid] : []);
    }
}

$previo = $_SESSION['form_personal'] ?? null;
unset($_SESSION['form_personal']);
$persona = $verId ? $dia->persona($nid, $verId) : null;
if ($verId && !$persona) {
    http_response_code(404);
    vista('error', ['mensaje' => 'No se encontró a esa persona.'], 'Personal · TuRenta');
    return;
}
$st = db()->prepare('SELECT * FROM negocios WHERE id = ?');
$st->execute([$nid]);
$negocio = $st->fetch();
$lista = $dia->personalLista($nid);
$proximos = $persona ? $dia->eventosDePersona((int) $persona['id']) : [];
$pagos = $persona ? $dia->pagosDePersona((int) $persona['id']) : null;
$enlace = $persona ? url_completa('staff', ['t' => $persona['token']]) : null;
$wa = $persona ? WhatsApp::enlace($persona['telefono'], 'Hola ' . trim(explode(' ', $persona['nombre'])[0]) . ', te escribe ' . $negocio['nombre']
    . ". Aquí ves los eventos a los que estás asignado, los horarios, la dirección y tus pagos. Guárdalo:\n$enlace") : null;
vista('personal', compact('lista', 'persona', 'proximos', 'pagos', 'enlace', 'wa', 'previo'), 'Personal · TuRenta');
