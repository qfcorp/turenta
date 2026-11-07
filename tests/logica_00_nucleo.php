<?php
/**
 * Núcleo de TuRenta: alta del negocio, eventos sin doble reserva, cotización, plan de pagos,
 * separación automática, contrato con firma y verificación, garantía y Payphone.
 */
use TuRenta\{Altas, Eventos, Cotizaciones, Pagos, Contratos, Letras, Planes};

$db = base_limpia();
planes_de_antes($db);   // estas pruebas usan los planes de antes (Esencial $19, Salón $39, Quinta $69); los de $20 y $45: logica_112
$hoy = new DateTimeImmutable('today');
$fecha = $hoy->modify('+120 days')->format('Y-m-d');

titulo('1. Alta del negocio con datos de ejemplo');
[$nid, $dueno] = negocio_prueba($db);
check('Negocio en prueba gratis 7 días', $hoy->modify('+7 days')->format('Y-m-d'), $db->query("SELECT prueba_hasta FROM negocios WHERE id = $nid")->fetchColumn());
check('2 espacios de ejemplo', 2, (int) $db->query("SELECT count(*) FROM espacios WHERE negocio_id = $nid")->fetchColumn());
check('18 productos en el catálogo', 18, (int) $db->query("SELECT count(*) FROM productos WHERE negocio_id = $nid")->fetchColumn());
check('3 paquetes', 3, (int) $db->query("SELECT count(*) FROM paquetes WHERE negocio_id = $nid")->fetchColumn());
check('Plantilla de contrato predeterminada', 1, (int) $db->query("SELECT count(*) FROM plantillas_contrato WHERE negocio_id = $nid AND predeterminada")->fetchColumn());
check('Mobiliario de ejemplo', 8, (int) $db->query("SELECT count(*) FROM mobiliario WHERE negocio_id = $nid")->fetchColumn());
check_error('No se repite el correo', 'Ya existe una cuenta', fn() => (new Altas($db))->crearNegocioConDueno('Otro local', 'Xavier', '', $dueno['email'], 'claveSegura1', 'salon'));

$salon = espacio_id($db, $nid, 'Salón principal');
$jardin = espacio_id($db, $nid, 'Jardín');
$ev = new Eventos($db);
$cot = new Cotizaciones($db);
$pag = new Pagos($db);
$con = new Contratos($db);

titulo('2. Evento nuevo (consulta)');
$e1 = $ev->crear($nid, ['cliente' => ['nombre' => 'Ana Torres', 'telefono' => '0991112233', 'identificacion' => '1712345678'],
    'tipo' => 'boda', 'fecha' => $fecha, 'hora_inicio' => '18:00', 'hora_fin' => '02:00', 'invitados' => 150,
    'espacios' => [$salon], 'origen' => 'instagram'], (int) $dueno['id'], $hoy);
$E1 = $ev->obtener($nid, $e1);
check('Código EV-año-0001', 'EV-' . $hoy->format('Y') . '-0001', $E1['codigo']);
check('Estado consulta', 'consulta', $E1['estado']);
check('Nombre automático', 'Boda de Ana Torres', $E1['nombre']);
check('Tareas creadas desde la plantilla (boda)', 14, count($ev->tareas($e1)));
check('El espacio no está bloqueado todavía', false, (bool) $E1['espacios'][0]['bloquea']);
check('Ocupa desde 3 horas antes (montaje)', "$fecha 15:00:00", $E1['espacios'][0]['desde']);
check('Termina al día siguiente + 1 hora (desmontaje)', (new DateTimeImmutable($fecha))->modify('+1 day')->format('Y-m-d') . ' 03:00:00', $E1['espacios'][0]['hasta']);
$mismoCliente = $ev->guardarCliente($nid, ['nombre' => 'Ana T.', 'telefono' => '+593 99 111 2233']);
check('No duplica el cliente (mismo celular)', (int) $E1['cliente_id'], $mismoCliente);
check_error('Pide al menos un espacio', 'al menos un espacio', fn() => $ev->crear($nid, ['cliente_id' => $E1['cliente_id'], 'fecha' => $fecha, 'invitados' => 10, 'espacios' => []]));

titulo('3. Cotización con el Paquete Boda Oro');
$paqOro = (int) $db->query("SELECT id FROM paquetes WHERE negocio_id = $nid AND nombre = 'Paquete Boda Oro'")->fetchColumn();
$c1 = $cot->crear($nid, $e1, $paqOro, (int) $dueno['id'], $hoy);
$C1 = $cot->obtener($nid, $c1);
check('11 líneas del paquete', 11, count($C1['items']));
check('Menú por persona x 150 invitados', 150.0, (float) $C1['items'][0]['cantidad']);
check('Subtotal', 10020.0, (float) $C1['subtotal']);
check('IVA 15 %', 1503.0, (float) $C1['iva']);
check('Total', 11523.0, (float) $C1['total']);
$cot->guardar($nid, $c1, $C1['items'], ['descuento' => '520', 'invitados' => 150]);
$C1 = $cot->obtener($nid, $c1);
check('Con descuento de $520: base 9.500', 9500.0, (float) $C1['base_iva']);
check('Con descuento: total 10.925', 10925.0, (float) $C1['total']);
check('Precio por persona', 72.83, $C1['por_persona']);
check('Ganancia estimada (subtotal − descuento − costo)', round(9500 - (float) $C1['costo'], 2), $C1['ganancia']);
$t = Cotizaciones::calcularTotales(1000, 200, 120, 15);
check('Descuento repartido: base con IVA 900', 900.0, $t['base_iva']);
check('Descuento repartido: base 0 % 180', 180.0, $t['base_cero']);
check('Total con base 0 %', 1215.0, $t['total']);

titulo('4. Enviar: el evento queda cotizado y la fecha retenida');
$cot->enviar($nid, $c1, $dueno);
$E1 = $ev->obtener($nid, $e1);
check('Estado cotizado', 'cotizado', $E1['estado']);
check('Fecha retenida 5 días', $hoy->modify('+5 days')->format('Y-m-d'), $E1['retenida_hasta']);
$ch = $ev->choques($nid, $fecha, '19:00', '23:00', [$salon], null, $hoy);
check('Otro evento ese día ve el choque como "retenido"', 'retenido', $ch[0]['nivel'] ?? null);

titulo('5. Aceptar la cotización: valor al evento y plan de pagos');
$cot->aceptar($nid, $c1, 'Ana Torres', '1.2.3.4', null, $hoy);
$E1 = $ev->obtener($nid, $e1);
check('Valor del evento = total aceptado', 10925.0, (float) $E1['total']);
$cuotas = $pag->cuotas($e1, $hoy);
check('Plan de 3 pagos', 3, count($cuotas));
check('Reserva 30 %', 3277.5, (float) $cuotas[0]['monto']);
check('Cuota 2', 3823.75, (float) $cuotas[1]['monto']);
check('Saldo', 3823.75, (float) $cuotas[2]['monto']);
check('El saldo vence 15 días antes del evento', (new DateTimeImmutable($fecha))->modify('-15 days')->format('Y-m-d'), $cuotas[2]['vence']);
check('Las cuotas suman el total', 10925.0, round(array_sum(array_map(fn($c) => (float) $c['monto'], $cuotas)), 2));
check_error('Una cotización aceptada no se edita', 'Crea una nueva versión', fn() => $cot->guardar($nid, $c1, [], []));

titulo('6. Pagos y separación automática de la fecha');
$p1 = $pag->registrar($nid, $e1, ['monto' => '1000', 'metodo' => 'transferencia'], $dueno);
check('Recibo N.º 1', 1, (int) $db->query("SELECT numero_recibo FROM pagos WHERE id = $p1")->fetchColumn());
check('Con $1.000 todavía no se separa', 'cotizado', $ev->obtener($nid, $e1)['estado']);
$pag->registrar($nid, $e1, ['monto' => '2277,50', 'metodo' => 'deuna'], $dueno);
$E1 = $ev->obtener($nid, $e1);
check('Al completar la reserva queda SEPARADO', 'separado', $E1['estado']);
check('El espacio queda bloqueado', true, (bool) $E1['espacios'][0]['bloquea']);
$r = $pag->resumen($e1, $hoy);
check('Pagado 3.277,50', 3277.5, $r['pagado']);
check('Saldo 7.647,50', 7647.5, $r['saldo']);
check('La reserva aparece pagada', 'pagada', $r['cuotas'][0]['estado']);

titulo('7. No hay doble reserva');
$e2 = $ev->crear($nid, ['cliente' => ['nombre' => 'Luis Mora', 'telefono' => '0987654321'], 'tipo' => 'quince', 'fecha' => $fecha,
    'hora_inicio' => '20:00', 'hora_fin' => '01:00', 'invitados' => 120, 'espacios' => [$salon]], null, $hoy);
check_error('Separar el mismo salón a la misma hora: bloqueado', 'ya está separado', fn() => $ev->cambiarEstado($nid, $e2, 'separado', [], $dueno, $hoy));
check_error('Ni saltándose el programa (la base de datos lo impide)', '', function () use ($db, $e2) {
    $db->exec("UPDATE evento_espacios SET bloquea = true WHERE evento_id = $e2");
});
check('El evento 2 sigue sin bloquear', false, (bool) $db->query("SELECT bloquea FROM evento_espacios WHERE evento_id = $e2")->fetchColumn());
$e3 = $ev->crear($nid, ['cliente' => ['nombre' => 'Empresa XYZ', 'telefono' => '0977777777'], 'tipo' => 'corporativo', 'fecha' => $fecha,
    'hora_inicio' => '19:00', 'hora_fin' => '23:00', 'invitados' => 80, 'espacios' => [$jardin]], null, $hoy);
$ev->cambiarEstado($nid, $e3, 'separado', [], $dueno, $hoy);
check('Otro espacio el mismo día: sí se puede', 'separado', $ev->obtener($nid, $e3)['estado']);
$e4 = $ev->crear($nid, ['cliente' => ['nombre' => 'Bautizo Pérez', 'telefono' => '0966666666'], 'tipo' => 'bautizo', 'fecha' => $fecha,
    'hora_inicio' => '09:00', 'hora_fin' => '13:00', 'invitados' => 60, 'espacios' => [$salon]], null, $hoy);
$ev->cambiarEstado($nid, $e4, 'separado', [], $dueno, $hoy);
check('Mismo salón en la mañana (sin cruzarse con el montaje): sí se puede', 'separado', $ev->obtener($nid, $e4)['estado']);
check_error('Mover el bautizo a la tarde choca con la boda', 'ya está separado', fn() => $ev->actualizar($nid, $e4, ['tipo' => 'bautizo', 'fecha' => $fecha, 'hora_inicio' => '14:00', 'hora_fin' => '17:00', 'invitados' => 60]));
$disp = $ev->disponibilidad($nid, $fecha, $hoy);
check('Disponibilidad: salón ocupado', 'ocupado', $disp[0]['estado']);
check('Disponibilidad: jardín ocupado', 'ocupado', $disp[1]['estado']);
$db->prepare('INSERT INTO fechas_cerradas (negocio_id, espacio_id, fecha, motivo) VALUES (?,?,?,?)')->execute([$nid, $jardin, $hoy->modify('+200 days')->format('Y-m-d'), 'Mantenimiento']);
check('Día cerrado aparece como cerrado', 'cerrado', $ev->disponibilidad($nid, $hoy->modify('+200 days')->format('Y-m-d'), $hoy)[1]['estado']);
check_error('Perder un evento pide motivo', 'motivo', fn() => $ev->cambiarEstado($nid, $e2, 'perdido', [], $dueno, $hoy));
$ev->cambiarEstado($nid, $e2, 'perdido', ['motivo' => 'Eligió otro local'], $dueno, $hoy);
check('Evento perdido con motivo', 'Eligió otro local', $ev->obtener($nid, $e2)['motivo_perdida']);
check_error('No se puede marcar realizado antes de la fecha', 'día del evento', fn() => $ev->cambiarEstado($nid, $e3, 'confirmado', [], $dueno, $hoy) ?? $ev->cambiarEstado($nid, $e3, 'realizado', [], $dueno, $hoy));

titulo('8. Contrato con firma del cliente y verificación');
$k1 = $con->generar($nid, $e1, null, $dueno, $hoy);
$K1 = $con->obtener($nid, $k1);
check('El contrato tiene el nombre del cliente', true, str_contains($K1['texto'], 'Ana Torres'));
check('El contrato tiene el total en letras', true, str_contains($K1['texto'], 'diez mil novecientos veinticinco dólares con 00/100'));
check('El contrato tiene el plan de pagos', true, str_contains($K1['texto'], 'Reserva: $3.277,50'));
check('No quedan variables sin reemplazar', false, str_contains($K1['texto'], '{{'));
check('Huella correcta', true, $K1['integro']);
check_error('No se firma un borrador', 'no está listo', fn() => $con->firmar($K1['token'], 'Ana Torres', '1712345678', firma_png(), true));
$con->enviar($nid, $k1, $dueno);
check_error('Firma vacía: rechazada', 'vacía', fn() => $con->firmar($K1['token'], 'Ana Torres', '1712345678', firma_png(true), true));
check_error('Debe aceptar', 'aceptar', fn() => $con->firmar($K1['token'], 'Ana Torres', '1712345678', firma_png(), false));
check_error('Cédula válida', 'cédula', fn() => $con->firmar($K1['token'], 'Ana Torres', '123', firma_png(), true));
$K1 = $con->firmar($K1['token'], 'Ana Torres Vega', '1712345678', firma_png(), true, '1.2.3.4', 'Prueba');
check('Contrato firmado', 'firmado', $K1['estado']);
check('Se guardó la imagen de la firma', true, $con->rutaFirma($nid, $k1) !== null);
check('Con reserva pagada + contrato firmado: CONFIRMADO', 'confirmado', $ev->obtener($nid, $e1)['estado']);
check_error('No se firma dos veces', 'ya fue firmado', fn() => $con->firmar($K1['token'], 'Ana Torres', '1712345678', firma_png(), true));
$V = $con->verificar($K1['codigo_verificacion']);
check('Verificación por código: íntegro', true, $V['integro']);
$db->prepare('UPDATE contratos SET texto = texto || ? WHERE id = ?')->execute([' (cambiado)', $k1]);
check('Si alguien cambia el texto, la verificación lo detecta', false, $con->verificar($K1['codigo_verificacion'])['integro']);
check_error('Variables que no existen en una plantilla', 'no existen', fn() => $con->guardarPlantilla($nid, null, 'Mala', str_repeat('texto ', 20) . '{{invento}}', false));

titulo('9. Comprobante del cliente, garantía y Payphone');
$pc = $pag->subirComprobante($e1, foto_subida(), 500, 'transferencia', 'Banco Pichincha 123');
check('El comprobante queda por revisar', 'por_revisar', $db->query("SELECT estado FROM pagos WHERE id = $pc")->fetchColumn());
check('Aparece en "por revisar" del negocio', 1, count($pag->porRevisar($nid)));
check('Avisó al dueño (campana)', true, (int) $db->query("SELECT count(*) FROM notificaciones WHERE negocio_id = $nid")->fetchColumn() > 0);
$pag->confirmar($nid, $pc, $dueno);
check('Al confirmar suma a lo pagado', 3777.5, $pag->resumen($e1, $hoy)['pagado']);
check_error('No se sube más de lo que falta', 'mayor que lo que falta', fn() => $pag->subirComprobante($e1, foto_subida(), 99999, 'deuna'));
$pag->registrar($nid, $e1, ['monto' => 300, 'metodo' => 'efectivo', 'tipo' => 'garantia'], $dueno);
$pag->registrar($nid, $e1, ['monto' => 250, 'metodo' => 'efectivo', 'tipo' => 'devolucion_garantia'], $dueno);
$pag->registrar($nid, $e1, ['monto' => 50, 'metodo' => 'efectivo', 'tipo' => 'cargo_danos', 'notas' => '2 copas rotas'], $dueno);
$g = $pag->garantia($e1);
check('Garantía recibida 300', 300.0, $g['recibida']);
check('Garantía: queda 0 en manos del local', 0.0, $g['saldo']);
check('La garantía NO cuenta como pago del evento', 3777.5, $pag->resumen($e1, $hoy)['pagado']);
check_error('No se devuelve más garantía de la que hay', 'garantía', fn() => $pag->registrar($nid, $e1, ['monto' => 10, 'metodo' => 'efectivo', 'tipo' => 'devolucion_garantia'], $dueno));
$db->prepare("UPDATE negocios SET payphone_token = 'tok', payphone_store_id = 'store' WHERE id = ?")->execute([$nid]);
$pp = $pag->prepararPayphone($e1, 100);
check('Payphone: monto en centavos', 10000, $pp['amount']);
$falso = fn($url, $cuerpo, $tok) => ['transactionStatus' => 'Approved', 'amount' => 10000, 'clientTransactionId' => $cuerpo['clientTxId'], 'transactionId' => 555];
[$estado] = $pag->confirmarPayphone(555, $pp['clientTransactionId'], $falso);
check('Payphone aprobado', 'aprobado', $estado);
check('El pago con tarjeta suma', 3877.5, $pag->resumen($e1, $hoy)['pagado']);
[$estado] = $pag->confirmarPayphone(555, $pp['clientTransactionId'], $falso);
check('Repetir la confirmación no cobra dos veces', 3877.5, $pag->resumen($e1, $hoy)['pagado']);
$pp2 = $pag->prepararPayphone($e1, 50);
$malo = fn($url, $cuerpo, $tok) => ['transactionStatus' => 'Approved', 'amount' => 1, 'clientTransactionId' => $cuerpo['clientTxId']];
[$estado] = $pag->confirmarPayphone(1, $pp2['clientTransactionId'], $malo);
check('Payphone con otro monto: no se acepta', 'no_aprobado', $estado);
$todos = (int) $db->query("SELECT count(*) FROM pagos WHERE evento_id = $e1 AND estado = 'confirmado' AND tipo = 'evento'")->fetchColumn();
$pag->anular($nid, $p1, 'Registrado dos veces', $dueno);
check('Anular un pago lo resta', 2877.5, $pag->resumen($e1, $hoy)['pagado']);
check('Pagos confirmados del evento después de anular', $todos - 1, (int) $db->query("SELECT count(*) FROM pagos WHERE evento_id = $e1 AND estado = 'confirmado' AND tipo = 'evento'")->fetchColumn());

titulo('10. Plan escrito a mano, nueva versión y letras');
check_error('El plan debe sumar el total', 'Deben ser iguales', fn() => $pag->guardarPlan($nid, $e1, [['concepto' => 'Uno', 'vence' => $fecha, 'monto' => 100]]));
$pag->guardarPlan($nid, $e1, [['concepto' => 'Reserva', 'vence' => $hoy->format('Y-m-d'), 'monto' => 5000], ['concepto' => 'Saldo', 'vence' => $fecha, 'monto' => 5925]]);
check('Plan a mano con 2 pagos', 2, count($pag->cuotas($e1)));
check('Se vuelve a aplicar lo pagado', 2877.5, (float) $pag->cuotas($e1)[0]['pagado']);
$c2 = $cot->crear($nid, $e3, null, null, $hoy);
check('Primera cotización sin paquete arranca con el alquiler del espacio', 'Alquiler de Jardín', $cot->obtener($nid, $c2)['items'][0]['descripcion']);
$c3 = $cot->crear($nid, $e3, null, null, $hoy);
check('Nueva versión copia las líneas', 1, count($cot->obtener($nid, $c3)['items']));
check('Versión 2', 2, (int) $cot->obtener($nid, $c3)['version']);
check('Letras: 1 dólar', 'un dólar con 00/100', Letras::dolares(1));
check('Letras: 21.000', 'veintiún mil dólares con 00/100', Letras::dolares(21000));
check('Letras: 1.250,75', 'mil doscientos cincuenta dólares con 75/100', Letras::dolares(1250.75));

titulo('11. Límites del plan Salón');
[$nid2] = negocio_prueba($db, 'salon', 'Salón Las Palmas');
$planes = new Planes($db);
check('Plan Salón: ya tiene 2 espacios, no puede agregar otro', false, $planes->puedeAgregarEspacio($nid2));
check('Plan Quinta: espacios ilimitados', true, $planes->puedeAgregarEspacio($nid));
check('Precio plan Salón', 39.0, $planes->cotizar('salon', 'mensual')['monto']);
check('Quinta anual: paga 10', 690.0, $planes->cotizar('quinta', 'anual')['monto']);
check('Un negocio no ve los eventos del otro', null, $ev->obtener($nid2, $e1));
