<?php
/**
 * Planes de $20 y $45 (migración 112, decisión de Dimitry del 28 oct 2026):
 *  - Básico $20 (código esencial): la base + 3 módulos que el cliente elige; cada módulo más $5. Límites de Esencial.
 *  - Completo $45 (código quinta): todos los módulos con el Asistente IA incluido, sin límites.
 *  - Asistente IA en Básico: $5 aparte, no cuenta entre los 3 (Modulos::FUERA_DEL_CUPO).
 *  - El plan del medio (salon) desaparece: negocios y pagos pasan a Completo.
 * Migración repetible (marca planes_20_45), precio al mes, Planes y precios, conector v2 con el Panel Tukán Central.
 */
use TuRenta\{Ia, Modulos, PanelTukan, Planes, TukanCentralV2};

$db = base_limpia();
planes_20_45($db);   // (6 nov 2026) la instalación nueva ya trae $12 y $25 (migración 113): aquí se prueba la 112
$hoy = new DateTimeImmutable('today');
$cuenta = fn(string $sql) => (int) $db->query($sql)->fetchColumn();
$valor = fn(string $sql) => $db->query($sql)->fetchColumn();
$mods = new Modulos($db);
$planes = new Planes($db);
$pagado = function (int $nid) use ($db): void {
    $db->prepare("UPDATE negocios SET estado = 'activo', activo_hasta = CURRENT_DATE + 30 WHERE id = ?")->execute([$nid]);
};

// ---------------------------------------------------------------------------------------------
titulo('1. Instalación nueva: solo Básico ($20, 3 a elegir, $5 cada uno más) y Completo ($45, todo con el Asistente IA)');
$pl = $mods->planes();
check('Dos planes: esencial (Básico) y quinta (Completo); el plan del medio ya no existe', [['esencial', 'quinta'], 'Básico', 'Completo', 0],
      [array_keys($pl), $pl['esencial']['nombre'], $pl['quinta']['nombre'], $cuenta("SELECT count(*) FROM planes WHERE codigo = 'salon'")]);
check('Básico: $20, solo la base, 3 módulos a elegir y $5 cada uno más', [20.0, [], 3, 5.0],
      [$pl['esencial']['precio_mensual'], $pl['esencial']['modulos'], $pl['esencial']['modulos_elegir'], $pl['esencial']['precio_modulo_extra']]);
check('Completo: $45, todos los módulos con el Asistente IA, sin módulos a elegir', [45.0, array_keys(Modulos::CATALOGO), 0, null, '*,asistente_ia'],
      [$pl['quinta']['precio_mensual'], $pl['quinta']['modulos'], $pl['quinta']['modulos_elegir'], $pl['quinta']['precio_modulo_extra'],
       $valor("SELECT modulos FROM planes WHERE codigo = 'quinta'")]);
check('Límites: Básico conserva los de Esencial (1 espacio, 2 usuarios); Completo sin límites', ['1|2', '-|-'],
      [$valor("SELECT coalesce(max_espacios::text, '-') || '|' || coalesce(max_usuarios::text, '-') FROM planes WHERE codigo = 'esencial'"),
       $valor("SELECT coalesce(max_espacios::text, '-') || '|' || coalesce(max_usuarios::text, '-') FROM planes WHERE codigo = 'quinta'")]);
check('Asistente IA: $5 en el catálogo y fuera del cupo de los 3', [5.0, ['asistente_ia']], [$mods->catalogo()['asistente_ia']['precio_mensual'], Modulos::FUERA_DEL_CUPO]);
check('La descripción de los planes (la ve el cliente en Mi plan) no tiene precios', [false, false],
      [str_contains((string) $valor("SELECT descripcion FROM planes WHERE codigo = 'esencial'"), '$'), str_contains((string) $valor("SELECT descripcion FROM planes WHERE codigo = 'quinta'"), '$')]);
check('Marca planes_20_45 puesta', '2026-10-28', $valor("SELECT valor FROM ajustes WHERE clave = 'planes_20_45'"));
check('PLAN_DEFECTO sigue en quinta (las demos tienen todo)', 'quinta', Modulos::PLAN_DEFECTO);

// ---------------------------------------------------------------------------------------------
titulo('2. Migración 112 sobre una base de antes (Esencial $19, Salón $39, Quinta $69)');
$db->exec("DELETE FROM ajustes WHERE clave = 'planes_20_45'; DELETE FROM modulos WHERE id = 'asistente_ia'");
planes_de_antes($db);
$db->exec("UPDATE planes SET modulos = 'paquetes' WHERE codigo = 'esencial'");   // un Esencial que traía un módulo
$mods->olvidar();
[$nEsPaga] = negocio_prueba($db, 'esencial', 'Local Esencial Que Paga');
[$nEsDemo] = negocio_prueba($db, 'esencial', 'Local Esencial En Demo');
[$nSal] = negocio_prueba($db, 'salon', 'Salón Que Paga');
[$nQui] = negocio_prueba($db, 'quinta', 'Quinta Que Paga');
foreach ([$nEsPaga, $nSal, $nQui] as $x) $pagado($x);
$db->exec("UPDATE negocios SET modulos_extra = 'plano', modulos_quitados = 'metas' WHERE id = $nSal");
$sSal = $planes->suscribir($nSal, 'salon', 'mensual', $hoy, 'transferencia');
check('Antes: el Salón paga $39 + plano $6 − metas $4 = $41', 41.0, $mods->mensual($nSal));
$db->exec(file_get_contents(RAIZ . '/db/migraciones/112_planes_20_45.sql'));
$mods->olvidar();
$pl = $mods->planes();
check('Después: solo Básico $20 y Completo $45', [['esencial', 'quinta'], 20.0, 45.0, 'Básico', 'Completo'],
      [array_keys($pl), $pl['esencial']['precio_mensual'], $pl['quinta']['precio_mensual'], $pl['esencial']['nombre'], $pl['quinta']['nombre']]);
$e = $mods->estado($nEsPaga);
check('Quien pagaba Esencial conserva el módulo de su plan: pasa a ser uno de sus 3 elegidos (sigue en $20)', [['paquetes'], ['paquetes'], 2, 20.0, []],
      [$e['pagados'], $e['elegidos'], $e['por_elegir'], $e['mensual'], $e['del_plan']]);
check('Una demo de Esencial no recibe extras (sigue con todo encendido)', '', (string) $valor("SELECT modulos_extra FROM negocios WHERE id = $nEsDemo"));
check('El negocio del Salón pasa a Completo, con su pago', ['quinta', 'quinta'],
      [$valor("SELECT plan_codigo FROM negocios WHERE id = $nSal"), $valor("SELECT plan_codigo FROM suscripciones WHERE id = $sSal")]);
check('… lo que tenía quitado sigue quitado: $45 − metas $4 = $41', [['metas'], 41.0], [$mods->estado($nSal)['quitados'], $mods->mensual($nSal)]);
check('La Quinta pasa a Completo: $45 con el Asistente IA', [45.0, true], [$mods->mensual($nQui), $mods->activo($nQui, 'asistente_ia')]);
check('Asistente IA $5 en el catálogo', 5.0, $mods->catalogo()['asistente_ia']['precio_mensual']);
$db->exec("UPDATE planes SET precio_mensual = 22, descripcion = 'La cambió Dimitry' WHERE codigo = 'esencial'; UPDATE modulos SET precio_mensual = 6 WHERE id = 'asistente_ia'");
$db->exec(file_get_contents(RAIZ . '/db/migraciones/112_planes_20_45.sql'));
check('Correrla otra vez no pisa lo que cambió Dimitry ni duplica extras', [22.0, 'La cambió Dimitry', 6.0, 'paquetes'],
      [(float) $valor("SELECT precio_mensual FROM planes WHERE codigo = 'esencial'"), $valor("SELECT descripcion FROM planes WHERE codigo = 'esencial'"),
       (float) $valor("SELECT precio_mensual FROM modulos WHERE id = 'asistente_ia'"), $valor("SELECT modulos_extra FROM negocios WHERE id = $nEsPaga")]);
$db->exec("UPDATE planes SET precio_mensual = 20 WHERE codigo = 'esencial'; UPDATE modulos SET precio_mensual = 5 WHERE id = 'asistente_ia'");
$mods->olvidar();

// ---------------------------------------------------------------------------------------------
titulo('3. Precio al mes del Básico: 3 módulos a elegir, $5 cada uno más; el Asistente IA aparte');
[$b] = negocio_prueba($db, 'esencial', 'Local Veinte');
$pagado($b);
$poner = function (string $extra) use ($db, $b, $mods): array {
    $db->prepare('UPDATE negocios SET modulos_extra = ?, modulos_quitados = \'\', precio_acordado = NULL WHERE id = ?')->execute([$extra, $b]);
    $mods->olvidar();
    $e = $mods->estado($b);
    return [$e['mensual'], count($e['elegidos']), $e['por_elegir']];
};
check('Básico sin módulos: $20 y le quedan 3 por elegir', [20.0, 0, 3], $poner(''));
check('Con 3 módulos elegidos (sin importar su precio del catálogo): sigue en $20', [20.0, 3, 0], $poner('contratos,dia_evento,invitados'));
check('Con 5 módulos: $20 + 2 × $5 = $30', [30.0, 3, 0], $poner('contratos,dia_evento,invitados,plano,metas'));
check('El Asistente IA cuesta $5 aparte y no gasta uno de los 3', [25.0, 1, 2], $poner('contratos,asistente_ia'));
check('Con 8 módulos llega a $45, lo mismo que el Completo', [45.0, 3, 0], $poner('paquetes,contratos,portal_cliente,cuestionarios,dia_evento,invitados,plano,mobiliario'));
$e = $mods->estado($b);
check('Los 3 primeros en el orden del catálogo van incluidos (0) y los demás $5', [['paquetes', 'contratos', 'portal_cliente'], 0.0, 5.0, 25.0],
      [$e['elegidos'], $e['precio_por_extra']['contratos'], $e['precio_por_extra']['mobiliario'], array_sum($e['precio_por_extra']) + 0.0]);
$poner('');
$r = $mods->cambiar($b, ['cobros_linea'], []);
check('Encender uno con dependencia (cobros en línea + portal del cliente): los dos cuentan entre los 3', [['portal_cliente', 'cobros_linea'], 1, 20.0],
      [$r['estado']['elegidos'], $r['estado']['por_elegir'], $r['estado']['mensual']]);
check('Cuánto pagaría con un pedido (estado con $sumar): uno más elegido sigue en $20; con dos más, $25', [20.0, 25.0],
      [$mods->estado($b, null, ['plano'])['mensual'], $mods->estado($b, null, ['plano', 'metas'])['mensual']]);
check('Cotizar semestral (paga 5) con sus módulos', [100.0, 20.0], (function () use ($planes, $b) { $c = $planes->cotizarNegocio($b, 'esencial', 'semestral'); return [$c['monto'], $c['mensual']]; })());
check('Cotizar el cambio a Completo: $45', 45.0, $planes->cotizarNegocio($b, 'quinta', 'mensual')['monto']);
$mods->cambiar($b, [], [], 18.0);
check('El precio acordado sigue mandando', 18.0, $mods->mensual($b));
$mods->cambiar($b, [], [], null);
check('Ingreso mensual del Panel Tukán: usa el precio con los módulos elegidos', true, (new PanelTukan($db))->ingresoMensual($hoy) >= 20.0);

// ---------------------------------------------------------------------------------------------
titulo('4. Completo y el Asistente IA');
[$c] = negocio_prueba($db, 'quinta', 'Quinta Cuarenta y Cinco');
$pagado($c);
$ia = new Ia($db, $mods);
check('Completo: $45 con todo, también el Asistente IA (del plan) con el tope de siempre', [45.0, true, true, Ia::TOPE_MODULO],
      [$mods->mensual($c), $mods->activo($c, 'asistente_ia'), in_array('asistente_ia', $mods->estado($c)['del_plan'], true), $ia->tope($c)]);
$poner('asistente_ia');
check('Básico con el Asistente IA ($25): tiene el módulo y el mismo tope', [25.0, true, Ia::TOPE_MODULO], [$mods->mensual($b), $ia->tieneModulo($b), $ia->tope($b)]);
$poner('');
check('Básico sin el Asistente IA: solo el tope de la Ayuda', Ia::TOPE_AYUDA, $ia->tope($b));
[$demo] = negocio_prueba($db, 'quinta', 'Demo Completo');
check('Una demo de Completo tiene todo (también el Asistente IA)', array_keys(Modulos::CATALOGO), $mods->activos($demo));

// ---------------------------------------------------------------------------------------------
titulo('5. Planes y precios (súper usuario): módulos a elegir y precio de cada uno más');
$poner('paquetes,contratos,portal_cliente,cuestionarios,dia_evento,invitados,plano,mobiliario');
$planes->guardarPlan('esencial', 'Básico', 20.0, 1, 2, 'La base + 4 a tu elección', 4, 4.0);
$mods->olvidar();
check('4 a elegir y $4 cada uno más (8 módulos: $20 + 4 × $4)', [4, 4.0, 36.0],
      [$mods->planes()['esencial']['modulos_elegir'], $mods->planes()['esencial']['precio_modulo_extra'], $mods->mensual($b)]);
$planes->guardarPlan('esencial', 'Básico', 20.0, 1, 2, 'La base + 4 a tu elección', null, null);
$mods->olvidar();
check('Sin precio por módulo (vacío): cada uno más a su precio del catálogo (no cambia los módulos a elegir)', [4, null, 20.0 + 8 + 8 + 6 + 5],
      [$mods->planes()['esencial']['modulos_elegir'], $mods->planes()['esencial']['precio_modulo_extra'], $mods->mensual($b)]);
$planes->guardarPlan('esencial', 'Básico', 20.0, 1, 2, 'La base de TuRenta + 3 módulos que tú eliges.', 3, 5.0);
$mods->olvidar();
check_error('Los módulos a elegir no aceptan números raros', 'Los módulos a elegir deben ser de 0 a 50', fn() => $planes->guardarPlan('esencial', 'Básico', 20.0, 1, 2, '', 99));
check_error('El precio de cada módulo más no puede ser negativo', 'El precio de cada módulo extra no es válido', fn() => $planes->guardarPlan('esencial', 'Básico', 20.0, 1, 2, '', 3, -1.0));
check('Guardar sin los campos nuevos no los cambia', [3, 5.0], (function () use ($planes, $mods) {
    $planes->guardarPlan('esencial', 'Básico', 20.0, 1, 2, 'La base de TuRenta + 3 módulos que tú eliges.');
    $mods->olvidar();
    return [$mods->planes()['esencial']['modulos_elegir'], $mods->planes()['esencial']['precio_modulo_extra']];
})());

// ---------------------------------------------------------------------------------------------
titulo('6. Conector v2 con el Panel Tukán Central');
$SECRETO = str_repeat('ef56', 16);
putenv('TUKAN_CENTRAL_ID=turenta'); putenv("TUKAN_CENTRAL_SECRET=$SECRETO"); putenv('TUKAN_CENTRAL_URL=https://panel.prueba.ec'); putenv('TURENTA_URL=https://turenta.prueba.ec');
$b64 = fn($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
$FL = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;
$pedir = function (string $accion, array $cuerpo = [], string $rol = 'dueno') use ($db, $SECRETO, $b64, $FL): array {
    $c = json_encode($cuerpo === [] ? new stdClass() : $cuerpo, $FL);
    $db->exec('DELETE FROM tukan_central_pedidos');
    $t = time();
    $p = ['v' => 2, 'tipo' => $accion, 'inst' => 'turenta', 'email' => 'dimitryfierro@gmail.com', 'nombre' => 'Dimitry', 'rol' => $rol,
          'iat' => $t, 'exp' => $t + ($accion === 'ejecutar' ? 30 : 60), 'nonce' => bin2hex(random_bytes(16)), 'bh' => $b64(hash('sha256', $c, true))];
    $a = $b64(json_encode($p, $FL));
    return (new TukanCentralV2($db))->procesar($accion, 'POST', 'application/json', $c, $a . '.' . $b64(hash_hmac('sha256', $a, $SECRETO, true)), '198.51.100.7');
};
$ej = fn(string $op, array $datos, ?int $cliente = null) => $pedir('ejecutar', ['operacion_id' => bin2hex(random_bytes(16)), 'accion' => $op,
                                                                               'datos' => $datos === [] ? new stdClass() : $datos] + ($cliente !== null ? ['cliente_id' => (string) $cliente] : []));
$pp = array_column($pedir('planes')[1]['planes'] ?? [], null, 'codigo');
check('planes: Básico con 3 módulos a elegir y $5 cada uno más; Completo sin', [3, 5.0, '3', 0, null, false],
      [$pp['esencial']['modulos_elegir'] ?? null, $pp['esencial']['precio_modulo_extra'] ?? null, $pp['esencial']['limites']['Módulos a elegir'] ?? null,
       $pp['quinta']['modulos_elegir'] ?? null, array_key_exists('precio_modulo_extra', $pp['quinta'] ?? []) ? $pp['quinta']['precio_modulo_extra'] : 'x',
       isset($pp['quinta']['limites']['Módulos a elegir'])]);
$m = $pedir('modulos')[1];
$mp = array_column($m['planes'], null, 'codigo');
check('modulos: fuera_del_cupo en el catálogo (para el total en vivo del Panel Central) y cada plan con sus módulos a elegir', [['asistente_ia'], 3, 5.0, 0, null],
      [$m['fuera_del_cupo'] ?? null, $mp['esencial']['modulos_elegir'] ?? null, $mp['esencial']['precio_modulo_extra'] ?? null, $mp['quinta']['modulos_elegir'] ?? null,
       array_key_exists('precio_modulo_extra', $mp['quinta'] ?? []) ? $mp['quinta']['precio_modulo_extra'] : 'x']);
check('… fuera_del_cupo: solo ids que existen en el catálogo', [], array_values(array_diff($m['fuera_del_cupo'] ?? ['x'], array_column($m['modulos'], 'id'))));
check('capacidades: el formulario guardar_plan pide módulos a elegir y precio de cada uno más', [true, true],
      (function () use ($pedir) { $f = array_column($pedir('capacidades')[1]['formularios']['guardar_plan'] ?? [], 'campo');
          return [in_array('modulos_elegir', $f, true), in_array('precio_modulo_extra', $f, true)]; })());
$poner('paquetes,contratos,portal_cliente,plano');
$cm = $pedir('cliente', ['id' => (string) $b])[1]['cliente']['modulos'] ?? [];
check('cliente.modulos: elegidos, por_elegir y precio de cada extra ($20 + $5)', [3, ['paquetes', 'contratos', 'portal_cliente'], 0, ['paquetes' => 0.0, 'contratos' => 0.0, 'portal_cliente' => 0.0, 'plano' => 5.0], 25.0],
      [$cm['modulos_elegir'] ?? null, $cm['elegidos'] ?? null, $cm['por_elegir'] ?? null, $cm['precio_por_extra'] ?? null, $cm['mensual'] ?? null]);
$r = $ej('guardar_plan', ['plan' => 'esencial', 'modulos_elegir' => 2, 'precio_modulo_extra' => 7]);
check('guardar_plan (editar plan desde el Central): 2 a elegir y $7 cada uno más ($20 + 2 × $7)', [200, 2, 7.0, 34.0],
      [$r[0], $r[1]['resultado']['plan']['modulos_elegir'] ?? null, $r[1]['resultado']['plan']['precio_modulo_extra'] ?? null, (new Modulos($db))->mensual($b)]);
$r = $ej('guardar_plan', ['plan' => 'esencial', 'precio_modulo_extra' => null]);
check('guardar_plan: precio vacío = el del catálogo; sin modulos_elegir no lo cambia', [200, 2, null],
      [$r[0], $r[1]['resultado']['plan']['modulos_elegir'] ?? null, array_key_exists('precio_modulo_extra', $r[1]['resultado']['plan'] ?? []) ? $r[1]['resultado']['plan']['precio_modulo_extra'] : 'x']);
check('guardar_plan: módulos a elegir fuera de rango → 422 con campo', [422, 'datos.modulos_elegir'],
      (function () use ($ej) { $r = $ej('guardar_plan', ['plan' => 'esencial', 'modulos_elegir' => 99]); return [$r[0], $r[1]['campo'] ?? null]; })());
$ej('guardar_plan', ['plan' => 'esencial', 'modulos_elegir' => 3, 'precio_modulo_extra' => 5]);
check('… y vuelve a 3 a elegir y $5', [3, 5.0], [(new Modulos($db))->planes()['esencial']['modulos_elegir'], (new Modulos($db))->planes()['esencial']['precio_modulo_extra']]);
$fz = array_column($pedir('finanzas')[1]['por_modulo'] ?? [], null, 'modulo');
check('finanzas.por_modulo: un módulo elegido del Básico suma $0; el cuarto, $5', [0.0, 5.0], [$fz['contratos']['mensual_extra'] ?? null, $fz['plano']['mensual_extra'] ?? null]);
// Pedido desde Mi plan: cuánto suma al mes
[$b2, $u2] = negocio_prueba($db, 'esencial', 'Local Que Pide');
$pagado($b2);
$p1 = (new Modulos($db))->pedir($b2, (int) $u2['id'], ['plano']);
$sol = array_column($pedir('solicitudes', ['por_pagina' => 200])[1]['solicitudes'], null, 'id')["cambio_modulos:$p1"] ?? [];
check('Solicitud de un módulo que entra entre los 3 elegidos: suma $0 (sigue en $20)', [0.0, 20.0], [$sol['monto'] ?? null, $sol['detalle']['mensual_nuevo'] ?? null]);
$db->exec("UPDATE negocios SET modulos_extra = 'paquetes,contratos,portal_cliente' WHERE id = $b2");
$p2 = (new Modulos($db))->pedir($b2, (int) $u2['id'], ['invitados']);
$sol = array_column($pedir('solicitudes', ['por_pagina' => 200])[1]['solicitudes'], null, 'id')["cambio_modulos:$p2"] ?? [];
check('Con los 3 ya elegidos: el pedido suma $5', [5.0, 25.0], [$sol['monto'] ?? null, $sol['detalle']['mensual_nuevo'] ?? null]);
$p3 = (new Modulos($db))->pedir($b2, (int) $u2['id'], ['asistente_ia']);
$sol = array_column($pedir('solicitudes', ['por_pagina' => 200])[1]['solicitudes'], null, 'id')["cambio_modulos:$p3"] ?? [];
check('El Asistente IA suma $5 (fuera del cupo)', 5.0, $sol['monto'] ?? null);

// ---------------------------------------------------------------------------------------------
titulo('7. Mi plan: lo que ve el cliente (sin precios de lo que no tiene)');
$v = (string) file_get_contents(RAIZ . '/app/vistas/planes.php');
check('Mi plan dice «Tu plan trae N módulos a tu elección» y cuántos le quedan', [true, true, true],
      [str_contains($v, 'Tu plan trae'), str_contains($v, 'módulos a tu elección'), str_contains($v, 'data-por-elegir')]);
check('Los elegidos dicen «Incluido · lo elegiste», los demás «Extra · $X al mes» y los que puede elegir «Puedes elegirlo en tu plan»', [true, true, true],
      [str_contains($v, "'Incluido · lo elegiste'"), str_contains($v, "'Extra · ' . dinero("), str_contains($v, "'Puedes elegirlo en tu plan'")]);
check('Los otros planes siguen sin precio («precio por cotización»)', [true, false],
      [str_contains($v, "' · precio por cotización'"), (bool) preg_match('/dinero\(\$pl\[.precio_mensual.\]\)/', $v)]);

echo "\n";
