<?php
/**
 * Panel Tukán Central v2, Anexo A: pausar, borrar (papelera), restaurar y purga a los 30 días.
 * Efecto real de cada operación, copia completa (todas las tablas del negocio, sacadas del esquema, y sus archivos),
 * restaurar devuelve todo, purga con reloj simulado, aislamiento (los otros negocios no se tocan), páginas públicas,
 * tareas automáticas, idempotencia, permisos y auditoría en registro_admin.
 */
use TuRenta\{Altas, Automaticas, Papelera, PanelTukan, Planes, TukanCentral, TukanCentralV2};

$db = base_limpia();
planes_de_antes($db);   // estas pruebas usan los planes de antes (Esencial $19, Salón $39, Quinta $69); los de $20 y $45: logica_112
$hoy = new DateTimeImmutable('today');
$d = fn(string $m) => $hoy->modify($m)->format('Y-m-d');
$SECRETO = str_repeat('ab12', 16);
putenv('TUKAN_CENTRAL_ID=turenta');
putenv("TUKAN_CENTRAL_SECRET=$SECRETO");
putenv('TUKAN_CENTRAL_URL=https://panel.prueba.ec');
putenv('TURENTA_URL=https://turenta.prueba.ec');
$COPIAS = sys_get_temp_dir() . '/turenta_borrados_' . getmypid();
putenv("TURENTA_BORRADOS=$COPIAS");
$cuenta = fn(string $sql) => (int) $db->query($sql)->fetchColumn();
$b64 = fn($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
const FLAGS97 = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

$token = function (string $tipo, string $cuerpo, array $cambios = []) use ($SECRETO, $b64): string {
    $t = time();
    $p = array_merge(['v' => 2, 'tipo' => $tipo, 'inst' => 'turenta', 'email' => 'dimitryfierro@gmail.com', 'nombre' => 'Dimitry', 'rol' => 'dueno',
                      'iat' => $t, 'exp' => $t + ($tipo === 'ejecutar' ? 30 : 60), 'nonce' => bin2hex(random_bytes(16)),
                      'bh' => $b64(hash('sha256', $cuerpo, true))], $cambios);
    $a = $b64(json_encode($p, FLAGS97));
    return $a . '.' . $b64(hash_hmac('sha256', $a, $SECRETO, true));
};
$sinAuditoria = function (array $datos): array { unset($datos['registro_admin']); return $datos; };
$pedir = function (string $accion, array $cuerpo = [], array $o = []) use ($db, $token): array {
    $c = json_encode($cuerpo === [] ? new stdClass() : $cuerpo, FLAGS97);
    $db->exec('DELETE FROM tukan_central_pedidos');
    return (new TukanCentralV2($db, $o['hoy'] ?? null))->procesar($accion, 'POST', 'application/json', $c,
                                                                 $token($accion, $c, $o['cambios'] ?? []), '198.51.100.97');
};
$ej = function (string $op, array $datos, int|string|null $cliente, ?string $opId = null, array $o = []) use ($pedir): array {
    $cuerpo = ['operacion_id' => $opId ?? bin2hex(random_bytes(16)), 'accion' => $op];
    if ($cliente !== null) $cuerpo['cliente_id'] = (string) $cliente;
    $cuerpo['datos'] = $datos === [] ? new stdClass() : $datos;
    return $pedir('ejecutar', $cuerpo, $o);
};
$codigo = fn(array $r) => [$r[0], $r[1]['codigo'] ?? null, $r[1]['detalle']['motivo'] ?? ($r[1]['campo'] ?? null)];
$clientes = fn(array $f = []) => $pedir('clientes', $f + ['por_pagina' => 200])[1];
$idsDe = fn(array $r) => array_map('intval', array_column($r['clientes'] ?? [], 'id'));
$planes = new Planes($db);
$panel = new PanelTukan($db);
$pap = new Papelera($db);

/** Negocio con datos en muchas tablas (eventos, invitados, cotización, contrato, compras, pagos, personal, archivos…). */
$rico = function (string $nombre) use ($db, $planes, $d): array {
    $a = (new Altas($db))->crearNegocioConDueno($nombre, 'Dueño ' . $nombre, '0990000000', 'd' . bin2hex(random_bytes(4)) . '@prueba.ec', 'claveSegura1', 'quinta', 'Quito', 'registro');
    $n = $a['negocio_id'];
    $q = fn(string $sql, array $p = []) => (function () use ($db, $sql, $p) { $st = $db->prepare($sql); $st->execute($p); return $st->columnCount() ? $st->fetchColumn() : null; })();
    $cli = (int) $q('INSERT INTO clientes (negocio_id, nombre, telefono) VALUES (?, ?, ?) RETURNING id', [$n, "Cliente de $nombre", '0991112233']);
    $ev = (int) $q("INSERT INTO eventos (negocio_id, codigo, cliente_id, tipo, nombre, fecha, invitados, estado) VALUES (?, ?, ?, 'boda', ?, ?, 120, 'confirmado') RETURNING id",
                   [$n, 'EV-' . $n, $cli, "Boda de $nombre", $d('+40 days')]);
    $evPasado = (int) $q("INSERT INTO eventos (negocio_id, codigo, cliente_id, tipo, nombre, fecha, invitados, estado) VALUES (?, ?, ?, 'boda', ?, ?, 80, 'confirmado') RETURNING id",
                         [$n, 'EV-P' . $n, $cli, "Boda pasada de $nombre", $d('-5 days')]);
    $esp = (int) $q('SELECT id FROM espacios WHERE negocio_id = ? ORDER BY id LIMIT 1', [$n]);
    $q("INSERT INTO evento_espacios (negocio_id, evento_id, espacio_id, periodo, bloquea) VALUES (?, ?, ?, tsrange(?::timestamp, ?::timestamp), true)",
       [$n, $ev, $esp, $d('+40 days') . ' 15:00', $d('+40 days') . ' 23:59']);
    $mesa = (int) $q('INSERT INTO mesas (evento_id, nombre) VALUES (?, ?) RETURNING id', [$ev, 'Mesa 1']);
    $q('INSERT INTO invitados (evento_id, nombre, mesa_id) VALUES (?, ?, ?)', [$ev, "Invitada de $nombre", $mesa]);
    $q('INSERT INTO invitados (evento_id, nombre) VALUES (?, ?)', [$ev, "Invitado de $nombre"]);
    $cot = (int) $q('INSERT INTO cotizaciones (negocio_id, evento_id, numero, valida_hasta, invitados, iva_pct) VALUES (?, ?, 1, ?, 120, 15) RETURNING id', [$n, $ev, $d('+10 days')]);
    $con = (int) $q("INSERT INTO contratos (negocio_id, evento_id, numero, codigo_verificacion, texto, hash_sha256) VALUES (?, ?, 1, ?, 'Contrato de prueba', ?) RETURNING id",
                    [$n, $ev, strtoupper(substr(bin2hex(random_bytes(6)), 0, 12)), hash('sha256', 'x')]);
    $q("INSERT INTO contrato_anexos (negocio_id, evento_id, contrato_id, numero, codigo_verificacion, texto, hash_sha256) VALUES (?, ?, ?, 1, ?, 'Anexo', ?)",
       [$n, $ev, $con, strtoupper(substr(bin2hex(random_bytes(6)), 0, 12)), hash('sha256', 'y')]);
    $prov = (int) $q('INSERT INTO proveedores (negocio_id, nombre) VALUES (?, ?) RETURNING id', [$n, "Flores de $nombre"]);
    $compra = (int) $q('INSERT INTO compras (negocio_id, numero, proveedor_id, evento_id) VALUES (?, 1, ?, ?) RETURNING id', [$n, $prov, $ev]);
    $q('INSERT INTO pagos_proveedor (negocio_id, compra_id, proveedor_id, monto) VALUES (?, ?, ?, 50)', [$n, $compra, $prov]);
    $q('INSERT INTO personal (negocio_id, nombre) VALUES (?, ?)', [$n, "Mesero de $nombre"]);
    $q('INSERT INTO pagos (negocio_id, evento_id, monto) VALUES (?, ?, 300)', [$n, $ev]);
    $q('INSERT INTO registro_cambios (negocio_id, evento_id, quien, accion) VALUES (?, ?, ?, ?)', [$n, $ev, 'Sistema', 'Prueba']);
    // Archivos subidos: logo del negocio y foto de un espacio
    $logo = bin2hex(random_bytes(16)) . '.png';
    $foto = bin2hex(random_bytes(16)) . '.jpg';
    foreach (['logos' => $logo, 'espacios' => $foto] as $carpeta => $archivo) {
        @mkdir(TuRenta\Fotos::carpeta($carpeta), 0750, true);
        file_put_contents(TuRenta\Fotos::carpeta($carpeta) . "/$archivo", "archivo $nombre");
    }
    $q('UPDATE negocios SET logo = ? WHERE id = ?', [$logo, $n]);
    $q('UPDATE espacios SET foto = ? WHERE id = ?', [$foto, $esp]);
    // Pago anual hace 40 días (cubre 12 meses)
    $planes->suscribir($n, 'quinta', 'anual', new DateTimeImmutable($d('-40 days')), 'transferencia', null, 690.0, new DateTimeImmutable($d('-40 days')));
    $db->prepare('INSERT INTO registro_admin (admin_id, negocio_id, accion) VALUES (NULL, ?, ?)')->execute([$n, 'Negocio creado desde el panel (prueba)']);
    $tok = $db->query("SELECT token_cliente, token_puerta, token_vivo, token_encuesta FROM eventos WHERE id = $ev")->fetch();
    return ['id' => $n, 'slug' => $a['slug'], 'usuario' => $a['usuario_id'], 'evento' => $ev, 'pasado' => $evPasado, 'espacio' => $esp, 'logo' => $logo, 'foto' => $foto,
            'tokens' => $tok + [
                'cot' => $db->query("SELECT token FROM cotizaciones WHERE id = $cot")->fetchColumn(),
                'contrato' => $db->query("SELECT token FROM contratos WHERE id = $con")->fetchColumn(),
                'codigo' => $db->query("SELECT codigo_verificacion FROM contratos WHERE id = $con")->fetchColumn(),
                'anexo' => $db->query("SELECT token FROM contrato_anexos WHERE contrato_id = $con")->fetchColumn(),
                'oc' => $db->query("SELECT codigo_verificacion FROM compras WHERE id = $compra")->fetchColumn(),
                'invitado' => $db->query("SELECT token FROM invitados WHERE evento_id = $ev ORDER BY id LIMIT 1")->fetchColumn(),
                'personal' => $db->query("SELECT token FROM personal WHERE negocio_id = $n")->fetchColumn(),
                'ical' => $db->query("SELECT token_ical FROM negocios WHERE id = $n")->fetchColumn(),
                'proveedor' => $db->query("SELECT token FROM proveedores WHERE id = $prov")->fetchColumn(),
                'pago' => $db->query("SELECT id FROM pagos WHERE negocio_id = $n")->fetchColumn(),
            ]];
};

// ---------------------------------------------------------------------------------------------
titulo('1. Negocios de prueba y capacidades (Anexo A)');
$A = $rico('Quinta Para Borrar');
$B = $rico('Hacienda Que Se Queda');
$P = $rico('Salón Para Pausar');
$X = $rico('Quinta Cancelada');
$db->exec("UPDATE negocios SET estado = 'cancelado' WHERE id = {$X['id']}");
check('4 negocios', 4, $cuenta('SELECT count(*) FROM negocios'));
$cap = $pedir('capacidades')[1];
check('capacidades: operaciones nuevas', [true, true, true], [in_array('pausar', $cap['ejecutar'], true), in_array('borrar_cliente', $cap['ejecutar'], true),
                                                               in_array('restaurar_cliente', $cap['ejecutar'], true)]);
check('capacidades: revisión 3 (Anexo B) y tipos de pausa', [3, ['falta_pago', 'pedido_cliente', 'revision', 'abuso', 'otro']], [$cap['revision'], array_keys($cap['tipos_pausa'])]);
check('capacidades: formularios de pausar y borrar', [['tipo', 'motivo'], ['confirmar', 'motivo']],
      [array_column($cap['formularios']['pausar'], 'campo'), array_column($cap['formularios']['borrar_cliente'], 'campo')]);
check('capacidades: 30 días de papelera, copia 1 año', ['dias' => 30, 'copia_dias' => 365], $cap['papelera']);
check('capacidades: filtro papelera', true, in_array('papelera', $cap['filtros_clientes'], true));

// ---------------------------------------------------------------------------------------------
titulo('2. Tablas del negocio sacadas del esquema (no de una lista escrita a mano)');
$esperadas = $db->query("WITH RECURSIVE t(n) AS (
        SELECT 'negocios'::text
        UNION SELECT c.table_name::text FROM information_schema.columns c WHERE c.table_schema = 'public' AND c.column_name = 'negocio_id'
                                                                                AND c.data_type IN ('integer', 'bigint', 'smallint')
        UNION SELECT cl.relname::text FROM pg_constraint k JOIN pg_class cl ON cl.oid = k.conrelid JOIN pg_class r ON r.oid = k.confrelid JOIN t ON t.n = r.relname
               WHERE k.contype = 'f')
    SELECT n FROM t JOIN information_schema.tables x ON x.table_name = t.n AND x.table_schema = 'public' AND x.table_type = 'BASE TABLE' ORDER BY n")->fetchAll(PDO::FETCH_COLUMN);
$tablas = $pap->tablas();
$nombres = array_keys($tablas);
sort($nombres);
check('Mismas tablas que el esquema (' . count($esperadas) . ')', $esperadas, $nombres);
check('Más de 40 tablas del negocio', true, count($esperadas) > 40);
check('tukan_ia_gasto (negocio_id de texto: gasto de IA de Tukán) no es del negocio: no se copia ni se borra', false, isset($pap->tablas()['tukan_ia_gasto']));
check('Hijas sin negocio_id incluidas (invitados, mesas, paquete_items, cotizacion_items, compra_items)', [true, true, true, true, true],
      array_map(fn($t) => isset($tablas[$t]), ['invitados', 'mesas', 'paquete_items', 'cotizacion_items', 'compra_items']));
check('registro_admin se copia pero no se borra (ON DELETE SET NULL)', [true, false], [isset($tablas['registro_admin']), $tablas['registro_admin']['propia']]);
check('usuarios, eventos e invitados se borran con el negocio', [true, true, true], [$tablas['usuarios']['propia'], $tablas['eventos']['propia'], $tablas['invitados']['propia']]);
$pos = array_flip(array_keys($tablas));
check('Orden de madre a hija (negocios primero, eventos antes que invitados, compras antes que pagos_proveedor)', [0, true, true],
      [$pos['negocios'], $pos['eventos'] < $pos['invitados'], $pos['compras'] < $pos['pagos_proveedor']]);
check('Condición de una hija: por su madre', true, str_contains($tablas['invitados']['condicion'], '"eventos"'));

// ---------------------------------------------------------------------------------------------
titulo('3. pausar: tipo y motivo obligatorios, pantalla del cliente y páginas públicas');
$antesB = $pap->datos($B['id']);
check('Sin tipo → 422 datos.tipo', [422, 'invalido', 'datos.tipo'], $codigo($ej('pausar', ['motivo' => 'Debe octubre'], $P['id'])));
check('Tipo que no existe → 422 datos.tipo', [422, 'invalido', 'datos.tipo'], $codigo($ej('pausar', ['tipo' => 'enojo', 'motivo' => 'Debe octubre'], $P['id'])));
check('Sin motivo → 422 datos.motivo', [422, 'invalido', 'datos.motivo'], $codigo($ej('pausar', ['tipo' => 'falta_pago'], $P['id'])));
check('Motivo de 2 letras → 422 datos.motivo', [422, 'invalido', 'datos.motivo'], $codigo($ej('pausar', ['tipo' => 'falta_pago', 'motivo' => 'no'], $P['id'])));
check('… nada cambió', 'activo', $db->query("SELECT estado FROM negocios WHERE id = {$P['id']}")->fetchColumn());
$op = bin2hex(random_bytes(16));
$r = $ej('pausar', ['tipo' => 'falta_pago', 'motivo' => 'Debe la mensualidad de octubre'], $P['id'], $op);
check('Pausar: 200', [200, true, false], [$r[0], $r[1]['ok'], $r[1]['sin_cambios']]);
$f = $db->query("SELECT estado, pausa_tipo, pausa_motivo, pausa_en IS NOT NULL AS con_fecha FROM negocios WHERE id = {$P['id']}")->fetch();
check('Pausar: en la base queda suspendido con tipo, motivo y fecha', ['suspendido', 'falta_pago', 'Debe la mensualidad de octubre', true],
      [$f['estado'], $f['pausa_tipo'], $f['pausa_motivo'], $f['con_fecha']]);
check('Pausar: respuesta con el cliente suspendido y su pausa', ['suspendido', 'falta_pago', 'Debe la mensualidad de octubre', true],
      [$r[1]['cliente']['estado'], $r[1]['cliente']['pausa']['tipo'], $r[1]['cliente']['pausa']['motivo'], $r[1]['cliente']['bloqueado']]);
check('Pausar: el cliente ve el motivo (falta de pago)', 'Debe la mensualidad de octubre', $r[1]['resultado']['pausa']['texto_cliente']);
check('Pausar: mensaje', true, str_contains($r[1]['mensaje'], 'temporalmente no disponible'));
check('Pausar: línea en registro_admin con el correo del Central', 1,
      $cuenta("SELECT count(*) FROM registro_admin r JOIN superadmins a ON a.id = r.admin_id WHERE r.negocio_id = {$P['id']}
               AND r.accion LIKE 'Panel Tukán Central · Pausado (Falta de pago): Debe la mensualidad de octubre%' AND a.email = 'dimitryfierro@gmail.com'"));
$r2 = $ej('pausar', ['tipo' => 'falta_pago', 'motivo' => 'Debe la mensualidad de octubre'], $P['id'], $op);
check('Mismo operacion_id → repetida, sin hacerla otra vez', [200, true], [$r2[0], $r2[1]['repetida']]);
check('… una sola línea de auditoría', 1, $cuenta("SELECT count(*) FROM registro_admin WHERE negocio_id = {$P['id']} AND accion LIKE '%Pausado%'"));
check('Otra operación con lo mismo → sin_cambios', [200, true], [$ej('pausar', ['tipo' => 'falta_pago', 'motivo' => 'Debe la mensualidad de octubre'], $P['id'])[0],
                                                               $ej('pausar', ['tipo' => 'falta_pago', 'motivo' => 'Debe la mensualidad de octubre'], $P['id'])[1]['sin_cambios']]);
check('Mismo operacion_id con otros datos → 409', [409, 'conflicto', 'otros_datos'], $codigo($ej('pausar', ['tipo' => 'otro', 'motivo' => 'Otra cosa'], $P['id'], $op)));
$r = $ej('pausar', ['tipo' => 'abuso', 'motivo' => 'Spam a sus clientes desde el portal'], $P['id']);
check('Cambiar el tipo de una pausa: cambia', [200, false, 'abuso'], [$r[0], $r[1]['sin_cambios'], $db->query("SELECT pausa_tipo FROM negocios WHERE id = {$P['id']}")->fetchColumn()]);
check('Abuso: el cliente ve un texto genérico (no el motivo)', [false, true],
      [str_contains($r[1]['resultado']['pausa']['texto_cliente'], 'Spam'), str_contains($r[1]['resultado']['pausa']['texto_cliente'], 'WhatsApp')]);
check('Revisión: texto genérico', false, str_contains(Papelera::textoPausa('revision', 'Revisando pagos raros'), 'raros'));
check('Pedido del cliente: se muestra', 'Pidió pausar por vacaciones', Papelera::textoPausa('pedido_cliente', 'Pidió pausar por vacaciones'));
$ficha = $pedir('cliente', ['id' => (string) $P['id']])[1]['cliente'];
$acc = array_column($ficha['acciones_posibles'], 'accion');
check('Ficha de un pausado: reactivar y borrar, no pausar', [true, false, true], [in_array('reactivar', $acc, true), in_array('pausar', $acc, true), in_array('borrar_cliente', $acc, true)]);
check('Ficha: estado_texto con el tipo de pausa', true, str_contains($ficha['estado_texto'], 'Pausado por Tukán: Uso indebido'));
check('Páginas públicas del pausado: "pausado"', 'pausado', $pap->estadoPublico($P['id']));
check('… el sitio del pausado es suyo', $P['id'], $pap->negocioDeRuta('sitio', ['s' => $P['slug']]));
$c = $clientes();
check('Un pausado cuenta como suspendido (contadores = estado v1)', [1 + 1, $c['contadores']['suspendido']],
      [$c['contadores']['suspendido'], (new TukanCentral($db))->estado($hoy)['negocios']['suspendidos']]);
check('Pausar a un cancelado → 409', [409, 'conflicto', 'cancelado'], $codigo($ej('pausar', ['tipo' => 'otro', 'motivo' => 'Prueba de cancelado'], $X['id'])));
// Tareas automáticas: el pausado no recibe tareas (su evento pasado no pasa a "realizado"); el otro sí
(new Automaticas($db))->correr(new DateTimeImmutable('now'));
check('Tareas: el pausado no se toca, el normal sí', ['confirmado', 'realizado'],
      [$db->query("SELECT estado FROM eventos WHERE id = {$P['pasado']}")->fetchColumn(), $db->query("SELECT estado FROM eventos WHERE id = {$B['pasado']}")->fetchColumn()]);
$antesB = $pap->datos($B['id']);   // B cambió con las tareas: desde aquí no debe cambiar más
// reactivar
$r = $ej('reactivar', [], $P['id']);
$f = $db->query("SELECT estado, pausa_tipo, pausa_motivo FROM negocios WHERE id = {$P['id']}")->fetch();
check('Reactivar: vuelve a activo (tenía pago) y se borra la pausa', [200, 'activo', null, null, null], [$r[0], $f['estado'], $f['pausa_tipo'], $f['pausa_motivo'], $r[1]['cliente']['pausa']]);
check('Reactivar otra vez: sin cambios', true, $ej('reactivar', [], $P['id'])[1]['sin_cambios']);
check('Reactivado: páginas normales', 'normal', $pap->estadoPublico($P['id']));
$r = $ej('suspender', ['motivo' => 'Suspensión vieja sin tipo'], $P['id']);
check('suspender (v2) guarda el motivo sin tipo: el cliente ve el texto genérico', [null, 'Suspensión vieja sin tipo', true],
      [$db->query("SELECT pausa_tipo FROM negocios WHERE id = {$P['id']}")->fetchColumn(), $db->query("SELECT pausa_motivo FROM negocios WHERE id = {$P['id']}")->fetchColumn(),
       str_contains($r[1]['cliente']['pausa']['texto_cliente'], 'WhatsApp')]);
$ej('reactivar', [], $P['id']);

// ---------------------------------------------------------------------------------------------
titulo('4. borrar_cliente: confirmación, permisos y copia completa');
$db->exec("UPDATE eventos SET estado = 'confirmado' WHERE id = {$A['pasado']}");   // para ver que las tareas no lo tocan en la papelera
$antesA = $pap->datos($A['id']);
$archivosAntes = count(glob("$COPIAS/*.json.gz") ?: []);
check('Sin confirmar → 422 datos.confirmar', [422, 'invalido', 'datos.confirmar'], $codigo($ej('borrar_cliente', ['motivo' => 'Ya no usa el sistema'], $A['id'])));
check('Nombre mal escrito → 422 datos.confirmar', [422, 'invalido', 'datos.confirmar'],
      $codigo($ej('borrar_cliente', ['confirmar' => 'quinta para borrar', 'motivo' => 'Ya no usa el sistema'], $A['id'])));
check('Nombre de otro negocio → 422', [422, 'invalido', 'datos.confirmar'], $codigo($ej('borrar_cliente', ['confirmar' => 'Hacienda Que Se Queda', 'motivo' => 'xx yy'], $A['id'])));
check('Sin motivo → 422 datos.motivo', [422, 'invalido', 'datos.motivo'], $codigo($ej('borrar_cliente', ['confirmar' => 'Quinta Para Borrar'], $A['id'])));
check('Rol soporte → 403 no_permitido (solo el dueño borra)', [403, 'no_permitido', 'rol'],
      $codigo($ej('borrar_cliente', ['confirmar' => 'Quinta Para Borrar', 'motivo' => 'Ya no usa el sistema'], $A['id'], null, ['cambios' => ['rol' => 'soporte']])));
$db->exec("UPDATE superadmins SET activo = false WHERE email = 'dimitryfierro@gmail.com'");
check('Super usuario desactivado → 403 no_permitido', [403, 'no_permitido'],
      array_slice($codigo($ej('borrar_cliente', ['confirmar' => 'Quinta Para Borrar', 'motivo' => 'Ya no usa el sistema'], $A['id'])), 0, 2));
$db->exec("UPDATE superadmins SET activo = true WHERE email = 'dimitryfierro@gmail.com'");
check('… nada se borró ni se copió', [null, $archivosAntes], [$db->query("SELECT eliminado_en FROM negocios WHERE id = {$A['id']}")->fetchColumn() ?: null,
                                                               count(glob("$COPIAS/*.json.gz") ?: [])]);
check('Cliente que no existe → 404', [404, 'no_encontrado'], array_slice($codigo($ej('borrar_cliente', ['confirmar' => 'x', 'motivo' => 'xyz'], 999999)), 0, 2));
$opB = bin2hex(random_bytes(16));
$r = $ej('borrar_cliente', ['confirmar' => 'Quinta Para Borrar', 'motivo' => 'Cerró el local'], $A['id'], $opB);
check('Borrar: 200', [200, true, false], [$r[0], $r[1]['ok'], $r[1]['sin_cambios']]);
$copia = $r[1]['resultado']['copia'] ?? '';
check('Borrar: copia y fecha del borrado definitivo (arriba y en resultado)', [$copia, $d('+30 days'), $d('+30 days')],
      [$r[1]['copia'] ?? null, $r[1]['borrar_definitivo_el'] ?? null, $r[1]['resultado']['borrar_definitivo_el'] ?? null]);
check('Borrar: la copia está en la carpeta de borrados (fuera de public/)', [true, true, false],
      [is_file($copia), str_starts_with($copia, $COPIAS . '/'), str_contains($copia, '/public/')]);
check('Borrar: copia con permisos 600 y carpeta 700', ['600', '700'], [substr(sprintf('%o', fileperms($copia)), -3), substr(sprintf('%o', fileperms($COPIAS)), -3)]);
check('Borrar: nombre <id>_<fecha>.json.gz', true, (bool) preg_match('/^' . $A['id'] . '_\d{8}-\d{6}_[a-f0-9]{6}\.json\.gz$/', basename($copia)));
$f = $db->query("SELECT eliminado_en IS NOT NULL AS borrado, eliminado_motivo, eliminado_por, eliminado_copia FROM negocios WHERE id = {$A['id']}")->fetch();
check('Borrar: marcado en la base con motivo, quién y copia', [true, 'Cerró el local', 'Dimitry <dimitryfierro@gmail.com>', $copia],
      [$f['borrado'], $f['eliminado_motivo'], $f['eliminado_por'], $f['eliminado_copia']]);
check('Borrar: auditoría', 1, $cuenta("SELECT count(*) FROM registro_admin WHERE negocio_id = {$A['id']} AND accion LIKE 'Panel Tukán Central · Enviado a la papelera: Cerró el local%'"));
check('Borrar: respuesta con el cliente en la papelera', [true, $d('+30 days'), 'Cerró el local', true],
      [$r[1]['cliente']['eliminado'], $r[1]['cliente']['papelera']['hasta'], $r[1]['cliente']['papelera']['motivo'], $r[1]['cliente']['papelera']['se_puede_restaurar']]);
$exp = Papelera::leerCopia($copia);
check('Copia: producto, negocio y nombre', ['turenta', $A['id'], 'Quinta Para Borrar'], [$exp['producto'], $exp['negocio_id'], $exp['nombre']]);
$enCopia = array_keys($exp['tablas']);
sort($enCopia);
check('Copia: TODAS las tablas del negocio (según el esquema) están en la copia', $esperadas, $enCopia);
$faltan = [];
foreach ($db->query("SELECT table_name FROM information_schema.columns WHERE table_schema = 'public' AND column_name = 'negocio_id' AND data_type IN ('integer', 'bigint', 'smallint')")->fetchAll(PDO::FETCH_COLUMN) as $t) {
    $n = $cuenta("SELECT count(*) FROM \"$t\" WHERE negocio_id = {$A['id']}" . ($t === 'registro_admin' ? " AND accion NOT LIKE '%Enviado a la papelera%'" : ''));
    if (count($exp['tablas'][$t] ?? []) !== $n) $faltan[] = "$t ($n en la base, " . count($exp['tablas'][$t] ?? []) . ' en la copia)';
    foreach ($exp['tablas'][$t] ?? [] as $fila) if ((int) $fila['negocio_id'] !== $A['id']) $faltan[] = "$t: fila de otro negocio";
}
check('Copia: cada tabla con negocio_id tiene todas sus filas y solo las de este negocio', [], $faltan);
check('Copia: filas de tablas hijas (invitados, mesas, paquete_items, compras/pagos_proveedor)',
      [2, 1, $cuenta("SELECT count(*) FROM paquete_items pi JOIN paquetes p ON p.id = pi.paquete_id WHERE p.negocio_id = {$A['id']}"), 1],
      [count($exp['tablas']['invitados']), count($exp['tablas']['mesas']), count($exp['tablas']['paquete_items']), count($exp['tablas']['pagos_proveedor'])]);
check('Copia: hay filas en más de 15 tablas', true, count(array_filter($exp['cuantas'])) > 15);
check('Copia: registro_admin del negocio', true, count($exp['tablas']['registro_admin']) >= 1);
$rutas = array_column($exp['archivos'], 'ruta');
check('Copia: lista de archivos subidos (logo y foto del espacio) con su ruta', [true, true],
      [in_array(TuRenta\Fotos::carpeta('logos') . '/' . $A['logo'], $rutas, true), in_array(TuRenta\Fotos::carpeta('espacios') . '/' . $A['foto'], $rutas, true)]);
check('Copia: ningún archivo de otro negocio', false, in_array(TuRenta\Fotos::carpeta('logos') . '/' . $B['logo'], $rutas, true));
check('Copia: nada de otro negocio dentro', [false, false], [str_contains(gzdecode(file_get_contents($copia)), 'Hacienda Que Se Queda'),
                                                             str_contains(gzdecode(file_get_contents($copia)), 'Salón Para Pausar')]);
check('Copia: las filas son las de antes de borrar', $antesA['eventos'], $exp['tablas']['eventos']);

// ---------------------------------------------------------------------------------------------
titulo('5. En la papelera: desaparece de entradas, listas, cifras, páginas y tareas');
$c = $clientes();
check('clientes: ya no sale', false, in_array($A['id'], $idsDe($c), true));
check('clientes: contadores sin él (3 vivos) = estado v1', [3, 3], [$c['contadores']['total'], (new TukanCentral($db))->estado($hoy)['negocios']['total']]);
check('clientes: papelera_total', 1, $c['papelera_total']);
check('clientes con ids = [él]: vacío', [], $idsDe($clientes(['ids' => [(string) $A['id']]])));
check('clientes buscando su nombre: vacío', [], $idsDe($clientes(['buscar' => 'Para Borrar'])));
$pp = $clientes(['filtros' => ['estado' => 'papelera']]);
check('filtros.estado = papelera: solo él', [[$A['id']], true], [$idsDe($pp), $pp['papelera']]);
check('… con papelera {desde, hasta, motivo}', [$d('+30 days'), 'Cerró el local', true],
      [$pp['clientes'][0]['papelera']['hasta'], $pp['clientes'][0]['papelera']['motivo'], str_starts_with($pp['clientes'][0]['papelera']['desde'], $d('today'))]);
check('estado = papelera y estados = [papelera] también sirven', [[$A['id']], [$A['id']]],
      [$idsDe($clientes(['estado' => 'papelera'])), $idsDe($clientes(['estados' => ['papelera']]))]);
check('papelera + buscar', [[$A['id']], []], [$idsDe($clientes(['filtros' => ['estado' => 'papelera'], 'buscar' => 'Borrar'])),
                                             $idsDe($clientes(['filtros' => ['estado' => 'papelera'], 'buscar' => 'Hacienda']))]);
$ficha = $pedir('cliente', ['id' => (string) $A['id']])[1]['cliente'];
check('Ficha en la papelera: solo restaurar y ver', ['restaurar_cliente', 'abrir'], array_column($ficha['acciones_posibles'], 'accion'));
check('Ficha: estado_texto dice papelera', true, str_contains($ficha['estado_texto'], 'En la papelera'));
check('Panel Tukán propio: no sale en la lista ni en el resumen', [false, 3],
      [in_array($A['id'], array_map('intval', array_column($panel->negocios(), 'id')), true), $panel->resumen($hoy)['total']]);
check('Panel Tukán propio: filtro papelera', [$A['id']], array_map('intval', array_column($panel->negocios('', '', $hoy, '', true), 'id')));
check('Solicitudes: ninguna de él', [], array_values(array_filter(array_column($pedir('solicitudes', ['estado' => 'todas', 'por_pagina' => 200])[1]['solicitudes'], 'cliente_id'),
                                                                 fn($x) => $x === (string) $A['id'])));
check('planes: no cuenta en "clientes" del plan (B, P y el cancelado)', 3, (int) array_column($pedir('planes')[1]['planes'], 'clientes', 'codigo')['quinta']);
// finanzas: lo cobrado antes sigue (es plata real); el devengado de los meses que vienen y el MRR ya no
$fin = $pedir('finanzas', ['desde' => $hoy->modify('-2 months')->format('Y-m'), 'hasta' => $hoy->modify('+2 months')->format('Y-m')])[1];
$porMes = array_column($fin['meses'], null, 'mes');
$mesPago = substr($d('-40 days'), 0, 7);
check('finanzas: el pago de hace 40 días sigue en su mes (4 negocios pagaron)', 4 * 690.0, $porMes[$mesPago]['ingresos']);
$proximo = $hoy->modify('first day of next month')->format('Y-m');
check('finanzas: el devengado del mes que viene es solo de los 3 vivos', true, abs($porMes[$proximo]['devengado'] - 3 * 690 / 12) < 3.0);
check('finanzas: MRR sin él (3 vivos × Quinta $69)', 3 * 69.0, $fin['mrr']);
check('estado v1: ingreso mensual recurrente sin él', 3 * 69.0, (new TukanCentral($db))->estado($hoy)['extra']['Ingreso mensual recurrente']);
check('estado v1: eventos y usuarios sin los suyos', [$cuenta("SELECT count(*) FROM usuarios WHERE activo AND negocio_id <> {$A['id']}")],
      [(new TukanCentral($db))->estado($hoy)['extra']['Usuarios activos']]);
// Páginas públicas: cada página pública es de un negocio conocido o no es de ningún negocio
$idx = (string) file_get_contents(RAIZ . '/public/index.php');
preg_match('/\$publicas = \[(.*?)\];/s', $idx, $m);
$publicas = [];
preg_match_all("/'([a-z_]+)'/", $m[1], $mm);
$publicas = $mm[1];
foreach ([...(preg_match_all('/array_push\(\$publicas, ([^)]*)\)/', $idx, $mm2) ? $mm2[1] : []), ...(preg_match_all('/\$publicas\[\] = ([^;]*);/', $idx, $mm3) ? $mm3[1] : [])] as $lista) {
    preg_match_all("/'([a-z_]+)'/", $lista, $mm);
    $publicas = array_merge($publicas, $mm[1]);
}
$sinCubrir = array_values(array_diff($publicas, array_keys(Papelera::RUTAS_PUBLICAS), Papelera::PUBLICAS_SIN_NEGOCIO));
check('Todas las páginas públicas (' . count($publicas) . ') están cubiertas por la papelera', [], $sinCubrir);
$T = $A['tokens'];
$pedidos = [
    'sitio' => ['s' => $A['slug']], 'consulta' => ['s' => $A['slug']], 'disponible' => ['s' => $A['slug']],
    'imagen_publica' => ['tipo' => 'logo', 's' => $A['slug']], 'cot' => ['t' => $T['cot']], 'firmar' => ['t' => $T['contrato']], 'firmar_anexo' => ['t' => $T['anexo']],
    'verificar' => ['c' => strtolower($T['codigo'])], 'verificar_oc' => ['c' => $T['oc']], 'portal' => ['t' => $T['token_cliente']],
    'portal_pagar' => ['t' => $T['token_cliente']], 'portal_invitados' => ['t' => $T['token_cliente']], 'portal_cuestionario' => ['t' => $T['token_cliente']],
    'portal_extras' => ['t' => $T['token_cliente']], 'encuesta' => ['t' => $T['token_encuesta']], 'puerta' => ['t' => $T['token_puerta']], 'vivo' => ['t' => $T['token_vivo']],
    'invitacion' => ['t' => $T['invitado']], 'ingreso' => ['t' => $T['invitado']], 'staff' => ['t' => $T['personal']], 'ical' => ['t' => $T['ical']],
    'portal_proveedor' => ['t' => $T['proveedor']], 'payphone' => ['clientTransactionId' => 'TE' . $T['pago'] . '-0a1b2c3d'],
    // TuRenta (migración 200): reserva en línea y Stripe
    'reservar' => ['s' => $A['slug']], 'stripe_webhook' => ['s' => $A['slug']], 'stripe' => ['session_id' => 'cs_test_papelera0000000000'],
];
$db->exec("INSERT INTO pagos (negocio_id, evento_id, monto, metodo, estado, origen, referencia)
           SELECT negocio_id, evento_id, 10, 'stripe', 'pendiente', 'stripe', 'cs_test_papelera0000000000' FROM pagos WHERE id = " . (int) $T['pago']);
$mal = [];
foreach (Papelera::RUTAS_PUBLICAS as $ruta => $_) {
    if (($pedidos[$ruta] ?? null) === null) { $mal[] = "$ruta sin probar"; continue; }
    if ($pap->negocioDeRuta($ruta, $pedidos[$ruta]) !== $A['id']) $mal[] = $ruta;
}
check('Cada página pública encuentra su negocio por su enlace (' . count(Papelera::RUTAS_PUBLICAS) . ')', [], $mal);
$db->exec("DELETE FROM pagos WHERE referencia = 'cs_test_papelera0000000000'");   // no cambia las filas que se comparan al restaurar
check('Foto pública de un espacio también', $A['id'], $pap->negocioDeRuta('imagen_publica', ['tipo' => 'espacio', 'id' => (string) $A['espacio']]));
check('Enlace que no existe o raro: null', [null, null, null], [$pap->negocioDeRuta('portal', ['t' => 'no-existe']), $pap->negocioDeRuta('sitio', ['s' => ['x']]),
                                                                $pap->negocioDeRuta('login', [])]);
check('Páginas públicas del borrado: "eliminado" (404); del otro: normal', ['eliminado', 'normal'], [$pap->estadoPublico($A['id']), $pap->estadoPublico($B['id'])]);
// Operaciones sobre un negocio en la papelera
check('registrar_pago en la papelera → 409 en_papelera', [409, 'conflicto', 'en_papelera'],
      $codigo($ej('registrar_pago', ['plan' => 'quinta', 'periodo' => 'mensual', 'metodo' => 'transferencia'], $A['id'])));
check('pausar / nota_interna / reactivar en la papelera → 409', [409, 409, 409],
      [$ej('pausar', ['tipo' => 'otro', 'motivo' => 'xyz'], $A['id'])[0], $ej('nota_interna', ['texto' => 'x'], $A['id'])[0], $ej('reactivar', [], $A['id'])[0]]);
$r = $ej('borrar_cliente', ['confirmar' => 'Quinta Para Borrar', 'motivo' => 'Otra vez'], $A['id']);
check('Borrar otra vez: sin_cambios, la misma copia, ninguna copia nueva', [200, true, $copia, $archivosAntes + 1],
      [$r[0], $r[1]['sin_cambios'], $r[1]['copia'], count(glob("$COPIAS/*.json.gz") ?: [])]);
$r = $ej('borrar_cliente', ['confirmar' => 'Quinta Para Borrar', 'motivo' => 'Cerró el local'], $A['id'], $opB);
check('Mismo operacion_id: repetida', [200, true, $copia], [$r[0], $r[1]['repetida'], $r[1]['copia']]);
// Tareas automáticas: nada del borrado se toca
(new Automaticas($db))->correr(new DateTimeImmutable('now'));
check('Tareas: el evento pasado del borrado no pasa a "realizado"', 'confirmado', $db->query("SELECT estado FROM eventos WHERE id = {$A['pasado']}")->fetchColumn());
check('Aislamiento: el otro negocio no cambió en nada', true, $sinAuditoria($pap->datos($B['id'])) == $sinAuditoria($antesB));
check('Los datos del borrado siguen ahí (borrado lógico)', $antesA['eventos'], $pap->datos($A['id'])['eventos']);

// ---------------------------------------------------------------------------------------------
titulo('6. restaurar_cliente: vuelve todo tal cual');
check('Restaurar a uno que no está en la papelera: sin_cambios', [200, true], [$ej('restaurar_cliente', [], $B['id'])[0], $ej('restaurar_cliente', [], $B['id'])[1]['sin_cambios']]);
$r = $ej('restaurar_cliente', [], $A['id']);
check('Restaurar: 200 y fuera de la papelera', [200, false, null], [$r[0], $r[1]['cliente']['eliminado'], $db->query("SELECT eliminado_en FROM negocios WHERE id = {$A['id']}")->fetchColumn() ?: null]);
$despues = $pap->datos($A['id']);
$despues['registro_admin'] = $antesA['registro_admin'] = [];   // la auditoría sí creció (es lo esperado)
check('Restaurar: todas las filas iguales que antes de borrar', true, $despues == $antesA);
check('Restaurar: vuelve a la lista y a las cifras', [true, 4], [in_array($A['id'], $idsDe($clientes()), true), $clientes()['contadores']['total']]);
check('Restaurar: páginas públicas normales', ['normal', 0], [$pap->estadoPublico($A['id']), $clientes()['papelera_total']]);
check('Restaurar: auditoría', 1, $cuenta("SELECT count(*) FROM registro_admin WHERE negocio_id = {$A['id']} AND accion LIKE 'Panel Tukán Central · Restaurado de la papelera%'"));
check('Restaurar otra vez: sin_cambios', true, $ej('restaurar_cliente', [], $A['id'])[1]['sin_cambios']);
check('La copia sigue guardada (1 año)', true, is_file($copia));

// ---------------------------------------------------------------------------------------------
titulo('7. Purga a los 30 días (reloj simulado) y copias de más de 1 año');
$r = $ej('borrar_cliente', ['confirmar' => (string) $A['id'], 'motivo' => 'Ahora sí, cerró'], $A['id']);
check('Borrar escribiendo el número del negocio también sirve', [200, false], [$r[0], $r[1]['sin_cambios']]);
$copia2 = $r[1]['copia'];
check('Restaurar el día 30 → 409 papelera_vencida', [409, 'conflicto', 'papelera_vencida'],
      $codigo($ej('restaurar_cliente', [], $A['id'], null, ['hoy' => $hoy->modify('+30 days')])));
$p = $pap->purgar(new DateTimeImmutable('+29 days'));
check('A los 29 días no se purga nada', [0, 1], [$p['negocios'], $cuenta("SELECT count(*) FROM negocios WHERE id = {$A['id']}")]);
$eventoA = $A['evento'];
$p = $pap->purgar(new DateTimeImmutable('+30 days +1 hour'));
check('A los 30 días se borra de verdad', [1, 0], [$p['negocios'], $cuenta("SELECT count(*) FROM negocios WHERE id = {$A['id']}")]);
$quedan = [];
foreach ($pap->tablas() as $t => $x) {
    if (!$x['propia'] || $t === 'negocios') continue;
    $col = $db->query("SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='$t' AND column_name='negocio_id'")->fetchColumn();
    if ($col && ($n = $cuenta("SELECT count(*) FROM \"$t\" WHERE negocio_id = {$A['id']}")) > 0) $quedan[] = "$t ($n)";
}
check('Purga: no queda ninguna fila suya en las tablas con negocio_id', [], $quedan);
check('Purga: tampoco en las hijas (invitados y mesas de su evento)', [0, 0],
      [$cuenta("SELECT count(*) FROM invitados WHERE evento_id = $eventoA"), $cuenta("SELECT count(*) FROM mesas WHERE evento_id = $eventoA")]);
check('Purga: la auditoría queda sin el negocio (SET NULL) + línea de borrado definitivo', [0, 1],
      [$cuenta("SELECT count(*) FROM registro_admin WHERE negocio_id = {$A['id']}"),
       $cuenta("SELECT count(*) FROM registro_admin WHERE negocio_id IS NULL AND accion LIKE 'Borrado definitivo%Quinta Para Borrar (N.º {$A['id']})%'")]);
check('Purga: el otro negocio sigue igual', true, $sinAuditoria($pap->datos($B['id'])) == $sinAuditoria($antesB));
check('Purga: los archivos del borrado salen de uploads y pasan junto a su copia', [false, false, true, true],
      [is_file(TuRenta\Fotos::carpeta('logos') . '/' . $A['logo']), is_file(TuRenta\Fotos::carpeta('espacios') . '/' . $A['foto']),
       is_file(preg_replace('/\.json\.gz$/', '', $copia2) . '.archivos/logos/' . $A['logo']), is_file($copia2)]);
check('Purga: los archivos del otro negocio siguen', true, is_file(TuRenta\Fotos::carpeta('logos') . '/' . $B['logo']));
check('Después de la purga: el cliente ya no existe (404)', [404, 'no_encontrado'], array_slice($codigo($ej('restaurar_cliente', [], $A['id'])), 0, 2));
check('Purga otra vez: nada', 0, $pap->purgar(new DateTimeImmutable('+31 days'))['negocios']);
// Copias viejas: la de hace más de un año se borra (con sus archivos), la reciente no, y lo que no es una copia no se toca
touch($copia, (new DateTimeImmutable('-366 days'))->getTimestamp());
file_put_contents("$COPIAS/LEEME.txt", 'no tocar');
$p = $pap->purgar(new DateTimeImmutable('now'));
check('Copias de más de 1 año: se borra la vieja, quedan la nueva y lo demás', [1, false, true, true],
      [$p['copias'], is_file($copia), is_file($copia2), is_file("$COPIAS/LEEME.txt")]);
touch($copia2, (new DateTimeImmutable('-400 days'))->getTimestamp());
$pap->purgar(new DateTimeImmutable('now'));
check('… y la carpeta de archivos de la copia se va con ella', [false, false], [is_file($copia2), is_dir(preg_replace('/\.json\.gz$/', '', $copia2) . '.archivos')]);
// La tarea de cada hora (bin/tareas.php → Automaticas::correr) hace la purga
$C = $rico('Salón Para La Tarea');
$ej('borrar_cliente', ['confirmar' => 'Salón Para La Tarea', 'motivo' => 'Prueba de la tarea'], $C['id']);
$db->exec("UPDATE negocios SET eliminado_en = now() - interval '31 days' WHERE id = {$C['id']}");
$t = (new Automaticas($db))->correr(new DateTimeImmutable('now'));
check('Tareas automáticas: purga los de más de 30 días', [1, 0], [$t['purgados'], $cuenta("SELECT count(*) FROM negocios WHERE id = {$C['id']}")]);
check('Tareas automáticas: el resumen lo dice', true, str_contains(Automaticas::resumen(new DateTimeImmutable('now'), $t), 'papelera: borrados 1'));
check('Tareas automáticas: sin errores', 0, $t['errores']);
check('Al final: B y P siguen', 2, $cuenta("SELECT count(*) FROM negocios WHERE id IN ({$B['id']}, {$P['id']})"));
