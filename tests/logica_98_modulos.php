<?php
/**
 * Programa modular (PROTOCOLO_V2.md del Central, Anexo B) y "todo se pide demo":
 * catálogo y dependencias, planes = base + módulos (migración 098), precio al mes (plan + extra − quitados o acordado),
 * demo con todos los módulos (o los elegidos), encender/apagar sin borrar datos, cada ruta / PDF / mensaje con su módulo,
 * tareas automáticas y Telegram según los módulos, Mi plan → pedido → aprobar, pedidos de demo, conector v2
 * (modulos, cliente.modulos, cambiar_modulos idempotente y auditado, guardar_modulos, solicitudes, crear_demo, finanzas.por_modulo)
 * y aislamiento entre negocios.
 */
use TuRenta\{Automaticas, Cobranza, Modulos, PanelTukan, PedidosDemo, Planes, Telegram, TukanCentral, TukanCentralV2};

$db = base_limpia();
planes_de_antes($db);   // estas pruebas usan los planes de antes (Esencial $19, Salón $39, Quinta $69); los de $20 y $45: logica_112
$hoy = new DateTimeImmutable('today');
$d = fn(string $m) => $hoy->modify($m)->format('Y-m-d');
$cuenta = fn(string $sql) => (int) $db->query($sql)->fetchColumn();
$mods = new Modulos($db);
$todos = Modulos::todos();   // "*" = todos menos los que son solo a pedido (Asistente IA)
/** Pasa un negocio de demo a pagado (activo un mes). */
$pagado = function (int $nid, string $plan) use ($db, $d): void {
    $db->prepare("UPDATE negocios SET estado = 'activo', plan_codigo = ?, activo_hasta = ? WHERE id = ?")->execute([$plan, $d('+1 month'), $nid]);
};

// ---------------------------------------------------------------------------------------------
titulo('1. Catálogo y dependencias');
check('17 módulos (con el Asistente IA de $5, migración 112), cada uno con nombre, ícono, descripción, precio, dependencias y grupo', 17, count(array_filter(Modulos::CATALOGO,
    fn($m, $id) => count($m) === 6 && $m[0] !== '' && $m[1] !== '' && mb_strlen($m[2]) > 30 && $m[3] > 0
                   && is_array($m[4]) && isset(Modulos::GRUPOS[$m[5]]), ARRAY_FILTER_USE_BOTH)));
check('Asistente IA: solo a pedido, fuera de "*", $5 al mes (migración 112)', [['asistente_ia'], false, 5.0],
      [Modulos::SOLO_A_PEDIDO, in_array('asistente_ia', Modulos::parse('*'), true), Modulos::CATALOGO['asistente_ia'][3]]);
check('ids en minúsculas y _', [], array_values(array_filter($todos, fn($id) => !preg_match('/^[a-z_]+$/', $id))));
check('Toda dependencia existe y no hay ciclos', true, (function () use ($todos) {
    foreach (Modulos::CATALOGO as $id => $m) foreach ($m[4] as $dep) if (!isset(Modulos::CATALOGO[$dep]) || in_array($id, Modulos::conDependencias([$dep]), true)) return false;
    return true;
})());
check('La base no se apaga y tiene texto', true, count(Modulos::BASE) >= 8);
check('Catálogo desde la base: precios de la migración', [3.0, 6.0, 4.0, 8.0], [$mods->catalogo()['paquetes']['precio_mensual'], $mods->catalogo()['contratos']['precio_mensual'],
      $mods->catalogo()['cobros_linea']['precio_mensual'], $mods->catalogo()['invitados']['precio_mensual']]);
check('conDependencias: cobros en línea trae el portal del cliente', ['portal_cliente', 'cobros_linea'], Modulos::conDependencias(['cobros_linea']));
check('conDependencias: boda en vivo trae el día del evento', ['dia_evento', 'boda_vivo'], Modulos::conDependencias(['boda_vivo']));
check('respetarDependencias: sin el portal, los cobros en línea no quedan', ['contratos'], Modulos::respetarDependencias(['contratos', 'cobros_linea']));
check('parse("*") = todos; parse ignora lo que no existe', [$todos, ['plano']], [Modulos::parse('*'), Modulos::parse('plano, nada ,')]);

// ---------------------------------------------------------------------------------------------
titulo('2. Planes = base + módulos (migración 098): cada plan conserva lo que tenía');
$pl = $mods->planes();
check('Tres planes: Esencial, Salón, Quinta', ['esencial', 'salon', 'quinta'], array_keys($pl));
check('Precios base', [19.0, 39.0, 69.0], array_column($pl, 'precio_mensual'));
check('Esencial: solo la base', [], $pl['esencial']['modulos']);
check('Plan más caro: todos ("*") + el Asistente IA incluido (migración 111: "*,asistente_ia")', [true, array_keys(Modulos::CATALOGO), '*,asistente_ia'],
      [$pl['quinta']['todos'], $pl['quinta']['modulos'], $db->query("SELECT modulos FROM planes WHERE codigo = 'quinta'")->fetchColumn()]);
$db->exec(file_get_contents(RAIZ . '/db/migraciones/111_asistente_ia_plan.sql'));
check('La migración 111 se puede correr otra vez (no lo repite)', '*,asistente_ia', $db->query("SELECT modulos FROM planes WHERE codigo = 'quinta'")->fetchColumn());
check('parse("*,asistente_ia") = todos + el Asistente IA; csvPlan lo vuelve a armar', [array_keys(Modulos::CATALOGO), '*,asistente_ia', '*'],
      [Modulos::parse('*,asistente_ia'), Modulos::csvPlan(array_keys(Modulos::CATALOGO)), Modulos::csvPlan($todos)]);
check('Salón = lo que tenía antes (todo menos lo que era solo de Quinta)',
      ['paquetes', 'contratos', 'portal_cliente', 'cobros_linea', 'cuestionarios', 'proveedores', 'incidencias', 'metas', 'telegram', 'exportar'], $pl['salon']['modulos']);
check('Salón NO tiene lo que era de Quinta', [], array_values(array_intersect($pl['salon']['modulos'], ['dia_evento', 'invitados', 'plano', 'mobiliario', 'boda_vivo', 'portal_proveedor'])));
$sql = file_get_contents(RAIZ . '/db/migraciones/098_modulos.sql');
$db->exec($sql);
check('La migración se puede correr otra vez sin cambiar nada (17 filas: la 112 agrega el Asistente IA a $5)', [$pl, 17], [(new Modulos($db))->planes(), $cuenta('SELECT count(*) FROM modulos')]);
check('Los negocios empiezan sin extras ni quitados', 0, $cuenta("SELECT count(*) FROM negocios WHERE modulos_extra <> '' OR modulos_quitados <> ''"));

// ---------------------------------------------------------------------------------------------
titulo('3. Módulos de un negocio y precio al mes');
[$nQ, $uQ] = negocio_prueba($db, 'quinta', 'Quinta Módulos');
[$nS, $uS] = negocio_prueba($db, 'salon', 'Salón Módulos');
[$nE, $uE] = negocio_prueba($db, 'esencial', 'Local Esencial');
check('Demo: todos los módulos encendidos (aunque sea Esencial)', [$todos, true, true], [$mods->activos($nE), $mods->estado($nE)['demo'], $mods->estado($nE)['demo_todos']]);
check('Demo de Esencial: lo que tendrá al pagar = solo la base', [], $mods->estado($nE)['pagados']);
$pagado($nS, 'salon');
$pagado($nQ, 'quinta');
$pagado($nE, 'esencial');
$eS = $mods->estado($nS);
check('Salón pagado: activos = los del plan', $pl['salon']['modulos'], $eS['activos']);
check('Salón: precio = plan', [39.0, 0.0, 0.0, null, 39.0], [$eS['precio_plan'], $eS['precio_extra'], $eS['descuento'], $eS['precio_acordado'], $eS['mensual']]);
check('Esencial pagado: ningún módulo', [], $mods->activos($nE));
check('modulo_activo: base siempre, módulo según el plan', [true, true, false], [$mods->activo($nS, ''), $mods->activo($nS, 'contratos'), $mods->activo($nS, 'invitados')]);
$r = $mods->cambiar($nS, ['invitados', 'boda_vivo'], ['metas']);
check('Encender boda en vivo enciende también el día del evento (y lo dice)', [['dia_evento'], true],
      [$r['automaticos'], str_contains($r['texto'], 'Se encendieron también (los necesita): Día del evento')]);
$eS = $mods->estado($nS);
check('Extra y quitados guardados', [['dia_evento', 'invitados', 'boda_vivo'], ['metas']], [$eS['extra'], $eS['quitados']]);
check('Precio = plan + extra − quitados (39 + 8 + 8 + 5 − 4)', [21.0, 4.0, 56.0], [$eS['precio_extra'], $eS['descuento'], $eS['mensual']]);
check('Activos = (plan − quitados) + extra', true, in_array('invitados', $eS['activos'], true) && !in_array('metas', $eS['activos'], true));
check('Encender lo mismo otra vez: sin cambios', true, $mods->cambiar($nS, ['invitados'], [])['sin_cambios']);
check_error('No se apaga uno que otro encendido necesita', 'No se puede apagar Día del evento: lo necesita Boda en vivo', fn() => $mods->cambiar($nS, [], ['dia_evento']));
check_error('Módulo que no existe', 'Ese módulo no existe', fn() => $mods->cambiar($nS, ['nada'], []));
check_error('Encender y apagar a la vez', 'No se puede encender y apagar a la vez', fn() => $mods->cambiar($nS, ['plano'], ['plano']));
$r = $mods->cambiar($nS, [], ['boda_vivo', 'dia_evento']);
check('Apagar los dos juntos sí se puede', [['dia_evento', 'boda_vivo'], 43.0], [$r['apagados'], $mods->mensual($nS)]);
$mods->cambiar($nS, [], [], 30.0);
check('Precio acordado manda', [30.0, 30.0, 43.0], [$mods->estado($nS)['precio_acordado'], $mods->mensual($nS), $mods->estado($nS)['calculado']]);
$mods->cambiar($nS, [], [], null);
check('Quitar el precio acordado', [null, 43.0], [$mods->estado($nS)['precio_acordado'], $mods->mensual($nS)]);
$mods->cambiar($nE, [], [], false);
check('Descuento nunca deja el precio en negativo', true, (function () use ($mods, $nE, $db) {
    $db->exec("UPDATE planes SET modulos = 'paquetes,contratos,portal_cliente,dia_evento' WHERE codigo = 'esencial'");
    $m = new Modulos($db);
    $m->cambiar($nE, [], ['paquetes', 'contratos', 'portal_cliente', 'dia_evento']);   // 3 + 6 + 6 + 8 = 23 > 19
    $ok = $m->estado($nE)['mensual'] === 0.0 && $m->estado($nE)['descuento'] === 19.0;
    $db->exec("UPDATE planes SET modulos = '' WHERE codigo = 'esencial'; UPDATE negocios SET modulos_quitados = '' WHERE id = $nE");
    return $ok;
})());
check('Aislamiento: cambiar un negocio no toca a otro', $pl['quinta']['modulos'], $mods->activos($nQ));
check('Cotizar con sus módulos: Salón + invitados, semestral (paga 5)', [43.0 * 5, 43.0], (function () use ($db, $nS) {
    $p = new Planes($db);
    $c = $p->cotizarNegocio($nS, 'salon', 'semestral');
    return [$c['monto'], $c['mensual']];
})());
check('Cotizar con otro plan: Quinta (todo) con metas quitado', 65.0, (new Planes($db))->cotizarNegocio($nS, 'quinta', 'mensual')['monto']);
$sid = (new Planes($db))->suscribir($nS, 'salon', 'mensual', $hoy, 'transferencia');
check('Registrar pago sin monto: sugiere lo que paga con sus módulos', 43.0, (float) $db->query("SELECT monto FROM suscripciones WHERE id = $sid")->fetchColumn());
check('Ingreso mensual del panel = lo que paga con módulos', 43.0, (new PanelTukan($db))->ingresoMensual($hoy));

// ---------------------------------------------------------------------------------------------
titulo('4. Demo con los módulos elegidos; apagar no borra datos');
[$nD] = negocio_prueba($db, 'quinta', 'Quinta Demo Elegida');
$mods->elegirDemo($nD, ['boda_vivo']);
check('Demo con módulos elegidos (con sus dependencias)', [['dia_evento', 'boda_vivo'], false], [$mods->activos($nD), $mods->estado($nD)['demo_todos']]);
$mods->elegirDemo($nD, null);
check('Volver a "todos" (en el plan más caro, también el Asistente IA incluido)', array_keys(Modulos::CATALOGO), $mods->activos($nD));
$cli = (int) $db->query("INSERT INTO clientes (negocio_id, nombre, telefono) VALUES ($nS, 'Cliente Módulos', '0991234567') RETURNING id")->fetchColumn();
$ev = (int) $db->query("INSERT INTO eventos (negocio_id, codigo, cliente_id, tipo, nombre, fecha, invitados, estado)
                        VALUES ($nS, 'EV-M-1', $cli, 'boda', 'Boda módulos', CURRENT_DATE + 30, 80, 'consulta') RETURNING id")->fetchColumn();
$db->exec("INSERT INTO invitados (evento_id, nombre, pases) VALUES ($ev, 'Tía Rosa', 2)");
$mods->cambiar($nS, [], ['invitados']);
check('Apagar invitados no borra los invitados', 1, $cuenta("SELECT count(*) FROM invitados WHERE evento_id = $ev"));
$mods->cambiar($nS, ['invitados'], []);
check('Al encenderlo, todo sigue como estaba', [true, 1], [$mods->activo($nS, 'invitados'), $cuenta("SELECT count(*) FROM invitados WHERE evento_id = $ev")]);

// ---------------------------------------------------------------------------------------------
titulo('5. Cada ruta, PDF y mensaje es de la base o de un módulo');
$src = file_get_contents(RAIZ . '/public/index.php');
$ini = strpos($src, '$publicas = [');
$fin = strpos($src, '$ruta = (string)');
$rutas = (function (string $codigo): array { eval($codigo); return array_unique(array_merge($publicas, $privadas, $deAdmin, $deCoordinador)); })(substr($src, $ini, $fin - $ini));
sort($rutas);
check('public/index.php tiene rutas (lectura del archivo)', true, count($rutas) > 80);
check('Toda ruta de public/index.php está en Modulos::RUTAS (módulo o base)', [], array_values(array_diff($rutas, array_keys(Modulos::RUTAS))));
check('Toda ruta de Modulos::RUTAS existe en public/index.php', [], array_values(array_diff(array_keys(Modulos::RUTAS), $rutas)));
$archivos = array_map(fn($f) => basename($f, '.php'), glob(RAIZ . '/app/paginas/*.php'));
check('Cada página de app/paginas está mapeada', [], array_values(array_diff($archivos, array_keys(Modulos::RUTAS))));
check('Cada módulo de RUTAS existe en el catálogo', [], array_values(array_diff(array_filter(array_unique(Modulos::RUTAS)), array_keys(Modulos::CATALOGO))));
$pdfs = array_map(fn($f) => basename($f, '.php'), glob(RAIZ . '/app/pdf/*.php'));
check('Cada PDF de app/pdf está mapeado (PDFS)', [[], []], [array_values(array_diff($pdfs, array_keys(Modulos::PDFS))), array_values(array_diff(array_keys(Modulos::PDFS), $pdfs))]);
check('Cada grupo de Mensajes de hoy está mapeado', array_keys(Cobranza::MENSAJES), array_keys(Modulos::MENSAJES));
check('Ningún archivo usa el viejo candado del plan Quinta', [], array_values(array_filter(array_merge(glob(RAIZ . '/app/*/*.php'), glob(RAIZ . '/src/*.php'), glob(RAIZ . '/public/*.php')),
      fn($f) => preg_match('/requiere_quinta|es_quinta|=== \'quinta\'/', (string) file_get_contents($f)))));
check('Todo módulo tiene al menos una ruta, PDF, sección o tarea que lo usa', [], array_values(array_filter($todos, function ($m) {
    static $codigo = null;
    $codigo ??= implode("\n", array_map('file_get_contents', array_merge(glob(RAIZ . '/app/*/*.php'), glob(RAIZ . '/src/*.php'))));
    return !in_array($m, Modulos::RUTAS, true) && !str_contains($codigo, "'$m'");
})));

// ---------------------------------------------------------------------------------------------
titulo('6. Mensajes de hoy, tareas automáticas y Telegram según los módulos');
$cob = new Cobranza($db, fn($r, $p = []) => 'https://x/?r=' . $r);
check('Salón sin día del evento: no sale "Personal sin confirmar"', [], $cob->mensajesDelDia($nS)['staff']);
$db->exec("INSERT INTO contratos (negocio_id, evento_id, numero, codigo_verificacion, texto, hash_sha256, estado, creado_en)
           VALUES ($nS, $ev, 99, 'VERMOD99', 'x', repeat('a', 64), 'enviado', now() - interval '5 days')");
check('Con contratos: sale el contrato por firmar', 1, count($cob->mensajesDelDia($nS)['contrato']));
$mods->cambiar($nS, [], ['contratos']);
check('Sin contratos: no sale (el contrato sigue guardado)', [[], 1], [$cob->mensajesDelDia($nS)['contrato'], $cuenta("SELECT count(*) FROM contratos WHERE negocio_id = $nS")]);
$mods->cambiar($nS, ['contratos'], []);
// Telegram: con un "servidor" de archivo que contesta {"ok":true}
$tg = sys_get_temp_dir() . '/tg_modulos_' . getmypid();
@mkdir("$tg/botT", 0777, true);
file_put_contents("$tg/botT/sendMessage", '{"ok":true}');
putenv('TELEGRAM_BOT_TOKEN=T'); putenv('TELEGRAM_BOT_USERNAME=bot_prueba'); putenv("TELEGRAM_API_BASE=file://$tg");
$db->exec("UPDATE usuarios SET telegram_chat_id = '123' WHERE id IN ({$uS['id']}, {$uE['id']})");
check('Telegram: con el módulo sí sale', 1, (new Telegram($db))->textoAUsuarios([(int) $uS['id']], 'hola'));
check('Telegram: sin el módulo no sale nada (Esencial)', 0, (new Telegram($db))->textoAUsuarios([(int) $uE['id']], 'hola'));
$mods->cambiar($nS, [], ['telegram']);
check('Telegram: al apagarlo deja de salir', 0, (new Telegram($db))->textoAUsuarios([(int) $uS['id']], 'hola'));
$mods->cambiar($nS, ['telegram'], []);
putenv('TELEGRAM_BOT_TOKEN'); putenv('TELEGRAM_BOT_USERNAME'); putenv('TELEGRAM_API_BASE');
// Tareas: proveedores con pago vencido; sin el módulo no se avisa
$prov = (int) $db->query("INSERT INTO proveedores (negocio_id, nombre, servicio) VALUES ($nE, 'DJ Esencial', 'musica') RETURNING id")->fetchColumn();
$db->exec("INSERT INTO proveedor_documentos (negocio_id, proveedor_id, tipo, nombre, vence) VALUES ($nE, $prov, 'ruc', 'RUC', CURRENT_DATE + 3)");
$ahora = $hoy->setTime(9, 0);
(new Automaticas($db))->correr($ahora);
check('Tareas: sin el módulo de proveedores no avisa sus documentos', [0, null], [$cuenta("SELECT count(*) FROM notificaciones WHERE negocio_id = $nE AND texto LIKE '%Documentos de proveedores%'"),
      $db->query("SELECT aviso_vence FROM proveedor_documentos WHERE proveedor_id = $prov")->fetchColumn()]);
$mods->cambiar($nE, ['proveedores'], []);
(new Automaticas($db))->correr($ahora->modify('+1 day'));
check('Tareas: con el módulo sí avisa', 1, $cuenta("SELECT count(*) FROM notificaciones WHERE negocio_id = $nE AND texto LIKE '%Documentos de proveedores%'"));

// ---------------------------------------------------------------------------------------------
titulo('7. Mi plan: pedir un módulo → solicitud cambio_modulos → aprobar');
$pid = $mods->pedir($nS, (int) $uS['id'], ['mobiliario'], 'Para las sillas');
check('Pedido guardado como pendiente', ['pendiente', 'mobiliario', 'Para las sillas'], array_values($db->query("SELECT estado, encender, nota FROM modulos_solicitudes WHERE id = $pid")->fetch()));
check('Pedir lo mismo otra vez: el mismo pedido (no se repite)', $pid, $mods->pedir($nS, (int) $uS['id'], ['mobiliario']));
check_error('Pedir uno que ya tiene', 'ya está en tu plan', fn() => $mods->pedir($nS, (int) $uS['id'], ['contratos']));
check_error('Pedir uno que no existe', 'no existe', fn() => $mods->pedir($nS, (int) $uS['id'], ['nada']));
check('Pedir no enciende nada todavía', false, $mods->activo($nS, 'mobiliario'));

// ---------------------------------------------------------------------------------------------
titulo('8. Pedidos de demo (no hay registro abierto con periodo de prueba)');
$pd = new PedidosDemo($db);
check_error('Pedido sin WhatsApp', 'WhatsApp', fn() => $pd->crear(['nombre' => 'Ana', 'negocio' => 'Quinta Ana', 'whatsapp' => '12', 'email' => 'ana@x.ec']));
check_error('Pedido sin correo válido', 'correo', fn() => $pd->crear(['nombre' => 'Ana', 'negocio' => 'Quinta Ana', 'whatsapp' => '0991112222', 'email' => 'malo']));
$antesNeg = $cuenta('SELECT count(*) FROM negocios');
$pdA = $pd->crear(['nombre' => 'Ana Ruiz', 'negocio' => 'Quinta Ana', 'ciudad' => 'Loja', 'whatsapp' => '0991112222', 'email' => 'ANA@x.ec',
                   'necesita' => 'Bodas', 'modulos' => ['invitados', 'nada']], '203.0.113.5');
check('Pedido guardado: correo en minúsculas, solo módulos que existen', ['nueva', 'ana@x.ec', 'invitados'], array_values($db->query("SELECT estado, email, modulos FROM pedidos_demo WHERE id = $pdA")->fetch()));
check('Pedir una demo NO crea una cuenta', $antesNeg, $cuenta('SELECT count(*) FROM negocios'));
check('Mismo correo con pedido abierto: se actualiza (no se repite)', $pdA, $pd->crear(['nombre' => 'Ana Ruiz', 'negocio' => 'Quinta Ana 2', 'whatsapp' => '0991112222', 'email' => 'ana@x.ec'], '203.0.113.5'));
for ($i = 0; $i < 5; $i++) $pd->crear(['nombre' => 'Robot', 'negocio' => 'Robot ' . $i, 'whatsapp' => '0991112222', 'email' => "r$i@x.ec"], '203.0.113.9');
check_error('Límite por IP: 5 pedidos por hora', 'varios pedidos', fn() => $pd->crear(['nombre' => 'Robot', 'negocio' => 'Robot 9', 'whatsapp' => '0991112222', 'email' => 'r9@x.ec'], '203.0.113.9'));
$db->exec("UPDATE pedidos_demo SET estado = 'descartada' WHERE nombre = 'Robot'");

// ---------------------------------------------------------------------------------------------
titulo('9. Conector v2 (Anexo B): modulos, cliente.modulos, cambiar_modulos, guardar_modulos, solicitudes, crear_demo, finanzas');
$SECRETO = str_repeat('cd34', 16);
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
$ej = function (string $op, array $datos, ?int $cliente = null, ?string $opId = null, string $rol = 'dueno') use ($pedir): array {
    $c = ['operacion_id' => $opId ?? bin2hex(random_bytes(16)), 'accion' => $op, 'datos' => $datos === [] ? new stdClass() : $datos];
    if ($cliente !== null) $c['cliente_id'] = (string) $cliente;
    return $pedir('ejecutar', $c, $rol);
};
$cap = $pedir('capacidades')[1];
check('capacidades: acción modulos, operaciones nuevas, tipos nuevos, revisión 3', [true, true, true, true, true, 3, false],
      [in_array('modulos', $cap['acciones'], true), in_array('cambiar_modulos', $cap['ejecutar'], true), in_array('guardar_modulos', $cap['ejecutar'], true),
       in_array('cambio_modulos', $cap['tipos_solicitud'], true), in_array('demo_pedida', $cap['tipos_solicitud'], true), $cap['revision'], $cap['registro_abierto']]);
$m = $pedir('modulos')[1];
check('modulos: base, 17 módulos con sus campos y 3 planes', [true, 17, ['id', 'nombre', 'icono', 'descripcion', 'precio_mensual', 'depende_de', 'grupo', 'grupo_nombre', 'orden'], 3],
      [count($m['base']) > 5, count($m['modulos']), array_keys($m['modulos'][0]), count($m['planes'])]);
check('modulos: el plan Salón trae sus 10 módulos', 10, count(array_column($m['planes'], null, 'codigo')['salon']['modulos']));
$c = $pedir('cliente', ['id' => (string) $nS])[1]['cliente'];
check('cliente.modulos con la forma de B.2 (+ módulos a elegir de la migración 112)', ['del_plan', 'extra', 'quitados', 'activos', 'pagados', 'precio_plan', 'precio_extra', 'descuento', 'precio_acordado', 'mensual', 'demo', 'demo_todos',
                                                     'modulos_elegir', 'elegidos', 'por_elegir', 'precio_por_extra'],
      array_keys($c['modulos']));
check('cliente: mensual_estimado = lo que paga con sus módulos (39 + invitados 8 − metas 4)', [43.0, 43.0], [$c['modulos']['mensual'], $c['mensual_estimado']]);
check('cliente: botón "Módulos" (cambiar_modulos)', true, in_array('cambiar_modulos', array_column($c['acciones_posibles'], 'accion'), true));
check('clientes: la lista también trae modulos y mensual_estimado', 43.0,
      array_column($pedir('clientes', ['por_pagina' => 200])[1]['clientes'], null, 'id')[(string) $nS]['mensual_estimado']);
check('cotizar: con sus módulos', 43.0, $pedir('cotizar', ['cliente_id' => (string) $nS, 'periodo' => 'mensual'])[1]['monto']);
// solicitudes
$sols = array_column($pedir('solicitudes', ['por_pagina' => 200])[1]['solicitudes'], null, 'id');
$sm = $sols["cambio_modulos:$pid"] ?? null;
check('Solicitud cambio_modulos del pedido de Mi plan', ['pedido', 'pendiente', (string) $nS, ['mobiliario'], 5.0],
      [$sm['categoria'] ?? null, $sm['estado'] ?? null, $sm['cliente_id'] ?? null, $sm['detalle']['encender'] ?? null, $sm['monto'] ?? null]);
check('… con Aprobar (cambiar_modulos) y Rechazar', ['cambiar_modulos', 'atender_solicitud'], array_slice(array_column($sm['acciones_posibles'], 'accion'), 0, 2));
$sd = $sols["demo_pedida:$pdA"] ?? null;
check('Solicitud demo_pedida del pedido de demo', ['pedido', 'pendiente', null, 'Quinta Ana 2', ['invitados']],
      [$sd['categoria'] ?? null, $sd['estado'] ?? null, array_key_exists('cliente_id', (array) $sd) ? $sd['cliente_id'] : 'x', $sd['quien']['organizacion'] ?? null, $sd['detalle']['modulos'] ?? null]);
check('… con Crear demo prellenado', ['crear_demo', 'ana@x.ec'], (function () use ($sd) {
    $a = array_values(array_filter($sd['acciones_posibles'], fn($x) => $x['accion'] === 'crear_demo'))[0];
    return [$a['accion'], array_column($a['campos'], 'valor', 'campo')['email']];
})());
// aprobar el pedido de módulos desde el Central
$a = $sm['acciones_posibles'][0];
$opA = bin2hex(random_bytes(16));
$r = $ej('cambiar_modulos', $a['datos'], (int) $nS, $opA);
check('Aprobar: enciende, responde modulos arriba y en resultado', [200, true, true, 48.0, 48.0],
      [$r[0], $r[1]['ok'], in_array('mobiliario', $r[1]['modulos']['activos'], true), $r[1]['resultado']['modulos']['mensual'], $r[1]['cliente']['modulos']['mensual']]);
check('… el pedido queda aprobado y la solicitud atendida', ['aprobada', 'atendida'], [$db->query("SELECT estado FROM modulos_solicitudes WHERE id = $pid")->fetchColumn(), $r[1]['solicitud']['estado']]);
$r2 = $ej('cambiar_modulos', $a['datos'], (int) $nS, $opA);
check('Repetida (mismo operacion_id): misma respuesta, no hace nada otra vez', [200, true, 48.0], [$r2[0], $r2[1]['repetida'], $r2[1]['modulos']['mensual']]);
check('Auditado en registro_admin', 1, $cuenta("SELECT count(*) FROM registro_admin WHERE negocio_id = $nS AND accion LIKE 'Panel Tukán Central · Módulos: Encendidos: Mobiliario%'"));
$r = $ej('cambiar_modulos', ['encender' => ['mobiliario']], (int) $nS);
check('Encender lo que ya tiene: sin_cambios', [200, true], [$r[0], $r[1]['sin_cambios']]);
$r = $ej('cambiar_modulos', ['encender' => 'cobros_linea', 'apagar' => 'portal_cliente'], (int) $nS);
check('Dependencias: no se apaga el portal si los cobros en línea lo necesitan → 422', [422, 'invalido'], [$r[0], $r[1]['codigo']]);
$r = $ej('cambiar_modulos', ['encender' => ['portal_proveedor'], 'precio_acordado' => 50], (int) $nS);
check('Encender con su dependencia (proveedores ya está) y precio acordado', [200, 50.0, true], [$r[0], $r[1]['modulos']['mensual'], in_array('portal_proveedor', $r[1]['modulos']['activos'], true)]);
$r = $ej('cambiar_modulos', ['precio_acordado' => null], (int) $nS);
check('Quitar el precio acordado', [200, null, 52.0], [$r[0], $r[1]['modulos']['precio_acordado'], $r[1]['modulos']['mensual']]);
check('Módulo que no existe → 422 con campo', [422, 'datos.encender'], (function () use ($ej, $nS) { $r = $ej('cambiar_modulos', ['encender' => ['oro']], (int) $nS); return [$r[0], $r[1]['campo']]; })());
check('Sin nada que cambiar → 422', 422, $ej('cambiar_modulos', [], (int) $nS)[0]);
check('Rol soporte no cambia módulos → 403', [403, 'no_permitido'], (function () use ($ej, $nS) { $r = $ej('cambiar_modulos', ['encender' => ['plano']], (int) $nS, null, 'soporte'); return [$r[0], $r[1]['codigo']]; })());
check('Otro negocio no cambió (aislamiento)', $pl['quinta']['modulos'], (new Modulos($db))->activos($nQ));
// rechazar un pedido
$pid2 = (new Modulos($db))->pedir($nS, (int) $uS['id'], ['plano']);
$r = $ej('atender_solicitud', ['solicitud_id' => "cambio_modulos:$pid2", 'resultado' => 'descartada', 'nota' => 'Más adelante']);
check('Rechazar el pedido: no enciende y queda la respuesta para Mi plan', [200, 'rechazada', 'Más adelante', false],
      [$r[0], $db->query("SELECT estado FROM modulos_solicitudes WHERE id = $pid2")->fetchColumn(), $db->query("SELECT respuesta FROM modulos_solicitudes WHERE id = $pid2")->fetchColumn(),
       (new Modulos($db))->activo($nS, 'plano')]);
check('Rechazar otra vez algo ya rechazado: sin cambios; aprobarlo → 409', [true, 409], [$ej('atender_solicitud', ['solicitud_id' => "cambio_modulos:$pid2", 'resultado' => 'descartada'])[1]['sin_cambios'],
      $ej('atender_solicitud', ['solicitud_id' => "cambio_modulos:$pid2", 'resultado' => 'atendida'])[0]]);
// guardar_modulos
$r = $ej('guardar_modulos', ['modulos' => [['id' => 'plano', 'precio_mensual' => 7.5, 'descripcion' => 'Plano nuevo']], 'planes' => ['esencial' => ['paquetes']]]);
check('guardar_modulos: precio, descripción y módulos del plan', [200, 7.5, 'Plano nuevo', ['paquetes']],
      [$r[0], (new Modulos($db))->catalogo()['plano']['precio_mensual'], (new Modulos($db))->catalogo()['plano']['descripcion'], (new Modulos($db))->planes()['esencial']['modulos']]);
check('guardar_modulos: el plan con dependencias completas', ['portal_cliente', 'cobros_linea'], (function () use ($ej, $db) {
    $ej('guardar_modulos', ['planes' => ['esencial' => ['cobros_linea']]]);
    return (new Modulos($db))->planes()['esencial']['modulos'];
})());
$ej('guardar_modulos', ['planes' => ['esencial' => []], 'modulos' => ['plano' => ['precio_mensual' => 6, 'descripcion' => '']]]);
check('guardar_modulos: descripción vacía vuelve a la de código', Modulos::CATALOGO['plano'][2], (new Modulos($db))->catalogo()['plano']['descripcion']);
check('guardar_modulos: precio negativo → 422', 422, $ej('guardar_modulos', ['modulos' => ['plano' => ['precio_mensual' => -1]]])[0]);
check('guardar_modulos: soporte → 403', 403, $ej('guardar_modulos', ['modulos' => ['plano' => ['precio_mensual' => 5]]], null, null, 'soporte')[0]);
// crear_demo con plan + módulos desde un pedido de demo
$r = $ej('crear_demo', ['nombre' => 'Quinta Ana', 'contacto_nombre' => 'Ana Ruiz', 'email' => 'ana@x.ec', 'telefono' => '0991112222', 'plan' => 'salon',
                        'solicitud_id' => "demo_pedida:$pdA", 'modulos' => 'invitados,boda_vivo', 'extra' => ['ciudad' => 'Loja']]);
$nAna = (int) ($r[1]['resultado']['cliente_id'] ?? 0);
check('crear_demo desde el pedido: demo con plan y módulos elegidos', [200, 'salon', 'demo_pedida', ['dia_evento', 'invitados', 'boda_vivo']],
      [$r[0], $db->query("SELECT plan_codigo FROM negocios WHERE id = $nAna")->fetchColumn(), $db->query("SELECT origen FROM negocios WHERE id = $nAna")->fetchColumn(),
       (new Modulos($db))->activos($nAna)]);
check('… el pedido queda "demo creada" y unido al negocio', ['demo_creada', $nAna, 'atendida'],
      [$db->query("SELECT estado FROM pedidos_demo WHERE id = $pdA")->fetchColumn(), (int) $db->query("SELECT negocio_id FROM pedidos_demo WHERE id = $pdA")->fetchColumn(), $r[1]['solicitud']['estado']]);
check('… otra demo con el mismo pedido → 409', 409, $ej('crear_demo', ['nombre' => 'Quinta Ana', 'contacto_nombre' => 'Ana', 'email' => 'ana2@x.ec', 'telefono' => '0991112222',
      'solicitud_id' => "demo_pedida:$pdA"])[0]);
$r = $ej('crear_demo', ['nombre' => 'Demo Completa', 'contacto_nombre' => 'Pepe', 'email' => 'pepe@x.ec', 'telefono' => '0991113333']);
check('crear_demo sin módulos: la demo tiene todos (plan más caro: con el Asistente IA)', [200, 'todos', array_keys(Modulos::CATALOGO)], [$r[0], $r[1]['resultado']['modulos'], (new Modulos($db))->activos((int) $r[1]['resultado']['cliente_id'])]);
check('crear_demo con un módulo que no existe → 422', 422, $ej('crear_demo', ['nombre' => 'Demo Mala', 'contacto_nombre' => 'Pepe', 'email' => 'pepe2@x.ec', 'telefono' => '0991113333', 'modulos' => ['oro']])[0]);
// finanzas por módulo
$fz = $pedir('finanzas')[1];
$pm = array_column($fz['por_modulo'], null, 'modulo');
check('finanzas.por_modulo: clientes que lo tienen como extra y cuánto suma al mes', [1, 8.0, true],
      [$pm['invitados']['clientes_extra'], $pm['invitados']['mensual_extra'], $pm['contratos']['clientes_activos'] >= 2]);
check('MRR de finanzas = suma de mensual_estimado de los que pagan', $fz['mrr'], array_sum(array_column($pedir('clientes', ['por_pagina' => 200])[1]['clientes'], 'mensual_estimado')));

titulo('Regla del dueño: textos visibles sin «gratis» y sin precios del software para lo que el cliente no tiene');
// Se revisa el texto que ve la gente (sin los comentarios del código): vistas, páginas, clases y JavaScript.
$conGratis = [];
$archivos = array_merge(glob(RAIZ . '/app/vistas/*.php') ?: [], glob(RAIZ . '/app/paginas/*.php') ?: [], glob(RAIZ . '/src/*.php') ?: [], [RAIZ . '/app/bootstrap.php']);
foreach ($archivos as $a) {
    $txt = '';
    foreach (token_get_all((string) file_get_contents($a)) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
        $txt .= is_array($t) ? $t[1] : $t;
    }
    // dominio_gratis y meses_gratis son nombres internos del protocolo con el Panel Tukán Central (no se muestran)
    $txt = str_replace(['dominio_gratis', 'meses_gratis'], '', $txt);
    if (preg_match('/gratis|prueba gratuita|free trial|\bfree\b/iu', $txt)) $conGratis[] = basename($a);
}
foreach (glob(RAIZ . '/public/assets/*.js') ?: [] as $a) {
    if (preg_match('/gratis|\bfree\b/iu', (string) file_get_contents($a))) $conGratis[] = basename($a);
}
check('Ningún texto visible dice «gratis» o «free»', [], $conGratis);
$vMiPlan = (string) file_get_contents(RAIZ . '/app/vistas/planes.php');
$vApagado = (string) file_get_contents(RAIZ . '/app/vistas/modulo_apagado.php');
check('Mi plan: un módulo que no tiene dice «Precio por cotización» (Asistente IA: «Pídalo y le cotizamos») y «Pedir cotización»', [true, true, true],
      [str_contains($vMiPlan, "'Precio por cotización'"), str_contains($vMiPlan, "'Pídalo y le cotizamos'"), str_contains($vMiPlan, 'Pedir cotización')]);
check('Mi plan: los otros planes no muestran su precio', false, (bool) preg_match('/dinero\(\$pl\[.precio_mensual.\]\)/', $vMiPlan));
check('Módulo apagado: sin precio, con «Pedir cotización»', [false, false, true],
      [str_contains($vApagado, 'precio_mensual'), str_contains($vApagado, 'Agrégalo por'), str_contains($vApagado, 'Pedir cotización')]);
