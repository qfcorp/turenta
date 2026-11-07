<?php
use TuRenta\{Catalogo, Cotizaciones};

/**
 * Catálogo y precios: /?r=catalogo
 * Productos y servicios por categoría (menú, bebidas, decoración…), con precio, costo y margen (solo el dueño ve costos).
 * Dueño y vendedor crean y cambian productos; solo el dueño sube precios en bloque. El usuario de consulta solo mira.
 */
$nid = (int) $u['negocio_id'];
$cat = new Catalogo(db());
$puede = es_comercial($u);
$dueno = es_admin_total($u);

$filtros = [
    'buscar' => trim((string) ($_GET['q'] ?? '')),
    'categoria' => isset(Cotizaciones::CATEGORIAS[$_GET['cat'] ?? '']) ? (string) $_GET['cat'] : '',
    'inactivos' => !empty($_GET['inactivos']),
];
$volver = array_filter(['q' => $filtros['buscar'], 'cat' => $filtros['categoria'], 'inactivos' => $filtros['inactivos'] ? 1 : null]);

if (es_post()) {
    verificar_csrf();
    exigir($puede, 'Solo el dueño y los vendedores pueden cambiar el catálogo.');
    $accion = (string) ($_GET['accion'] ?? '');
    try {
        switch ($accion) {
            case 'guardar':
                $id = (int) ($_POST['producto_id'] ?? 0) ?: null;
                $datos = [
                    'nombre' => campo('nombre'), 'descripcion' => campo('descripcion'), 'categoria' => campo('categoria'),
                    'unidad' => campo('unidad'), 'precio' => campo('precio'), 'con_iva' => !empty($_POST['con_iva']), 'orden' => campo('orden'),
                ];
                if ($dueno) $datos['costo'] = campo('costo');
                $nuevo = $cat->guardarProducto($nid, $id, $datos);
                aviso($id ? 'Producto guardado.' : 'Producto agregado al catálogo. Ya lo puedes usar en cotizaciones y paquetes.');
                redirigir('catalogo', array_merge($volver, ['cat' => $datos['categoria'], 'ver' => $nuevo]));
            case 'desactivar':
            case 'activar':
                $cat->activarProducto($nid, (int) ($_POST['producto_id'] ?? 0), $accion === 'activar');
                aviso($accion === 'activar' ? 'El producto vuelve a estar disponible.' : 'Producto desactivado. Ya no sale para cotizar, pero las cotizaciones anteriores lo siguen mostrando.');
                break;
            case 'subir_precios':
                exigir($dueno, 'Solo el dueño o un administrador puede cambiar todos los precios a la vez.');
                $pct = numero($_POST['pct'] ?? '') ?? 0.0;
                $categoria = campo('categoria_bloque') ?: null;
                $n = $cat->subirPrecios($nid, $pct, $categoria, !empty($_POST['paquetes']));
                $txtPct = rtrim(rtrim(number_format(abs($pct), 2, ',', '.'), '0'), ',') . ' %';
                aviso(($pct > 0 ? 'Subiste ' : 'Bajaste ') . "$txtPct los precios de $n " . ($n === 1 ? 'producto' : 'productos')
                    . ($categoria ? ' de ' . Cotizaciones::CATEGORIAS[$categoria] : ' de todo el catálogo') . '. Las cotizaciones ya hechas no cambian.');
                break;
            default:
                throw new RuntimeException('Acción no válida.');
        }
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
        if ($accion === 'guardar') {
            $_SESSION['doc_producto_borrador'] = $_POST;
            redirigir('catalogo', $volver + (!empty($_POST['producto_id']) ? ['editar' => (int) $_POST['producto_id']] : ['nuevo' => 1]));
        }
    }
    redirigir('catalogo', $volver);
}

$productos = $cat->productos($nid, $filtros);
$todos = $cat->productos($nid, ['inactivos' => true]);
$conteo = [];
foreach ($todos as $p) if ($p['activo']) $conteo[$p['categoria']] = ($conteo[$p['categoria']] ?? 0) + 1;
$editar = null;
if (!empty($_GET['editar'])) {
    $editar = $cat->producto($nid, (int) $_GET['editar']);
    if (!$editar) aviso('No se encontró ese producto.', 'error');
}
$borrador = $_SESSION['doc_producto_borrador'] ?? null;
unset($_SESSION['doc_producto_borrador']);
if ($borrador) {
    $editar = array_merge($editar ?? [], array_intersect_key($borrador, array_flip(['nombre', 'descripcion', 'categoria', 'unidad', 'precio', 'costo', 'orden'])),
                          ['con_iva' => !empty($borrador['con_iva'])]);
    if (empty($borrador['producto_id'])) unset($editar['id']);
}
// Para la vista previa de "subir precios" (solo precio y categoría; nunca costos)
$paraBloque = array_values(array_map(fn($p) => ['n' => $p['nombre'], 'c' => $p['categoria'], 'p' => (float) $p['precio']],
    array_filter($todos, fn($p) => $p['activo'] && (float) $p['precio'] > 0)));

vista('catalogo', compact('productos', 'filtros', 'conteo', 'editar', 'puede', 'dueno', 'paraBloque') + ['totalActivos' => array_sum($conteo),
    'abrirForm' => $editar !== null || !empty($_GET['nuevo']), 'ver' => (int) ($_GET['ver'] ?? 0)], 'Catálogo y precios · TuRenta');
