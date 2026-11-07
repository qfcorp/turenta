<?php
/**
 * Ayuda e inteligencia artificial (migración 110, compartido/LEEME_IA.md y LEEME_CORREO.md del Panel Tukán Central):
 * migración que se puede repetir, topes por negocio (3,00 con «Asistente IA», 0,50 sin él, o el que puso Tukán),
 * Ayuda con FAQ / IA / manual (pregunta nueva, la misma, una parecida, pausa, tope silencioso, 30 nuevas al mes sin el módulo,
 * sin /etc/tukan-ia.env), cotización a partir de un mensaje (JSON validado contra el catálogo, módulo apagado, tope),
 * conector v2 (ia_uso e ia_tope), FAQ del súper usuario y el correo de la demo (sello cifrado + SMTP de mentira).
 * La API de Anthropic y el SMTP son DE MENTIRA: tests/anthropic_falso.py (8261) y tests/smtp_falso.py (8262).
 * Otros puertos (si chocan con otras pruebas): IA_PUERTO_LOGICA=8343 SMTP_PUERTO_LOGICA=8344 php tests/run_tests.php
 */
use TuRenta\{CorreoDemo, Eventos, Ia, Modulos, Papelera, TukanCentralV2};

$db = base_limpia();
planes_de_antes($db);   // estas pruebas usan los planes de antes (Esencial $19, Salón $39, Quinta $69); los de $20 y $45: logica_112
$hoy = new DateTimeImmutable('today');
$cuenta = fn(string $sql) => (int) $db->query($sql)->fetchColumn();
$itmp = sys_get_temp_dir() . '/turenta_ia_' . getmypid();
@mkdir($itmp, 0755, true);
$CLAVE = 'sk-ant-prueba-buena-0123456789';
$IA_P = (string) (int) (getenv('IA_PUERTO_LOGICA') ?: 8261);
$SMTP_P = (string) (int) (getenv('SMTP_PUERTO_LOGICA') ?: 8262);
$logApi = "$itmp/api.jsonl";
@unlink($logApi);
$procs = [];
register_shutdown_function(function () use (&$procs): void { foreach ($procs as $p) { @proc_terminate($p); @proc_close($p); } });
$procs[] = proc_open(['python3', __DIR__ . '/anthropic_falso.py', $IA_P, $logApi], [1 => ['file', '/dev/null', 'w'], 2 => ['file', "$itmp/py.err", 'w']], $tub);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', (int) $IA_P); $i++) usleep(100000);
$pedidos = fn() => is_file($logApi) ? array_map(fn($l) => json_decode($l, true), file($logApi, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : [];
$nPedidos = fn() => count($pedidos());
$envIa = function (array $extra = []) use ($itmp, $CLAVE, $IA_P): void {
    $v = ['TUKAN_IA_CLAVE' => $CLAVE, 'TUKAN_IA_URL' => 'http://127.0.0.1:' . $IA_P, 'TUKAN_IA_ESPERA_MS' => '0', 'TUKAN_IA_MODELO_RAPIDO' => 'claude-haiku-5-5'] + $extra;
    $f = "$itmp/ia.env";
    file_put_contents($f, implode("\n", array_map(fn($k) => "$k=" . $v[$k], array_keys($v))) . "\n");
    putenv("TUKAN_IA_ENV=$f");
    tukan_ia_config(true);
};
$sinIa = function () use ($itmp): void { putenv("TUKAN_IA_ENV=$itmp/no_existe.env"); tukan_ia_config(true); };
// Lo que la Ayuda agrega alrededor de lo que copia del manual (primera y última línea): ahí nunca se explica por qué no usó la IA
$envoltura = fn(string $t) => strtok($t, "\n") . "\n" . substr($t, (int) strrpos($t, "\n") + 1);
$prohibidas = fn(string $t) => (bool) preg_match('/l[ií]mite|cupo|saldo|cr[eé]dito|se acab[oó]|pas[oó] el tope|IA apagada/iu', $t);
$errLog = ini_get('error_log');
ini_set('error_log', "$itmp/php.log");   // los avisos de la librería (sin la clave) no ensucian la salida

// ---------------------------------------------------------------------------------------------
titulo('1. Migración 110 y topes por negocio');
$db->exec(file_get_contents(RAIZ . '/db/migraciones/110_ia.sql'));
check('La migración se puede correr otra vez (tablas e índice)', [1, 1, 1],
      [$cuenta("SELECT count(*) FROM information_schema.tables WHERE table_name = 'tukan_ia_faq'"), $cuenta("SELECT count(*) FROM information_schema.tables WHERE table_name = 'tukan_ia_gasto'"),
       $cuenta("SELECT count(*) FROM pg_indexes WHERE indexname = 'tukan_ia_faq_norm'")]);
check('negocios.ia_tope_usd con CHECK (0 a 1000)', 1, $cuenta("SELECT count(*) FROM pg_constraint WHERE conname = 'negocios_ia_tope_usd_check'"));
check('tukan_ia_gasto no es «del negocio» para la papelera (negocio_id de texto)', false, isset((new Papelera($db))->tablas()['tukan_ia_gasto']));
[$nA, $uA] = negocio_prueba($db, 'salon', 'Quinta Ayuda');
[$nB, $uB] = negocio_prueba($db, 'salon', 'Hacienda Asistente');
[$nQ] = negocio_prueba($db, 'quinta', 'Quinta Plan Completo');
$db->exec("UPDATE negocios SET estado = 'activo', activo_hasta = CURRENT_DATE + 30 WHERE id IN ($nA, $nB, $nQ)");
$mods = new Modulos($db);
$ia = new Ia($db, $mods);
check('Los otros planes NO traen el Asistente IA (extra de $5)', false, $ia->tieneModulo($nA));
check('El plan más caro lo trae INCLUIDO (del plan, no extra) con tope de 1,50', [true, true, [], 1.5],
      [$ia->tieneModulo($nQ), in_array('asistente_ia', $mods->estado($nQ)['del_plan'], true), $mods->estado($nQ)['extra'], $ia->tope($nQ)]);
check('Demo con todos los módulos de un plan que no lo trae: sin el Asistente IA', false, in_array('asistente_ia', $mods->estado((int) negocio_prueba($db, 'salon', 'Demo IA')[0])['activos'], true));
$mods->cambiar($nB, ['asistente_ia'], []);
check('Encendido como extra: lo tiene y suma $5 al mes (migración 112: ya no es por cotización)', [true, 5.0], [$ia->tieneModulo($nB), $mods->estado($nB)['precio_extra']]);
check('Tope sin el módulo = 0,50 · con el módulo comprado aparte = 1,50', [0.5, 1.5], [$ia->tope($nA), $ia->tope($nB)]);
$ia->cambiarTope($nA, 1.25);
check('Tope propio (lo pone Tukán) gana', [1.25, 1.25], [$ia->topePropio($nA), $ia->tope($nA)]);
$ia->cambiarTope($nA, null);
check('null = vuelve al de siempre', [null, 0.5], [$ia->topePropio($nA), $ia->tope($nA)]);
check_error('Tope fuera de rango', 'entre 0 y 1000', fn() => $ia->cambiarTope($nA, 5000));
// Demos: 0,50 al mes en total aunque tengan todos los módulos (decisión del dueño, 21 oct 2026)
[$nD] = negocio_prueba($db, 'quinta', 'Quinta En Demo');
$db->exec("UPDATE negocios SET estado = 'prueba', prueba_hasta = CURRENT_DATE + 7 WHERE id = $nD");
check('Demo con el módulo Asistente IA: tope 0,50 (TOPE_DEMO)', [true, true, 0.5, 0.5], [$ia->esDemo($nD), $ia->tieneModulo($nD), $ia->tope($nD), Ia::TOPE_DEMO]);
$ia->cambiarTope($nD, 2.5);
check('Demo con tope propio de Tukán: manda el propio', 2.5, $ia->tope($nD));
$ia->cambiarTope($nD, null);
(new \TuRenta\Planes($db))->suscribir($nD, 'quinta', 'mensual', $hoy, 'transferencia');
check('La demo pasa a cliente pagado con el módulo: vuelve sola a 1,50', [false, 1.5], [$ia->esDemo($nD), $ia->tope($nD)]);
$db->exec("UPDATE negocios SET estado = 'cancelado' WHERE id = $nD");   // fuera del resto de esta prueba

// ---------------------------------------------------------------------------------------------
titulo('2. Ayuda: sin /etc/tukan-ia.env solo FAQ y manual');
$sinIa();
$manual = Ia::manual();
check('El manual del cliente se lee (HTML convertido, con títulos) y queda en caché', [true, true, true],
      [mb_strlen($manual) > 5000, str_contains($manual, '# '), (bool) glob(sys_get_temp_dir() . '/turenta_manual_*.txt')]);
$a = $ia->ayuda($nA, '¿Cómo registro un pago?');
check('Sin IA: responde con el manual (sin llamar)', ['manual', 0], [$a['fuente'], $nPedidos()]);
check('…sin decir por qué (ni «límite», «cupo», «saldo» ni que la IA está apagada)', [false, 'Esto dice el manual de TuRenta:'],
      [(bool) preg_match('/l[ií]mite|cupo|saldo|cr[eé]dito|apagad|inteligencia artificial/iu', $envoltura($a['respuesta'])), strtok($a['respuesta'], "\n")]);
check('…ni cuenta como respuesta nueva; sí como ahorrada', [0, 1], [$ia->gastoMes($nA)['ayudas_nuevas'], $ia->gastoMes($nA)['ahorradas']]);
$vacia = $ia->ayuda($nA, '¿?');
check('Pregunta vacía: pide más detalle', 'vacia', $vacia['fuente']);

// ---------------------------------------------------------------------------------------------
titulo('3. Ayuda con la IA de mentira: nueva, la misma, parecida, pausa, tope y 30 nuevas');
$envIa();
$a = $ia->ayuda($nA, '¿Cómo envío la cotización al cliente por WhatsApp?');
check('Pregunta nueva: la responde la IA (1 llamada, modelo rápido)', ['ia', 1, 'claude-haiku-5-5'], [$a['fuente'], $nPedidos(), $pedidos()[0]['modelo'] ?? null]);
check('…con el manual dentro (secciones) y la pregunta como dato', [true, true],
      [str_contains($pedidos()[0]['texto'], '<manual>'), str_contains($pedidos()[0]['texto'], '<pregunta>')]);
$faqs = $ia->faq()->listar('sin_aprobar');
check('…queda guardada como FAQ automática sin aprobar', [1, 'automatica', 0], [count($faqs), $faqs[0]['origen'] ?? null, $faqs[0]['aprobada'] ?? null]);
$g = $ia->gastoMes($nA);
check('…y en el gasto del negocio: 1 llamada, 1 ayuda nueva, costo > 0', [1, 1, true], [$g['llamadas'], $g['ayudas_nuevas'], $g['costo'] > 0]);
$a2 = $ia->ayuda($nA, 'como envio la cotizacion al cliente por whatsapp');
check('La misma pregunta (sin tildes ni signos): sale de la FAQ, sin llamar', ['faq', 1], [$a2['fuente'], $nPedidos()]);
$a3 = $ia->ayuda($nB, '¿Cómo mando la cotización al cliente por WhatsApp?');
check('Una parecida desde OTRO negocio: también de la FAQ (sirve para todos)', ['faq', 1], [$a3['fuente'], $nPedidos()]);
check('Usos de la FAQ = 3', 3, (int) $ia->faq()->obtener($faqs[0]['id'])['usos']);
$envIa(['TUKAN_IA_PAUSA' => '1']);
$a4 = $ia->ayuda($nA, '¿Cómo cambio el logo de mi negocio?');
check('Pausa (TUKAN_IA_PAUSA=1): responde con el manual, sin llamar', ['manual', 1], [$a4['fuente'], $nPedidos()]);
$sinIa();
$igual = $ia->ayuda($nA, '¿Cómo cambio el logo de mi negocio?')['respuesta'];
$envIa(['TUKAN_IA_PAUSA' => '1']);
check('…igual que sin IA y sin decir por qué', [true, false, false], [$a4['respuesta'] === $igual, $prohibidas($envoltura($a4['respuesta'])), str_contains($envoltura($a4['respuesta']), 'pausa')]);
$envIa();
$db->prepare("INSERT INTO tukan_ia_gasto (negocio_id, mes, costo_usd) VALUES (?, ?, 0.50)
              ON CONFLICT (negocio_id, mes) DO UPDATE SET costo_usd = 0.50")->execute([(string) $nA, date('Y-m')]);
$a5 = $ia->ayuda($nA, '¿Cómo cambio el logo de mi negocio?');
check('Negocio en su tope (0,50 sin el módulo): tope SILENCIOSO, responde con el manual sin llamar', ['manual', 1], [$a5['fuente'], $nPedidos()]);
check('…igual que sin IA: ninguna parte agregada dice «límite», «cupo» ni «saldo»', [true, false], [$a5['respuesta'] === $igual, $prohibidas($envoltura($a5['respuesta']))]);
$db->exec("UPDATE tukan_ia_gasto SET costo_usd = 0, ayudas_nuevas = 30 WHERE negocio_id = '$nA'");
$a6 = $ia->ayuda($nA, '¿Cómo agrego un usuario vendedor?');
check('Sin el módulo y con 30 respuestas nuevas este mes: manual, sin llamar', ['manual', 1], [$a6['fuente'], $nPedidos()]);
$db->exec("INSERT INTO tukan_ia_gasto (negocio_id, mes, ayudas_nuevas) VALUES ('$nB', '" . date('Y-m') . "', 30)
           ON CONFLICT (negocio_id, mes) DO UPDATE SET ayudas_nuevas = 30");
$a7 = $ia->ayuda($nB, '¿Cómo agrego un usuario vendedor?');
check('Con el módulo no hay tope de cantidad (vale el tope en USD): IA', ['ia', 2], [$a7['fuente'], $nPedidos()]);
check('Ritmo: 10 preguntas por minuto por usuario', [true, false], (function () {
    $m = [];
    for ($i = 0; $i < 9; $i++) Ia::dentroDelRitmo($m, 1000);
    return [Ia::dentroDelRitmo($m, 1000), Ia::dentroDelRitmo($m, 1001)];
})());
check('Respuesta en HTML: escapa y deja **negritas**', "Use <strong>Cobros</strong> &lt;b&gt;<br>\n2.", Ia::html("Use **Cobros** <b>\n2."));

// ---------------------------------------------------------------------------------------------
titulo('4. Cotización a partir de un mensaje (Asistente IA)');
$cat = $ia->catalogo($nB);
$esp = array_key_first($cat['espacios']);
$prods = array_keys($cat['productos']);
$paq = array_key_first($cat['paquetes']);
$anio = (int) $hoy->format('Y') + 1;
$json = json_encode(['cliente' => ['nombre' => 'Ana Pérez', 'telefono' => '0991234567', 'email' => 'ana@correo.ec'],
    'evento' => ['tipo' => 'boda', 'fecha' => "$anio-02-14", 'hora_inicio' => '19:00', 'hora_fin' => '03:00', 'invitados' => 150, 'espacio_ids' => [$esp, 999999], 'notas' => 'Quiere pirotecnia'],
    'paquete_id' => $paq, 'lineas' => [['producto_id' => $prods[0], 'cantidad' => 2], ['producto_id' => 999999, 'cantidad' => 1], ['producto_id' => $prods[1]]],
    'dudas' => ['¿A qué hora es la ceremonia?']], JSON_UNESCAPED_UNICODE);
$antes = $nPedidos();
$r = $ia->proponerCotizacion($nB, "Hola, boda de 150 personas el 14 de febrero con DJ. RESPONDER:$json", '/?r=evento_nuevo', $hoy);
check('Con el módulo: propone (1 llamada, JSON validado)', [true, $antes + 1], [$r['ok'], $nPedidos()]);
$p = $r['propuesta'] ?? [];
check('Cliente y evento leídos', ['Ana Pérez', 'boda', "$anio-02-14", 150, '19:00'],
      [$p['cliente']['nombre'] ?? null, $p['evento']['tipo'] ?? null, $p['evento']['fecha'] ?? null, $p['evento']['invitados'] ?? null, $p['evento']['hora_inicio'] ?? null]);
check('Solo ids del catálogo del negocio (se quitan los inventados)', [[$esp], $paq, [$prods[0], $prods[1]]],
      [$p['evento']['espacio_ids'] ?? null, $p['paquete_id'] ?? null, array_column($p['lineas'] ?? [], 'producto_id')]);
check('Cantidad que faltaba: 1 (o los invitados si es por persona)', true,
      in_array($p['lineas'][1]['cantidad'] ?? null, [1.0, 150.0], true));
$ped = $pedidos()[$antes] ?? [];
check('El catálogo y el mensaje van como DATOS (etiquetas), con JSON pedido', [true, true, true],
      [str_contains($ped['texto'] ?? '', '<catalogo>'), str_contains($ped['texto'] ?? '', '<mensaje>'), str_contains($ped['system'] ?? '', 'SOLO con un objeto JSON')]);
$v = Ia::validarPropuesta(['evento' => ['tipo' => 'fiesta_rara', 'fecha' => '2020-01-01', 'invitados' => -3, 'hora_inicio' => '25:00'], 'lineas' => 'x'], $cat, $hoy);
check('Validar: tipo desconocido = otro, fecha pasada = vacía, invitados malos = 0, hora mala = 18:00', ['otro', '', 0, '18:00', []],
      [$v['evento']['tipo'], $v['evento']['fecha'], $v['evento']['invitados'], $v['evento']['hora_inicio'], $v['lineas']]);
check('Validar: sin «evento» no sirve (la librería pide otra vez)', null, Ia::validarPropuesta(['ok' => true], $cat, $hoy));
$antes = $nPedidos();
$r = $ia->proponerCotizacion($nB, 'Boda de 80 personas JSON_MALO_UNA_VEZ', '/?r=evento_nuevo', $hoy);
check('JSON que no sirve dos veces: mensaje neutro con el enlace a mano (2 llamadas)', [false, $antes + 2, true],
      [$r['ok'], $nPedidos(), str_contains($r['aviso'] ?? '', 'puede hacerlo a mano aquí: /?r=evento_nuevo')]);
$antes = $nPedidos();
$r = $ia->proponerCotizacion($nA, 'Boda de 150 personas el 14 de febrero', '/?r=evento_nuevo', $hoy);
check('Sin el módulo: no llama y da el mensaje neutro', [false, $antes], [$r['ok'], $nPedidos()]);
$db->exec("UPDATE tukan_ia_gasto SET costo_usd = 4 WHERE negocio_id = '$nB'");
$r = $ia->proponerCotizacion($nB, "Boda de 150 personas RESPONDER:$json", '/?r=evento_nuevo', $hoy);
check('Con el módulo pero en su tope (1,50): no llama, mensaje neutro', [false, $antes, 'En este momento no pudimos procesar el texto automáticamente; puede hacerlo a mano aquí: /?r=evento_nuevo.'],
      [$r['ok'], $nPedidos(), $r['aviso'] ?? null]);
check('…sin «límite», «cupo» ni «saldo»', false, $prohibidas($r['aviso'] ?? ''));
check_error('Mensaje muy corto', 'Pegue el mensaje', fn() => $ia->proponerCotizacion($nB, 'hola', '/'));
$db->exec("UPDATE tukan_ia_gasto SET costo_usd = 0 WHERE negocio_id = '$nB'");
// Guardar el borrador revisado
$post = ['cliente_nombre' => 'Ana Pérez', 'cliente_telefono' => '0991234567', 'cliente_email' => 'ana@correo.ec', 'tipo' => 'boda', 'fecha' => "$anio-02-14",
         'hora_inicio' => '19:00', 'hora_fin' => '03:00', 'invitados' => '150', 'espacios' => [(string) $esp, '999999'], 'notas' => 'Quiere pirotecnia', 'paquete_id' => '',
         'linea' => [$prods[0] => ['usar' => '1', 'cantidad' => '2'], $prods[1] => ['cantidad' => '1'], 999999 => ['usar' => '1', 'cantidad' => '1']]];
check_error('Borrador: el espacio de otro negocio se quita (y sin espacio no se guarda)', 'Elige al menos un espacio',
            fn() => $ia->guardarBorrador($nB, ...[...$ia->leerBorrador($nB, ['espacios' => ['999999']] + $post), (int) $uB['id']]));
[$datos, $pq, $lineas] = $ia->leerBorrador($nB, $post);
check('Borrador: solo las líneas marcadas y del catálogo', [[$prods[0]], [2.0]], [array_column($lineas, 'producto_id'), array_column($lineas, 'cantidad')]);
[$evId, $cotId] = $ia->guardarBorrador($nB, $datos, $pq, $lineas, (int) $uB['id']);
$ev = (new Eventos($db))->obtener($nB, $evId);
check('Crea el evento en consulta con el cliente nuevo', ['consulta', 150, 'Ana Pérez'], [$ev['estado'], (int) $ev['invitados'], $ev['cliente_nombre'] ?? $db->query("SELECT nombre FROM clientes WHERE id = {$ev['cliente_id']}")->fetchColumn()]);
check('…y la cotización en borrador con el alquiler del espacio y el producto', [2, 'borrador'],
      [$cuenta("SELECT count(*) FROM cotizacion_items WHERE cotizacion_id = $cotId"), $db->query("SELECT estado FROM cotizaciones WHERE id = $cotId")->fetchColumn()]);
check_error('Cantidad mala en el borrador', 'Revisa la cantidad', fn() => $ia->leerBorrador($nB, ['linea' => [$prods[0] => ['usar' => '1', 'cantidad' => '0']]] + $post));

// ---------------------------------------------------------------------------------------------
titulo('5. Conector v2: ia_uso e ia_tope (Anexo C)');
$SECRETO = str_repeat('cd34', 16);
putenv('TUKAN_CENTRAL_ID=turenta');
putenv("TUKAN_CENTRAL_SECRET=$SECRETO");
putenv('TUKAN_CENTRAL_URL=https://panel.prueba.ec');
$db->exec("INSERT INTO superadmins (nombre, email, password_hash) VALUES ('Dimitry', 'dimitryfierro@gmail.com', 'x') ON CONFLICT DO NOTHING");
$b64 = fn($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
$pedir = function (string $accion, array|string $cuerpo = [], array $cambios = []) use ($db, $SECRETO, $b64): array {
    $c = is_string($cuerpo) ? $cuerpo : json_encode($cuerpo === [] ? new stdClass() : $cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    $t = time();
    $p = array_merge(['v' => 2, 'tipo' => $accion, 'inst' => 'turenta', 'email' => 'dimitryfierro@gmail.com', 'nombre' => 'Dimitry', 'rol' => 'dueno',
                      'iat' => $t, 'exp' => $t + ($accion === 'ejecutar' ? 30 : 60), 'nonce' => bin2hex(random_bytes(16)), 'bh' => $b64(hash('sha256', $c, true))], $cambios);
    $a = $b64(json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $db->exec('DELETE FROM tukan_central_pedidos');
    return (new TukanCentralV2($db))->procesar($accion, 'POST', 'application/json', $c, $a . '.' . $b64(hash_hmac('sha256', $a, $SECRETO, true)), '198.51.100.110');
};
$cap = $pedir('capacidades')[1];
check('capacidades: acción ia_uso y operación ia_tope (con su formulario)', [true, true, ['tope_usd']],
      [in_array('ia_uso', $cap['acciones'], true), in_array('ia_tope', $cap['ejecutar'], true), array_column($cap['formularios']['ia_tope'], 'campo')]);
$r = $pedir('ia_uso', ['mes' => '2020-01']);
check('ia_uso de un mes sin datos: listas vacías', [200, [], 0, 0.0, 'asistente_ia', 'USD'],
      [$r[0], $r[1]['negocios'], $r[1]['totales']['llamadas'], $r[1]['totales']['costo'], $r[1]['modulo'], $r[1]['moneda']]);
$db->exec("UPDATE tukan_ia_gasto SET costo_usd = 0.2 WHERE negocio_id = '$nA'");
$db->exec("UPDATE tukan_ia_gasto SET costo_usd = 0.9 WHERE negocio_id = '$nB'");
$r = $pedir('ia_uso', ['mes' => date('Y-m')]);
$ng = $r[1]['negocios'] ?? [];
check('ia_uso con dos negocios: el que más gastó primero, con nombre, tope y módulo', [200, [(string) $nB, (string) $nA], ['Hacienda Asistente', 1.5, true], [0.5, false]],
      [$r[0], array_column($ng, 'cliente_id'), [$ng[0]['nombre'] ?? null, $ng[0]['tope'] ?? null, $ng[0]['modulo'] ?? null], [$ng[1]['tope'] ?? null, $ng[1]['modulo'] ?? null]]);
check('ia_uso: totales, FAQ, configurada, versión de la librería y producto', [round(1.1, 6), true, true, TUKAN_IA_VERSION, 'turenta', 'ia_uso'],
      [$r[1]['totales']['costo'], $r[1]['faq']['total'] >= 1, $r[1]['configurada'], $r[1]['version_libreria'], $r[1]['producto'], $r[1]['accion']]);
check('ia_uso: la respuesta NO trae la clave', false, str_contains(json_encode($r[1]), $CLAVE) || str_contains(json_encode($r[1]), 'sk-ant'));
$r = $pedir('ia_uso', ['mes' => '2026-13']);
check('ia_uso: mes inválido → 422 invalido (campo mes)', [422, 'invalido', 'mes'], [$r[0], $r[1]['codigo'], $r[1]['campo'] ?? null]);
$r = $pedir('ia_uso', ['mes' => date('Y-m'), 'otro' => 1]);
check('ia_uso: campo desconocido → 422', [422, 'invalido'], [$r[0], $r[1]['codigo']]);
$r = $pedir('ia_uso', ['mes' => date('Y-m')], ['tipo' => 'clientes']);
check('ia_uso: token de otra acción → 401', 401, $r[0]);
$ej = fn(array $datos, $cliente, array $cambios = [], ?string $op = null) => $pedir('ejecutar',
    ['operacion_id' => $op ?? bin2hex(random_bytes(16)), 'accion' => 'ia_tope', 'cliente_id' => (string) $cliente, 'datos' => $datos], $cambios);
$c = $pedir('cliente', ['id' => (string) $nA])[1]['cliente'];
$btn = array_values(array_filter($c['acciones_posibles'], fn($x) => $x['accion'] === 'ia_tope'))[0] ?? null;
check('cliente: botón «Tope de IA» con el campo tope_usd tipo dinero', ['Tope de IA', 'tope_usd', 'dinero'],
      [$btn['etiqueta'] ?? null, $btn['campos'][0]['campo'] ?? null, $btn['campos'][0]['tipo'] ?? null]);
$op = bin2hex(random_bytes(16));
$r = $ej(['tope_usd' => 5.5, 'cliente_id' => (string) $nA], $nA, [], $op);
check('ia_tope: 5,50 → queda y vale', [200, 5.5, 5.5, 5.5], [$r[0], $r[1]['resultado']['tope_usd'] ?? null, $r[1]['resultado']['tope_efectivo'] ?? null, $ia->tope($nA)]);
check('ia_tope: auditado en registro_admin', 1, $cuenta("SELECT count(*) FROM registro_admin WHERE negocio_id = $nA AND accion LIKE 'Panel Tukán Central · Tope de IA: el de siempre → \$5,50%'"));
$r = $ej(['tope_usd' => 5.5, 'cliente_id' => (string) $nA], $nA, [], $op);
check('ia_tope repetido (misma operación): respuesta guardada', [200, true], [$r[0], $r[1]['repetida']]);
$r = $ej(['tope_usd' => 5.5], $nA);
check('ia_tope igual al que tenía: sin cambios', [200, true], [$r[0], $r[1]['sin_cambios']]);
$r = $ej(['tope_usd' => null], $nA);
check('ia_tope null → vuelve al de siempre (0,50 sin el módulo)', [200, null, 0.5], [$r[0], $r[1]['resultado']['tope_usd'], $ia->tope($nA)]);
$r = $ej(['tope_usd' => 'mucho'], $nA);
check('ia_tope con texto → 422 datos.tope_usd', [422, 'datos.tope_usd'], [$r[0], $r[1]['campo'] ?? null]);
$r = $ej(['tope_usd' => 2000], $nA);
check('ia_tope fuera de rango → 422', 422, $r[0]);
$r = $ej(['tope_usd' => 1], $nA, ['rol' => 'soporte']);
check('ia_tope: solo el dueño del Panel (soporte → 403)', [403, 'no_permitido'], [$r[0], $r[1]['codigo']]);

// ---------------------------------------------------------------------------------------------
titulo('6. Preguntas frecuentes del súper usuario (TukanIaFaqPdo)');
$faq = $ia->faq();
$id = $faq->agregar('¿Cómo separo una fecha?', 'Registre el pago de la reserva en **Pagos**.');
$f = $faq->obtener($id);
check('Agregar: queda aprobada y «manual»', ['manual', 1], [$f['origen'], $f['aprobada']]);
$auto = $faq->listar('sin_aprobar')[0];
$faq->aprobar($auto['id']);
check('Aprobar: sale de «Por revisar»', 0, count(array_filter($faq->listar('sin_aprobar'), fn($x) => $x['id'] === $auto['id'])));
$faq->editar($auto['id'], '¿Cómo envío la cotización por WhatsApp?', 'Abra el evento, toque **Cotización** y luego **Enviar por WhatsApp**.');
check('Editar: queda aprobada, «manual» y responde así a todos', ['manual', 1, 'faq', true],
      [$faq->obtener($auto['id'])['origen'], $faq->obtener($auto['id'])['aprobada'], ($x = $ia->ayuda($nA, '¿Cómo envío la cotización por WhatsApp?'))['fuente'], str_contains($x['respuesta'], 'Enviar por WhatsApp')]);
$faq->borrar($id);
check('Borrar', null, $faq->obtener($id));

// ---------------------------------------------------------------------------------------------
titulo('7. Correo de la demo: sello cifrado de un solo uso y SMTP de mentira');
$ses = [];
$datosDemo = ['programa' => 'TuRenta', 'enlace' => 'https://turenta.prueba.ec/?r=login', 'usuario' => 'ana@quinta.ec', 'clave' => 'Clave-Demo-9xQ',
              'hasta' => $hoy->modify('+7 days')->format('Y-m-d'), 'contacto' => 'Ana Pérez', 'negocio' => 'Quinta Ana', 'negocio_id' => $nA];
$sello = CorreoDemo::sellar($datosDemo, 7, $ses);
check('El sello no trae la clave a la vista y la sesión tampoco', [false, false], [str_contains($sello, 'Clave-Demo'), str_contains(json_encode($ses), 'Clave-Demo')]);
$ab = CorreoDemo::abrir($sello, 7, $ses);
check('Abrir: los datos y no usado', ['Clave-Demo-9xQ', false], [$ab['d']['clave'] ?? null, $ab['usado'] ?? null]);
check('Otro súper usuario no lo abre', null, CorreoDemo::abrir($sello, 8, $ses));
check('Cambiado en el camino no se abre', null, CorreoDemo::abrir(substr($sello, 0, -2) . (str_ends_with($sello, 'AA') ? 'BB' : 'AA'), 7, $ses));
check('Otra sesión (otra llave) no lo abre', null, CorreoDemo::abrir($sello, 7, ['correo_demo_llave' => base64_encode(random_bytes(32))]));
check('Vence a los 30 minutos', null, CorreoDemo::abrir($sello, 7, $ses, time() + 1801));
$m = CorreoDemo::mensaje($ab['d']);
check('Correo «Su demo de TuRenta está lista» con sus datos, sin «gratis»', ['Su demo de TuRenta está lista', true, false],
      [$m['asunto'], str_contains($m['texto'], 'Clave-Demo-9xQ'), (bool) preg_match('/gratis/iu', $m['html'] . $m['texto'])]);
// SMTP de mentira (sin TLS: solo se permite con 127.0.0.1)
$dirSmtp = "$itmp/smtp";
@mkdir($dirSmtp, 0755, true);
$procs[] = proc_open(['python3', __DIR__ . '/smtp_falso.py', '--puerto', $SMTP_P, '--modo', 'plano', '--usuario', 'info@tukansoftware.com', '--clave', 'Cl@ve-buzon', '--dir', $dirSmtp],
                     [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', "$itmp/smtp.err", 'a']], $tubSmtp);
stream_set_timeout($tubSmtp[1], 5);
fgets($tubSmtp[1]);   // LISTO
putenv("TUKAN_CORREO_ENV=$itmp/no_hay_correo.env");
tukan_correo_config(true);
check('Sin /etc/tukan-correo.env: no está listo', false, CorreoDemo::listo());
file_put_contents("$itmp/correo.env", "TUKAN_SMTP_HOST=127.0.0.1\nTUKAN_SMTP_PUERTO=$SMTP_P\nTUKAN_SMTP_SEGURIDAD=ninguna\nTUKAN_SMTP_USUARIO=info@tukansoftware.com\n"
                                    . "TUKAN_SMTP_CLAVE=Cl@ve-buzon\nTUKAN_CORREO_DE=info@tukansoftware.com\nTUKAN_CORREO_NOMBRE=Tukán Software\nTUKAN_SMTP_TIMEOUT=3\n");
putenv("TUKAN_CORREO_ENV=$itmp/correo.env");
tukan_correo_config(true);
check('Con el archivo de correo: listo', true, CorreoDemo::listo());
$ok = CorreoDemo::enviar($ab['d'], 'ana@quinta.ec', $error);
for ($i = 0; $i < 40 && !glob("$dirSmtp/*.eml"); $i++) usleep(50000);
$eml = quoted_printable_decode((string) @file_get_contents((glob("$dirSmtp/*.eml") ?: [''])[0] ?: '/dev/null'));
check('Enviar: llega al SMTP para el cliente, desde info@tukansoftware.com, con sus datos', [true, true, true, true],
      [$ok, str_contains($eml, 'ana@quinta.ec'), str_contains($eml, 'info@tukansoftware.com'), str_contains($eml, 'Clave-Demo-9xQ')]);
CorreoDemo::gastar($ab['n'], $ses);
check('Después de enviar, el mismo sello queda «usado»', true, CorreoDemo::abrir($sello, 7, $ses)['usado'] ?? null);
file_put_contents("$itmp/correo.env", str_replace('Cl@ve-buzon', 'clave-mala-123', (string) file_get_contents("$itmp/correo.env")));
tukan_correo_config(true);
$ok = CorreoDemo::enviar($ab['d'], 'ana@quinta.ec', $error);
check('Clave del buzón mala: false y el error no trae ninguna clave', [false, true, false, false],
      [$ok, $error !== null && $error !== '', str_contains((string) $error, 'clave-mala-123'), str_contains((string) $error, 'Clave-Demo-9xQ')]);
putenv("TUKAN_CORREO_ENV=$itmp/no_hay_correo.env");
tukan_correo_config(true);

ini_set('error_log', (string) $errLog);
$sinIa();
foreach ($procs as $p) { @proc_terminate($p); @proc_close($p); }
$procs = [];
