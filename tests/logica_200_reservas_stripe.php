<?php
/**
 * TuRenta (migración 200): reserva en línea por paquete y equipo, y cobro con Stripe Checkout
 * (depósito o total, regreso del cliente, webhook firmado, sin doble cobro, cambio de equipo si otro lo tomó).
 * Stripe se simula: nunca se llama a api.stripe.com en las pruebas.
 */
use TuRenta\{Reservas, Stripe, Pagos, Eventos};

$db = base_limpia();
$hoy = new DateTimeImmutable('today');
$fecha = $hoy->modify('+20 days')->format('Y-m-d');
[$nid, $dueno] = negocio_prueba($db, 'quinta', 'Zombie Ice');
$db->prepare("UPDATE negocios SET slug = 'zombieice', reserva_en_linea = true, idioma_publico = 'en', horas_montaje = 1, horas_desmontaje = 1,
                     reserva_pct = 30, reserva_hora_desde = '10:00', reserva_hora_hasta = '17:00', reserva_paso_min = 60, reserva_dias_min = 2,
                     stripe_secret_key = 'sk_test_51PruebaClaveSecreta000000', stripe_webhook_secret = 'whsec_prueba123' WHERE id = ?")->execute([$nid]);
$esp = function (string $nombre, int $orden) use ($db, $nid): int {
    $st = $db->prepare('INSERT INTO espacios (negocio_id, nombre, orden) VALUES (?,?,?) RETURNING id');
    $st->execute([$nid, $nombre, $orden]);
    return (int) $st->fetchColumn();
};
$camionA = $esp('ZombieTruck A', 1);
$camionB = $esp('ZombieTruck B', 2);
$st = $db->prepare("INSERT INTO productos (negocio_id, categoria, nombre, unidad, precio, con_iva) VALUES (?, 'otros', 'Signature Experience (1 hour)', 'evento', 495, false) RETURNING id");
$st->execute([$nid]);
$prod = (int) $st->fetchColumn();
$st = $db->prepare("INSERT INTO paquetes (negocio_id, nombre, slug, publico, duracion_min, maximo_invitados, orden) VALUES (?, 'Signature Experience', 'signature-experience', true, 60, 50, 1) RETURNING id");
$st->execute([$nid]);
$paq = (int) $st->fetchColumn();
$db->prepare('INSERT INTO paquete_items (paquete_id, producto_id, cantidad) VALUES (?,?,1)')->execute([$paq, $prod]);
$db->prepare('INSERT INTO paquete_espacios (paquete_id, espacio_id) VALUES (?,?), (?,?)')->execute([$paq, $camionA, $paq, $camionB]);

$res = new Reservas($db);
$neg = $res->negocio('zombieice');
titulo('1. Paquetes publicados y horarios');
check('El negocio con reserva en línea se encuentra por su dirección', true, $neg !== null);
check('Un negocio sin reserva en línea no aparece', null, $res->negocio('no-existe'));
$lista = $res->paquetes($nid);
check('Un paquete publicado', 1, count($lista));
check('Precio del paquete = suma de sus productos', 495.0, $lista[0]['precio']);
check('Dos equipos pueden hacerlo', [$camionA, $camionB], $lista[0]['espacios']);
$p = $res->paquete($nid, 'signature-experience');
$horas = $res->horarios($neg, $p, $fecha, $hoy);
check('Horarios de 10:00 a 17:00 cada hora = 8', 8, count($horas));
check('Primera hora en formato de EE.UU.', '10:00 AM', $horas[0]['texto']);
check('Sin aviso mínimo: mañana no se puede reservar', [], $res->horarios($neg, $p, $hoy->modify('+1 day')->format('Y-m-d'), $hoy));
check('Fecha pasada: sin horarios', [], $res->horarios($neg, $p, $hoy->modify('-3 days')->format('Y-m-d'), $hoy));

titulo('2. Crear la reserva');
$datos = ['paquete' => 'signature-experience', 'fecha' => $fecha, 'hora' => '15:00', 'nombre' => 'Laura Gómez', 'telefono' => '(954) 555-0123',
          'email' => 'laura@example.com', 'direccion' => '1200 Main St, Weston', 'zip' => '33326', 'ninos' => 25, 'ocasion' => 'cumpleanos',
          'homenajeado' => 'Sofia', 'notas' => 'Gate code 1234', 'acepto' => '1'];
check_error('Teléfono que no es de EE.UU.', 'US mobile number', fn() => $res->crear($neg, ['telefono' => '0991234567'] + $datos, '1.1.1.1', $hoy));
check_error('Más niños de los permitidos', 'up to 50 kids', fn() => $res->crear($neg, ['ninos' => 80] + $datos, '1.1.1.1', $hoy));
check_error('Sin aceptar las condiciones', 'accept the booking terms', fn() => $res->crear($neg, ['acepto' => ''] + $datos, '1.1.1.1', $hoy));
check_error('Hora que no se ofrece', 'just taken', fn() => $res->crear($neg, ['hora' => '20:00'] + $datos, '1.1.1.1', $hoy));
check_error('Campo trampa de robots', 'could not save', fn() => $res->crear($neg, ['sitio_web' => 'http://spam'] + $datos, '1.1.1.1', $hoy));
$e1 = $res->crear($neg, $datos, '1.1.1.1', $hoy);
$ev = (new Eventos($db))->obtener($nid, $e1);
check('Queda cotizado (falta el pago)', 'cotizado', $ev['estado']);
check('Retiene la hora solo hoy', $hoy->format('Y-m-d'), $ev['retenida_hasta']);
check('Hora de fin = inicio + duración', '16:00', substr((string) $ev['hora_fin'], 0, 5));
check('Total del paquete', 495.0, (float) $ev['total']);
check('Dirección de la fiesta guardada', '1200 Main St, Weston', $ev['direccion']);
check('Teléfono guardado con el 1 de EE.UU.', '19545550123', $db->query("SELECT telefono FROM clientes WHERE id = {$ev['cliente_id']}")->fetchColumn());
check('Usa el primer camión', $camionA, (int) (new Eventos($db))->espacios($e1)[0]['id']);
$m = $res->montos($e1);
check('Depósito = 30 %', 148.5, $m['deposito']);
check('Total pendiente', 495.0, $m['total']);
$horas2 = $res->horarios($neg, $p, $fecha, $hoy);
check('Con un camión retenido, las 15:00 siguen libres (queda el camión B)', true, in_array('15:00', array_column($horas2, 'hora'), true));
$hora15 = array_values(array_filter($horas2, fn($h) => $h['hora'] === '15:00'))[0];
check('…y se ofrece el camión B', $camionB, $hora15['espacio_id']);

titulo('3. Stripe Checkout: crear la sesión');
$llamadas = [];
$sesiones = [];
$falso = function (string $metodo, string $url, array $campos, string $clave) use (&$llamadas, &$sesiones) {
    $llamadas[] = [$metodo, $url, $campos, $clave];
    if ($metodo === 'POST') {
        $id = 'cs_test_' . substr(md5((string) count($llamadas)), 0, 24);
        $sesiones[$id] = ['id' => $id, 'url' => "https://checkout.stripe.com/c/pay/$id", 'client_reference_id' => $campos['client_reference_id'],
                          'amount_total' => (int) $campos['line_items[0][price_data][unit_amount]'], 'currency' => 'usd',
                          'payment_status' => 'unpaid', 'status' => 'open', 'payment_intent' => null];
        return $sesiones[$id];
    }
    $id = basename(parse_url($url, PHP_URL_PATH));
    return $sesiones[$id] ?? null;
};
$stripe = new Stripe($db, $falso);
check('Stripe listo con clave de prueba', true, $stripe->listo($nid));
check('Modo prueba', true, $stripe->modoPrueba($nid));
check('Clave mal escrita no sirve', false, Stripe::claveValida('pk_test_123'));
check_error('Más que el saldo', 'between $0.50', fn() => $stripe->crearCheckout($e1, 600, 'Deposit', 'https://x/ok', 'https://x/no'));
$url = $stripe->crearCheckout($e1, $m['deposito'], 'Deposit', 'https://turenta.test/?r=stripe', 'https://turenta.test/?r=portal&t=x', 'laura@example.com');
check('Devuelve la URL de Stripe', true, str_starts_with($url, 'https://checkout.stripe.com/'));
[$met, $u, $campos, $clave] = $llamadas[0];
check('Llama a /v1/checkout/sessions', 'https://api.stripe.com/v1/checkout/sessions', $u);
check('Con la clave secreta del negocio', 'sk_test_51PruebaClaveSecreta000000', $clave);
check('Cobra el depósito en centavos', '14850', $campos['line_items[0][price_data][unit_amount]']);
check('En dólares', 'usd', $campos['line_items[0][price_data][currency]']);
check('Vuelve con el número de sesión', true, str_contains($campos['success_url'], 'session_id={CHECKOUT_SESSION_ID}'));
check('Correo del cliente para el recibo', 'laura@example.com', $campos['customer_email']);
$sid1 = array_key_first($sesiones);
$pago1 = $db->query("SELECT * FROM pagos WHERE referencia = '$sid1'")->fetch();
check('Pago pendiente guardado con la sesión', 'pendiente', $pago1['estado']);
check('Método stripe', 'stripe', $pago1['metodo']);

titulo('4. El cliente vuelve sin pagar y luego pagado');
check('Sin pagar: pendiente', ['pendiente', $ev['token_cliente']], $stripe->confirmarSesionPorId($sid1));
check('Sesión inventada: no se reconoce', 'not recognized', (function () use ($stripe) {
    try { $stripe->confirmarSesionPorId('cs_test_inventado0000000000'); return ''; } catch (RuntimeException $e) { return 'not recognized'; }
})());
$sesiones[$sid1]['payment_status'] = 'paid';
$sesiones[$sid1]['status'] = 'complete';
$sesiones[$sid1]['payment_intent'] = 'pi_test_123';
check('Pagado', ['pagado', $ev['token_cliente']], $stripe->confirmarSesionPorId($sid1));
$ev = (new Eventos($db))->obtener($nid, $e1);
check('Con el depósito pagado la fecha queda separada', 'separado', $ev['estado']);
check('El camión queda bloqueado', true, (bool) $db->query("SELECT bloquea FROM evento_espacios WHERE evento_id = $e1")->fetchColumn());
$pago1 = $db->query("SELECT * FROM pagos WHERE referencia = '$sid1'")->fetch();
check('Pago confirmado con recibo', true, $pago1['estado'] === 'confirmado' && (int) $pago1['numero_recibo'] > 0);
check('Notas con el pago de Stripe', true, str_contains((string) $pago1['notas'], 'pi_test_123'));
check('Volver otra vez no cobra dos veces', ['pagado', $ev['token_cliente']], $stripe->confirmarSesionPorId($sid1));
check('Sigue un solo pago confirmado', 1, (int) $db->query("SELECT count(*) FROM pagos WHERE evento_id = $e1 AND estado = 'confirmado'")->fetchColumn());
check('Falta el saldo', 346.5, (new Reservas($db))->montos($e1)['total']);

titulo('5. Webhook firmado');
$e2 = $res->crear($neg, ['hora' => '11:00', 'telefono' => '954-555-0199', 'nombre' => 'Mark Lee', 'email' => 'mark@example.com'] + $datos, '2.2.2.2', $hoy);
$stripe->crearCheckout($e2, (new Reservas($db))->montos($e2)['total'], 'Full payment', 'https://x/ok', 'https://x/no');
$sid2 = array_key_last($sesiones);
$obj = ['id' => $sid2, 'client_reference_id' => $sesiones[$sid2]['client_reference_id'], 'amount_total' => 49500, 'currency' => 'usd',
        'payment_status' => 'paid', 'status' => 'complete', 'payment_intent' => 'pi_test_456'];
$cuerpo = json_encode(['id' => 'evt_1', 'type' => 'checkout.session.completed', 'data' => ['object' => $obj]]);
$t = time();
$firma = 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $cuerpo, 'whsec_prueba123');
check_error('Firma falsa: rechazado', 'Bad signature', fn() => $stripe->webhook('zombieice', $cuerpo, "t=$t,v1=abc"));
check_error('Firma vieja (más de 5 minutos): rechazado', 'Bad signature', fn() => $stripe->webhook('zombieice', $cuerpo, $firma, $t + 400));
check('Firma correcta: pagado', 'pagado', $stripe->webhook('zombieice', $cuerpo, $firma, $t));
check('Pagó todo: separado', 'separado', (new Eventos($db))->obtener($nid, $e2)['estado']);
check('Nada pendiente', 0.0, (new Reservas($db))->montos($e2)['total']);
check('El mismo aviso repetido no duplica', 'pagado', $stripe->webhook('zombieice', $cuerpo, $firma, $t));
check('Un solo pago de Mark', 1, (int) $db->query("SELECT count(*) FROM pagos WHERE evento_id = $e2 AND estado = 'confirmado'")->fetchColumn());
$monto_mal = json_encode(['type' => 'checkout.session.completed', 'data' => ['object' => ['amount_total' => 100] + $obj]]);
$f2 = 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $monto_mal, 'whsec_prueba123');
check('Valor distinto al guardado: no se acepta (ya estaba pagado, se queda igual)', 'pagado', $stripe->webhook('zombieice', $monto_mal, $f2, $t));

titulo('6. Dos clientes pagan la misma hora: el segundo pasa al otro camión');
$fecha2 = $hoy->modify('+21 days')->format('Y-m-d');
$e3 = $res->crear($neg, ['fecha' => $fecha2, 'hora' => '13:00', 'telefono' => '305-555-0001', 'nombre' => 'Ana Ruiz', 'email' => 'ana@example.com'] + $datos, '3.3.3.3', $hoy);
$e4 = $res->crear($neg, ['fecha' => $fecha2, 'hora' => '13:00', 'telefono' => '305-555-0002', 'nombre' => 'Bob Stone', 'email' => 'bob@example.com'] + $datos, '4.4.4.4', $hoy);
$ids = fn($e) => array_map('intval', array_column((new Eventos($db))->espacios($e), 'id'));
check('Ana con el camión A', [$camionA], $ids($e3));
check('Bob con el camión B (el A está retenido por Ana)', [$camionB], $ids($e4));
// Simula que el camión B lo tomó otro evento separado a esa hora y Bob paga después
$db->prepare("UPDATE evento_espacios SET espacio_id = ? WHERE evento_id = ?")->execute([$camionA, $e4]);
$pg = new Pagos($db);
$pg->registrar($nid, $e3, ['monto' => 148.5, 'metodo' => 'efectivo'], $dueno);
check('Ana separada en el A', 'separado', (new Eventos($db))->obtener($nid, $e3)['estado']);
$stripe->crearCheckout($e4, 148.5, 'Deposit', 'https://x/ok', 'https://x/no');
$sid4 = array_key_last($sesiones);
$sesiones[$sid4]['payment_status'] = 'paid';
$stripe->confirmarSesionPorId($sid4);
check('Bob separado igual', 'separado', (new Eventos($db))->obtener($nid, $e4)['estado']);
check('Bob pasó al camión B', [$camionB], $ids($e4));
check('Ya no hay camión libre a las 13:00', false, in_array('13:00', array_column($res->horarios($neg, $p, $fecha2, $hoy), 'hora'), true));

titulo('7. Intentos de pago repetidos');
$e5 = $res->crear($neg, ['fecha' => $fecha2, 'hora' => '16:00', 'telefono' => '305-555-0003', 'nombre' => 'Cleo Park', 'email' => 'cleo@example.com'] + $datos, '5.5.5.5', $hoy);
$stripe->crearCheckout($e5, 148.5, 'Deposit', 'https://x/ok', 'https://x/no');
$viejo = array_key_last($sesiones);
$stripe->crearCheckout($e5, 495, 'Full payment', 'https://x/ok', 'https://x/no');
check('Solo un intento abierto', 1, (int) $db->query("SELECT count(*) FROM pagos WHERE evento_id = $e5 AND metodo = 'stripe' AND estado = 'pendiente'")->fetchColumn());
$sesiones[$viejo]['payment_status'] = 'paid';
check('Si igual paga el intento viejo, se recupera', 'pagado', $stripe->confirmarSesionPorId($viejo)[0]);
check('Quedó separado con el depósito', 'separado', (new Eventos($db))->obtener($nid, $e5)['estado']);

titulo('8. Sin clave de Stripe');
$db->prepare("UPDATE negocios SET stripe_secret_key = NULL WHERE id = ?")->execute([$nid]);
check('No está listo', false, $stripe->listo($nid));
check_error('No abre el pago', 'not turned on', fn() => $stripe->crearCheckout($e5, 10, 'Balance', 'https://x/ok', 'https://x/no'));

titulo('9. Configuración y paquetes desde el panel');
$cfg = new \TuRenta\Configuracion($db);
check_error('Clave secreta mal pegada', 'sk_test_', fn() => $cfg->guardarReserva($nid, ['stripe_secret_key' => 'pk_test_123', 'reserva_paso_min' => 30]));
check_error('Claves de modos distintos', 'mismo modo', fn() => $cfg->guardarReserva($nid, ['stripe_secret_key' => 'sk_test_1234567890abc', 'stripe_publishable_key' => 'pk_live_1234567890abc', 'reserva_paso_min' => 30]));
check_error('Web sin https', 'https://', fn() => $cfg->guardarReserva($nid, ['sitio_externo' => 'http://zombieice.com', 'reserva_paso_min' => 30]));
check_error('Horas al revés', 'después de la primera', fn() => $cfg->guardarReserva($nid, ['reserva_hora_desde' => '18:00', 'reserva_hora_hasta' => '10:00', 'reserva_paso_min' => 30]));
$cfg->guardarReserva($nid, ['reserva_en_linea' => '1', 'idioma_publico' => 'en', 'reserva_hora_desde' => '09:30', 'reserva_hora_hasta' => '17:00',
    'reserva_paso_min' => 45, 'reserva_dias_min' => 3, 'sitio_externo' => 'https://zombieice.com', 'stripe_secret_key' => 'sk_test_51NuevaClave0000000',
    'stripe_webhook_secret' => 'whsec_nuevo0000000']);
$n = $db->query("SELECT * FROM negocios WHERE id = $nid")->fetch();
check('Guarda horas, paso y aviso', ['09:30', '17:00', 45, 3], [substr($n['reserva_hora_desde'], 0, 5), substr($n['reserva_hora_hasta'], 0, 5), (int) $n['reserva_paso_min'], (int) $n['reserva_dias_min']]);
check('Guarda la clave secreta', 'sk_test_51NuevaClave0000000', $n['stripe_secret_key']);
$cfg->guardarReserva($nid, ['reserva_en_linea' => '1', 'idioma_publico' => 'en', 'reserva_paso_min' => 45, 'stripe_secret_key' => '']);
check('Campo vacío conserva la clave', 'sk_test_51NuevaClave0000000', $db->query("SELECT stripe_secret_key FROM negocios WHERE id = $nid")->fetchColumn());
$cfg->guardarReserva($nid, ['reserva_paso_min' => 45, 'quitar_stripe' => '1']);
check('Quitar Stripe borra las claves', [null, null], array_values($db->query("SELECT stripe_secret_key, stripe_webhook_secret FROM negocios WHERE id = $nid")->fetch(PDO::FETCH_NUM)));
check('Cerrar la reserva en línea', null, (new Reservas($db))->negocio('zombieice'));

$cat = new \TuRenta\Catalogo($db);
check_error('En línea sin equipos', 'equipos', fn() => $cat->guardarReservaPaquete($nid, $paq, ['en_linea' => 1, 'slug' => 'x-y', 'duracion_min' => 60]));
check_error('Nombre corto repetido', 'ya usa', function () use ($db, $nid, $cat, $camionA) {
    $st = $db->prepare("INSERT INTO paquetes (negocio_id, nombre) VALUES (?, 'Otro') RETURNING id");
    $st->execute([$nid]);
    $cat->guardarReservaPaquete($nid, (int) $st->fetchColumn(), ['en_linea' => 1, 'slug' => 'Signature Experience', 'duracion_min' => 60, 'espacios' => [$camionA]]);
});
$cat->guardarReservaPaquete($nid, $paq, ['en_linea' => 1, 'slug' => 'Signature  Experience!', 'duracion_min' => 90, 'maximo_invitados' => '40', 'espacios' => [$camionB]]);
$pq = $db->query("SELECT slug, duracion_min, maximo_invitados FROM paquetes WHERE id = $paq")->fetch();
check('Nombre corto limpio', 'signature-experience', $pq['slug']);
check('Duración y máximo', [90, 40], [(int) $pq['duracion_min'], (int) $pq['maximo_invitados']]);
check('Equipos del paquete', [$camionB], $cat->espaciosPaquete($paq));
