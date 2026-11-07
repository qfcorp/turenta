<?php
/**
 * Gestión: reportes (ventas, cobrado, ocupación, conversión, rentabilidad, comisión), CSV, iCal,
 * tareas automáticas (cada aviso UNA vez, realizado automático, retenciones), panel Tukán, usuarios y configuración.
 */
use TuRenta\{Automaticas, Configuracion, Cotizaciones, Eventos, Pagos, PanelTukan, Planes, Reportes, Usuarios};

$db = base_limpia();
planes_de_antes($db);   // estas pruebas usan los planes de antes (Esencial $19, Salón $39, Quinta $69); los de $20 y $45: logica_112
$hoy = new DateTimeImmutable('2026-10-12');   // lunes
[$nid, $dueno] = negocio_prueba($db);
$db->prepare("UPDATE negocios SET prueba_hasta = '2027-12-31', comision_vendedor_pct = 5 WHERE id = ?")->execute([$nid]);
$salon = espacio_id($db, $nid, 'Salón principal');
$jardin = espacio_id($db, $nid, 'Jardín');
$ev = new Eventos($db);
$cot = new Cotizaciones($db);
$pag = new Pagos($db);
$rep = new Reportes($db);
$usu = new Usuarios($db);

titulo('1. Usuarios y límite del plan');
$v = $usu->crear($nid, 'Carla Vendedora', 'carla@prueba.ec', '0991112222', 'vendedor');
check('Vendedor creado con clave temporal de 10 letras', 10, strlen($v['clave']));
check('La clave temporal sirve para entrar', true, password_verify($v['clave'], (string) $db->query("SELECT password_hash FROM usuarios WHERE id = {$v['id']}")->fetchColumn()));
check('Queda marcado para cambiar la clave', true, (bool) $db->query("SELECT clave_temporal FROM usuarios WHERE id = {$v['id']}")->fetchColumn());
check_error('No se repite el correo', 'Ya existe un usuario', fn() => $usu->crear($nid, 'Otra', 'CARLA@prueba.ec', '', 'vendedor'));
check_error('No se crea otro dueño', 'Elige qué va a hacer', fn() => $usu->crear($nid, 'Otro', 'otro@prueba.ec', '', 'dueno'));
check_error('Correo malo', 'no es válido', fn() => $usu->crear($nid, 'Otro', 'otro', '', 'vendedor'));
check_error('El dueño no se quita a sí mismo', 'a ti mismo', fn() => $usu->activar($nid, (int) $dueno['id'], false, (int) $dueno['id']));
check_error('El dueño no se puede cambiar', 'dueño no se puede', fn() => $usu->cambiarRol($nid, (int) $dueno['id'], 'vendedor', $v['id']));
$coord = $usu->crear($nid, 'Pedro Coordinador', 'pedro@prueba.ec', '', 'coordinador');
$usu->cambiarRol($nid, $coord['id'], 'consulta', (int) $dueno['id']);
check('Cambiar rol', 'consulta', $db->query("SELECT rol FROM usuarios WHERE id = {$coord['id']}")->fetchColumn());
$usu->cambiarRol($nid, $coord['id'], 'coordinador', (int) $dueno['id']);
[$nidSalon, $duenoSalon] = negocio_prueba($db, 'salon', 'Salón Las Palmas');
$usu->crear($nidSalon, 'Uno', 'uno@palmas.ec', '', 'vendedor');
$dos = $usu->crear($nidSalon, 'Dos', 'dos@palmas.ec', '', 'coordinador');
check_error('Plan Salón: máximo 3 usuarios', 'permite 3 usuarios', fn() => $usu->crear($nidSalon, 'Tres', 'tres@palmas.ec', '', 'vendedor'));
$usu->activar($nidSalon, $dos['id'], false, (int) $duenoSalon['id']);
$tres = $usu->crear($nidSalon, 'Tres', 'tres@palmas.ec', '', 'vendedor');
check('Al desactivar a uno, se puede crear otro', true, $tres['id'] > 0);
check_error('Reactivar pasaría el límite', 'permite 3 usuarios', fn() => $usu->activar($nidSalon, $dos['id'], true, (int) $duenoSalon['id']));
check_error('Un negocio no cambia usuarios de otro', 'No se encontró', fn() => $usu->cambiarRol($nid, $tres['id'], 'consulta', (int) $dueno['id']));
check_error('Mi cuenta: clave actual mala', 'actual no es correcta', fn() => $usu->cambiarClave($v['id'], 'mala', 'nuevaClave1', 'nuevaClave1'));
check_error('Mi cuenta: no coinciden', 'no son iguales', fn() => $usu->cambiarClave($v['id'], $v['clave'], 'nuevaClave1', 'nuevaClave2'));
check_error('Mi cuenta: muy corta', 'al menos 8', fn() => $usu->cambiarClave($v['id'], $v['clave'], 'corta', 'corta'));
$usu->cambiarClave($v['id'], $v['clave'], 'nuevaClave1', 'nuevaClave1');
check('Clave cambiada y ya no es temporal', false, (bool) $db->query("SELECT clave_temporal FROM usuarios WHERE id = {$v['id']}")->fetchColumn());
$nueva = $usu->nuevaClave($nid, $v['id'], (int) $dueno['id']);
check('El dueño da una clave nueva', true, password_verify($nueva['clave'], (string) $db->query("SELECT password_hash FROM usuarios WHERE id = {$v['id']}")->fetchColumn()));
$usu->guardarPerfil($v['id'], 'Carla Andrade', '0993334444');
check('Perfil guardado', 'Carla Andrade', $db->query("SELECT nombre FROM usuarios WHERE id = {$v['id']}")->fetchColumn());

titulo('2. Configuración valida los datos');
$conf = new Configuracion($db);
check_error('RUC de 13 dígitos', 'RUC debe tener 13', fn() => $conf->guardarDatos($nid, ['nombre' => 'Quinta Los Arrayanes', 'ruc' => '17900']));
check_error('Color válido', 'color', fn() => $conf->guardarDatos($nid, ['nombre' => 'Quinta Los Arrayanes', 'color' => 'rojo']));
check_error('WhatsApp válido', 'WhatsApp no es válido', fn() => $conf->guardarDatos($nid, ['nombre' => 'Quinta Los Arrayanes', 'whatsapp' => '12', 'color' => '#6B2D3C']));
$conf->guardarDatos($nid, ['nombre' => 'Quinta Los Arrayanes', 'ruc' => '1790000000001', 'whatsapp' => '0990000000', 'direccion' => 'Vía a Tumbaco km 5',
    'instagram' => 'https://www.instagram.com/losarrayanes.ec/', 'mapa_url' => 'maps.app.goo.gl/abc', 'color' => '#3e7c59', 'ciudad' => 'Tumbaco']);
$N = $conf->negocio($nid);
check('Instagram: se guarda solo el usuario', 'losarrayanes.ec', $N['instagram']);
check('Mapa: se completa https://', 'https://maps.app.goo.gl/abc', $N['mapa_url']);
check('Color en mayúsculas', '#3E7C59', $N['color']);
$reglas = ['iva_pct' => '15', 'validez_cotizacion' => '15', 'dias_retencion' => '7', 'reserva_pct' => '30', 'cuotas_defecto' => '3',
           'dias_saldo_antes' => '15', 'garantia_defecto' => '300,00', 'horas_montaje' => '3', 'horas_desmontaje' => '1,5', 'hora_limite' => '02:00',
           'invitados_por_mesero' => '15', 'meses_revisar_precio' => '6', 'comision_vendedor_pct' => '5', 'condiciones' => 'Precios en dólares.'];
check_error('Reglas: IVA fuera de rango', 'IVA', fn() => $conf->guardarReglas($nid, ['iva_pct' => '80'] + $reglas));
check_error('Reglas: reserva 0', 'reserva', fn() => $conf->guardarReglas($nid, ['reserva_pct' => '0'] + $reglas));
check_error('Reglas: hora límite', 'hora límite', fn() => $conf->guardarReglas($nid, ['hora_limite' => '25:00'] + $reglas));
$conf->guardarReglas($nid, $reglas);
$N = $conf->negocio($nid);
check('Garantía con coma', 300.0, (float) $N['garantia_defecto']);
check('Desmontaje 1,5 horas', 1.5, (float) $N['horas_desmontaje']);
check('Retención 7 días', 7, (int) $N['dias_retencion']);
check_error('Payphone necesita las dos claves', 'las dos claves', fn() => $conf->guardarCobros($nid, ['payphone_token' => str_repeat('a', 40)]));
$conf->guardarCobros($nid, ['pago_banco' => 'Banco Pichincha cta. ahorros 2200000000', 'pago_deuna' => '0990000000',
                            'payphone_token' => str_repeat('a', 40), 'payphone_store_id' => 'abc-123']);
$conf->guardarCobros($nid, ['pago_banco' => 'Banco Pichincha', 'payphone_token' => '', 'payphone_store_id' => 'abc-123']);
check('El token de Payphone se conserva si se deja vacío', str_repeat('a', 40), $conf->negocio($nid)['payphone_token']);
$conf->guardarCobros($nid, ['quitar_payphone' => '1']);
check('Quitar Payphone', null, $conf->negocio($nid)['payphone_token']);
$t0 = $conf->negocio($nid)['token_ical'];
$t1 = $conf->cambiarTokenIcal($nid);
check('Nuevo enlace del calendario', true, $t0 !== $t1 && strlen($t1) === 32);
$antes = count($conf->tareas($nid));
$tid = $conf->guardarTarea($nid, ['titulo' => 'Prueba de peinado', 'dias_antes' => '20', 'tipo_evento' => 'quince']);
check('Tarea agregada a la plantilla', $antes + 1, count($conf->tareas($nid)));
$conf->guardarTarea($nid, ['titulo' => 'Prueba de peinado y maquillaje', 'dias_antes' => '21', 'tipo_evento' => 'quince'], $tid);
check('Tarea editada', 21, (int) $db->query("SELECT dias_antes FROM plantilla_tareas WHERE id = $tid")->fetchColumn());
check_error('Días no válidos', 'días antes', fn() => $conf->guardarTarea($nid, ['titulo' => 'Algo', 'dias_antes' => 'mucho']));
check_error('No borra tareas de otro negocio', 'No se encontró', fn() => $conf->borrarTarea($nidSalon, $tid));
$conf->borrarTarea($nid, $tid);
check('Tarea borrada', $antes, count($conf->tareas($nid)));
$conf->guardarTransmision($nid, '0987654321');
check('WhatsApp de transmisiones normalizado', '593987654321', $conf->negocio($nid)['whatsapp_transmision']);
$pasos = $conf->primerosPasos($nid, (int) $dueno['id']);
check('Primeros pasos: datos completos', true, $pasos[0]['hecho']);
check('Primeros pasos: sin logo', false, $pasos[1]['hecho']);
check('Primeros pasos: sin eventos', false, $pasos[3]['hecho']);
$conf->guardarLogo($nid, foto_subida());
check('Logo subido', true, $conf->primerosPasos($nid, (int) $dueno['id'])[1]['hecho']);

titulo('3. Datos para los reportes');
// Helper: evento vendido con cotización aceptada y pagos
$vender = function (string $nombre, string $fecha, int $inv, array $esp, string $origen, ?int $vendedor, string $estado = 'separado', ?string $vendidoEl = null)
    use ($db, $ev, $cot, $pag, $nid, $dueno, $hoy): int {
    $id = $ev->crear($nid, ['cliente' => ['nombre' => $nombre, 'telefono' => '09' . random_int(10000000, 99999999)], 'tipo' => 'boda', 'fecha' => $fecha,
        'hora_inicio' => '18:00', 'hora_fin' => '02:00', 'invitados' => $inv, 'espacios' => $esp, 'origen' => $origen, 'vendedor_id' => $vendedor], (int) $dueno['id'], $hoy);
    $c = $cot->crear($nid, $id, null, null, $hoy);           // alquiler del espacio (precio base)
    $cot->aceptar($nid, $c, $nombre, '', $dueno, $hoy);
    $ev->cambiarEstado($nid, $id, 'separado', [], $dueno, $hoy);
    if ($estado === 'confirmado') $ev->cambiarEstado($nid, $id, 'confirmado', [], $dueno, $hoy);
    if ($vendidoEl) {
        $db->prepare("UPDATE registro_cambios SET creado_en = ? WHERE evento_id = ? AND accion LIKE '%a Separado%'")->execute([$vendidoEl . ' 10:00', $id]);
        $db->prepare('UPDATE eventos SET confirmado_en = CASE WHEN confirmado_en IS NULL THEN NULL ELSE ?::timestamp END, creado_en = ? WHERE id = ?')
           ->execute([$vendidoEl . ' 12:00', $vendidoEl . ' 09:00', $id]);
    }
    return $id;
};
// Salón principal vale $1.500 + IVA = $1.725 ; Jardín $1.200 + IVA = $1.380
$a = $vender('Ana Torres', '2026-11-14', 150, [$salon], 'instagram', $v['id'], 'confirmado', '2026-10-03');   // sábado
$b = $vender('Bruno Paz', '2027-02-20', 100, [$jardin], 'referido', null, 'separado', '2026-10-05');            // sábado
$c = $vender('Carmen Ruiz', '2026-10-31', 200, [$salon, $jardin], 'instagram', $v['id'], 'separado', '2026-09-20'); // sábado, vendido el mes pasado
$pag->registrar($nid, $a, ['monto' => 517.5, 'metodo' => 'transferencia', 'fecha' => '2026-10-03 11:00'], $dueno);
$pag->registrar($nid, $b, ['monto' => 414, 'metodo' => 'efectivo', 'fecha' => '2026-10-05 16:00'], $dueno);
$pag->registrar($nid, $c, ['monto' => 1000, 'metodo' => 'deuna', 'fecha' => '2026-09-20 09:00'], $dueno);
$pag->registrar($nid, $c, ['monto' => 500, 'metodo' => 'garantia' === 'x' ? 'efectivo' : 'efectivo', 'tipo' => 'garantia', 'fecha' => '2026-10-06 09:00'], $dueno);
// Consultas que no se vendieron
$p1 = $ev->crear($nid, ['cliente' => ['nombre' => 'Diego Perdido', 'telefono' => '0981111111'], 'tipo' => 'quince', 'fecha' => '2026-12-05',
    'invitados' => 80, 'espacios' => [$salon], 'origen' => 'facebook', 'vendedor_id' => $v['id']], null, $hoy);
$ev->cambiarEstado($nid, $p1, 'perdido', ['motivo' => 'Precio alto'], $dueno, $hoy);
$p2 = $ev->crear($nid, ['cliente' => ['nombre' => 'Elena Perdida', 'telefono' => '0982222222'], 'tipo' => 'boda', 'fecha' => '2026-12-12',
    'invitados' => 90, 'espacios' => [$jardin], 'origen' => 'instagram'], null, $hoy);
$ev->cambiarEstado($nid, $p2, 'perdido', ['motivo' => 'precio alto'], $dueno, $hoy);
$abierta = $ev->crear($nid, ['cliente' => ['nombre' => 'Fabián Consulta', 'telefono' => '0983333333'], 'tipo' => 'boda', 'fecha' => '2027-03-06',
    'invitados' => 120, 'espacios' => [$salon], 'origen' => 'instagram'], null, $hoy);
$db->exec("UPDATE eventos SET creado_en = '2026-10-08 10:00' WHERE id IN ($p1, $p2, $abierta)");
// Gastos y personal del evento A
$db->prepare("INSERT INTO gastos (negocio_id, evento_id, fecha, categoria, concepto, monto) VALUES (?,?,'2026-10-10','decoracion','Flores',200)")->execute([$nid, $a]);
$db->prepare("INSERT INTO gastos (negocio_id, evento_id, fecha, categoria, concepto, monto) VALUES (?,NULL,'2026-10-10','otros','Luz del mes',80)")->execute([$nid]);
$mesero = (int) $db->query("INSERT INTO personal (negocio_id, nombre, rol, pago_evento) VALUES ($nid, 'Mesero Uno', 'mesero', 40) RETURNING id")->fetchColumn();
$db->prepare('INSERT INTO evento_personal (evento_id, personal_id, rol, pago) VALUES (?,?,?,?)')->execute([$a, $mesero, 'mesero', 40]);
$db->prepare("UPDATE eventos SET costo_estimado = 300 WHERE id = ?")->execute([$b]);

titulo('4. Reporte de ventas');
$per = Reportes::periodo('mes', null, null, $hoy);
check('Periodo este mes', ['2026-10-01', '2026-10-31'], [$per['desde'], $per['hasta']]);
check('Periodo mes pasado', '2026-09-01', Reportes::periodo('mes_pasado', null, null, $hoy)['desde']);
check('Periodo rango al revés se ordena', '2026-01-01', Reportes::periodo('rango', '2026-03-01', '2026-01-01', $hoy)['desde']);
check('Periodo rango inválido = este mes', 'mes', Reportes::periodo('rango', 'x', 'y', $hoy)['clave']);
$ven = $rep->ventas($nid, $per['desde'], $per['hasta']);
check('Vendidos en octubre (por fecha de venta): 2', 2, $ven['cantidad']);
check('Valor vendido en octubre', 3105.0, $ven['valor']);
check('Sin IVA', 2700.0, $ven['sin_iva']);
check('Ticket promedio', 1552.5, $ven['ticket']);
check('Precio promedio por invitado', 12.42, $ven['por_invitado']);
check('Por fecha del evento en octubre: solo Carmen', 1, $ven['por_evento']['cantidad']);
check('Gráfico: 6 meses (un solo mes muestra los anteriores)', 6, count($ven['por_mes']));
check('Gráfico: septiembre tiene 1 venta', 1, $ven['por_mes']['2026-09']['cantidad']);

titulo('5. Cobrado y por cobrar');
$cob = $rep->cobrado($nid, $per['desde'], $per['hasta']);
check('Cobrado en octubre (sin garantías)', 931.5, $cob['total']);
check('Cobrado septiembre en el gráfico', 1000.0, $cob['por_mes']['2026-09']);
$pc = $rep->porCobrar($nid, $hoy);
check('Por cobrar de los vendidos', round(1725 + 1380 + 3105 - 931.5 - 1000, 2), $pc['saldo']);
check('Tres eventos con saldo', 3, count($pc['lista']));

titulo('6. Ocupación de los próximos 12 meses');
$oc = $rep->ocupacion($nid, $hoy);
check('52 sábados en 12 meses', 52, $oc['sabados']);
check('3 sábados vendidos', 3, $oc['sabados_vendidos']);
check('% de sábados ocupados', 6, $oc['pct_sabados']);
check('Salón principal: 2 fechas', 2, $oc['espacios'][0]['fechas']);
check('Jardín: 2 fechas', 2, $oc['espacios'][1]['fechas']);
check('Noviembre: 1 de 4 sábados', ['sabados' => 4, 'vendidos' => 1, 'pct' => 25], $oc['por_mes']['2026-11']);

titulo('7. Embudo y conversión (consultas que llegaron en octubre)');
$cv = $rep->conversion($nid, $per['desde'], $per['hasta']);
check('5 consultas en octubre', 5, $cv['total']['consultas']);
check('2 vendidas', 2, $cv['total']['vendidos']);
check('Conversión 40 %', 40, $cv['total']['pct']);
check('Instagram: 3 consultas, 1 venta', [3, 1], [$cv['por_origen']['Instagram']['consultas'], $cv['por_origen']['Instagram']['vendidos']]);
check('Recomendación convierte 100 %', 100, $cv['por_origen']['Recomendación']['pct']);
check('Motivo más común (sin importar mayúsculas)', ['Precio alto', 2], [$cv['motivos'][0]['motivo'], $cv['motivos'][0]['cantidad']]);
check('Por vendedor: Carla 2 consultas', 2, $cv['por_vendedor']['Carla Andrade']['consultas']);
check('Por tipo: Quince años 1 perdida', 1, $cv['por_tipo']['Quince años']['perdidos']);
check('Embudo: 1 sigue en consulta', 1, $cv['embudo']['consulta']);

titulo('8. Rentabilidad por evento');
$rt = $rep->rentabilidad($nid, '2026-11-01', '2027-02-28');
check('Dos eventos (Ana y Bruno)', 2, count($rt['lista']));
$ra = $rt['lista'][0];
check('Ana: sin IVA 1.500', 1500.0, $ra['sin_iva']);
check('Ana: gastos reales 200', 200.0, $ra['costo']);
check('Ana: pago al personal 40', 40.0, $ra['personal']);
check('Ana: ganancia 1.260', 1260.0, $ra['ganancia']);
check('Ana: margen 84 %', 84, $ra['margen']);
$rb = $rt['lista'][1];
check('Bruno: sin gastos usa el costo estimado', [true, 300.0], [$rb['usa_estimado'], $rb['costo']]);
check('Bruno: ganancia 900', 900.0, $rb['ganancia']);
check('Total ganancia', 2160.0, $rt['total']['ganancia']);
check('Gastos generales en el periodo (no hay)', 0.0, $rt['gastos_generales']);
check('Gastos generales de octubre', 80.0, $rep->rentabilidad($nid, $per['desde'], $per['hasta'])['gastos_generales']);

titulo('9. Comisión de vendedores');
$cm = $rep->comisiones($nid, $per['desde'], $per['hasta']);
check('Comisión 5 %', 5.0, $cm['pct']);
$carla = array_values(array_filter($cm['lista'], fn($x) => $x['vendedor'] === 'Carla Andrade'))[0] ?? null;
check('Carla vendió Ana (1.500 sin IVA)', 1500.0, $carla['sin_iva'] ?? null);
check('Carla gana 75', 75.0, $carla['comision'] ?? null);
check('El dueño (vendedor de Bruno) no cobra comisión', 0.0, array_values(array_filter($cm['lista'], fn($x) => $x['vendedor'] === 'Dimitry Prueba'))[0]['comision'] ?? -1);
check('Total comisiones', 75.0, $cm['total']);

titulo('10. Exportar CSV');
[$enc, $filas] = $rep->exportar($nid, 'eventos', '2026-10-01', '2027-12-31');
check('Eventos exportados', 6, count($filas));
$csv = Reportes::csv($enc, $filas);
check('CSV con BOM UTF-8', "\xEF\xBB\xBF", substr($csv, 0, 3));
check('CSV con separador ;', true, str_starts_with(substr($csv, 3), 'Código;Evento;Tipo;Fecha'));
check('CSV con fin de línea de Windows', true, str_contains($csv, "\r\n"));
check('Montos con coma decimal', true, str_contains($csv, '1725,00'));
$peligro = Reportes::csv(['a'], [['=HYPERLINK("x")'], ['-5,00'], ['-algo']]);
check('Fórmulas desactivadas', true, str_contains($peligro, "'=HYPERLINK"));
check('Negativos numéricos se quedan', true, str_contains($peligro, "\r\n-5,00\r\n"));
[$enc, $filas] = $rep->exportar($nid, 'pagos', '2026-09-01', '2026-10-31');
check('Pagos exportados (incluye garantía)', 4, count($filas));
[$enc, $filas] = $rep->exportar($nid, 'cuotas', '2026-01-01', '2027-12-31', $hoy);
check('Cuotas exportadas', true, count($filas) >= 3);
[$enc, $filas] = $rep->exportar($nid, 'gastos', '2026-10-01', '2026-10-31');
check('Gastos exportados', 2, count($filas));
check('Gasto general marcado', 'Gasto general', $filas[0][5] === 'Gasto general' || $filas[1][5] === 'Gasto general' ? 'Gasto general' : '');
[$enc, $filas] = $rep->exportar($nid, 'clientes', '2026-10-01', '2026-10-31');
check('Clientes exportados', true, count($filas) >= 5);
check_error('Tipo de exportación inválido', 'no existe', fn() => $rep->exportar($nid, 'nada', '2026-10-01', '2026-10-31'));

titulo('11. Calendario iCal');
$ret = $ev->crear($nid, ['cliente' => ['nombre' => 'Gina Retenida', 'telefono' => '0984444444'], 'tipo' => 'boda', 'fecha' => '2026-11-21',
    'hora_inicio' => '12:00', 'hora_fin' => '18:00', 'invitados' => 80, 'espacios' => [$jardin]], null, $hoy);
$ev->cambiarEstado($nid, $ret, 'cotizado', [], $dueno, $hoy);
$ics = $rep->calendarioIcs($nid, 'https://turenta.tukanec.com', $hoy);
check('Empieza con BEGIN:VCALENDAR', true, str_starts_with($ics, "BEGIN:VCALENDAR\r\n"));
check('Termina con END:VCALENDAR', true, str_ends_with($ics, "END:VCALENDAR\r\n"));
check('4 eventos (3 vendidos + 1 retenido)', 4, substr_count($ics, 'BEGIN:VEVENT'));
check('No incluye perdidos ni consultas', false, str_contains($ics, 'Diego') || str_contains($ics, 'Fabián'));
check('Retenido marcado', true, str_contains($ics, 'SUMMARY:RETENIDO · Boda de Gina Retenida'));
check('Retenido es tentativo', true, str_contains($ics, 'STATUS:TENTATIVE'));
check('Hora de Ecuador', true, str_contains($ics, 'DTSTART;TZID=America/Guayaquil:20261114T180000'));
check('Pasa la medianoche: termina al día siguiente', true, str_contains($ics, 'DTEND;TZID=America/Guayaquil:20261115T020000'));
check('UID estable', true, str_contains($ics, "UID:evento-$a@turenta.tukanec.com"));
check('Comas escapadas', true, str_contains($ics, 'Salón principal\, Jardín'));
check('Sin montos de dinero', false, str_contains($ics, '1.725') || str_contains($ics, '$'));
$largo = max(array_map('strlen', explode("\r\n", $ics)));
check('Ninguna línea pasa de 75 bytes', true, $largo <= 75);
check('Token falso no encuentra negocio', null, $rep->negocioPorTokenIcal('abc'));
check('Token bueno encuentra el negocio', $nid, (int) ($rep->negocioPorTokenIcal($t1)['id'] ?? 0));

titulo('12. Inicio');
$ci = $rep->cifrasInicio($nid, $hoy);
check('Eventos este mes (Carmen el 31)', 1, $ci['eventos_mes']);
check('Próximos 30 días (Carmen)', 1, $ci['proximos_30']);
check('Consultas sin atender: Fabián', 1, $ci['consultas_nuevas']);
$ev->agregarActividad($nid, $abierta, null, 'llamada', 'Le llamé, viene el sábado', null, $v['id']);
check('Con una nota ya no está "sin atender"', 0, $rep->cifrasInicio($nid, $hoy)['consultas_nuevas']);
check('Retenida que vence en 2 días: ninguna (7 días de retención)', 0, count($rep->retenidasPorVencer($nid, 2, $hoy)));
check('Retenida que vence en 7 días: Gina', 1, count($rep->retenidasPorVencer($nid, 7, $hoy)));

titulo('13. Tareas automáticas: cada aviso UNA vez');
$auto = new Automaticas($db);
$avisos = fn() => (int) $db->query("SELECT count(*) FROM notificaciones WHERE negocio_id = $nid")->fetchColumn();
$db->exec("DELETE FROM notificaciones");
$a0 = $avisos();
// Retención vencida: Gina retenida hasta 2026-10-19
$r = $auto->correr(new DateTimeImmutable('2026-10-20 06:00'));
check('Fecha retenida liberada: 1 aviso', 1, $r['retenciones']);
check('Aviso "se liberó" en la campana', 1, (int) $db->query("SELECT count(*) FROM notificaciones WHERE texto LIKE 'La fecha que estaba retenida para Boda de Gina Retenida%'
                                                               AND usuario_id = {$dueno['id']}")->fetchColumn());
check('Antes de las 8:00 no salen avisos de cobro', 0, $r['cuotas']);
$r = $auto->correr(new DateTimeImmutable('2026-10-20 07:00'));
check('La segunda vuelta no repite la retención', 0, $r['retenciones']);
$ev->extenderRetencion($nid, $ret, 3, new DateTimeImmutable('2026-10-20'));
$r = $auto->correr(new DateTimeImmutable('2026-10-24 06:00'));
check('Si se vuelve a retener y vence otra vez, avisa de nuevo', 1, $r['retenciones']);
$db->exec("DELETE FROM notificaciones");
// Cuotas: las de Carmen (evento 31/10) vencen pronto
$r1 = $auto->correr(new DateTimeImmutable('2026-10-20 08:05'));
check('Aviso de cobros a las 8:00: 1', 1, $r1['cuotas']);
$txt = (string) $db->query("SELECT texto FROM notificaciones WHERE texto LIKE '💰 Cobros:%' LIMIT 1")->fetchColumn();
check('El aviso de cobros dice cuántas y cuánto', true, str_contains($txt, 'cuota') && str_contains($txt, '$'));
check('Llega al dueño y a la vendedora', 2, (int) $db->query("SELECT count(*) FROM notificaciones WHERE texto LIKE '💰 Cobros:%'")->fetchColumn());
$r2 = $auto->correr(new DateTimeImmutable('2026-10-20 15:05'));
check('El mismo día no se repite', 0, $r2['cuotas']);
$r3 = $auto->correr(new DateTimeImmutable('2026-10-21 08:05'));
check('Al día siguiente sale otra vez', 1, $r3['cuotas']);
// Semana: Carmen (31/10) está en 7 días el 24/10
$db->exec("DELETE FROM notificaciones");
$r = $auto->correr(new DateTimeImmutable('2026-10-24 09:00'));
check('Evento en 7 días: 1 aviso', 1, $r['semana']);
$sem = (string) $db->query("SELECT texto FROM notificaciones WHERE texto LIKE '📅%' LIMIT 1")->fetchColumn();
check('El aviso de la semana trae las tareas pendientes', true, str_contains($sem, 'tareas pendientes'));
$r = $auto->correr(new DateTimeImmutable('2026-10-25 09:00'));
check('No se repite el aviso de la semana', 0, $r['semana']);
$db->prepare("UPDATE eventos SET fecha = '2026-11-01' WHERE id = ?")->execute([$c]);
$r = $auto->correr(new DateTimeImmutable('2026-10-25 10:00'));
check('Si cambia la fecha, avisa para la fecha nueva', 1, $r['semana']);
$db->prepare("UPDATE eventos SET fecha = '2026-10-31' WHERE id = ?")->execute([$c]);
titulo('14. Realizado automático y garantía');
$db->exec("DELETE FROM notificaciones");
$r = $auto->correr(new DateTimeImmutable('2026-11-01 01:30'));
check('A la 01:30 la boda de Carmen sigue (termina 02:00): no se marca', 'separado', $db->query("SELECT estado FROM eventos WHERE id = $c")->fetchColumn());
$r = $auto->correr(new DateTimeImmutable('2026-11-01 04:00'));
check('Después de terminar pasa a realizado', 'realizado', $db->query("SELECT estado FROM eventos WHERE id = $c")->fetchColumn());
check('Se cuenta 1 realizado', 1, $r['realizados']);
check('Aviso: devolver la garantía ($500) al dueño', 1, (int) $db->query("SELECT count(*) FROM notificaciones WHERE texto LIKE '%Devuelve la garantía de%' AND texto LIKE '%\$500,00%' AND usuario_id = {$dueno['id']}")->fetchColumn());
check('Y a la vendedora del evento', 1, (int) $db->query("SELECT count(*) FROM notificaciones WHERE texto LIKE '%Devuelve la garantía de%' AND usuario_id = {$v['id']}")->fetchColumn());
check('El historial dice que lo hizo el Sistema', 1, (int) $db->query("SELECT count(*) FROM registro_cambios WHERE evento_id = $c AND quien = 'Sistema' AND accion LIKE '%a Realizado%'")->fetchColumn());
check('Los espacios siguen bloqueados (fecha usada)', true, (bool) $db->query("SELECT bool_and(bloquea) FROM evento_espacios WHERE evento_id = $c")->fetchColumn());
$r = $auto->correr(new DateTimeImmutable('2026-11-01 05:00'));
check('No se repite', 0, $r['realizados']);
$auto->correr(new DateTimeImmutable('2026-11-16 09:00'));
check('Un evento confirmado también pasa a realizado', 'realizado', $db->query("SELECT estado FROM eventos WHERE id = $a")->fetchColumn());
check('Un evento futuro no se toca', 'separado', $db->query("SELECT estado FROM eventos WHERE id = $b")->fetchColumn());

titulo('15. Reporte del mes y cotizaciones vencidas');
// Las fechas de esta sección son fijas (oct-nov 2026): la demo del otro negocio vence antes del 1 de noviembre, corra el día que corra
$db->prepare("UPDATE negocios SET prueba_hasta = '2026-10-25' WHERE id = ?")->execute([$nidSalon]);
$db->exec("DELETE FROM notificaciones");
$db->exec("UPDATE negocios SET reporte_enviado_mes = NULL");
$r = $auto->correr(new DateTimeImmutable('2026-11-01 06:30'));
check('Antes de las 7:00 no sale el reporte', 0, $r['reportes']);
$r = $auto->correr(new DateTimeImmutable('2026-11-01 07:10'));
check('El día 1 a las 7:00 sale el reporte', 1, $r['reportes']);
check('El reporte llega solo al dueño', 1, (int) $db->query("SELECT count(*) FROM notificaciones WHERE texto LIKE '📊 Tu reporte de octubre 2026%'")->fetchColumn());
check('Se marca el mes enviado', '2026-10', $db->query("SELECT reporte_enviado_mes FROM negocios WHERE id = $nid")->fetchColumn());
$r = $auto->correr(new DateTimeImmutable('2026-11-01 08:10'));
check('No se repite el reporte', 0, $r['reportes']);
$resumen = $rep->resumenMes($nid, '2026-10', new DateTimeImmutable('2026-11-01'));
check('Resumen: 2 vendidos en octubre', 2, $resumen['vendidos']);
$texto = Reportes::textoResumenMes($resumen, 'Quinta Los Arrayanes');
check('Texto del reporte con el motivo de pérdida', true, str_contains($texto, 'Precio alto'));
$cq = $cot->crear($nid, $abierta, null, null, new DateTimeImmutable('2026-10-01'));
$cot->enviar($nid, $cq, $dueno);
$db->prepare("UPDATE cotizaciones SET valida_hasta = '2026-10-15' WHERE id = ?")->execute([$cq]);
$r = $auto->correr(new DateTimeImmutable('2026-10-20 06:00'));
check('Cotización enviada vencida', 'vencida', $db->query("SELECT estado FROM cotizaciones WHERE id = $cq")->fetchColumn());
check('Línea de resumen', true, str_contains(Automaticas::resumen(new DateTimeImmutable('2026-10-20 06:00'), $r), 'negocios'));
// Un negocio con la prueba vencida no recibe tareas
$db->prepare("UPDATE negocios SET prueba_hasta = '2026-01-01' WHERE id = ?")->execute([$nidSalon]);
$r = $auto->correr(new DateTimeImmutable('2026-10-20 06:00'));
check('Solo negocios al día', 1, $r['negocios']);
// Revisar precio: Bruno (20/02/2027) se vendió en octubre 2026; con 6 meses no aplica (se vendió a 4,5 meses). Un evento lejano sí.
$lejos = $vender('Hugo Lejano', '2027-09-18', 100, [$salon], 'google', $v['id'], 'separado');
$r = $auto->correr(new DateTimeImmutable('2027-03-18 09:00'));
check('Revisar precio a 6 meses del evento: 1', 1, $r['precios']);
check('Marca aviso_precio_enviado', true, (bool) $db->query("SELECT aviso_precio_enviado FROM eventos WHERE id = $lejos")->fetchColumn());
$r = $auto->correr(new DateTimeImmutable('2027-03-19 09:00'));
check('Revisar precio no se repite', 0, $r['precios']);
check('Bruno no pide revisar precio (se vendió cerca)', false, (bool) $db->query("SELECT aviso_precio_enviado FROM eventos WHERE id = $b")->fetchColumn());


titulo('16. Seguimientos del día por Telegram (sin Telegram configurado no hace nada)');
check('Sin Telegram: 0', 0, $auto->enviarSeguimientos($nid, new DateTimeImmutable('2026-10-20 08:30')));

titulo('17. Panel Tukán');
$panel = new PanelTukan($db);
$res = $panel->resumen($hoy);
check('Dos negocios', 2, $res['total']);
check('Uno en prueba y uno con la prueba vencida', [1, 1], [$res['cuenta']['prueba'], $res['cuenta']['prueba_vencida']]);
$pg = $panel->registrarPago($nidSalon, ['plan' => 'salon', 'periodo' => 'anual', 'inicio' => '2026-10-12', 'metodo' => 'transferencia', 'monto' => '', 'dominio' => 'LasPalmas.com']);
check('Pago anual: paga 10 meses', 390.0, $pg['monto']);
$N2 = $panel->negocio($nidSalon, $hoy);
check('Queda activo', 'activo', $N2['estado_cuenta']);
check('Pagado hasta (12 meses)', '2027-10-11', $N2['activo_hasta']);
check('Dominio guardado en minúsculas', 'laspalmas.com', $db->query("SELECT dominio FROM suscripciones WHERE id = {$pg['id']}")->fetchColumn());
$res = $panel->resumen($hoy);
check('Cobrado del mes', 390.0, $res['cobrado_mes']);
check('Ingreso mensual recurrente = lo que paga al mes con su plan y módulos (Salón)', 39.0, $res['mensual']);
check_error('Pago sin fecha', 'fecha de inicio', fn() => $panel->registrarPago($nidSalon, ['plan' => 'salon', 'periodo' => 'mensual', 'inicio' => '', 'metodo' => 'efectivo']));
check_error('Pago sin método', 'cómo pagó', fn() => $panel->registrarPago($nidSalon, ['plan' => 'salon', 'periodo' => 'mensual', 'inicio' => '2026-10-12', 'metodo' => 'bitcoin']));
check_error('Dominio malo', 'dominio no es válido', fn() => $panel->registrarPago($nidSalon, ['plan' => 'salon', 'periodo' => 'anual', 'inicio' => '2026-10-12', 'metodo' => 'efectivo', 'dominio' => 'no válido']));
$cort = $panel->registrarPago($nid, ['plan' => 'quinta', 'periodo' => 'mensual', 'inicio' => '2026-10-12', 'metodo' => 'cortesia']);
check('Cortesía sin monto = gratis', 0.0, $cort['monto']);
(new Planes($db))->anularPago($cort['id']);
check('Anular deja el negocio sin fecha de pago', null, $panel->negocio($nid, $hoy)['activo_hasta']);
$hasta = $panel->extenderPrueba($nid, 7, $hoy);
check('Extender prueba 7 días desde su fin', '2028-01-07', $hasta);
check_error('Extender: días fuera de rango', 'de 1 a 90', fn() => $panel->extenderPrueba($nid, 200, $hoy));
$panel->cambiarPlan($nidSalon, 'quinta');
check('Cambiar plan', 'quinta', $db->query("SELECT plan_codigo FROM negocios WHERE id = $nidSalon")->fetchColumn());
check_error('Plan inexistente', 'Plan no válido', fn() => $panel->cambiarPlan($nidSalon, 'oro'));
$panel->cambiarEstado($nidSalon, 'suspendido');
check('Suspendido', 'suspendido', $panel->negocio($nidSalon, $hoy)['estado_cuenta']);
check('Filtro por estado', 1, count($panel->negocios('suspendido', '', $hoy)));
check('Búsqueda por nombre', 1, count($panel->negocios('', 'palmas', $hoy)));
check('Búsqueda por correo del dueño', 1, count($panel->negocios('', $dueno['email'], $hoy)));
$clave = $panel->nuevaClaveDueno($nid);
check('Nueva clave del dueño funciona', true, password_verify($clave['clave'], (string) $db->query("SELECT password_hash FROM usuarios WHERE id = {$dueno['id']}")->fetchColumn()));
$panel->guardarNotas($nid, 'Paga el 5 de cada mes');
check('Notas internas', 'Paga el 5 de cada mes', $panel->negocio($nid, $hoy)['notas_admin']);
$fila = $panel->negocio($nid, $hoy);
check('Ficha: eventos del último mes', true, (int) $fila['eventos_30'] >= 6);
check('Ficha: usuarios activos', 3, (int) $fila['usuarios']);
check('Ficha: espacios', 2, (int) $fila['espacios']);
check('Ficha: última actividad', true, $fila['ultima_actividad'] !== null);
check('Pagos del negocio', 1, count($panel->pagos($nidSalon)));
