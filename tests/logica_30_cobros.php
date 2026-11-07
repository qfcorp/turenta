<?php
/**
 * Cobros y portal del cliente: ganancia del evento, gastos, garantías por devolver, tablero de cobranza,
 * mensajes por WhatsApp del día (y marcarlos como enviados), recibo y estado de cuenta en PDF, Payphone.
 */
use TuRenta\{Eventos, Cotizaciones, Pagos, Contratos, Cobranza, PdfCobros, Pdf, Letras};

$db = base_limpia();
planes_de_antes($db);   // estas pruebas usan los planes de antes (Esencial $19, Salón $39, Quinta $69); los de $20 y $45: logica_112
$hoy = new DateTimeImmutable('today');
$fecha = $hoy->modify('+60 days')->format('Y-m-d');
[$nid, $dueno] = negocio_prueba($db);
$salon = espacio_id($db, $nid, 'Salón principal');
$jardin = espacio_id($db, $nid, 'Jardín');
$ev = new Eventos($db);
$cot = new Cotizaciones($db);
$pag = new Pagos($db);
$cob = new Cobranza($db, fn(string $r, array $p = []) => 'https://prueba.turenta.ec/?' . http_build_query(['r' => $r] + $p));
$texto_pdf = function (object $pdf): string {
    $tmp = tempnam(sys_get_temp_dir(), 'pdf');
    file_put_contents($tmp, Pdf::comoTexto($pdf));
    $t = (string) shell_exec('pdftotext -layout ' . escapeshellarg($tmp) . ' - 2>/dev/null');
    @unlink($tmp);
    return preg_replace('/\s+/u', ' ', $t);
};

titulo('1. Evento con cotización aceptada (Paquete Boda Oro, 150 invitados)');
$e1 = $ev->crear($nid, ['cliente' => ['nombre' => 'María José Paredes', 'telefono' => '0991112233', 'identificacion' => '1712345678'],
    'tipo' => 'boda', 'fecha' => $fecha, 'hora_inicio' => '18:00', 'hora_fin' => '02:00', 'invitados' => 150, 'espacios' => [$salon]], (int) $dueno['id'], $hoy);
$paqOro = (int) $db->query("SELECT id FROM paquetes WHERE negocio_id = $nid AND nombre = 'Paquete Boda Oro'")->fetchColumn();
$c1 = $cot->crear($nid, $e1, $paqOro, (int) $dueno['id'], $hoy);
$cot->enviar($nid, $c1, $dueno);
$cot->aceptar($nid, $c1, 'María José Paredes', '1.2.3.4', null, $hoy);
$C1 = $cot->obtener($nid, $c1);
check('Total del evento = cotización', (float) $C1['total'], (float) $ev->obtener($nid, $e1)['total']);

titulo('2. Ganancia del evento');
$g = $cob->ganancia($nid, $e1);
check('Ingreso sin IVA = subtotal − descuento', round((float) $C1['subtotal'] - (float) $C1['descuento'], 2), $g['ingreso']);
check('IVA = total − ingreso', round((float) $C1['total'] - $g['ingreso'], 2), $g['iva']);
check('Ganancia estimada = ingreso − costo de la cotización', round($g['ingreso'] - (float) $C1['costo'], 2), $g['ganancia_estimada']);
check('Sin gastos: costo real 0', 0.0, $g['costo_real']);
$cob->registrarGasto($nid, $e1, ['concepto' => 'Flores para la ceremonia', 'monto' => '350,50', 'categoria' => 'decoracion'], $dueno);
$gid = $cob->registrarGasto($nid, $e1, ['concepto' => 'Hielo extra', 'monto' => 40, 'categoria' => 'bebidas', 'fecha' => $hoy->format('Y-m-d')], $dueno);
check_error('Gasto sin concepto', 'en qué se gastó', fn() => $cob->registrarGasto($nid, $e1, ['concepto' => '', 'monto' => 10], $dueno));
check_error('Gasto sin valor', 'valor del gasto', fn() => $cob->registrarGasto($nid, $e1, ['concepto' => 'Algo', 'monto' => 0], $dueno));
check_error('Gasto con fecha mala', 'fecha', fn() => $cob->registrarGasto($nid, $e1, ['concepto' => 'Algo', 'monto' => 5, 'fecha' => '31/12/2026'], $dueno));
check('2 gastos registrados', 2, count($cob->gastos($nid, $e1)));
$per = $db->prepare("INSERT INTO personal (negocio_id, nombre, telefono, rol, pago_evento) VALUES (?,?,?,?,?) RETURNING id");
$per->execute([$nid, 'Carlos Mesero', '0987770001', 'mesero', 40]);
$p1 = (int) $per->fetchColumn();
$per->execute([$nid, 'Lucía Cocina', '0987770002', 'cocina', 60]);
$p2 = (int) $per->fetchColumn();
$per->execute([$nid, 'Pedro Faltó', '0987770003', 'mesero', 40]);
$p3 = (int) $per->fetchColumn();
$ep = $db->prepare('INSERT INTO evento_personal (evento_id, personal_id, rol, pago, asistio, confirmado) VALUES (?,?,?,?,?,?) RETURNING id');
$ep->execute([$e1, $p1, 'mesero', 40, null, 'false']);
$ep1 = (int) $ep->fetchColumn();
$ep->execute([$e1, $p2, 'cocina', 60, 'true', 'true']);
$ep->execute([$e1, $p3, 'mesero', 40, 'false', 'false']);
$g = $cob->ganancia($nid, $e1);
check('Gastos reales 390,50', 390.5, $g['gastos']);
check('Personal: no cuenta al que no asistió (40 + 60)', 100.0, $g['personal']);
check('Ganancia real = ingreso − gastos − personal', round($g['ingreso'] - 390.5 - 100, 2), $g['ganancia_real']);
check('Margen real en %', round(($g['ingreso'] - 490.5) * 100 / $g['ingreso'], 1), $g['margen_real']);
$cob->borrarGasto($nid, $gid, $dueno);
check('Borrar gasto', 350.5, $cob->ganancia($nid, $e1)['gastos']);
check_error('Borrar un gasto que ya no existe', 'ya no existe', fn() => $cob->borrarGasto($nid, $gid, $dueno));
[$nidOtro, $duenoOtro] = negocio_prueba($db, 'salon', 'Salón Otro Negocio');
check_error('Otro negocio no ve la ganancia', 'No se encontró', fn() => $cob->ganancia($nidOtro, $e1));
check_error('Otro negocio no registra gastos en mi evento', 'No se encontró', fn() => $cob->registrarGasto($nidOtro, $e1, ['concepto' => 'X', 'monto' => 5]));
// Ganancia sin cotización aceptada: total / (1 + IVA)
$eSin = $ev->crear($nid, ['cliente' => ['nombre' => 'Sin Cotización', 'telefono' => '0990000123'], 'tipo' => 'otro', 'fecha' => $hoy->modify('+90 days')->format('Y-m-d'),
    'hora_inicio' => '10:00', 'hora_fin' => '14:00', 'invitados' => 40, 'espacios' => [$jardin]], null, $hoy);
$db->exec("UPDATE eventos SET total = 1150 WHERE id = $eSin");
check('Sin cotización: ingreso = total sin IVA 15 %', 1000.0, $cob->ganancia($nid, $eSin)['ingreso']);

titulo('3. Pagos, separación y mensaje para la pantalla');
$cuotas = $pag->cuotas($e1, $hoy);
$reserva = (float) $cuotas[0]['monto'];
$antes = $ev->obtener($nid, $e1)['estado'];
$pid1 = $pag->registrar($nid, $e1, ['monto' => $reserva - 100, 'metodo' => 'transferencia', 'referencia' => 'PICH-1'], $dueno);
check('Pago parcial: no hay mensaje de separación', null, $cob->mensajeSeparacion($nid, $e1, $antes));
$pid2 = $pag->registrar($nid, $e1, ['monto' => 100, 'metodo' => 'efectivo'], $dueno);
check('Reserva completa: "la fecha quedó separada"', 'Reserva pagada: la fecha quedó separada.', $cob->mensajeSeparacion($nid, $e1, $antes));
check('Evento separado', 'separado', $ev->obtener($nid, $e1)['estado']);

titulo('4. Recibo en PDF');
$pdf = (new PdfCobros($db))->recibo($nid, $pid2);
$bin = Pdf::comoTexto($pdf);
check('Es un PDF', '%PDF', substr($bin, 0, 4));
$t = $texto_pdf($pdf);
$num2 = (int) $db->query("SELECT numero_recibo FROM pagos WHERE id = $pid2")->fetchColumn();
check('Tiene el N.º de recibo', true, str_contains($t, 'Recibo N.º ' . str_pad((string) $num2, 5, '0', STR_PAD_LEFT)));
check('Monto en letras (cien dólares)', true, str_contains($t, 'Cien dólares con 00/100'));
check('Nombre del cliente', true, str_contains($t, 'María José Paredes'));
check('Cédula del cliente', true, str_contains($t, '1712345678'));
check('Código del evento', true, str_contains($t, 'EV-' . $hoy->format('Y') . '-0001'));
check('Forma de pago', true, str_contains($t, 'Efectivo'));
$saldo = round((float) $C1['total'] - $reserva, 2);
check('Saldo después del pago', true, str_contains($t, '$' . number_format($saldo, 2, ',', '.')));
check('Dice que no reemplaza a la factura', true, str_contains($t, 'Este recibo no reemplaza a la factura'));
$t1 = $texto_pdf((new PdfCobros($db))->recibo($nid, $pid1));
check('Recibo 1: monto en letras', true, str_contains($t1, ucfirst(Letras::dolares($reserva - 100))));
check('Recibo 1: referencia', true, str_contains($t1, 'PICH-1'));
check('Recibo 1: saldo después del pago 1 (incluye los 100 que faltaban)', true, str_contains($t1, '$' . number_format($saldo + 100, 2, ',', '.')));
check_error('Otro negocio no saca mi recibo', 'No se encontró', fn() => (new PdfCobros($db))->recibo($nidOtro, $pid1));
$pc = $pag->subirComprobante($e1, foto_subida(), 50, 'deuna', 'DU-9');
check_error('Comprobante por revisar no tiene recibo', 'no tiene recibo', fn() => (new PdfCobros($db))->recibo($nid, $pc));

titulo('5. Estado de cuenta en PDF');
$pdf = (new PdfCobros($db))->estadoCuenta($nid, $e1, $hoy);
check('Estado de cuenta es PDF', '%PDF', substr(Pdf::comoTexto($pdf), 0, 4));
$t = $texto_pdf($pdf);
check('Tiene el plan de pagos', true, str_contains($t, 'PLAN DE PAGOS') && str_contains($t, 'Reserva') && str_contains($t, 'Saldo'));
check('Tiene los pagos recibidos', true, str_contains($t, 'PAGOS RECIBIDOS'));
check('Reserva pagada', true, str_contains($t, 'Pagada'));
check('Avisa el comprobante en revisión', true, str_contains($t, 'Comprobantes en revisión'));
check('Valor del evento', true, str_contains($t, Pdf::dinero((float) $C1['total'])));

titulo('6. Garantía por daños y garantías por devolver');
$cob->cambiarGarantia($nid, $e1, 300, $dueno);
check('Garantía pedida 300', 300.0, $pag->garantia($e1)['pedida']);
check_error('Garantía negativa', 'garantía válido', fn() => $cob->cambiarGarantia($nid, $e1, -5, $dueno));
$pag->registrar($nid, $e1, ['monto' => 300, 'metodo' => 'efectivo', 'tipo' => 'garantia'], $dueno);
check('Evento futuro: no está en garantías por devolver', 0, count($cob->garantiasPorDevolver($nid, $hoy)));
$despues = $hoy->modify('+62 days');
$gd = $cob->garantiasPorDevolver($nid, $despues);
check('Después del evento: garantía por devolver', 1, count($gd));
check('Saldo de garantía 300', 300.0, $gd[0]['saldo'] ?? null);
check('Mensaje de devolución listo', true, str_contains($gd[0]['texto'] ?? '', 'devolución de tu garantía de $300,00'));
$pag->registrar($nid, $e1, ['monto' => 50, 'metodo' => 'efectivo', 'tipo' => 'cargo_danos', 'notas' => '5 copas rotas'], $dueno);
check('Retener 50 por daños: quedan 250', 250.0, $cob->garantiasPorDevolver($nid, $despues)[0]['saldo']);
$pag->registrar($nid, $e1, ['monto' => 250, 'metodo' => 'transferencia', 'tipo' => 'devolucion_garantia'], $dueno);
check('Devuelta toda: sale de la lista', 0, count($cob->garantiasPorDevolver($nid, $despues)));
check('Otro negocio no ve mis garantías', 0, count($cob->garantiasPorDevolver($nidOtro, $despues)));

titulo('7. Tablero de cobranza');
$pag->confirmar($nid, $pc, $dueno);
$t = $cob->tablero($nid, $hoy);
check('Cobrado este mes = pagos del evento confirmados', round($reserva + 50, 2), $t['cobrado_mes']['total']);
check('3 pagos este mes (la garantía no cuenta)', 3, $t['cobrado_mes']['cuantos']);
check('Mes anterior en 0', 0.0, $t['cobrado_anterior']['total']);
check('Por cobrar = total − pagado', round((float) $C1['total'] - $reserva - 50, 2), $t['por_cobrar']);
check('1 evento por cobrar', 1, $t['eventos_por_cobrar']);
check('Sin comprobantes por revisar', 0, count($t['por_revisar']));
// Cuota vencida: mover la cuota 2 al pasado
$db->prepare('UPDATE cuotas SET vence = ? WHERE evento_id = ? AND numero = 2')->execute([$hoy->modify('-2 days')->format('Y-m-d'), $e1]);
$t = $cob->tablero($nid, $hoy);
check('1 cuota vencida', 1, count($t['vencidas']));
check('Total vencido = saldo de la cuota 2 (con el abono de 50)', round((float) $cuotas[1]['monto'] - 50, 2), $t['total_vencido']);
$db->prepare('UPDATE cuotas SET vence = ? WHERE evento_id = ? AND numero = 3')->execute([$hoy->modify('+10 days')->format('Y-m-d'), $e1]);
$t = $cob->tablero($nid, $hoy);
check('Por vencer en 15 días: el saldo', round((float) $cuotas[2]['monto'], 2), $t['tramos'][15]);
check('Por vencer en 7 días: nada', 0.0, $t['tramos'][7]);
check('Otro negocio: tablero vacío', 0, count($cob->tablero($nidOtro, $hoy)['vencidas']));

titulo('8. Mensajes por WhatsApp de hoy');
$m = $cob->mensajesDelDia($nid, $hoy);
check('Recordatorio de la cuota vencida', 1, count($m['cuota']));
check('Mensaje con el enlace del portal', true, str_contains($m['cuota'][0]['texto'], 'r=portal&t=' . $ev->obtener($nid, $e1)['token_cliente']));
check('Mensaje dice "venció"', true, str_contains($m['cuota'][0]['texto'], 'venció el'));
check('Enlace de WhatsApp listo', true, str_starts_with((string) $m['cuota'][0]['wa'], 'https://wa.me/593991112233?text='));
// Contrato enviado sin firmar (creado ayer)
$con = new Contratos($db);
$k1 = $con->generar($nid, $e1, null, $dueno, $hoy);
$con->enviar($nid, $k1, $dueno);
check('Contrato enviado hoy: todavía no se recuerda', 0, count($cob->mensajesDelDia($nid, $hoy)['contrato']));
$db->exec("UPDATE contratos SET creado_en = now() - interval '2 days' WHERE id = $k1");
$m = $cob->mensajesDelDia($nid, $hoy);
check('Contrato sin firmar: 1 mensaje', 1, count($m['contrato']));
$tk = $db->query("SELECT token FROM contratos WHERE id = $k1")->fetchColumn();
check('Mensaje con el enlace de firma', true, str_contains($m['contrato'][0]['texto'], 'r=firmar&t=' . $tk));
// Cotización enviada hace 4 días sin respuesta
$e2 = $ev->crear($nid, ['cliente' => ['nombre' => 'Andrés Quinceañera', 'telefono' => '0982223344'], 'tipo' => 'quince', 'fecha' => $hoy->modify('+100 days')->format('Y-m-d'),
    'hora_inicio' => '19:00', 'hora_fin' => '01:00', 'invitados' => 100, 'espacios' => [$jardin]], null, $hoy);
$c2 = $cot->crear($nid, $e2, null, null, $hoy);
$cot->enviar($nid, $c2, $dueno);
check('Cotización de hoy: todavía no', 0, count($cob->mensajesDelDia($nid, $hoy)['cotizacion']));
$db->prepare('UPDATE cotizaciones SET fecha = ? WHERE id = ?')->execute([$hoy->modify('-4 days')->format('Y-m-d'), $c2]);
$m = $cob->mensajesDelDia($nid, $hoy);
check('Cotización sin respuesta: 1 mensaje', 1, count($m['cotizacion']));
$tc = $db->query("SELECT token FROM cotizaciones WHERE id = $c2")->fetchColumn();
check('Mensaje con el enlace de la cotización', true, str_contains($m['cotizacion'][0]['texto'], 'r=cot&t=' . $tc));
// Personal sin confirmar para mañana: evento e3 mañana separado
$e3 = $ev->crear($nid, ['cliente' => ['nombre' => 'Empresa Andina', 'telefono' => '0975556677'], 'tipo' => 'corporativo', 'fecha' => $hoy->modify('+1 day')->format('Y-m-d'),
    'hora_inicio' => '12:00', 'hora_fin' => '16:00', 'invitados' => 60, 'espacios' => [$jardin]], null, $hoy);
$ev->cambiarEstado($nid, $e3, 'separado', [], $dueno, $hoy);
$ep->execute([$e3, $p1, 'mesero', 40, null, 'false']);
$ep3 = (int) $ep->fetchColumn();
$ep->execute([$e3, $p2, 'cocina', 60, null, 'true']);
$m = $cob->mensajesDelDia($nid, $hoy);
check('Personal sin confirmar mañana: solo el que no confirmó', 1, count($m['staff']));
check('Mensaje al personal con su enlace', true, str_contains($m['staff'][0]['texto'], 'r=staff&t=' . $db->query("SELECT token FROM personal WHERE id = $p1")->fetchColumn()));
check('Pedir lista de invitados: evento de mañana (en 10 días)', 1, count($m['invitados']));
check('Lista de invitados con el enlace del portal (plan Quinta)', true, str_contains($m['invitados'][0]['texto'], 'lista de invitados en tu portal'));
// Reseña: evento de ayer confirmado
$e4 = $ev->crear($nid, ['cliente' => ['nombre' => 'Familia Ruiz', 'telefono' => '0964445566'], 'tipo' => 'bautizo', 'fecha' => $hoy->modify('+5 days')->format('Y-m-d'),
    'hora_inicio' => '09:00', 'hora_fin' => '12:00', 'invitados' => 50, 'espacios' => [$salon]], null, $hoy);
$ev->cambiarEstado($nid, $e4, 'separado', [], $dueno, $hoy);
$db->exec("UPDATE eventos SET fecha = CURRENT_DATE - 1, estado = 'realizado' WHERE id = $e4");
$m = $cob->mensajesDelDia($nid, $hoy);
check('Agradecer y pedir reseña al día siguiente', 1, count($m['resena']));
check('Sin enlace de reseñas: pide responder el mensaje', true, str_contains($m['resena'][0]['texto'], 'respóndenos'));
$db->exec("UPDATE negocios SET resenas_url = 'https://g.page/r/arrayanes/review' WHERE id = $nid");
check('Con enlace de reseñas: lo incluye', true, str_contains($cob->mensajesDelDia($nid, $hoy)['resena'][0]['texto'], 'https://g.page/r/arrayanes/review'));
$total = $cob->cuantosMensajes($nid, $hoy);
check('Total de mensajes de hoy', 6, $total);

titulo('9. Marcar como enviado (no se repite)');
$cuotaRef = $m['cuota'][0]['ref_id'];
$cob->marcarEnviado($nid, 'cuota', $cuotaRef, $dueno, $hoy);
check('La cuota ya no sale hoy', 0, count($cob->mensajesDelDia($nid, $hoy)['cuota']));
check('cuotas.aviso_enviado = hoy', $hoy->format('Y-m-d'), $db->query("SELECT aviso_enviado FROM cuotas WHERE id = $cuotaRef")->fetchColumn());
check('Mañana tampoco sale', 0, count($cob->mensajesDelDia($nid, $hoy->modify('+1 day'))['cuota']));
check('En 3 días vuelve a salir si sigue sin pagar', 1, count($cob->mensajesDelDia($nid, $hoy->modify('+3 days'))['cuota']));
$cob->marcarEnviado($nid, 'staff', $ep3, $dueno, $hoy);
$cob->marcarEnviado($nid, 'resena', $e4, $dueno, $hoy);
$cob->marcarEnviado($nid, 'invitados', $e3, $dueno, $hoy);
$m2 = $cob->mensajesDelDia($nid, $hoy);
check('Personal marcado: no sale', 0, count($m2['staff']));
check('Reseña marcada: no sale nunca más', 0, count($cob->mensajesDelDia($nid, $hoy->modify('+3 days'))['resena']));
check('Invitados marcado: no sale', 0, count($m2['invitados']));
$cob->marcarEnviado($nid, 'cuota', $cuotaRef, $dueno, $hoy);
check('Marcar dos veces no falla ni duplica', 1, (int) $db->query("SELECT count(*) FROM mensajes_enviados WHERE tipo = 'cuota' AND ref_id = $cuotaRef")->fetchColumn());
check('Enviados hoy: 4', 4, count($cob->enviadosHoy($nid, $hoy)));
$cob->desmarcarEnviado($nid, 'cuota', $cuotaRef, $hoy);
check('Deshacer: la cuota vuelve a salir', 1, count($cob->mensajesDelDia($nid, $hoy)['cuota']));
check('Deshacer limpia aviso_enviado', null, $db->query("SELECT aviso_enviado FROM cuotas WHERE id = $cuotaRef")->fetchColumn());
check_error('Deshacer algo que no se marcó', 'no estaba marcado', fn() => $cob->desmarcarEnviado($nid, 'contrato', $k1, $hoy));
check_error('Otro negocio no marca mis mensajes', 'ya no está en la lista', fn() => $cob->marcarEnviado($nidOtro, 'cuota', $cuotaRef, $duenoOtro, $hoy));
check_error('Tipo de mensaje inválido', 'no válido', fn() => $cob->marcarEnviado($nid, 'spam', 1, $dueno, $hoy));
check('Otro negocio no ve mis mensajes', 0, $cob->cuantosMensajes($nidOtro, $hoy));
// Contrato: firmarlo lo saca de la lista
$con->firmar($tk, 'María José Paredes', '1712345678', firma_png(), true);
check('Contrato firmado: ya no sale', 0, count($cob->mensajesDelDia($nid, $hoy)['contrato']));
check('Contrato firmado + reserva pagada: confirmado', 'confirmado', $ev->obtener($nid, $e1)['estado']);
// Pagar la cuota la saca de la lista
$pag->registrar($nid, $e1, ['monto' => $pag->resumen($e1, $hoy)['vencido'], 'metodo' => 'transferencia'], $dueno);
check('Cuota pagada: ya no sale', 0, count($cob->mensajesDelDia($nid, $hoy)['cuota']));

titulo('10. Payphone (con Payphone simulado)');
$db->prepare("UPDATE negocios SET payphone_token = 'tok-prueba', payphone_store_id = 'tienda-1' WHERE id = ?")->execute([$nid]);
$saldoAntes = $pag->resumen($e1, $hoy)['pagado'];
$pp = $pag->prepararPayphone($e1, 120.5);
check('Payphone: 12050 centavos', 12050, $pp['amount']);
check('Payphone: pago pendiente creado', 'pendiente', $db->query("SELECT estado FROM pagos WHERE referencia = " . $db->quote($pp['clientTransactionId']))->fetchColumn());
$llamadas = [];
$falso = function ($url, $cuerpo, $tok) use (&$llamadas) {
    $llamadas[] = [$url, $cuerpo, $tok];
    return ['transactionStatus' => 'Approved', 'amount' => 12050, 'clientTransactionId' => $cuerpo['clientTxId'], 'transactionId' => 9988];
};
[$estado, $tokCli] = $pag->confirmarPayphone(9988, $pp['clientTransactionId'], $falso);
check('Aprobado', 'aprobado', $estado);
check('Devuelve el token del portal', $ev->obtener($nid, $e1)['token_cliente'], $tokCli);
check('Llamó a /api/button/V2/Confirm con el token del negocio', true, str_ends_with($llamadas[0][0], '/api/button/V2/Confirm') && $llamadas[0][2] === 'tok-prueba');
check('Suma a lo pagado', round($saldoAntes + 120.5, 2), $pag->resumen($e1, $hoy)['pagado']);
$pidPP = (int) $db->query("SELECT id FROM pagos WHERE referencia = " . $db->quote($pp['clientTransactionId']))->fetchColumn();
$t = $texto_pdf((new PdfCobros($db))->recibo($nid, $pidPP));
check('Recibo de Payphone: forma de pago', true, str_contains($t, 'Payphone'));
check('Recibo de Payphone: monto en letras', true, str_contains($t, 'Ciento veinte dólares con 50/100'));
$pp2 = $pag->prepararPayphone($e1, 10);
[$estado] = $pag->confirmarPayphone(1, $pp2['clientTransactionId'], fn() => ['transactionStatus' => 'Canceled']);
check('Cancelado en Payphone', 'cancelado', $estado);
check('El pago cancelado queda anulado', 'anulado', $db->query("SELECT estado FROM pagos WHERE referencia = " . $db->quote($pp2['clientTransactionId']))->fetchColumn());
check_error('Código de Payphone inventado', 'no reconocido', fn() => $pag->confirmarPayphone(1, 'TE999-abcdef12', $falso));

titulo('11. Evento con cancelación: el portal no deja subir comprobantes');
$ev->cambiarEstado($nid, $e2, 'perdido', ['motivo' => 'Se fue a otro local'], $dueno, $hoy);
check_error('Comprobante en evento perdido', 'ya no está activo', fn() => $pag->subirComprobante($e2, foto_subida(), 10, 'transferencia'));
check('Evento perdido: su cotización ya no se recuerda', 0, count($cob->mensajesDelDia($nid, $hoy)['cotizacion']));
