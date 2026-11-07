<?php
use TuRenta\{Eventos, Ventas};

/**
 * Lista de eventos con filtros y vista de tablero por estado (consulta → cotizado → separado → confirmado).
 *   /?r=eventos&estado=activos&q=&tipo=&vendedor=&desde=&hasta=&vista=lista|tablero
 */
$nid = (int) $u['negocio_id'];
$eventos = new Eventos(db());
$ventas = new Ventas(db());

$estados = ['activos' => 'Activos', 'vendidos' => 'Vendidos', '' => 'Todos'] + Eventos::ESTADOS;
$estado = (string) ($_GET['estado'] ?? 'activos');
if (!array_key_exists($estado, $estados)) $estado = 'activos';
$vistaLista = ($_GET['vista'] ?? '') === 'tablero' ? 'tablero' : 'lista';
$f = [
    'buscar' => mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 80),
    'tipo' => isset(Eventos::TIPOS[$_GET['tipo'] ?? '']) ? (string) $_GET['tipo'] : '',
    'vendedor_id' => (int) ($_GET['vendedor'] ?? 0) ?: null,
    'desde' => Ventas::fechaValida($_GET['desde'] ?? null) ? (string) $_GET['desde'] : '',
    'hasta' => Ventas::fechaValida($_GET['hasta'] ?? null) ? (string) $_GET['hasta'] : '',
];
$orden = ($_GET['orden'] ?? '') === 'recientes' ? 'recientes' : 'fecha';
// Por defecto (activos sin rango) solo se ven los de hoy en adelante
$soloFuturos = $estado === 'activos' && $f['desde'] === '' && $f['hasta'] === '' && empty($_GET['pasados']);
$filtrosLista = $f + ['estado' => $estado, 'orden' => $orden];
if ($soloFuturos) $filtrosLista['desde'] = date('Y-m-d');

$datos = [
    'estado' => $estado, 'estados' => $estados, 'f' => $f, 'vistaLista' => $vistaLista, 'orden' => $orden,
    'soloFuturos' => $soloFuturos, 'vendedores' => $ventas->usuariosVenta($nid),
    'verDinero' => $u['rol'] !== 'coordinador', 'puedeCrear' => es_comercial($u),
];
if ($vistaLista === 'tablero') {
    $tab = $f;
    if ($tab['desde'] === '') $tab['desde'] = date('Y-m-d');
    $datos['tablero'] = $ventas->tablero($nid, $tab);
    $datos['perdidos'] = $eventos->lista($nid, ['estado' => 'perdido'] + $f + ['orden' => 'recientes'], 200);
} else {
    $lista = $eventos->lista($nid, $filtrosLista, 500);
    $datos['lista'] = $lista;
    $datos['totales'] = Ventas::totales($lista);
}
$datos['hayFiltros'] = $f['buscar'] !== '' || $f['tipo'] !== '' || $f['vendedor_id'] || $f['desde'] !== '' || $f['hasta'] !== '';
vista('eventos', $datos, 'Eventos · TuRenta');
