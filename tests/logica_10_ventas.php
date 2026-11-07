<?php
/**
 * Módulo de ventas: calendario, próximos sábados, disponibilidad pública, consulta desde la web,
 * seguimiento, clientes, tablero, espacios (límite del plan) y fechas cerradas.
 */
use TuRenta\{Eventos, Ventas, Cotizaciones};

$db = base_limpia();
planes_de_antes($db);   // estas pruebas usan los planes de antes (Esencial $19, Salón $39, Quinta $69); los de $20 y $45: logica_112
$hoy = new DateTimeImmutable('today');
[$nid, $dueno] = negocio_prueba($db);
$ev = new Eventos($db);
$ve = new Ventas($db);
$salon = espacio_id($db, $nid, 'Salón principal');
$jardin = espacio_id($db, $nid, 'Jardín');

titulo('1. Semanas del mes y fechas');
$sem = Ventas::semanasDelMes(2026, 11);   // noviembre 2026 empieza domingo
check('Noviembre 2026: la primera semana empieza el lunes 26 oct', '2026-10-26', $sem[0][0]);
check('Noviembre 2026: 6 semanas', 6, count($sem));
check('La última semana termina el domingo 6 dic', '2026-12-06', end($sem)[6]);
check('Febrero 2027: 4 semanas (empieza lunes)', 4, count(Ventas::semanasDelMes(2027, 2)));
check('Mes inválido -> el de hoy', [(int) $hoy->format('Y'), (int) $hoy->format('n')], Ventas::mesValido('abc', 13, $hoy));
check('Fecha válida', true, Ventas::fechaValida('2026-02-28'));
check('Fecha inválida (30 de febrero)', false, Ventas::fechaValida('2026-02-30'));
check('Número con coma', 1500.5, Ventas::numeroTexto('1.500,50'));
check('Miles con punto (12.000)', 12000.0, Ventas::numeroTexto('12.000'));
check('Decimal con punto (12.5)', 12.5, Ventas::numeroTexto('12.5'));
check_error('Presupuesto con letras', 'solo el número', fn() => Ventas::numeroTexto('mil'));

titulo('2. Calendario del mes y filtro por espacio');
$sabado = $hoy->modify('next saturday')->modify('+21 days');
$f = $sabado->format('Y-m-d');
$e1 = $ev->crear($nid, ['cliente' => ['nombre' => 'Ana Torres', 'telefono' => '0991112233'], 'tipo' => 'boda', 'fecha' => $f,
    'hora_inicio' => '18:00', 'hora_fin' => '02:00', 'invitados' => 150, 'espacios' => [$salon]], (int) $dueno['id'], $hoy);
$e2 = $ev->crear($nid, ['cliente' => ['nombre' => 'Luis Mora', 'telefono' => '0982223344'], 'tipo' => 'quince', 'fecha' => $f,
    'hora_inicio' => '12:00', 'hora_fin' => '16:00', 'invitados' => 80, 'espacios' => [$jardin]], (int) $dueno['id'], $hoy);
$cal = $ve->calendario($nid, (int) $sabado->format('Y'), (int) $sabado->format('n'));
check('El día tiene los 2 eventos', 2, count($cal['eventos'][$f] ?? []));
$cal = $ve->calendario($nid, (int) $sabado->format('Y'), (int) $sabado->format('n'), $jardin);
check('Filtrado por jardín: solo 1', 1, count($cal['eventos'][$f] ?? []));
check('Filtrado por jardín: es el de quince', $e2, (int) $cal['eventos'][$f][0]['id']);
$ev->cambiarEstado($nid, $e2, 'perdido', ['motivo' => 'Precio alto'], $dueno, $hoy);
$cal = $ve->calendario($nid, (int) $sabado->format('Y'), (int) $sabado->format('n'));
check('Los perdidos no salen en el calendario', 1, count($cal['eventos'][$f] ?? []));

titulo('3. Próximos sábados');
$sabados = $ve->proximosSabados($nid, 12, $hoy);
check('12 sábados', 12, count($sabados));
check('Todos son sábado', true, array_reduce($sabados, fn($ok, $s) => $ok && (new DateTimeImmutable($s['fecha']))->format('N') === '6', true));
check('El primero es el próximo sábado (o hoy si es sábado)', ((int) $hoy->format('N') === 6 ? $hoy : $hoy->modify('next saturday'))->format('Y-m-d'), $sabados[0]['fecha']);
$s4 = array_values(array_filter($sabados, fn($s) => $s['fecha'] === $f))[0] ?? null;
check('El sábado del evento aparece', true, $s4 !== null);
$porEsp = array_column($s4['espacios'], 'estado', 'espacio');
check('Salón con una consulta', 'consulta', $porEsp['Salón principal']);
check('Jardín libre (el otro se perdió)', 'libre', $porEsp['Jardín']);
check('1 espacio libre ese sábado', 1, $s4['libres']);
check('…pero los 2 se pueden vender (el otro solo tiene consulta)', 2, $s4['vendibles']);

titulo('4. Disponibilidad pública (sin datos de otros clientes)');
$pub = $ve->disponibilidadPublica($nid, $f, $hoy);
check('2 espacios', 2, count($pub));
check('Consulta = "consultar"', 'consultar', $pub[0]['estado']);
check('Libre', 'libre', $pub[1]['estado']);
check('Capacidad sentados del salón', 250, $pub[0]['capacidad']);
check('No muestra eventos ni clientes', ['espacio', 'capacidad', 'estado'], array_keys($pub[0]));
$ev->cambiarEstado($nid, $e1, 'cotizado', [], $dueno, $hoy);
check('Cotizado (retenido) = "consultar"', 'consultar', $ve->disponibilidadPublica($nid, $f, $hoy)[0]['estado']);
$ev->cambiarEstado($nid, $e1, 'separado', [], $dueno, $hoy);
check('Separado = "ocupado"', 'ocupado', $ve->disponibilidadPublica($nid, $f, $hoy)[0]['estado']);
$ve->agregarFechaCerrada($nid, $f, $jardin, 'Mantenimiento', $hoy);
check('Día cerrado = "ocupado"', 'ocupado', $ve->disponibilidadPublica($nid, $f, $hoy)[1]['estado']);

titulo('5. Choques explicados');
$ch = Ventas::separarChoques($ev->choques($nid, $f, '19:00', '23:00', [$salon, $jardin], null, $hoy));
check('Dos bloqueos (separado + cerrado)', 2, count($ch['bloqueados']));
check('Texto del separado', true, str_contains(Ventas::textoChoque($ch['bloqueados'][0]), 'ya está separado'));
check('Texto del cerrado', true, str_contains(Ventas::textoChoque($ch['bloqueados'][1]), 'Día cerrado'));

titulo('6. Consulta desde la página web');
$fc = $hoy->modify('+90 days')->format('Y-m-d');
$antes = (int) $db->query("SELECT count(*) FROM notificaciones WHERE negocio_id = $nid")->fetchColumn();
$cid = $ve->crearConsultaWeb($nid, ['nombre' => 'María Pérez', 'telefono' => '0997776655', 'email' => 'maria@correo.ec', 'tipo' => 'boda',
    'fecha' => $fc, 'invitados' => '120', 'espacio_id' => $jardin, 'mensaje' => 'Queremos ceremonia en el jardín'], '10.0.0.1', $hoy);
$C = $ev->obtener($nid, $cid);
check('Evento en consulta', 'consulta', $C['estado']);
check('Origen: página web', 'pagina', $C['origen']);
check('Usa el espacio preferido', 'Jardín', $C['espacios_texto']);
check('Nombre del evento', 'Boda de María Pérez', $C['nombre']);
check('Las notas guardan el mensaje', true, str_contains((string) $C['notas'], 'ceremonia en el jardín'));
check('Cliente nuevo con origen página', 'pagina', $ev->cliente($nid, (int) $C['cliente_id'])['origen']);
check('Actividad pendiente para responder', 1, (int) $db->query("SELECT count(*) FROM actividades WHERE evento_id = $cid AND NOT hecha")->fetchColumn());
check('Aviso al negocio', $antes + 1, (int) $db->query("SELECT count(*) FROM notificaciones WHERE negocio_id = $nid")->fetchColumn());
check('Historial: lo hizo el cliente', 'Cliente', $db->query("SELECT quien FROM registro_cambios WHERE evento_id = $cid ORDER BY id LIMIT 1")->fetchColumn());
$cid2 = $ve->crearConsultaWeb($nid, ['nombre' => 'Mari P.', 'telefono' => '+593 99 777 6655', 'tipo' => 'cumpleanos', 'fecha' => $fc, 'invitados' => 40], '10.0.0.1', $hoy);
check('Mismo celular: reutiliza el cliente', (int) $C['cliente_id'], (int) $ev->obtener($nid, $cid2)['cliente_id']);
check('…sin cambiarle el nombre', 'María Pérez', $ev->cliente($nid, (int) $C['cliente_id'])['nombre']);
check('Sin preferencia: el primer espacio', 'Salón principal', $ev->obtener($nid, $cid2)['espacios_texto']);
check_error('Campo trampa (robot)', 'No se pudo enviar', fn() => $ve->crearConsultaWeb($nid, ['sitio_web' => 'http://spam', 'nombre' => 'Robot', 'telefono' => '0991111111', 'tipo' => 'boda', 'fecha' => $fc, 'invitados' => 10], '10.0.0.9', $hoy));
check_error('Pide el celular', 'celular', fn() => $ve->crearConsultaWeb($nid, ['nombre' => 'Sin Cel', 'telefono' => '12', 'tipo' => 'boda', 'fecha' => $fc, 'invitados' => 10], '10.0.0.9', $hoy));
check_error('Fecha pasada', 'ya pasó', fn() => $ve->crearConsultaWeb($nid, ['nombre' => 'Pasado', 'telefono' => '0991111111', 'tipo' => 'boda', 'fecha' => $hoy->modify('-1 day')->format('Y-m-d'), 'invitados' => 10], '10.0.0.9', $hoy));
check_error('Tipo de evento', 'celebrar', fn() => $ve->crearConsultaWeb($nid, ['nombre' => 'Sin Tipo', 'telefono' => '0991111111', 'tipo' => 'x', 'fecha' => $fc, 'invitados' => 10], '10.0.0.9', $hoy));
check('Las rechazadas no crean eventos', 2, (int) $db->query("SELECT count(*) FROM eventos WHERE negocio_id = $nid AND origen = 'pagina'")->fetchColumn());
for ($i = 0; $i < 3; $i++) {
    $ve->crearConsultaWeb($nid, ['nombre' => "Persona $i", 'telefono' => '09800000' . (10 + $i), 'tipo' => 'boda', 'fecha' => $fc, 'invitados' => 50], '10.0.0.1', $hoy);
}
check('5 consultas desde la misma IP en la hora', 5, $ve->consultasRecientes($nid, '10.0.0.1'));
check_error('La sexta se frena', 'varias consultas', fn() => $ve->crearConsultaWeb($nid, ['nombre' => 'Sexta Vez', 'telefono' => '0980000099', 'tipo' => 'boda', 'fecha' => $fc, 'invitados' => 50], '10.0.0.1', $hoy));
$otraIp = $ve->crearConsultaWeb($nid, ['nombre' => 'Otra IP', 'telefono' => '0980000098', 'tipo' => 'boda', 'fecha' => $fc, 'invitados' => 50], '10.0.0.2', $hoy);
check('Desde otra IP sí pasa', true, $otraIp > 0);

titulo('7. Seguimiento');
$db->exec("DELETE FROM actividades WHERE negocio_id = $nid");
$ayer = $hoy->modify('-1 day')->format('Y-m-d 10:00');
$a1 = $ev->agregarActividad($nid, $e1, null, 'llamada', 'Llamar para la degustación', $ayer, (int) $dueno['id']);
$a2 = $ev->agregarActividad($nid, $e1, null, 'visita', 'Visita al local', $hoy->format('Y-m-d 23:00'), (int) $dueno['id']);
$a3 = $ev->agregarActividad($nid, $e1, null, 'degustacion', 'Degustación', $hoy->modify('+3 days')->format('Y-m-d 11:00'), (int) $dueno['id']);
$ev->agregarActividad($nid, $e1, null, 'llamada', 'Lejos', $hoy->modify('+20 days')->format('Y-m-d 11:00'), (int) $dueno['id']);
$g = $ve->pendientesAgrupados($nid, null, $hoy);
check('1 atrasada', 1, count($g['vencidas']));
check('1 para hoy', 1, count($g['hoy']));
check('1 en los próximos 7 días (la de 20 días no)', 1, count($g['proximas']));
check('Solo lo mío: otro usuario no ve nada', 0, count(array_merge(...array_values($ve->pendientesAgrupados($nid, 999999, $hoy)))));
$ve->reagendar($nid, $a1, $hoy->modify('+2 days')->format('Y-m-d') . 'T09:30');
$g = $ve->pendientesAgrupados($nid, null, $hoy);
check('Reagendada: ya no está atrasada', 0, count($g['vencidas']));
check('Reagendada a las 09:30', $hoy->modify('+2 days')->format('Y-m-d') . ' 09:30:00', $db->query("SELECT programada_para FROM actividades WHERE id = $a1")->fetchColumn());
check_error('Reagendar sin fecha', 'Elige la nueva fecha', fn() => $ve->reagendar($nid, $a1, ''));
check('Agenda de visitas y degustaciones de la semana', 2, count($ve->agendaVisitas($nid, $hoy)));

// Consultas sin atender: las creadas desde la web tienen 0 actividades ahora (las borramos). Las fechamos 4 días atrás.
$db->exec("UPDATE eventos SET creado_en = now() - interval '4 days' WHERE negocio_id = $nid AND origen = 'pagina'");
$sin = $ve->consultasSinAtender($nid, 3, $hoy);
check('Consultas de la web sin atender', 6, count($sin));
$ev->agregarActividad($nid, $cid, null, 'whatsapp', 'Le escribí', null, (int) $dueno['id']);
check('Al escribirle, deja de estar sin atender', 5, count($ve->consultasSinAtender($nid, 3, $hoy)));
$ev->agregarActividad($nid, $cid2, null, 'llamada', 'Llamar el lunes', $hoy->modify('+2 days')->format('Y-m-d 10:00'), (int) $dueno['id']);
check('Con algo agendado, tampoco', 4, count($ve->consultasSinAtender($nid, 3, $hoy)));

titulo('8. Cotizaciones sin respuesta y retenciones');
$cot = new Cotizaciones($db);
$eC = $ev->crear($nid, ['cliente' => ['nombre' => 'Pedro Cotiza', 'telefono' => '0975554433'], 'tipo' => 'corporativo', 'fecha' => $hoy->modify('+60 days')->format('Y-m-d'),
    'invitados' => 60, 'espacios' => [$salon]], (int) $dueno['id'], $hoy);
$paq = (int) $db->query("SELECT id FROM paquetes WHERE negocio_id = $nid ORDER BY id LIMIT 1")->fetchColumn();
$qid = $cot->crear($nid, $eC, $paq, (int) $dueno['id'], $hoy);
$cot->enviar($nid, $qid, $dueno);
check('Cotización enviada sin respuesta', 1, count($ve->cotizacionesSinRespuesta($nid)));
check('Retenida: vence en 5 días (fuera de los 3)', 0, count($ve->retencionesPorVencer($nid, 3, $hoy)));
$db->exec("UPDATE eventos SET retenida_hasta = CURRENT_DATE + 1 WHERE id = $eC");
check('Vence mañana: aparece', 1, count($ve->retencionesPorVencer($nid, 3, $hoy)));
check('Valor estimado del tablero = la cotización', (float) $db->query("SELECT total FROM cotizaciones WHERE id = $qid")->fetchColumn(),
    $ve->valoresEstimados($nid, [$eC])[$eC]['valor']);
$cot->aceptar($nid, $qid, 'Pedro Cotiza', '', $dueno, $hoy);
check('Aceptada: ya no está sin respuesta', 0, count($ve->cotizacionesSinRespuesta($nid)));

titulo('9. Tablero y totales');
$db->exec("UPDATE eventos SET presupuesto_cliente = 8000 WHERE id = $cid");
$t = $ve->tablero($nid, ['desde' => $hoy->format('Y-m-d')]);
check('Columnas en orden', Ventas::TABLERO, array_keys($t));
check('Separados: 1', 1, $t['separado']['cantidad']);
check('Cotizados: 1', 1, $t['cotizado']['cantidad']);
$vCid = array_values(array_filter($t['consulta']['eventos'], fn($x) => (int) $x['id'] === $cid))[0];
check('Valor de una consulta = presupuesto del cliente', 8000.0, $vCid['valor']);
check('Fuente del valor', 'presupuesto', $vCid['fuente_valor']);
$tot = Ventas::totales([['total' => 1000, 'pagado' => 300], ['total' => '500.50', 'pagado' => 0]]);
check('Totales: vendido', 1500.5, $tot['total']);
check('Totales: por cobrar', 1200.5, $tot['saldo']);

titulo('10. Clientes');
$cli = (int) $C['cliente_id'];
$ve->editarCliente($nid, $cli, ['nombre' => 'María Pérez López', 'telefono' => '0997776655', 'identificacion' => '1712345678', 'email' => '', 'direccion' => 'Cumbayá', 'origen' => 'instagram', 'notas' => 'VIP']);
$Cl = $ev->cliente($nid, $cli);
check('Nombre editado', 'María Pérez López', $Cl['nombre']);
check('Correo borrado (se puede vaciar)', null, $Cl['email']);
check('Origen editado', 'instagram', $Cl['origen']);
check_error('Cédula inválida', '10 dígitos', fn() => $ve->editarCliente($nid, $cli, ['nombre' => 'María', 'identificacion' => '123']));
check_error('Otro negocio no edita', 'No se encontró', fn() => $ve->editarCliente($nid + 999, $cli, ['nombre' => 'Hacker']));
$r = $ve->resumenCliente($nid, $cli);
check('Resumen: 2 eventos', 2, $r['eventos']);
check('Resumen: nada contratado', 0.0, $r['contratado']);
check('Actividades del cliente', 2, count($ve->actividadesCliente($nid, $cli)));
$r = $ve->resumenCliente($nid, (int) $ev->obtener($nid, $eC)['cliente_id']);
check('Cliente con cotización aceptada (aún cotizado): sin contratar', 0, $r['contratados']);

titulo('11. Coordinador y detalles');
$db->prepare("INSERT INTO usuarios (negocio_id, nombre, email, password_hash, rol) VALUES (?, 'Carla Coord', ?, 'x', 'coordinador')")
   ->execute([$nid, 'coord' . bin2hex(random_bytes(3)) . '@prueba.ec']);
$coord = (int) $db->lastInsertId();
check('Asigna coordinador', 'Carla Coord', $ve->asignarCoordinador($nid, $e1, $coord));
check('Queda guardado', $coord, (int) $db->query("SELECT coordinador_id FROM eventos WHERE id = $e1")->fetchColumn());
check_error('Usuario de otro negocio', 'equipo', fn() => $ve->asignarCoordinador($nid, $e1, 999999));
$ve->guardarDetalles($nid, $e1, 'Menú tres tiempos', 'Colores dorado y blanco');
check('Menú guardado', 'Menú tres tiempos', $db->query("SELECT menu_elegido FROM eventos WHERE id = $e1")->fetchColumn());
check('Capacidad del salón (banquete)', 250, $ve->capacidad($nid, [$salon]));
check('Capacidad de los dos en cóctel', 650, $ve->capacidad($nid, [$salon, $jardin], 'coctel'));
check('Vendedores: el dueño', 1, count($ve->usuariosVenta($nid)));
check('Equipo: dueño y coordinador', 2, count($ve->usuariosEquipo($nid)));
check_error('Vendedor que no es del equipo', 'vendedor', fn() => $ve->vendedorValido($nid, $coord));

titulo('12. Formulario de evento');
$d = Ventas::leerFormularioEvento(['modo_cliente' => 'nuevo', 'cliente_nombre' => 'Rosa Gil', 'cliente_telefono' => '0991231231', 'tipo' => 'bautizo',
    'fecha' => $fc, 'hora_inicio' => '10:00', 'hora_fin' => '14:00', 'invitados' => '60', 'espacios' => ['' . $salon], 'presupuesto_cliente' => '2.500,00']);
check('Cliente nuevo', 'Rosa Gil', $d['cliente']['nombre']);
check('Presupuesto leído', 2500.0, $d['presupuesto_cliente']);
check('Espacios como números', [$salon], $d['espacios']);
check_error('Existente sin elegir', 'Elige el cliente', fn() => Ventas::leerFormularioEvento(['modo_cliente' => 'existente', 'cliente_id' => '']));
check_error('Celular mal escrito', 'celular', fn() => Ventas::leerFormularioEvento(['modo_cliente' => 'nuevo', 'cliente_nombre' => 'Rosa Gil', 'cliente_telefono' => '12']));
$ev2 = $ev->crear($nid, $d, (int) $dueno['id'], $hoy);
$ve->cambiarCliente($nid, $ev2, $cli);
check('Cambiar el cliente del evento', $cli, (int) $ev->obtener($nid, $ev2)['cliente_id']);

titulo('13. Espacios y límite del plan');
[$nidS, $duenoS] = negocio_prueba($db, 'salon', 'Salón Las Rosas');
check('Plan Salón: 2 espacios máximo', 2, $ve->maxEspacios($nidS));
check_error('No deja crear el tercero', 'plan Completo', fn() => $ve->guardarEspacio($nidS, ['nombre' => 'Terraza']));
$jardinS = espacio_id($db, $nidS, 'Jardín');
$ve->activarEspacio($nidS, $jardinS, false);
$terraza = $ve->guardarEspacio($nidS, ['nombre' => 'Terraza', 'capacidad_banquete' => '80', 'capacidad_coctel' => '120', 'precio_base' => '600,50', 'color' => '#22457A', 'orden' => '3']);
$T = $ve->espacio($nidS, $terraza);
check('Con uno desactivado, sí crea', 'Terraza', $T['nombre']);
check('Precio con coma', 600.5, (float) $T['precio_base']);
check_error('No reactiva por encima del límite', 'plan Completo', fn() => $ve->activarEspacio($nidS, $jardinS, true));
check_error('Nombre repetido', 'Ya tienes', fn() => $ve->guardarEspacio($nidS, ['nombre' => 'terraza']));
check_error('Capacidad con letras', 'capacidad', fn() => $ve->guardarEspacio($nidS, ['nombre' => 'Patio', 'capacidad_banquete' => 'cien'], $terraza));
$ve->guardarEspacio($nidS, ['nombre' => 'Terraza Norte', 'capacidad_banquete' => '90', 'color' => 'rojo'], $terraza);
check('Edita el nombre', 'Terraza Norte', $ve->espacio($nidS, $terraza)['nombre']);
check('Color inválido -> el de siempre', '#6B2D3C', $ve->espacio($nidS, $terraza)['color']);
check_error('Otro negocio no edita mis espacios', 'No se encontró', fn() => $ve->guardarEspacio($nid, ['nombre' => 'Robado'], $terraza));
$salonS = espacio_id($db, $nidS, 'Salón principal');
$ve->activarEspacio($nidS, $terraza, false);
check_error('Siempre queda al menos uno activo', 'al menos un espacio', fn() => $ve->activarEspacio($nidS, $salonS, false));
check_error('No desactiva un espacio con eventos separados', 'separado', fn() => $ve->activarEspacio($nid, $salon, false));
check('Foto: nombre inválido', true, (function () use ($ve, $nidS, $salonS) {
    try { $ve->ponerFotoEspacio($nidS, $salonS, '../../etc/passwd'); return false; } catch (RuntimeException) { return true; }
})());
$foto = \TuRenta\Fotos::guardarImagen(foto_subida(), \TuRenta\Fotos::carpeta('espacios'));
check('Foto guardada: no había anterior', null, $ve->ponerFotoEspacio($nidS, $salonS, $foto));
check('La foto queda en el espacio', $foto, $ve->espacio($nidS, $salonS)['foto']);

titulo('14. Fechas cerradas');
$fx = $hoy->modify('+40 days')->format('Y-m-d');
$afect = $ve->agregarFechaCerrada($nid, $fx, null, 'Feriado propio', $hoy);
check('Sin eventos afectados', 0, count($afect));
check_error('No se repite', 'ya estaba cerrada', fn() => $ve->agregarFechaCerrada($nid, $fx, null, '', $hoy));
check_error('Fecha pasada', 'ya pasó', fn() => $ve->agregarFechaCerrada($nid, $hoy->modify('-2 days')->format('Y-m-d'), null, '', $hoy));
check_error('Espacio de otro negocio', 'No se encontró el espacio', fn() => $ve->agregarFechaCerrada($nid, $fx, $salonS, '', $hoy));
$afect = $ve->agregarFechaCerrada($nid, $fc, $salon, '', $hoy);
check('Avisa los eventos de ese día en ese espacio', true, count($afect) >= 1);
$lista = $ve->fechasCerradas($nid, $hoy->format('Y-m-d'));
check('Lista de cerradas (3)', 3, count($lista));
check('Todo el local', null, $lista[array_search($fx, array_column($lista, 'fecha'), true)]['espacio']);
check_error('Separar en día cerrado', 'cerrada', fn() => $ev->cambiarEstado($nid, $cid2, 'separado', [], $dueno, $hoy));
$idFx = (int) $lista[array_search($fx, array_column($lista, 'fecha'), true)]['id'];
$ve->borrarFechaCerrada($nid, $idFx);
check('Borrada', 2, count($ve->fechasCerradas($nid, $hoy->format('Y-m-d'))));
check_error('Otro negocio no borra', 'No se encontró', fn() => $ve->borrarFechaCerrada($nidS, (int) $ve->fechasCerradas($nid)[0]['id']));
$cal = $ve->calendario($nid, (int) substr($fc, 0, 4), (int) substr($fc, 5, 2), $jardin);
check('Calendario por jardín no muestra el cierre del salón', 0, count($cal['cerrados'][$fc] ?? []));

titulo('15. Página pública: negocio y enlaces');
$slug = $db->query("SELECT slug FROM negocios WHERE id = $nid")->fetchColumn();
check('Negocio por slug', $nid, (int) $ve->negocioPorSlug($slug)['id']);
check('Slug raro: nada', null, $ve->negocioPorSlug("x' OR 1=1"));
check('Espacios públicos: solo activos', 2, count($ve->espaciosPublicos($nid)));
check('Instagram con @', 'https://instagram.com/quinta.ec', Ventas::enlaceInstagram('@quinta.ec'));
check('Instagram con dirección', 'https://www.instagram.com/x', Ventas::enlaceInstagram('https://www.instagram.com/x'));
check('Enlace peligroso: nada', null, Ventas::enlaceSeguro('javascript:alert(1)'));
check('Facebook', 'https://facebook.com/QuintaArrayanes', Ventas::enlaceFacebook('QuintaArrayanes'));
check('Fecha en texto', 'sáb 14 nov 2026', Ventas::fechaTexto('2026-11-14'));
