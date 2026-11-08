<?php
/**
 * Conector con el Panel Tukán Central, protocolo v2 (PROTOCOLO_V2.md §11): token v2 (firma, bh, nonce, vida, códigos),
 * transporte (POST, JSON, tamaño), capacidades, clientes (regla única de estados = estado v1), cliente, cotizar, planes,
 * solicitudes y su atención, finanzas, cada operación de "ejecutar" con su efecto real en la base, idempotencia
 * (operacion_id), auditoría en registro_admin, privacidad, aislamiento entre negocios, límite y demo de punta a punta.
 */
use TuRenta\{Altas, PanelTukan, Planes, TukanCentral, TukanCentralError, TukanCentralV2};

$db = base_limpia();
planes_de_antes($db);   // estas pruebas usan los planes de antes (Esencial $19, Salón $39, Quinta $69); los de $20 y $45: logica_112
$hoy = new DateTimeImmutable('today');
$d = fn(string $m) => $hoy->modify($m)->format('Y-m-d');
$SECRETO = str_repeat('ab12', 16);
putenv('TUKAN_CENTRAL_ID=turenta');
putenv("TUKAN_CENTRAL_SECRET=$SECRETO");
putenv('TUKAN_CENTRAL_URL=https://panel.prueba.ec');
putenv('TURENTA_URL=https://turenta.prueba.ec');
$cuenta = fn(string $sql) => (int) $db->query($sql)->fetchColumn();
$b64 = fn($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
const QUITAR = '__quitar__';
const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

/** Token v2 como lo arma el Central (§13.1). $cambios con QUITAR borra la clave. */
$token = function (string $tipo, string $cuerpo, array $cambios = [], ?string $secreto = null) use ($SECRETO, $b64): string {
    $t = time();
    $p = array_merge(['v' => 2, 'tipo' => $tipo, 'inst' => 'turenta', 'email' => 'dimitryfierro@gmail.com', 'nombre' => 'Dimitry', 'rol' => 'dueno',
                      'iat' => $t, 'exp' => $t + ($tipo === 'ejecutar' ? 30 : 60), 'nonce' => bin2hex(random_bytes(16)),
                      'bh' => $b64(hash('sha256', $cuerpo, true))], $cambios);
    $p = array_filter($p, fn($v) => $v !== QUITAR);
    $a = $b64(json_encode($p, FLAGS));
    return $a . '.' . $b64(hash_hmac('sha256', $a, $secreto ?? $SECRETO, true));
};
/**
 * Pedido v2 completo: $pedir('clientes', ['pagina' => 1]) -> [http, json, cabeceras].
 * $o: metodo, ct, ip, hoy, cambios (del token), token (ya armado), enviado (cuerpo distinto al firmado), secreto
 */
$pedir = function (string $accion, array|string $cuerpo = [], array $o = []) use ($db, $token): array {
    $c = is_string($cuerpo) ? $cuerpo : json_encode($cuerpo === [] ? new stdClass() : $cuerpo, FLAGS);
    if (!isset($o['ip'])) $db->exec('DELETE FROM tukan_central_pedidos');
    $t = $o['token'] ?? $token($o['tipo'] ?? $accion, $c, $o['cambios'] ?? [], $o['secreto'] ?? null);
    return (new TukanCentralV2($db, $o['hoy'] ?? null))->procesar($accion, $o['metodo'] ?? 'POST', $o['ct'] ?? 'application/json; charset=utf-8',
                                                                 $o['enviado'] ?? $c, $t, $o['ip'] ?? '198.51.100.20');
};
/** ejecutar: $ej('registrar_pago', [...datos], cliente_id, operacion_id) -> [http, json] */
$ej = function (string $op, array $datos = [], int|string|null $cliente = null, ?string $opId = null, array $o = []) use ($pedir): array {
    $cuerpo = ['operacion_id' => $opId ?? bin2hex(random_bytes(16)), 'accion' => $op];
    if ($cliente !== null) $cuerpo['cliente_id'] = (string) $cliente;
    $cuerpo['datos'] = $datos === [] ? new stdClass() : $datos;
    return $pedir('ejecutar', $cuerpo, $o);
};
$codigo = fn(array $r) => [$r[0], $r[1]['codigo'] ?? null];
$sols = function (array $filtro = [], ?DateTimeImmutable $h = null) use ($pedir): array {
    $r = $pedir('solicitudes', $filtro + ['por_pagina' => 200], $h ? ['hoy' => $h] : []);
    return $r[1]['solicitudes'] ?? [];
};
$ids = fn(array $lista) => array_column($lista, 'id');

// ---------------------------------------------------------------------------------------------
titulo('1. Negocios de prueba en los 5 estados (con uno en gracia y uno que vence en 7 días exactos)');
$planes = new Planes($db);
$panel = new PanelTukan($db);
$nuevo = function (string $nombre, string $plan = 'quinta', string $origen = 'registro') use ($db): int {
    $a = (new Altas($db))->crearNegocioConDueno($nombre, 'Dueño ' . $nombre, '0990000000', 'd' . bin2hex(random_bytes(4)) . '@prueba.ec', 'claveSegura1', $plan, 'Quito', $origen);
    return $a['negocio_id'];
};
$pagar = function (int $id, string $plan, string $periodo, string $inicio, ?float $monto, string $pagado, ?string $hasta = null, string $metodo = 'transferencia') use ($db, $planes): int {
    $sid = $planes->suscribir($id, $plan, $periodo, new DateTimeImmutable($inicio), $metodo, null, $monto, new DateTimeImmutable($pagado));
    if ($hasta) {
        $db->prepare('UPDATE suscripciones SET fin = ? WHERE id = ?')->execute([$hasta, $sid]);
        $db->prepare('UPDATE negocios SET activo_hasta = ? WHERE id = ?')->execute([$hasta, $id]);
    }
    return $sid;
};
$P1 = $nuevo('Quinta Prueba Uno');                      // prueba (faltan 7)
$P2 = $nuevo('Salón Termina Hoy', 'salon');             // prueba, termina hoy
$db->exec("UPDATE negocios SET prueba_hasta = CURRENT_DATE WHERE id = $P2");
$P3 = $nuevo('Quinta Faltan Dos');                      // prueba, faltan 2
$db->exec("UPDATE negocios SET prueba_hasta = CURRENT_DATE + 2 WHERE id = $P3");
$O1 = $nuevo('Hacienda Del Panel', 'quinta', 'super');  // prueba, creada por el super
$A1 = $nuevo('Quinta Vence En Siete');                  // pagado, vence en 7 días exactos -> por_vencer
$pagoA1 = $pagar($A1, 'quinta', 'mensual', $d('-23 days'), null, $d('today'), $d('+7 days'));
$A3 = $nuevo('Quinta Vence En Tres');                   // pagado, vence en 3 días -> por_vencer (+ renovación)
$pagoA3 = $pagar($A3, 'quinta', 'mensual', $d('-27 days'), null, $d('-10 days'), $d('+3 days'));
$A2 = $nuevo('Salón Anual Al Día', 'salon');            // pagado 1 año -> activo (+ dominio gratis)
$pagoA2 = $pagar($A2, 'salon', 'anual', $d('today'), null, $d('today'));
$G1 = $nuevo('Salón En Gracia', 'salon');               // venció ayer: en gracia -> vencido
$pagar($G1, 'salon', 'mensual', $d('-1 month -1 day'), null, $d('-1 month'), $d('-1 day'));
$V1 = $nuevo('Salón Vencido Hace Diez', 'salon');       // venció hace 10 días -> vencido
$pagar($V1, 'salon', 'mensual', $d('-40 days'), 30.0, $d('-40 days'), $d('-10 days'), 'deuna');
$T1 = $nuevo('Quinta Prueba Terminada');                // prueba terminada -> vencido
$db->exec("UPDATE negocios SET prueba_hasta = CURRENT_DATE - 3 WHERE id = $T1");
$S1 = $nuevo('Salón Suspendido', 'salon');
$db->exec("UPDATE negocios SET estado = 'suspendido' WHERE id = $S1");
$C1 = $nuevo('Salón Cancelado', 'salon');
$db->exec("UPDATE negocios SET estado = 'cancelado' WHERE id = $C1");
$anulado = $pagar($P1, 'quinta', 'mensual', $d('today'), 500.0, $d('today'));
$planes->anularPago($anulado);
$db->exec("UPDATE negocios SET estado = 'prueba' WHERE id = $P1");
$cli = (int) $db->query("INSERT INTO clientes (negocio_id, nombre, telefono) VALUES ($A2, 'Cliente Secreto Del Salón', '0987654321') RETURNING id")->fetchColumn();
// Ya no hay registro abierto con periodo de prueba: llegan pedidos de demo (demo_pedida) y pedidos de módulos desde Mi plan (cambio_modulos)
$pedidosDemo = new \TuRenta\PedidosDemo($db);
$PD1 = $pedidosDemo->crear(['nombre' => 'Rosa Pérez', 'negocio' => 'Hacienda La Pedida', 'ciudad' => 'Ibarra', 'whatsapp' => '0981112233',
                            'email' => 'rosa@pedida.ec', 'necesita' => 'Tenemos 3 salones', 'modulos' => ['invitados', 'plano']]);
$PD2 = $pedidosDemo->crear(['nombre' => 'Luis Mora', 'negocio' => 'Salón Los Pinos', 'whatsapp' => '0982223344', 'email' => 'luis@pinos.ec']);
$pedidosDemo->marcar($PD2, 'contactada');
$PM1 = (new \TuRenta\Modulos($db))->pedir($A2, null, ['dia_evento'], 'Para las bodas grandes');
check('12 negocios', 12, $cuenta('SELECT count(*) FROM negocios'));
check('El origen se guarda (registro / super)', ['registro', 'super'], [$db->query("SELECT origen FROM negocios WHERE id = $P1")->fetchColumn(),
                                                                       $db->query("SELECT origen FROM negocios WHERE id = $O1")->fetchColumn()]);
check('suscribir() guarda la fecha del pago', $d('-10 days'), $db->query("SELECT pagado_en FROM suscripciones WHERE id = $pagoA3")->fetchColumn());
check('suscribir() sin fecha: hoy', $d('today'), $db->query("SELECT pagado_en FROM suscripciones WHERE id = $pagoA2")->fetchColumn());
$v2 = new TukanCentralV2($db);
$e = (new TukanCentral($db))->estado($hoy);
$esperado = ['total' => 12, 'prueba' => 4, 'activos' => 1, 'por_vencer' => 2, 'vencidos' => 3, 'suspendidos' => 2];
check('estado v1 con la regla única (§4.2)', $esperado, $e['negocios']);

// ---------------------------------------------------------------------------------------------
titulo('2. Transporte y token v2 (§2, §3)');
$r = $pedir('capacidades');
check('Pedido correcto: 200', 200, $r[0]);
check('Respuesta: ok, protocolo 2, acción y producto', [true, 2, 'capacidades', 'turenta'], [$r[1]['ok'], $r[1]['protocolo'], $r[1]['accion'], $r[1]['producto']]);
check('generado con zona -05:00', true, (bool) preg_match('/-05:00$/', $r[1]['generado']));
$c = json_encode(['pagina' => 1], FLAGS);
check('Token sin bh → 401 firma', [401, 'firma'], $codigo($pedir('clientes', $c, ['cambios' => ['bh' => QUITAR]])));
check('bh de otro cuerpo → 401 firma', [401, 'firma'], $codigo($pedir('clientes', $c, ['token' => $token('clientes', '{"pagina":2}')])));
$r = $pedir('clientes', $c, ['enviado' => '{"pagina":2}']);
check('Cuerpo cambiado un byte → 401 firma', [401, 'firma'], $codigo($r));
check('… con detalle parte = cuerpo', ['parte' => 'cuerpo'], $r[1]['detalle'] ?? null);
check('Otro secreto → 401 firma', [401, 'firma'], $codigo($pedir('clientes', $c, ['secreto' => str_repeat('cd34', 16)])));
check('Token de una parte → 401 firma', [401, 'firma'], $codigo($pedir('clientes', $c, ['token' => 'abc'])));
check('Sin token → 401 firma', [401, 'firma'], $codigo($pedir('clientes', $c, ['token' => ''])));
check('GET → 405 invalido', [405, 'invalido'], $codigo($pedir('clientes', $c, ['metodo' => 'GET'])));
check('GET trae cabecera Allow: POST', ['Allow' => 'POST'], $pedir('clientes', $c, ['metodo' => 'GET'])[2]);
check('Content-Type que no es JSON → 415 invalido', [415, 'invalido'], $codigo($pedir('clientes', $c, ['ct' => 'text/plain'])));
check('Cuerpo de más de 64 KiB → 413 invalido', [413, 'invalido'], $codigo($pedir('clientes', '{"x":"' . str_repeat('a', 65536) . '"}')));
check('Cuerpo que no es JSON → 400 invalido', [400, 'invalido'], $codigo($pedir('clientes', 'esto no es json')));
check('Lista JSON en vez de objeto → 400 invalido', [400, 'invalido'], $codigo($pedir('clientes', '[1,2]')));
check('Cuerpo vacío "{}" sirve', 200, $pedir('clientes', '{}')[0]);
check('v:1 en una acción v2 → 401 version', [401, 'version'], $codigo($pedir('clientes', $c, ['cambios' => ['v' => 1]])));
check('tipo distinto de la acción → 401 tipo', [401, 'tipo'], $codigo($pedir('clientes', $c, ['tipo' => 'planes'])));
check('Otra instancia → 401 inst', [401, 'inst'], $codigo($pedir('clientes', $c, ['cambios' => ['inst' => 'tusalon']])));
$t = time();
check('Lectura con exp - iat = 61 → 401 invalido', [401, 'invalido'], $codigo($pedir('clientes', $c, ['cambios' => ['iat' => $t, 'exp' => $t + 61]])));
check('Lectura con exp - iat = 60 → 200', 200, $pedir('clientes', $c, ['cambios' => ['iat' => $t, 'exp' => $t + 60]])[0]);
$cE = json_encode(['operacion_id' => bin2hex(random_bytes(16)), 'accion' => 'nota_interna', 'cliente_id' => (string) $P1, 'datos' => ['texto' => 'x']], FLAGS);
check('ejecutar con exp - iat = 31 → 401 invalido', [401, 'invalido'], $codigo($pedir('ejecutar', $cE, ['cambios' => ['iat' => $t, 'exp' => $t + 31]])));
check('Vencido → 401 vencido', [401, 'vencido'], $codigo($pedir('clientes', $c, ['cambios' => ['iat' => $t - 100, 'exp' => $t - 40]])));
check('Futuro → 401 futuro', [401, 'futuro'], $codigo($pedir('clientes', $c, ['cambios' => ['iat' => $t + 45, 'exp' => $t + 90]])));
check('Fechas como texto → 401 invalido', [401, 'invalido'], $codigo($pedir('clientes', $c, ['cambios' => ['iat' => (string) $t]])));
$tk = $token('clientes', $c);
check('Primera vez: 200', 200, $pedir('clientes', $c, ['token' => $tk])[0]);
check('Token repetido → 401 nonce', [401, 'nonce'], $codigo($pedir('clientes', $c, ['token' => $tk])));
check('Nonce mal formado → 401 nonce', [401, 'nonce'], $codigo($pedir('clientes', $c, ['cambios' => ['nonce' => 'ABC']])));
$n = bin2hex(random_bytes(16));
$pedir('clientes', $c, ['cambios' => ['nonce' => $n, 'bh' => 'malo']]);
check('Un token roto (bh malo) no gasta su nonce', 200, $pedir('clientes', $c, ['cambios' => ['nonce' => $n]])[0]);
check('Correo inválido → 401 invalido', [401, 'invalido'], $codigo($pedir('clientes', $c, ['cambios' => ['email' => 'no-es-correo']])));
check('Acción que no existe en el protocolo → 400 invalido', [400, 'invalido'], $codigo($pedir('borrar_todo', $c)));
$r = $pedir('adjunto', ['solicitud_id' => 'x:1', 'adjunto_id' => '1']);
check('adjunto (TuRenta no tiene archivos) → 403 no_permitido', [403, 'no_permitido'], $codigo($r));
check('… motivo no_disponible', ['motivo' => 'no_disponible'], $r[1]['detalle'] ?? null);
putenv('TUKAN_CENTRAL_SECRET=corto');
check('Sin configurar → 404 sin_configurar', [404, 'sin_configurar'], $codigo($pedir('clientes', $c)));
putenv("TUKAN_CENTRAL_SECRET=$SECRETO");
$todos = [];
foreach ([$pedir('clientes', $c, ['metodo' => 'GET']), $pedir('clientes', 'x'), $pedir('clientes', $c, ['tipo' => 'planes']),
          $pedir('cliente', ['id' => '999999']), $pedir('cliente', ['id' => 'a b']), $ej('aprobar_comprobante', [], $P1)] as $x) $todos[] = $x[1];
check('Todos los errores: ok=false, protocolo 2, código de la lista y error en español', true,
      array_filter($todos, fn($j) => $j['ok'] !== false || $j['protocolo'] !== 2 || !in_array($j['codigo'], TukanCentralError::CODIGOS, true)
                                     || !is_string($j['error']) || $j['error'] === '') === []);

// ---------------------------------------------------------------------------------------------
titulo('3. Límite: 121 pedidos en un minuto → 429 limite (y 30 rechazados)');
$db->exec('DELETE FROM tukan_central_pedidos');
$cods = [];
for ($i = 0; $i < 121; $i++) $cods[] = $pedir('clientes', $c, ['ip' => '203.0.113.77', 'metodo' => 'GET'])[0];
check('Los primeros 120 se atienden (405 por GET)', 120, count(array_filter(array_slice($cods, 0, 120), fn($x) => $x === 405)));
$r = $pedir('capacidades', [], ['ip' => '203.0.113.77']);
check('El 121 (y siguientes) → 429 limite', [429, 'limite', 429], [$cods[120], $r[1]['codigo'], $r[0]]);
check('Con Retry-After: 60', ['Retry-After' => '60'], $r[2]);
check('Otra IP pasa', 200, $pedir('capacidades', [], ['ip' => '203.0.113.78'])[0]);
for ($i = 0; $i < 30; $i++) $pedir('clientes', $c, ['ip' => '203.0.113.79', 'secreto' => str_repeat('cd34', 16)]);
check('30 rechazados (401) → el siguiente correcto se frena', [429, 'limite'], $codigo($pedir('capacidades', [], ['ip' => '203.0.113.79'])));
$db->exec('DELETE FROM tukan_central_pedidos');

// ---------------------------------------------------------------------------------------------
titulo('4. capacidades (§5.1): solo lo que de verdad funciona');
$cap = $pedir('capacidades')[1];
check('Acciones', ['capacidades', 'estado', 'sso', 'clientes', 'cliente', 'cotizar', 'planes', 'modulos', 'solicitudes', 'solicitud', 'finanzas', 'ejecutar', 'ia_uso'], $cap['acciones']);
check('Sin adjunto', false, in_array('adjunto', $cap['acciones'], true));
check('Operaciones', TukanCentralV2::OPERACIONES, $cap['ejecutar']);
check('Sin comprobantes ni tickets', [], array_intersect($cap['ejecutar'], ['aprobar_comprobante', 'rechazar_comprobante', 'responder_ticket']));
check('Tipos de solicitud (sin registro_nuevo: no hay registro abierto)', ['demo_pedida', 'cambio_modulos', 'seguimiento_prueba', 'renovacion', 'dominio_gratis'], $cap['tipos_solicitud']);
check('Datos básicos', [false, 'USD', 'America/Guayaquil', 'TuRenta', true, false, true],
      [$cap['unico'], $cap['moneda'], $cap['zona_horaria'], $cap['nombre'], $cap['sso_ir'], $cap['meses_libres'], $cap['planes_editar']]);
check('rutas_sso', ['/?r=admin'], $cap['rutas_sso']);
check('Períodos con los meses que recibe', [['periodo' => 'mensual', 'meses' => 1], ['periodo' => 'semestral', 'meses' => 6], ['periodo' => 'anual', 'meses' => 12]], $cap['periodos']);
check('Métodos de pago de TuRenta', array_keys(PanelTukan::METODOS), array_column($cap['metodos_pago'], 'metodo'));
check('Formulario propio de crear demo: ciudad y módulos', ['ciudad', 'modulos'], array_column($cap['formularios']['crear_demo'], 'campo'));
check('Estados propios con nombre', true, isset($cap['estados_detalle']['gracia'], $cap['estados_detalle']['cancelado'], $cap['estados_detalle']['prueba_vencida']));
check('Límites', ['por_pagina_max' => 200, 'meses_finanzas_max' => 24, 'dias_prueba_max' => 90], $cap['limites']);
check('Versión = commit de git', TukanCentral::version(RAIZ), $cap['version']);
check('url_publica', 'https://turenta.prueba.ec', $cap['url_publica']);

// ---------------------------------------------------------------------------------------------
titulo('5. clientes (§5.4): contadores = estado v1, filtros, orden y páginas');
$r = $pedir('clientes', ['por_pagina' => 200]);
$lista = $r[1]['clientes'];
$por = [];
foreach ($lista as $x) $por[(int) $x['id']] = $x;
check('Total', 12, $r[1]['total']);
check('contadores', ['total' => 12, 'prueba' => 4, 'activo' => 1, 'por_vencer' => 2, 'vencido' => 3, 'suspendido' => 2], $r[1]['contadores']);
$ev1 = (new TukanCentral($db))->estado($hoy)['negocios'];
check('contadores = estado.negocios (mismos números)', [$ev1['total'], $ev1['prueba'], $ev1['activos'], $ev1['por_vencer'], $ev1['vencidos'], $ev1['suspendidos']],
      array_values($r[1]['contadores']));
check('Estados de cada uno', ['prueba', 'prueba', 'prueba', 'prueba', 'por_vencer', 'por_vencer', 'activo', 'vencido', 'vencido', 'vencido', 'suspendido', 'suspendido'],
      array_map(fn($i) => $por[$i]['estado'], [$P1, $P2, $P3, $O1, $A1, $A3, $A2, $G1, $V1, $T1, $S1, $C1]));
check('Vence en 7 días exactos: por_vencer, faltan 7', ['por_vencer', 7, $d('+7 days')], [$por[$A1]['estado'], $por[$A1]['fechas']['dias_para_vencer'], $por[$A1]['fechas']['vence']]);
check('En gracia: vencido, en_gracia, no bloqueado', ['vencido', 'gracia', true, false], [$por[$G1]['estado'], $por[$G1]['estado_detalle'], $por[$G1]['en_gracia'], $por[$G1]['bloqueado']]);
check('Prueba terminada: vencido y bloqueado', ['vencido', 'prueba_vencida', false, true], [$por[$T1]['estado'], $por[$T1]['estado_detalle'], $por[$T1]['en_gracia'], $por[$T1]['bloqueado']]);
check('Cancelado cuenta como suspendido', ['suspendido', 'cancelado', true], [$por[$C1]['estado'], $por[$C1]['estado_detalle'], $por[$C1]['bloqueado']]);
check('Prueba que termina hoy sigue en prueba', ['prueba', 0], [$por[$P2]['estado'], $por[$P2]['fechas']['dias_para_vencer']]);
check('Vencido: días negativos', -10, $por[$V1]['fechas']['dias_para_vencer']);
$x = $por[$A2];
check('Forma del cliente (§4.3)', ['id', 'nombre', 'tipo', 'tipo_nombre', 'ciudad', 'plan', 'estado', 'estado_detalle', 'estado_texto', 'en_gracia', 'bloqueado',
      'fechas', 'dueno', 'ultima_actividad', 'pagos', 'mensual_estimado', 'modulos', 'origen', 'vendedor', 'uso', 'ir', 'ir_trabajar', 'actualizado',
      'pausa', 'eliminado', 'papelera'], array_keys($x));   // + Anexo A y B
check('id como texto y plan', [(string) $A2, ['codigo' => 'salon', 'nombre' => 'Salón']], [$x['id'], $x['plan']]);
check('Dueño con WhatsApp normalizado', ['Dueño Salón Anual Al Día', '0990000000', '593990000000'], [$x['dueno']['nombre'], $x['dueno']['telefono'], $x['dueno']['whatsapp']]);
check('Pagos (sin anulados) y mensual estimado = precio de su plan con módulos', [390.0, 1, $d('today'), 390.0, 39.0], [$x['pagos']['total'], $x['pagos']['cantidad'], $x['pagos']['primer_pago'], $x['pagos']['ultimo_monto'], $x['mensual_estimado']]);
check('El pago anulado no cuenta', [0.0, 0], [$por[$P1]['pagos']['total'], $por[$P1]['pagos']['cantidad']]);
check('Uso', ['Espacios' => 2, 'Eventos (30 días)' => 0, 'Eventos vendidos' => 0, 'Usuarios' => 1], $x['uso']);
check('Ficha en el súper panel', "/?r=admin_negocio&id=$A2", $x['ir']);
check('Origen', ['registro', 'super'], [$x['origen'], $por[$O1]['origen']]);
check('Suma de mensual_estimado = MRR', $panel->ingresoMensual($hoy), array_sum(array_column($lista, 'mensual_estimado')));
check('Dinero como número con decimales', true, str_contains(json_encode($r[1], FLAGS), '"total":390.0'));
$f = fn(array $b) => array_map('intval', array_column($pedir('clientes', $b + ['por_pagina' => 200])[1]['clientes'], 'id'));
check('Filtro por estado (por_vencer), ordenados por vencimiento', [$A3, $A1], $f(['estados' => ['por_vencer']]));
check('Filtro por varios estados', 6, count($f(['estados' => ['vencido', 'por_vencer', 'activo']])));
check('Buscar por nombre', [$G1], $f(['buscar' => 'gracia']));
check('Buscar por correo del dueño', 1, count($f(['buscar' => $db->query("SELECT email FROM usuarios WHERE negocio_id = $V1")->fetchColumn()])));
check('Filtro por plan', 6, count($f(['plan' => 'salon'])));
check('Filtro por origen', [$O1], $f(['origen' => 'super']));
check('Filtro vence_desde / vence_hasta', [$A3, $A1], $f(['vence_desde' => $d('+1 day'), 'vence_hasta' => $d('+7 days'), 'estados' => ['por_vencer']]));
check('Filtro por ids', [$A1, $A2], array_values(array_intersect([$A1, $A2], $f(['ids' => [(string) $A1, (string) $A2]]))));
check('Orden por vencimiento: primero el que venció antes', [$V1, $T1], array_slice($f([]), 0, 2));
check('Orden por alta: el más nuevo primero', $C1, $f(['orden' => 'alta'])[0]);
check('Orden por nombre', 'Hacienda Del Panel', $pedir('clientes', ['orden' => 'nombre'])[1]['clientes'][0]['nombre']);
$db->exec("UPDATE usuarios SET ultimo_ingreso = now() WHERE negocio_id = $A2");
check('sin_uso_dias: deja fuera al que entró hoy', false, in_array($A2, $f(['sin_uso_dias' => 15]), true));
check('sin_uso_dias: incluye al que nunca entró', true, in_array($A1, $f(['sin_uso_dias' => 15]), true));
$r = $pedir('clientes', ['por_pagina' => 4, 'pagina' => 3]);
check('Páginas', [12, 3, 4, 3, 4], [$r[1]['total'], $r[1]['pagina'], $r[1]['por_pagina'], $r[1]['paginas'], count($r[1]['clientes'])]);
check('Página vacía más allá del final', 0, count($pedir('clientes', ['por_pagina' => 50, 'pagina' => 9])[1]['clientes']));
$r = $pedir('clientes', ['estados' => ['en_gracia']]);
check('Estado que no existe → 422 con campo', [422, 'invalido', 'estados'], [$r[0], $r[1]['codigo'], $r[1]['campo']]);
check('por_pagina 500 → 422', [422, 'por_pagina'], [$pedir('clientes', ['por_pagina' => 500])[0], $pedir('clientes', ['por_pagina' => 500])[1]['campo']]);
check('Fecha mal → 422', [422, 'vence_desde'], [$pedir('clientes', ['vence_desde' => '13/10/2026'])[0], $pedir('clientes', ['vence_desde' => '2026-13-01'])[1]['campo']]);
check('Orden desconocido → 422', 422, $pedir('clientes', ['orden' => 'precio'])[0]);
$json = json_encode($pedir('clientes', ['por_pagina' => 200])[1], FLAGS);
check('Privacidad: sin contraseñas ni datos de los clientes de cada negocio', false,
      str_contains($json, 'password') || str_contains($json, '$2y$') || str_contains($json, 'Cliente Secreto') || str_contains($json, '0987654321'));
check('Privacidad: sin RUC ni notas internas en la lista', false, str_contains($json, '1790000000001') || str_contains($json, 'notas_admin'));

// ---------------------------------------------------------------------------------------------
titulo('6. cliente (§5.5), cotizar (§5.6) y planes (§5.7)');
$x = $pedir('cliente', ['id' => (string) $P1])[1]['cliente'];
check('Ficha: resumen + detalle', true, isset($x['notas_admin'], $x['pagos_detalle'], $x['registro'], $x['mensajes_sugeridos'], $x['acciones_posibles'], $x['enlace_publico']));
check('pagos_detalle incluye el anulado', [1, true, false, 500.0], [count($x['pagos_detalle']), $x['pagos_detalle'][0]['anulado'], $x['pagos_detalle'][0]['puede_anular'], $x['pagos_detalle'][0]['monto']]);
check('Enlace público', 'https://turenta.prueba.ec/?r=sitio&s=' . $db->query("SELECT slug FROM negocios WHERE id = $P1")->fetchColumn(), $x['enlace_publico']);
check('Mensaje para recordar la prueba', true, str_contains((string) $x['mensajes_sugeridos']['recordar_prueba'], 'Tu demo de Quinta Prueba Uno termina el'));
check('Sin mensaje de vencido', null, $x['mensajes_sugeridos']['vencido']);
$acc = array_column($x['acciones_posibles'], 'accion');
check('Botones de una demo', ['whatsapp', 'registrar_pago', 'extender_prueba', 'cambiar_plan', 'cambiar_modulos', 'pausar', 'cancelar', 'nueva_clave_dueno', 'nota_interna', 'ia_tope', 'borrar_cliente', 'abrir'], $acc);
$pago = $x['acciones_posibles'][1];
check('Formulario de pago (§4.4)', ['plan', 'periodo', 'monto', 'metodo', 'fecha', 'inicio', 'nota', 'dominio'], array_column($pago['campos'], 'campo'));
check('… con datos fijos y cotizar', [['cliente_id' => (string) $P1], true, 69.0], [$pago['datos'], $pago['campos'][2]['cotizar'], $pago['campos'][2]['valor']]);
check('Solicitudes abiertas del cliente (en demo, sin registro_nuevo)', [], array_column($x['solicitudes'], 'id'));
$x2 = $pedir('cliente', ['id' => (string) $A2])[1]['cliente'];
check('Solicitudes abiertas de quien pidió un módulo', true, in_array("cambio_modulos:$PM1", array_column($x2['solicitudes'], 'id'), true));
check('A quien paga no se le ofrece alargar la prueba', false, in_array('extender_prueba', array_column($x2['acciones_posibles'], 'accion'), true));
check('Mensaje de renovación con el precio', true, str_contains((string) $x2['mensajes_sugeridos']['recordar_renovacion'], '$39,00 + IVA al mes'));
check('Suspendido: botón reactivar', true, in_array('reactivar', array_column($pedir('cliente', ['id' => (string) $S1])[1]['cliente']['acciones_posibles'], 'accion'), true));
check('Cliente que no existe → 404 no_encontrado', [404, 'no_encontrado'], $codigo($pedir('cliente', ['id' => '999999'])));
check('Sin id → 422', [422, 'id'], [$pedir('cliente', [])[0], $pedir('cliente', [])[1]['campo']]);
$r = $pedir('cotizar', ['cliente_id' => (string) $A2, 'plan' => 'quinta', 'periodo' => 'semestral']);
$hastaA2 = $db->query("SELECT activo_hasta FROM negocios WHERE id = $A2")->fetchColumn();
$desde = (new DateTimeImmutable($hastaA2))->modify('+1 day');
check('cotizar: Quinta semestral = 5 × 69, recibe 6', [345.0, 6, false], [$r[1]['monto'], $r[1]['meses'], $r[1]['incluye_dominio']]);
check('cotizar: desde el día después de lo pagado', [$desde->format('Y-m-d'), $desde->modify('+6 months -1 day')->format('Y-m-d')], [$r[1]['desde'], $r[1]['hasta']]);
check('cotizar: detalle', 'Quinta semestral (paga 5, recibe 6)', $r[1]['detalle']);
$r = $pedir('cotizar', ['cliente_id' => (string) $V1, 'periodo' => 'anual']);
check('cotizar: vencido empieza hoy; anual con dominio; plan actual', [$d('today'), 390.0, true], [$r[1]['desde'], $r[1]['monto'], $r[1]['incluye_dominio']]);
check('cotizar: período que no existe → 422', [422, 'periodo'], [$pedir('cotizar', ['cliente_id' => (string) $V1, 'periodo' => 'trimestral'])[0],
                                                               $pedir('cotizar', ['cliente_id' => (string) $V1, 'periodo' => 'trimestral'])[1]['campo']]);
check('cotizar: meses libres no → 422 meses', 'meses', $pedir('cotizar', ['cliente_id' => (string) $V1, 'meses' => 2])[1]['campo']);
check('cotizar: plan que no existe → 422', 'plan', $pedir('cotizar', ['cliente_id' => (string) $V1, 'plan' => 'oro', 'periodo' => 'mensual'])[1]['campo']);
$r = $pedir('planes')[1];
check('planes: editable y 3 planes (Esencial, Salón, Quinta)', [true, ['esencial', 'salon', 'quinta']], [$r['editable'], array_column($r['planes'], 'codigo')]);
check('planes: precios de Salón', [39.0, 195.0, 390.0], array_column($r['planes'][1]['precios'], 'precio'));
check('planes: meses gratis', [0, 1, 2], array_column($r['planes'][1]['precios'], 'meses_gratis'));
check('planes: clientes por plan', [0, 6, 6], array_column($r['planes'], 'clientes'));
check('planes: límites', ['Espacios' => '2', 'Usuarios' => '3'], $r['planes'][1]['limites']);
check('planes: módulos de cada plan (Esencial = solo la base, Quinta = todos)', [[], 10, count(\TuRenta\Modulos::todos()) + 1],
      [$r['planes'][0]['modulos'], count($r['planes'][1]['modulos']), count($r['planes'][2]['modulos'])]);
check('planes: ajustes (registro cerrado: todo se pide demo)', ['dias_prueba' => 7, 'dias_demo' => 7, 'dias_gracia' => 3, 'dias_gracia_demo' => 0, 'registro_abierto' => false], $r['ajustes']);

// ---------------------------------------------------------------------------------------------
titulo('7. solicitudes (§5.8, §5.9): registros nuevos y recordatorios');
$ab = $sols();
$tipos = array_count_values(array_column($ab, 'tipo'));
check('Sin registros nuevos (no hay registro abierto)', 0, $tipos['registro_nuevo'] ?? 0);
check('Pedidos de demo abiertos (nuevo y contactado)', 2, $tipos['demo_pedida'] ?? 0);
check('Pedido de módulos desde Mi plan', 1, $tipos['cambio_modulos'] ?? 0);
check('Seguimiento de prueba: termina hoy, faltan 2 y terminada', ["seguimiento_prueba:$P2:vence_hoy:" . $d('today'), "seguimiento_prueba:$P3:faltan_2:" . $d('+2 days'),
      "seguimiento_prueba:$T1:terminada:" . $d('-3 days')], array_values(array_filter($ids($ab), fn($i) => str_starts_with($i, 'seguimiento_prueba:'))));
check('Renovación: vence en 3 días y los dos vencidos', ["renovacion:$G1:vencida:" . $d('-1 day'), "renovacion:$V1:vencida:" . $d('-10 days'), "renovacion:$A3:vence_pronto:" . $d('+3 days')],
      array_values(array_filter($ids($ab), fn($i) => str_starts_with($i, 'renovacion:'))));
check('Dominio gratis del pago anual', ["dominio_gratis:$pagoA2"], array_values(array_filter($ids($ab), fn($i) => str_starts_with($i, 'dominio_gratis:'))));
check('Total de abiertas', 10, count($ab));
check('estado v1: solicitudes_abiertas', 10, (new TukanCentral($db))->estado($hoy)['solicitudes_abiertas']);
check('estado v1: huella = la de la lista', TukanCentralV2::huella($ab), (new TukanCentral($db))->estado($hoy)['solicitudes_huella']);
$s = array_values(array_filter($ab, fn($x) => $x['id'] === "seguimiento_prueba:$P2:vence_hoy:" . $d('today')))[0];
check('Forma de la solicitud', ['id', 'tipo', 'categoria', 'titulo', 'fecha', 'actualizado', 'cliente_id', 'quien', 'resumen', 'monto', 'estado', 'estado_producto',
      'prioridad', 'vence_el', 'detalle', 'adjuntos', 'acciones_posibles', 'ir'], array_keys($s));
check('Recordatorio de alta prioridad', ['recordatorio', 'pendiente', 'alta', 'vence_hoy'], [$s['categoria'], $s['estado'], $s['prioridad'], $s['detalle']['etapa']]);
check('Quién', ['Dueño Salón Termina Hoy', 'Salón Termina Hoy', '593990000000'], [$s['quien']['nombre'], $s['quien']['organizacion'], $s['quien']['whatsapp']]);
check('Botones: WhatsApp, alargar, pagar, atender, abrir', ['whatsapp', 'extender_prueba', 'registrar_pago', 'atender_solicitud', 'abrir'], array_column($s['acciones_posibles'], 'accion'));
check('Las alta primero', 'alta', $ab[0]['prioridad']);
$r = $pedir('solicitudes', ['tipos' => ['renovacion']]);
check('Filtro por tipo y contadores', [3, ['renovacion' => 3]], [$r[1]['total'], (array) $r[1]['contadores']]);
check('Filtro por cliente', ["renovacion:$A3:vence_pronto:" . $d('+3 days')], $ids($sols(['cliente_id' => (string) $A3])));
check('Ninguna cerrada todavía', 0, count($sols(['estado' => 'cerradas'])));
check('solicitud por id', "renovacion:$A3:vence_pronto:" . $d('+3 days'), $pedir('solicitud', ['id' => "renovacion:$A3:vence_pronto:" . $d('+3 days')])[1]['solicitud']['id']);
check('solicitud que no existe → 404', [404, 'no_encontrado'], $codigo($pedir('solicitud', ['id' => 'renovacion:1:x'])));
check('Estado de solicitud desconocido → 422', 422, $pedir('solicitudes', ['estado' => 'nuevas'])[0]);

// ---------------------------------------------------------------------------------------------
titulo('8. finanzas (§5.11): cuadra con los pagos de prueba');
$r = $pedir('finanzas');
$fz = $r[1];
check('12 meses por defecto, en orden, terminando este mes', [12, $hoy->format('Y-m'), $hoy->modify('first day of this month')->modify('-11 months')->format('Y-m')],
      [count($fz['meses']), end($fz['meses'])['mes'], $fz['meses'][0]['mes']]);
$esperados = [];   // pagos no anulados: fecha => monto
foreach ($db->query('SELECT pagado_en, monto, negocio_id FROM suscripciones WHERE NOT anulada')->fetchAll() as $p) {
    $m = substr($p['pagado_en'], 0, 7);
    $esperados[$m]['ingresos'] = ($esperados[$m]['ingresos'] ?? 0) + (float) $p['monto'];
    $esperados[$m]['pagos'] = ($esperados[$m]['pagos'] ?? 0) + 1;
}
$porMes = array_column($fz['meses'], null, 'mes');
$okMeses = true;
foreach ($porMes as $m => $x) {
    if (abs($x['ingresos'] - ($esperados[$m]['ingresos'] ?? 0)) > 0.001 || $x['pagos'] !== ($esperados[$m]['pagos'] ?? 0)) $okMeses = false;
}
check('Ingresos y pagos de cada mes = suma de la base (sin anulados)', true, $okMeses);
$mesHoy = $hoy->format('Y-m');
check('Este mes: 69 + 390 (+ los de este mes del resto)', $esperados[$mesHoy]['ingresos'], $porMes[$mesHoy]['ingresos']);
check('Totales = suma de los meses', [array_sum(array_column($fz['meses'], 'ingresos')), array_sum(array_column($fz['meses'], 'pagos'))], [$fz['totales']['ingresos'], $fz['totales']['pagos']]);
check('MRR = ingreso mensual del panel = lo que pagan al mes con sus módulos (69 + 69 + 39)', [$panel->ingresoMensual($hoy), 177.0], [$fz['mrr'], $fz['mrr']]);
check('Por plan en este mes', true, in_array(['plan' => 'salon', 'nombre' => 'Salón', 'monto' => 390.0, 'pagos' => 1], $porMes[$mesHoy]['por_plan'], true));
check('Por período en este mes (anual 12 meses)', true, in_array(['periodo' => 'anual', 'meses' => 12, 'monto' => 390.0, 'pagos' => 1], $porMes[$mesHoy]['por_periodo'], true));
check('Por forma de pago', 'Transferencia', $porMes[$mesHoy]['por_metodo'][0]['nombre']);
check('Nuevos pagadores = primer pago en el mes', array_sum(array_column($fz['meses'], 'nuevos_pagadores')),
      $cuenta("SELECT count(DISTINCT negocio_id) FROM suscripciones WHERE NOT anulada AND pagado_en >= '" . $fz['meses'][0]['mes'] . "-01'"));
check('Devengado: el anual de este mes es 390 × días del mes / 365 o 366 (más los demás)', true, $porMes[$mesHoy]['devengado'] > 0);
check('Sin costos ni otros ingresos (TuRenta no los conoce)', [[], []], [$porMes[$mesHoy]['costos'], $porMes[$mesHoy]['otros_ingresos']]);
$r = $pedir('finanzas', ['desde' => $hoy->modify('-1 month')->format('Y-m'), 'hasta' => $mesHoy]);
check('Rango de 2 meses', 2, count($r[1]['meses']));
check('Rango al revés → 422', [422, 'desde'], [$pedir('finanzas', ['desde' => '2026-10', 'hasta' => '2026-01'])[0], $pedir('finanzas', ['desde' => '2026-10', 'hasta' => '2026-01'])[1]['campo']]);
check('Más de 24 meses → 422', 422, $pedir('finanzas', ['desde' => '2024-01', 'hasta' => '2026-01'])[0]);
check('24 meses sí', 24, count($pedir('finanzas', ['desde' => '2024-02', 'hasta' => '2026-01'])[1]['meses']));
check('Mes mal escrito → 422', 422, $pedir('finanzas', ['desde' => '2026-1'])[0]);

// ---------------------------------------------------------------------------------------------
titulo('9. ejecutar registrar_pago: idempotencia (§7.1) y auditoría (§7.3)');
$op = bin2hex(random_bytes(16));
$datosPago = ['plan' => 'salon', 'periodo' => 'mensual', 'monto' => 39.0, 'metodo' => 'transferencia', 'fecha' => $d('-2 days'), 'nota' => 'Pichincha 12345'];
$r1 = $ej('registrar_pago', $datosPago, $G1, $op);
check('Pago registrado: 200', 200, $r1[0]);
check('Respuesta', ['ejecutar', 'registrar_pago', $op, false, false], [$r1[1]['accion'], $r1[1]['operacion'], $r1[1]['operacion_id'], $r1[1]['repetida'], $r1[1]['sin_cambios']]);
$finG1 = $hoy->modify('+1 month -1 day')->format('Y-m-d');
check('Resultado: activo hasta un mes desde hoy (estaba vencido)', [$finG1, 39.0, null], [$r1[1]['resultado']['activo_hasta'], $r1[1]['resultado']['monto'], $r1[1]['resultado']['comision']]);
check('Mensaje', 'Pago registrado. Salón En Gracia queda activo hasta el ' . (new DateTimeImmutable($finG1))->format('d/m/Y') . '.', $r1[1]['mensaje']);
check('El cliente actualizado viene en la respuesta', ['activo', $finG1], [$r1[1]['cliente']['estado'], $r1[1]['cliente']['fechas']['activo_hasta']]);
$pid = (int) $r1[1]['resultado']['pago_id'];
check('En la base: pago con la fecha del pago', ['39.00', $d('-2 days'), $d('today'), 'transferencia'],
      array_values($db->query("SELECT monto, pagado_en, inicio, metodo_pago FROM suscripciones WHERE id = $pid")->fetch()));
check('Negocio activo', 'activo', $db->query("SELECT estado FROM negocios WHERE id = $G1")->fetchColumn());
$r2 = $ej('registrar_pago', $datosPago, $G1, $op);
check('Mismo operacion_id con otro token: 200 repetida', [200, true], [$r2[0], $r2[1]['repetida']]);
check('… mismo resultado guardado', $r1[1]['resultado'], $r2[1]['resultado']);
check('Un solo pago nuevo', 2, $cuenta("SELECT count(*) FROM suscripciones WHERE negocio_id = $G1"));
$r3 = $ej('registrar_pago', ['monto' => 1.0] + $datosPago, $G1, $op);
check('Mismo operacion_id con otro monto → 409 conflicto', [409, 'conflicto'], $codigo($r3));
check('Sigue un solo pago nuevo', 2, $cuenta("SELECT count(*) FROM suscripciones WHERE negocio_id = $G1"));
$aud = $db->query("SELECT r.accion, a.email, a.creado_por_central FROM registro_admin r JOIN superadmins a ON a.id = r.admin_id
                    WHERE r.negocio_id = $G1 AND r.accion LIKE 'Panel Tukán Central%'")->fetchAll();
check('Una línea en registro_admin (no dos)', 1, count($aud));
check('… con el correo del Central y la operación', ['dimitryfierro@gmail.com', true, true],
      [$aud[0]['email'], $aud[0]['creado_por_central'], str_contains($aud[0]['accion'], 'Pago registrado: salon mensual $39,00 (Transferencia)') && str_contains($aud[0]['accion'], '(op ' . substr($op, 0, 8) . ')')]);
check('… y la nota', true, str_contains($aud[0]['accion'], 'Pichincha 12345'));
check('La operación quedó guardada como ok', ['ok', 200], array_values($db->query("SELECT estado, http FROM tukan_central_operaciones WHERE operacion_id = '$op'")->fetch()));
$opEnCurso = bin2hex(random_bytes(16));
$cuerpoEnCurso = json_encode(['operacion_id' => $opEnCurso, 'accion' => 'nota_interna', 'cliente_id' => (string) $A1, 'datos' => ['texto' => 'x']], FLAGS);
$db->prepare("INSERT INTO tukan_central_operaciones (operacion_id, accion, cuerpo_hash, estado, email) VALUES (?, 'nota_interna', ?, 'en_curso', 'x@y.ec')")
   ->execute([$opEnCurso, TukanCentralV2::hashCuerpo($cuerpoEnCurso)]);
$r = $pedir('ejecutar', $cuerpoEnCurso);
check('Operación en curso → 409 conflicto (en_curso)', [409, 'conflicto', 'en_curso'], [$r[0], $r[1]['codigo'], $r[1]['detalle']['motivo']]);
$opMal = bin2hex(random_bytes(16));
$r = $ej('registrar_pago', ['metodo' => 'bitcoin'] + $datosPago, $V1, $opMal);
check('Forma de pago que no existe → 422 con campo', [422, 'invalido', 'datos.metodo'], [$r[0], $r[1]['codigo'], $r[1]['campo']]);
$r = $ej('registrar_pago', ['metodo' => 'bitcoin'] + $datosPago, $V1, $opMal);
check('El error queda guardado: el reintento recibe lo mismo (repetida)', [422, 'datos.metodo', true], [$r[0], $r[1]['campo'], $r[1]['repetida']]);
check('Período que no existe → 422', 'datos.periodo', $ej('registrar_pago', ['periodo' => 'trimestral'] + $datosPago, $V1)[1]['campo']);
check('Meses sueltos → 422 (meses_libres false)', 'datos.meses', $ej('registrar_pago', ['metodo' => 'efectivo', 'meses' => 2], $V1)[1]['campo']);
check('Fecha futura → 422', 'datos.fecha', $ej('registrar_pago', ['fecha' => $d('+1 day')] + $datosPago, $V1)[1]['campo']);
check('Fecha de hace más de un año → 422', 'datos.fecha', $ej('registrar_pago', ['fecha' => $d('-400 days')] + $datosPago, $V1)[1]['campo']);
check('Monto como texto → 422', 'datos.monto', $ej('registrar_pago', ['monto' => '39'] + $datosPago, $V1)[1]['campo']);
check('Monto negativo → 422', 'datos.monto', $ej('registrar_pago', ['monto' => -5] + $datosPago, $V1)[1]['campo']);
check('Dominio con pago mensual → 422', 'datos.dominio', $ej('registrar_pago', ['dominio' => 'x.com'] + $datosPago, $V1)[1]['campo']);
check('Sin cliente → 422', [422, 'cliente_id'], [$ej('registrar_pago', $datosPago)[0], $ej('registrar_pago', $datosPago)[1]['campo']]);
check('Cliente que no existe → 404 no_encontrado', [404, 'no_encontrado'], $codigo($ej('registrar_pago', $datosPago, 999999)));
check('Ningún error registró pagos', 1, $cuenta("SELECT count(*) FROM suscripciones WHERE negocio_id = $V1"));
$r = $ej('registrar_pago', ['plan' => 'quinta', 'periodo' => 'anual', 'metodo' => 'efectivo', 'dominio' => 'SalonVencido.com'], $V1);
check('Sin monto: el de cotizar; anual con dominio', [200, 690.0, 'salonvencido.com'], [$r[0], $r[1]['resultado']['monto'],
      $db->query("SELECT dominio FROM suscripciones WHERE id = " . (int) $r[1]['resultado']['pago_id'])->fetchColumn()]);
check('cliente_id dentro de datos también sirve', 200, $ej('nota_interna', ['cliente_id' => (string) $V1, 'texto' => 'Pagó en efectivo'])[0]);
check('Operación que TuRenta no tiene → 403 no_disponible', [403, 'no_permitido', 'no_disponible'],
      [$ej('aprobar_comprobante', ['solicitud_id' => 'comprobante:1'])[0], $ej('aprobar_comprobante', [])[1]['codigo'], $ej('responder_ticket', [])[1]['detalle']['motivo']]);
check('operacion_id mal formado → 422', 'operacion_id', $ej('nota_interna', ['texto' => 'x'], $A1, 'ABC')[1]['campo']);
// Error interno: no muestra detalles técnicos y la operación se puede reintentar
$opInt = bin2hex(random_bytes(16));
$db->exec('ALTER TABLE suscripciones RENAME TO suscripciones_x');
$antes = ini_set('error_log', '/dev/null');
$r = $ej('registrar_pago', $datosPago, $V1, $opInt);
ini_set('error_log', (string) $antes);
$db->exec('ALTER TABLE suscripciones_x RENAME TO suscripciones');
check('Excepción → 500 interno', [500, 'interno'], $codigo($r));
check('… sin detalles técnicos', false, str_contains(json_encode($r[1], FLAGS), 'SQLSTATE') || str_contains(json_encode($r[1], FLAGS), 'suscripciones'));
check('… y se borra la operación (se puede reintentar)', 0, $cuenta("SELECT count(*) FROM tukan_central_operaciones WHERE operacion_id = '$opInt'"));
check('El reintento funciona', 200, $ej('registrar_pago', $datosPago, $V1, $opInt)[0]);

// ---------------------------------------------------------------------------------------------
titulo('10. crear_demo de punta a punta (§6.3)');
$opDemo = bin2hex(random_bytes(16));
$datosDemo = ['nombre' => 'Quinta Demo Central', 'contacto_nombre' => 'Juan Pérez', 'email' => 'Juan.Demo@Ejemplo.com', 'telefono' => '0991234567',
              'plan' => 'quinta', 'dias' => 10, 'solicitud_id' => null, 'enviar_correo' => true, 'extra' => ['ciudad' => 'Cuenca']];
$r = $ej('crear_demo', $datosDemo, null, $opDemo);
check('Demo creada: 200', 200, $r[0]);
$res = $r[1]['resultado'];
$nid = (int) $res['cliente_id'];
check('Mensaje', 'Demo creada: Quinta Demo Central, hasta el ' . $d('+10 days') . '.', $r[1]['mensaje']);
check('Prueba por los días pedidos', [$d('+10 days'), $d('+10 days')], [$res['prueba_hasta'], $db->query("SELECT prueba_hasta FROM negocios WHERE id = $nid")->fetchColumn()]);
check('Accesos', ['https://turenta.prueba.ec/?r=login', 'juan.demo@ejemplo.com', 10], [$res['acceso']['url_panel'], $res['acceso']['usuario'], strlen($res['acceso']['clave'])]);
$u = $db->query("SELECT * FROM usuarios WHERE email = 'juan.demo@ejemplo.com'")->fetch();
check('Entra con la clave devuelta (es la del dueño)', [true, 'dueno', $nid], [password_verify($res['acceso']['clave'], $u['password_hash']), $u['rol'], (int) $u['negocio_id']]);
check('Clave temporal: la cambia al entrar', true, $u['clave_temporal']);
$n = $db->query("SELECT * FROM negocios WHERE id = $nid")->fetch();
check('Negocio en prueba, plan, ciudad y origen central', ['prueba', 'quinta', 'Cuenca', 'central', '0991234567'], [$n['estado'], $n['plan_codigo'], $n['ciudad'], $n['origen'], $n['whatsapp']]);
check('Con datos de ejemplo (espacios, catálogo, paquetes, contrato)', [2, 18, 3, 1], [$cuenta("SELECT count(*) FROM espacios WHERE negocio_id = $nid"),
      $cuenta("SELECT count(*) FROM productos WHERE negocio_id = $nid"), $cuenta("SELECT count(*) FROM paquetes WHERE negocio_id = $nid"),
      $cuenta("SELECT count(*) FROM plantillas_contrato WHERE negocio_id = $nid")]);
check('Enlace público y WhatsApp con los accesos', [true, true, true], [str_starts_with($res['enlace_publico'], 'https://turenta.prueba.ec/?r=sitio&s=quinta-demo-central'),
      str_contains($res['mensaje_whatsapp'], $res['acceso']['clave']), str_starts_with((string) $res['whatsapp_url'], 'https://wa.me/593991234567?text=')]);
check('Sin correo de bienvenida (TuRenta no lo tiene)', false, $res['correo_enviado']);
check('El cliente viene en la respuesta', ['prueba', 'central'], [$r[1]['cliente']['estado'], $r[1]['cliente']['origen']]);
$guardada = (string) $db->query("SELECT respuesta FROM tukan_central_operaciones WHERE operacion_id = '$opDemo'")->fetchColumn();
check('La respuesta guardada NO tiene la clave', false, str_contains($guardada, $res['acceso']['clave']));
$r2 = $ej('crear_demo', $datosDemo, null, $opDemo);
check('Repetida: no crea otra cuenta', [200, true, (string) $nid, 1], [$r2[0], $r2[1]['repetida'], $r2[1]['resultado']['cliente_id'],
      $cuenta("SELECT count(*) FROM usuarios WHERE email = 'juan.demo@ejemplo.com'")]);
check('… y la clave viene en null (oculta)', [null, true, null], [$r2[1]['resultado']['acceso']['clave'], $r2[1]['resultado']['acceso']['clave_oculta'], $r2[1]['resultado']['mensaje_whatsapp']]);
check('Registro: demo creada por el Central', 1, $cuenta("SELECT count(*) FROM registro_admin WHERE negocio_id = $nid AND accion LIKE 'Panel Tukán Central · Demo creada: Quinta Demo Central%'"));
$r = $ej('crear_demo', ['email' => 'JUAN.demo@ejemplo.com'] + $datosDemo);
check('Correo que ya existe → 422 datos.email', [422, 'datos.email'], [$r[0], $r[1]['campo']]);
check('Nombre corto → 422', 'datos.nombre', $ej('crear_demo', ['nombre' => 'Ab', 'email' => 'otro1@ejemplo.com'] + $datosDemo)[1]['campo']);
check('Teléfono con menos de 9 dígitos → 422', 'datos.telefono', $ej('crear_demo', ['telefono' => '099-12-34-5', 'email' => 'otro2@ejemplo.com'] + $datosDemo)[1]['campo']);
check('Días fuera de rango → 422', 'datos.dias', $ej('crear_demo', ['dias' => 91, 'email' => 'otro3@ejemplo.com'] + $datosDemo)[1]['campo']);
check('Pedido de demo que no existe → 404', [404, 'no_encontrado'], $codigo($ej('crear_demo', ['solicitud_id' => 'demo_pedida:99999', 'email' => 'otro4@ejemplo.com'] + $datosDemo)));
check('Solicitud que no es pedido de demo → 422', 'datos.solicitud_id', $ej('crear_demo', ['solicitud_id' => 'renovacion:1', 'email' => 'otro5@ejemplo.com'] + $datosDemo)[1]['campo']);
check('Ningún error creó cuentas', 0, $cuenta("SELECT count(*) FROM usuarios WHERE email LIKE 'otro%@ejemplo.com'"));
$r = $ej('crear_demo', ['nombre' => 'Salón Sin Plan', 'contacto_nombre' => 'Ana', 'email' => 'ana.demo@ejemplo.com', 'telefono' => '0987777777']);
check('Sin plan ni días: plan por defecto y días del ajuste', [200, 'quinta', $d('+7 days'), 'Quito'], [$r[0], $r[1]['cliente']['plan']['codigo'], $r[1]['resultado']['prueba_hasta'], $r[1]['cliente']['ciudad']]);

// ---------------------------------------------------------------------------------------------
titulo('11. Las demás operaciones con su efecto real');
// extender_prueba
$r = $ej('extender_prueba', ['dias' => 10], $P1);
check('Alargar prueba: desde su fin', [200, $d('+17 days'), $d('+17 days')], [$r[0], $r[1]['resultado']['prueba_hasta'], $db->query("SELECT prueba_hasta FROM negocios WHERE id = $P1")->fetchColumn()]);
$r = $ej('extender_prueba', ['dias' => 7], $A2);
check('Alargar la prueba de quien paga → 403 no_permitido', [403, 'no_permitido'], $codigo($r));
check('… y no le cambia nada', 'activo', $db->query("SELECT estado FROM negocios WHERE id = $A2")->fetchColumn());
$r = $ej('extender_prueba', ['dias' => 5], $T1);
check('Prueba terminada sin pagar nunca: sí, desde hoy', [200, $d('+5 days')], [$r[0], $r[1]['resultado']['prueba_hasta']]);
check('Días fuera de rango → 422', 'datos.dias', $ej('extender_prueba', ['dias' => 0], $P1)[1]['campo']);
check('Sin días → 422', 'datos.dias', $ej('extender_prueba', [], $P1)[1]['campo']);
// anular_pago
$r = $ej('anular_pago', ['pago_id' => (string) $pagoA3, 'motivo' => 'Se registró por error'], $A3);
check('Anular pago', [200, true], [$r[0], $db->query("SELECT anulada FROM suscripciones WHERE id = $pagoA3")->fetchColumn()]);
check('Sin pagos que queden: vuelve a la prueba', [null, 'prueba'], array_values($db->query("SELECT activo_hasta, estado FROM negocios WHERE id = $A3")->fetch()));
check('… y lo dice el mensaje', true, str_contains($r[1]['mensaje'], 'vuelve al estado de demo'));
check('Anular otra vez → 409 conflicto', [409, 'conflicto'], $codigo($ej('anular_pago', ['pago_id' => (string) $pagoA3, 'motivo' => 'otra vez'], $A3)));
check('Anular un pago de OTRO negocio → 404', [404, 'no_encontrado'], $codigo($ej('anular_pago', ['pago_id' => (string) $pagoA2, 'motivo' => 'intento de otro'], $A3)));
check('… y el pago del otro sigue igual', false, $db->query("SELECT anulada FROM suscripciones WHERE id = $pagoA2")->fetchColumn());
check('Motivo corto → 422', 'datos.motivo', $ej('anular_pago', ['pago_id' => (string) $pagoA1, 'motivo' => 'x'], $A1)[1]['campo']);
$pagoG1 = (int) $db->query("SELECT max(id) FROM suscripciones WHERE negocio_id = $G1 AND NOT anulada")->fetchColumn();
$r = $ej('anular_pago', ['pago_id' => $pagoG1, 'motivo' => 'Transferencia rebotada'], $G1);
check('Anular el último pago devuelve la fecha anterior', [200, $d('-1 day')], [$r[0], $r[1]['resultado']['activo_hasta']]);
// suspender / reactivar / cancelar
$r = $ej('suspender', ['motivo' => 'Pidió pausar el servicio'], $A2);
check('Suspender', [200, 'suspendido', 'suspendido'], [$r[0], $db->query("SELECT estado FROM negocios WHERE id = $A2")->fetchColumn(), $r[1]['resultado']['estado']]);
check('… el motivo queda en el registro', 1, $cuenta("SELECT count(*) FROM registro_admin WHERE negocio_id = $A2 AND accion LIKE 'Panel Tukán Central · Suspendido: Pidió pausar el servicio%'"));
$r = $ej('suspender', ['motivo' => 'Pidió pausar el servicio'], $A2);
check('Suspender a un suspendido: ok sin cambios', [200, true], [$r[0], $r[1]['sin_cambios']]);
$r = $ej('reactivar', [], $A2);
check('Reactivar: vuelve a activo (tiene pagos)', [200, 'activo', 'activo'], [$r[0], $db->query("SELECT estado FROM negocios WHERE id = $A2")->fetchColumn(), $r[1]['resultado']['estado']]);
check('Reactivar a un activo: sin cambios', true, $ej('reactivar', [], $A2)[1]['sin_cambios']);
$r = $ej('cancelar', ['motivo' => 'Cerró el local'], $O1);
check('Cancelar', [200, 'cancelado'], [$r[0], $db->query("SELECT estado FROM negocios WHERE id = $O1")->fetchColumn()]);
check('Cancelar otra vez: sin cambios', true, $ej('cancelar', ['motivo' => 'Cerró el local'], $O1)[1]['sin_cambios']);
$ej('reactivar', [], $O1);
check('Reactivar un cancelado sin pagos: vuelve a prueba', 'prueba', $db->query("SELECT estado FROM negocios WHERE id = $O1")->fetchColumn());
check('Suspender sin motivo → 422', 'datos.motivo', $ej('suspender', [], $A1)[1]['campo']);
// cambiar_plan
$r = $ej('cambiar_plan', ['plan' => 'quinta'], $A2);
check('Cambiar plan sin cobrar', [200, 'quinta', 1], [$r[0], $db->query("SELECT plan_codigo FROM negocios WHERE id = $A2")->fetchColumn(), $cuenta("SELECT count(*) FROM suscripciones WHERE negocio_id = $A2")]);
check('Al mismo plan: sin cambios', true, $ej('cambiar_plan', ['plan' => 'quinta'], $A2)[1]['sin_cambios']);
check('Plan que no existe → 422', 'datos.plan', $ej('cambiar_plan', ['plan' => 'oro'], $A2)[1]['campo']);
// nueva_clave_dueno
$opClave = bin2hex(random_bytes(16));
$r = $ej('nueva_clave_dueno', [], $A1, $opClave);
$uA1 = $db->query("SELECT * FROM usuarios WHERE negocio_id = $A1 AND rol = 'dueno'")->fetch();
check('Nueva clave: sirve y es temporal', [200, true, true, $uA1['email']], [$r[0], password_verify($r[1]['resultado']['clave'], $uA1['password_hash']), $uA1['clave_temporal'], $r[1]['resultado']['usuario']]);
check('La anterior ya no sirve', false, password_verify('claveSegura1', $uA1['password_hash']));
check('WhatsApp con la clave', true, str_contains($r[1]['resultado']['mensaje_whatsapp'], $r[1]['resultado']['clave']));
$r2 = $ej('nueva_clave_dueno', [], $A1, $opClave);
check('Repetida: no cambia otra vez la clave y no la muestra', [true, null, true, null], [$r2[1]['repetida'], $r2[1]['resultado']['clave'], $r2[1]['resultado']['clave_oculta'],
      $r2[1]['resultado']['mensaje_whatsapp']]);
check('… la clave de la primera vez sigue sirviendo', true, password_verify($r[1]['resultado']['clave'], (string) $db->query("SELECT password_hash FROM usuarios WHERE id = {$uA1['id']}")->fetchColumn()));
// nota_interna
$r = $ej('nota_interna', ['texto' => 'Pidió factura con RUC 1790012345001'], $A1);
check('Nota interna', [200, 'Pidió factura con RUC 1790012345001'], [$r[0], $db->query("SELECT notas_admin FROM negocios WHERE id = $A1")->fetchColumn()]);
check('Otro negocio no cambia (aislamiento)', null, $db->query("SELECT notas_admin FROM negocios WHERE id = $A3")->fetchColumn());
check('Misma nota: sin cambios', true, $ej('nota_interna', ['texto' => 'Pidió factura con RUC 1790012345001'], $A1)[1]['sin_cambios']);
$ej('nota_interna', ['texto' => ''], $A1);
check('Nota vacía la borra', null, $db->query("SELECT notas_admin FROM negocios WHERE id = $A1")->fetchColumn());
check('Nota de más de 4000 → 422', 'datos.texto', $ej('nota_interna', ['texto' => str_repeat('a', 4001)], $A1)[1]['campo']);
// guardar_plan
$r = $ej('guardar_plan', ['plan' => 'salon', 'precio_mensual' => 45.0, 'descripcion' => 'Para salones de eventos']);
check('Guardar plan', [200, 45.0, 'Para salones de eventos'], [$r[0], (float) $db->query("SELECT precio_mensual FROM planes WHERE codigo = 'salon'")->fetchColumn(),
      $db->query("SELECT descripcion FROM planes WHERE codigo = 'salon'")->fetchColumn()]);
check('… cotizar usa el precio nuevo', 45.0, $pedir('cotizar', ['cliente_id' => (string) $V1, 'plan' => 'salon', 'periodo' => 'mensual'])[1]['monto']);
check('… el límite que no vino se conserva', 2, (int) $db->query("SELECT max_espacios FROM planes WHERE codigo = 'salon'")->fetchColumn());
$r = $ej('guardar_plan', ['plan' => 'salon', 'precios' => [['periodo' => 'mensual', 'precio' => 39]], 'max_usuarios' => null, 'ajustes' => ['dias_gracia' => 5]]);
check('Con precios[] y ajustes', [200, 39.0, null, '5'], [$r[0], (float) $db->query("SELECT precio_mensual FROM planes WHERE codigo = 'salon'")->fetchColumn(),
      $db->query("SELECT max_usuarios FROM planes WHERE codigo = 'salon'")->fetchColumn(), (new Planes($db))->ajuste('dias_gracia')]);
$ej('guardar_plan', ['plan' => 'salon', 'max_usuarios' => 3, 'ajustes' => ['dias_gracia' => 3]]);
check('Plan que no existe → 422', 'datos.plan', $ej('guardar_plan', ['plan' => 'oro'])[1]['campo']);
check('Ajuste fuera de rango → 422', 422, $ej('guardar_plan', ['plan' => 'salon', 'ajustes' => ['dias_gracia' => 99]])[0]);
check('… y no se guardó nada', '3', (new Planes($db))->ajuste('dias_gracia'));

// ---------------------------------------------------------------------------------------------
titulo('12. atender_solicitud (§6.14, §7.2): ya no sale en abiertas; la etapa siguiente sí');
$idP3 = "seguimiento_prueba:$P3:faltan_2:" . $d('+2 days');
$r = $ej('atender_solicitud', ['solicitud_id' => $idP3, 'resultado' => 'atendida', 'nota' => 'Le escribí por WhatsApp']);
check('Atender un recordatorio', [200, 'atendida'], [$r[0], $r[1]['solicitud']['estado']]);
check('… el cliente viene en la respuesta', (string) $P3, $r[1]['cliente']['id']);
check('Ya no sale en abiertas', false, in_array($idP3, $ids($sols()), true));
check('Sale en cerradas', true, in_array($idP3, $ids($sols(['estado' => 'cerradas'])), true));
check('Queda guardado en tukan_central_atenciones', ['seguimiento_prueba', 'atendida', 'Le escribí por WhatsApp', 'dimitryfierro@gmail.com'],
      array_values($db->query("SELECT tipo, estado, nota, email FROM tukan_central_atenciones WHERE solicitud_id = '$idP3'")->fetch()));
$luego = $hoy->modify('+2 days');
$abLuego = $ids($sols([], $luego));
check('Dos días después: sale la etapa "vence hoy" (id nuevo)', true, in_array("seguimiento_prueba:$P3:vence_hoy:" . $d('+2 days'), $abLuego, true));
check('… y la atendida no vuelve', false, in_array($idP3, $abLuego, true));
check('Atender otra vez igual: sin cambios', true, $ej('atender_solicitud', ['solicitud_id' => $idP3, 'resultado' => 'atendida'])[1]['sin_cambios']);
$r = $ej('atender_solicitud', ['solicitud_id' => $idP3, 'resultado' => 'descartada']);
check('Ya atendida y piden descartar → 409 conflicto', [409, 'conflicto', 'ya_atendida', 'atendida'], [$r[0], $r[1]['codigo'], $r[1]['detalle']['motivo'], $r[1]['detalle']['estado']]);
$idReg = "demo_pedida:$PD1";
$r = $ej('atender_solicitud', ['solicitud_id' => $idReg, 'resultado' => 'en_proceso']);
check('En proceso: sigue abierta', [200, 'en_proceso'], [$r[0], array_column($sols(), 'estado', 'id')[$idReg] ?? null]);
check('Cambia la huella del estado v1', true, TukanCentralV2::huella($ab) !== (new TukanCentral($db))->estado($hoy)['solicitudes_huella']);
check('Pedido de demo: "atendida" solo creando la demo → 422', 'datos.resultado', $ej('atender_solicitud', ['solicitud_id' => $idReg, 'resultado' => 'atendida'])[1]['campo']);
check('De en proceso a descartada sí', [200, 'descartada'], [$ej('atender_solicitud', ['solicitud_id' => $idReg, 'resultado' => 'descartada'])[0],
      $db->query("SELECT estado FROM pedidos_demo WHERE id = $PD1")->fetchColumn()]);
check('Solicitud que no existe → 404', [404, 'no_encontrado'], $codigo($ej('atender_solicitud', ['solicitud_id' => 'renovacion:1:x:2026-01-01', 'resultado' => 'atendida'])));
check('Resultado no válido → 422', 'datos.resultado', $ej('atender_solicitud', ['solicitud_id' => $idReg, 'resultado' => 'lista'])[1]['campo']);
$r = $ej('atender_solicitud', ['solicitud_id' => "dominio_gratis:$pagoA2", 'resultado' => 'atendida', 'dominio' => 'SalonAnual.com']);
check('Dominio gratis: se guarda en el pago anual', [200, 'salonanual.com'], [$r[0], $db->query("SELECT dominio FROM suscripciones WHERE id = $pagoA2")->fetchColumn()]);
check('… y la solicitud ya no aparece', false, in_array("dominio_gratis:$pagoA2", $ids($sols(['estado' => 'todas'])), true));
check('Dominio en una solicitud que no es dominio gratis → 422', 'datos.dominio', $ej('atender_solicitud', ['solicitud_id' => "seguimiento_prueba:$P2:vence_hoy:" . $d('today'), 'resultado' => 'atendida', 'dominio' => 'no valido'])[1]['campo']);
$r = $ej('extender_prueba', ['dias' => 3], $P3);
check('Alargar la prueba reinicia los avisos (borra lo atendido de esa prueba)', [200, 0], [$r[0], $cuenta("SELECT count(*) FROM tukan_central_atenciones WHERE solicitud_id LIKE 'seguimiento_prueba:$P3:%'")]);
$todasEj = $cuenta("SELECT count(*) FROM tukan_central_operaciones WHERE estado = 'ok'");
$lineas = $cuenta("SELECT count(*) FROM registro_admin r JOIN superadmins a ON a.id = r.admin_id WHERE r.accion LIKE 'Panel Tukán Central · %' AND a.email = 'dimitryfierro@gmail.com'");
check('Cada ejecutar correcto deja su línea en registro_admin con el correo del Central', $todasEj, $lineas);
check('Un solo super usuario creado por el Central', 1, $cuenta("SELECT count(*) FROM superadmins WHERE email = 'dimitryfierro@gmail.com' AND creado_por_central"));
$db->exec("UPDATE superadmins SET activo = false WHERE email = 'dimitryfierro@gmail.com'");
check('Super usuario desactivado → 403 no_permitido', [403, 'no_permitido'], $codigo($ej('nota_interna', ['texto' => 'x'], $A1)));
$db->exec("UPDATE superadmins SET activo = true WHERE email = 'dimitryfierro@gmail.com'");
$db->exec("UPDATE tukan_central_operaciones SET creado = now() - interval '91 days' WHERE operacion_id = '$opMal'");
$ej('nota_interna', ['texto' => 'limpieza'], $A1);
check('Las operaciones de más de 90 días se borran', 0, $cuenta("SELECT count(*) FROM tukan_central_operaciones WHERE operacion_id = '$opMal'"));
$json = (string) $db->query("SELECT string_agg(respuesta, ' ') FROM tukan_central_operaciones")->fetchColumn();
check('Ninguna respuesta guardada tiene contraseñas', false, str_contains($json, $res['acceso']['clave']) || str_contains($json, '"clave":"'));

// ---------------------------------------------------------------------------------------------
titulo('13. SSO con enlace directo: ir válido o no (§5.3)');
foreach (['/?r=admin_negocio&id=3' => true, '/?r=admin' => true, '/?r=admin_negocios&estado=prueba#lista' => true,
          '//malo.com' => false, 'https://malo.com/?r=admin' => false, '/?r=inicio' => false, '/otra' => false, '' => false,
          "/?r=admin\nX" => false, '/?r=admin\\x' => false, '/?r=admin<script>' => false, '/?r=admin ' . 'x' => false,
          '/?r=admin' . str_repeat('a', 300) => false] as $ir => $esperado) {
    check('ir ' . json_encode($ir) . ($esperado ? ' sí' : ' no'), $esperado, TukanCentral::irValido($ir));
}
check('ir que no es texto: no', false, TukanCentral::irValido(['/?r=admin']));

foreach (['TUKAN_CENTRAL_ID', 'TUKAN_CENTRAL_SECRET', 'TUKAN_CENTRAL_URL', 'TURENTA_URL'] as $v) putenv($v);
