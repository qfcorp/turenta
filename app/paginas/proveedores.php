<?php
use TuRenta\{Compras, DiaEvento, ProveedoresFicha};

/**
 * Proveedores: DJ, fotógrafos, floristas, catering... ?id=<proveedor> para editar, ?ver=ranking para el ranking.
 * La ficha completa (cuenta, documentos, eventos, bitácora) está en ?r=proveedor&id=<id>.
 * RUC, banco y condiciones de crédito solo los ve y cambia el dueño o el vendedor.
 */
$nid = (int) $u['negocio_id'];
$dia = new DiaEvento(db());
$ficha = new ProveedoresFicha(db());
$verId = (int) ($_GET['id'] ?? 0);
$comercial = es_comercial($u);
$verDinero = $u['rol'] !== 'coordinador';

if (es_post()) {
    verificar_csrf();
    exigir(puede_editar($u), 'Tu usuario solo puede mirar.');
    $accion = (string) ($_POST['accion'] ?? '');
    $pid = (int) ($_POST['proveedor_id'] ?? 0);
    try {
        $datos = ['nombre' => campo('nombre'), 'servicio' => campo('servicio', 'otros'), 'contacto' => campo('contacto'),
                  'telefono' => campo('telefono'), 'email' => campo('email'), 'notas' => campo('notas')];
        if (!in_array($accion, ['crear', 'editar'], true)) throw new RuntimeException('Acción no válida.');
        if ($comercial) ProveedoresFicha::validarDatos($_POST);   // antes de guardar nada
        db()->beginTransaction();
        try {
            if ($accion === 'crear') {
                $pid = $dia->guardarProveedor($nid, null, $datos);
                $ficha->anotar($nid, $pid, 'condiciones', 'Agregó el proveedor ' . $datos['nombre'], $u);
            } else {
                $dia->guardarProveedor($nid, $pid, $datos + ['activo' => !empty($_POST['activo'])]);
            }
            if ($comercial) $ficha->guardarDatos($nid, $pid, $_POST, $u);
            db()->commit();
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            throw $e;
        }
        if ($accion === 'crear') {
            aviso("Listo, {$datos['nombre']} está en tus proveedores. Sube sus documentos o hazle una compra desde su ficha.");
            redirigir('proveedor', ['id' => $pid]);
        }
        aviso('Cambios guardados.');
        redirigir('proveedor', ['id' => $pid]);
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
        $_SESSION['form_proveedor'] = $_POST;
        redirigir('proveedores', $pid ? ['id' => $pid] : []);
    }
}

$previo = $_SESSION['form_proveedor'] ?? null;
unset($_SESSION['form_proveedor']);
$proveedor = $verId ? $ficha->proveedor($nid, $verId) : null;
if ($verId && !$proveedor) {
    http_response_code(404);
    vista('error', ['mensaje' => 'No se encontró ese proveedor.'], 'Proveedores · TuRenta');
    return;
}
$lista = $dia->proveedores($nid);
$puntajes = $ficha->puntajes($nid);
$alertas = $ficha->alertasDocumentos($nid);
$verRanking = ($_GET['ver'] ?? '') === 'ranking';
$ranking = $verRanking ? $ficha->ranking($nid, null, false) : [];
$cfgPuntaje = (new \TuRenta\PuntajeProveedores(db()))->config($nid);   // 091
vista('proveedores', compact('lista', 'proveedor', 'previo', 'puntajes', 'alertas', 'verRanking', 'ranking', 'comercial', 'verDinero', 'cfgPuntaje'), 'Proveedores · TuRenta');
