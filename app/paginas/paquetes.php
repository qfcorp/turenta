<?php
use TuRenta\{Catalogo, Eventos};

/**
 * Paquetes: /?r=paquetes   (editar: &editar=<id> · nuevo: &nuevo=1 · simulador: &sim=<id>&n=<invitados>)
 * Paquetes armados con productos del catálogo (cantidad y precio especial), mínimo de invitados y tipo de evento.
 * Simulador: ¿cuánto sale para N invitados? (total y por persona, IVA incluido).
 */
$nid = (int) $u['negocio_id'];
$cat = new Catalogo(db());
$puede = es_comercial($u);
$dueno = es_admin_total($u);

if (es_post()) {
    verificar_csrf();
    exigir($puede, 'Solo el dueño y los vendedores pueden cambiar los paquetes.');
    $accion = (string) ($_GET['accion'] ?? '');
    $id = (int) ($_POST['paquete_id'] ?? 0);
    try {
        switch ($accion) {
            case 'guardar':
                $items = [];
                foreach ((array) ($_POST['items'] ?? []) as $fila) {
                    if (!is_array($fila) || empty($fila['producto_id'])) continue;
                    $items[] = ['producto_id' => (int) $fila['producto_id'], 'cantidad' => (string) ($fila['cantidad'] ?? '1'), 'precio' => (string) ($fila['precio'] ?? '')];
                }
                $nuevo = $cat->guardarPaquete($nid, $id ?: null, [
                    'nombre' => campo('nombre'), 'descripcion' => campo('descripcion'),
                    'tipo_evento' => campo('tipo_evento'), 'minimo_invitados' => (int) campo('minimo_invitados', '0'),
                ], $items);
                aviso($id ? 'Paquete guardado.' : 'Paquete creado. Ya lo puedes usar al cotizar.');
                redirigir('paquetes', ['sim' => $nuevo]);
            case 'duplicar':
                $nuevo = $cat->duplicarPaquete($nid, $id);
                aviso('Paquete copiado. Cámbiale el nombre y ajusta lo que necesites.');
                redirigir('paquetes', ['editar' => $nuevo]);
            case 'desactivar':
            case 'activar':
                $cat->activarPaquete($nid, $id, $accion === 'activar');
                aviso($accion === 'activar' ? 'El paquete vuelve a estar disponible para cotizar.' : 'Paquete desactivado. Ya no sale al cotizar.');
                break;
            default:
                throw new RuntimeException('Acción no válida.');
        }
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
        if ($accion === 'guardar') {
            $_SESSION['doc_paquete_borrador'] = $_POST;
            redirigir('paquetes', $id ? ['editar' => $id] : ['nuevo' => 1]);
        }
    }
    redirigir('paquetes');
}

$paquetes = $cat->paquetes($nid);
$productos = $cat->productos($nid);
$editar = null;
if ($puede && !empty($_GET['editar'])) {
    $editar = $cat->paquete($nid, (int) $_GET['editar']);
    if (!$editar) aviso('No se encontró ese paquete.', 'error');
} elseif ($puede && !empty($_GET['nuevo'])) {
    $editar = ['id' => null, 'nombre' => '', 'descripcion' => '', 'tipo_evento' => '', 'minimo_invitados' => 0, 'items' => []];
}
$borrador = $_SESSION['doc_paquete_borrador'] ?? null;
unset($_SESSION['doc_paquete_borrador']);
if ($borrador && $editar !== null) {
    $editar = array_merge($editar, array_intersect_key($borrador, array_flip(['nombre', 'descripcion', 'tipo_evento', 'minimo_invitados'])));
    $editar['items'] = [];
    foreach ((array) ($borrador['items'] ?? []) as $fila) {
        if (!is_array($fila) || empty($fila['producto_id'])) continue;
        $editar['items'][] = ['producto_id' => (int) $fila['producto_id'], 'cantidad' => $fila['cantidad'] ?? 1, 'precio_paquete' => ($fila['precio'] ?? '') === '' ? null : $fila['precio']];
    }
}

// Simulador
$sim = null;
$simId = (int) ($_GET['sim'] ?? 0);
$simN = max(1, min(5000, (int) ($_GET['n'] ?? 0)));
if ($simId) {
    try {
        $p = $cat->paquete($nid, $simId);
        if (!$p) throw new RuntimeException('No se encontró ese paquete.');
        if (empty($_GET['n'])) $simN = max(100, (int) $p['minimo_invitados']);
        $sim = $cat->simular($nid, $simId, $simN);
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
    }
}
// Totales de cada paquete para su mínimo de invitados (o 100)
$resumen = [];
foreach ($paquetes as $p) {
    $n = max(1, (int) $p['minimo_invitados'] ?: 100);
    $resumen[(int) $p['id']] = ((int) $p['productos'] > 0) ? $cat->simular($nid, (int) $p['id'], $n) : null;
}
$st = db()->prepare('SELECT iva_pct FROM negocios WHERE id = ?');
$st->execute([$nid]);
$ivaPct = (float) $st->fetchColumn();

vista('paquetes', compact('paquetes', 'productos', 'editar', 'sim', 'simId', 'simN', 'resumen', 'puede', 'dueno', 'ivaPct'), 'Paquetes · TuRenta');
