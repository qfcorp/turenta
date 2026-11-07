<?php
/**
 * Precios de $12 y $25 (migración 113, decisión de Dimitry del 6 nov 2026): Básico $12 con 3 módulos a elegir, cada módulo
 * $5, Completo $25 con todo y el Asistente IA, IA $3 aparte en el Básico con «uso limitado» (tope de $1,50 al mes).
 * Los nombres y los módulos de cada plan no cambian. Se pone una sola vez (marca planes_12_25).
 */
use TuRenta\{Ia, Modulos};

$db = base_limpia();
$mods = new Modulos($db);
$valor = fn(string $sql) => $db->query($sql)->fetchColumn();

titulo('1. Instalación nueva: Básico $12 (3 a elegir, $5 cada uno más) y Completo $25 con todo');
$pl = $mods->planes();
check('Básico $12, 3 módulos a elegir, $5 cada uno más (el nombre no cambia)', ['Básico', 12.0, 3, 5.0],
      [$pl['esencial']['nombre'], $pl['esencial']['precio_mensual'], $pl['esencial']['modulos_elegir'], $pl['esencial']['precio_modulo_extra']]);
check('Completo $25 con todos los módulos y el Asistente IA', ['Completo', 25.0, '*,asistente_ia'], [$pl['quinta']['nombre'], $pl['quinta']['precio_mensual'], $valor("SELECT modulos FROM planes WHERE codigo = 'quinta'")]);
$cat = $mods->catalogo();
check('Cada módulo $5 y el Asistente IA $3', [[5.0], 3.0], [array_values(array_unique(array_map(fn($m) => (float) $m['precio_mensual'], array_diff_key($cat, ['asistente_ia' => 1])))), $cat['asistente_ia']['precio_mensual']]);
check('IA con uso limitado: tope de $1,50 al mes con el módulo', 1.5, Ia::TOPE_MODULO);
check('Marca planes_12_25 puesta', '2026-11-06', $valor("SELECT valor FROM ajustes WHERE clave = 'planes_12_25'"));

titulo('2. Precios: Básico + extras, Completo');
[$n] = negocio_prueba($db, 'esencial', 'Local Básico');
$db->prepare("UPDATE negocios SET estado = 'activo', activo_hasta = CURRENT_DATE + 30, modulos_extra = 'paquetes,contratos,portal_cliente,cobros_linea' WHERE id = ?")->execute([$n]);
$mods->olvidar();
check('Básico con 4 módulos: 3 incluidos + 1 extra = $17', 17.0, $mods->mensual($n));
$db->prepare("UPDATE negocios SET modulos_extra = 'paquetes,asistente_ia' WHERE id = ?")->execute([$n]);
$mods->olvidar();
check('Básico con 1 módulo + IA: la IA no cuenta entre los 3 = $15', 15.0, $mods->mensual($n));
[$q] = negocio_prueba($db, 'quinta', 'Quinta Completa');
$db->prepare("UPDATE negocios SET estado = 'activo', activo_hasta = CURRENT_DATE + 30 WHERE id = ?")->execute([$q]);
$mods->olvidar();
check('Completo = $25', 25.0, $mods->mensual($q));

titulo('3. Una sola vez: si Dimitry cambia precios desde el panel, el instalador no los pisa');
$db->exec("UPDATE planes SET precio_mensual = 14 WHERE codigo = 'esencial'; UPDATE modulos SET precio_mensual = 6 WHERE id = 'paquetes'");
$db->exec(file_get_contents(RAIZ . '/db/migraciones/113_planes_12_25.sql'));
check('Correr la 113 otra vez no cambia nada', ['14.00', '6.00'], [$valor("SELECT precio_mensual FROM planes WHERE codigo = 'esencial'"), $valor("SELECT precio_mensual FROM modulos WHERE id = 'paquetes'")]);
