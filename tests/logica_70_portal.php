<?php
/**
 * Portal del cliente, segunda parte (migración 070):
 *  - Formulario del evento: plantillas de ejemplo por tipo, copia por evento, respuestas por tipo de pregunta, avance,
 *    cierre X días antes, "listo" con aviso, preguntas propias del evento, recordatorio en Mensajes de hoy.
 *  - Extras pedidos desde el portal: aprobar (versión nueva de la cotización + cuota de extras + saldo), rechazar,
 *    doble aprobación bloqueada, descuento, plan vacío, evento pagado.
 *  - Encuesta de satisfacción: NPS, una respuesta por evento, freno por IP, aviso al dueño, reporte y resumen del mes.
 *  - Aislamiento entre negocios y enlaces secretos.
 */
use TuRenta\{Anexos, Cobranza, Contratos, Cotizaciones, Cuestionarios, Encuestas, Eventos, Extras, Pagos, Pdf, PdfDocumentos, Reportes};

$db = base_limpia();
planes_de_antes($db);   // estas pruebas usan los planes de antes (Esencial $19, Salón $39, Quinta $69); los de $20 y $45: logica_112
$hoy = new DateTimeImmutable('today');
[$nid, $dueno] = negocio_prueba($db);
[$nidB, $duenoB] = negocio_prueba($db, 'salon', 'Salón Otro Negocio');
$salon = espacio_id($db, $nid, 'Salón principal');
$jardin = espacio_id($db, $nid, 'Jardín');
$ev = new Eventos($db);
$cot = new Cotizaciones($db);
$pag = new Pagos($db);
$cues = new Cuestionarios($db);
$ext = new Extras($db);
$enc = new Encuestas($db);
$cob = new Cobranza($db, fn(string $r, array $p = []) => 'https://prueba.turenta.ec/?' . http_build_query(['r' => $r] + $p));
$avisos = fn(string $like, int $negocio) => (int) $db->query("SELECT count(*) FROM notificaciones WHERE negocio_id = $negocio AND texto LIKE " . $db->quote($like))->fetchColumn();

/** Evento con la cotización del paquete aceptada y la reserva pagada (queda separado). */
$eventoVendido = function (string $cliente, string $tipo, int $dias, int $espacio, ?string $paquete = 'Paquete Boda Oro', float $descuento = 0) use ($db, $ev, $cot, $pag, $nid, $dueno, $hoy): int {
    $id = $ev->crear($nid, ['cliente' => ['nombre' => $cliente, 'telefono' => '09' . random_int(10000000, 99999999)], 'tipo' => $tipo,
        'fecha' => $hoy->modify("+$dias days")->format('Y-m-d'), 'hora_inicio' => '18:00', 'hora_fin' => '23:00', 'invitados' => 120, 'espacios' => [$espacio]],
        (int) $dueno['id'], $hoy);
    $paq = $paquete ? (int) $db->query("SELECT id FROM paquetes WHERE negocio_id = $nid AND nombre = " . $db->quote($paquete))->fetchColumn() : null;
    $c = $cot->crear($nid, $id, $paq ?: null, (int) $dueno['id'], $hoy);
    if (!$paq) $cot->agregarProducto($nid, $c, (int) $db->query("SELECT id FROM productos WHERE negocio_id = $nid AND nombre = 'Menú buffet'")->fetchColumn());
    if ($descuento > 0) $cot->guardar($nid, $c, array_map(fn($i) => $i, $cot->items($c)), ['descuento' => $descuento]);
    $cot->enviar($nid, $c, $dueno);
    $cot->aceptar($nid, $c, $cliente, '1.2.3.4', null, $hoy);
    $reserva = (float) $pag->cuotas($id, $hoy)[0]['monto'];
    $pag->registrar($nid, $id, ['monto' => $reserva, 'metodo' => 'transferencia'], $dueno);
    return $id;
};
$sumaCuotas = fn(int $e) => round((float) $db->query("SELECT COALESCE(sum(monto),0) FROM cuotas WHERE evento_id = $e")->fetchColumn(), 2);
$totalEvento = fn(int $e) => round((float) $db->query("SELECT total FROM eventos WHERE id = $e")->fetchColumn(), 2);

titulo('1. Plantillas de ejemplo por tipo de evento');
check('Antes de usarlas, no hay plantillas', 0, (int) $db->query("SELECT count(*) FROM cuestionario_plantillas WHERE negocio_id = $nid")->fetchColumn());
$pls = $cues->plantillas($nid);
check('Una plantilla por cada tipo de evento', count(Eventos::TIPOS), count($pls));
check('El orden sigue los tipos (boda primero)', 'boda', $pls[0]['tipo_evento']);
$cues->asegurarPlantillas($nid);
$cues->plantillas($nid);
check('Llamar otra vez no duplica', count(Eventos::TIPOS), (int) $db->query("SELECT count(*) FROM cuestionario_plantillas WHERE negocio_id = $nid")->fetchColumn());
$plBoda = $cues->plantillaParaTipo($nid, 'boda');
$textosBoda = array_column($plBoda['preguntas'], 'texto');
check('Boda: canción de entrada', true, in_array('Canción de entrada de la novia', $textosBoda, true));
check('Boda: primer baile', true, in_array('Canción del primer baile', $textosBoda, true));
check('Boda: hora del vals (tipo hora)', 'hora', $plBoda['preguntas'][array_search('Hora del vals', $textosBoda, true)]['tipo']);
check('Boda: alergias o dietas', true, (bool) array_filter($textosBoda, fn($t) => str_contains($t, 'alergias')));
check('Boda: número de niños (tipo número)', 'numero', $plBoda['preguntas'][array_search('¿Cuántos niños asistirán?', $textosBoda, true)]['tipo']);
check('Boda: contacto del día obligatorio', true, (bool) array_filter($plBoda['preguntas'], fn($p) => str_contains($p['texto'], 'contacto') && $p['obligatoria']));
check('Boda: proveedores externos', true, (bool) array_filter($textosBoda, fn($t) => str_contains($t, 'proveedores externos')));
check('Boda: brindis', true, (bool) array_filter($textosBoda, fn($t) => str_contains($t, 'brindis')));
check('Boda: colores de la decoración', true, in_array('Colores de la decoración', $textosBoda, true));
$tiposUsados = array_unique(array_merge(...array_map(fn($t) => array_column(Cuestionarios::preguntasEjemplo($t), 2), array_keys(Eventos::TIPOS))));
check('Los ejemplos usan los 8 tipos de pregunta', 8, count(array_intersect(array_keys(Cuestionarios::TIPOS), $tiposUsados)));
check('Corporativo: equipos (varias opciones)', true, (bool) array_filter(Cuestionarios::preguntasEjemplo('corporativo'), fn($p) => $p[1] === 'Equipos que necesitan' && $p[2] === 'varias'));
check('Quince años: nombre de la quinceañera', 'Nombre de la quinceañera', Cuestionarios::preguntasEjemplo('quince')[0][1]);
check('Bautizo y primera comunión comparten ejemplo', Cuestionarios::preguntasEjemplo('bautizo'), Cuestionarios::preguntasEjemplo('primera_comunion'));
check('Otro negocio todavía no tiene plantillas', 0, (int) $db->query("SELECT count(*) FROM cuestionario_plantillas WHERE negocio_id = $nidB")->fetchColumn());
check('Otro negocio no abre mi plantilla', null, $cues->plantilla($nidB, (int) $plBoda['id']));

titulo('2. Editar plantillas');
$q1 = $cues->guardarPregunta($nid, (int) $plBoda['id'], null, ['texto' => '¿Habrá mariachis?', 'tipo' => 'sino', 'seccion' => 'Música']);
check('Pregunta agregada al final', $q1, (int) end($cues->plantilla($nid, (int) $plBoda['id'])['preguntas'])['id']);
check_error('Pregunta sin texto', 'Escribe la pregunta', fn() => $cues->guardarPregunta($nid, (int) $plBoda['id'], null, ['texto' => '', 'tipo' => 'corto']));
check_error('Una opción con menos de 2 opciones', 'al menos 2 opciones', fn() => $cues->guardarPregunta($nid, (int) $plBoda['id'], null, ['texto' => '¿Color?', 'tipo' => 'opcion', 'opciones' => 'Rojo']));
check_error('Tipo inválido', 'tipo de respuesta', fn() => $cues->guardarPregunta($nid, (int) $plBoda['id'], null, ['texto' => '¿Color?', 'tipo' => 'radio']));
$q2 = $cues->guardarPregunta($nid, (int) $plBoda['id'], null, ['texto' => 'Color del mantel', 'tipo' => 'opcion', 'opciones' => "Blanco\nMarfil\nDorado\nBlanco"]);
check('Opciones sin repetir', "Blanco\nMarfil\nDorado", $db->query("SELECT opciones FROM cuestionario_preguntas WHERE id = $q2")->fetchColumn());
check('Opciones separadas por coma también sirven', ['Rojo', 'Verde'], Cuestionarios::opciones('Rojo, Verde'));
$cues->moverPregunta($nid, $q2, -1);
$ids = array_map(fn($p) => (int) $p['id'], $cues->plantilla($nid, (int) $plBoda['id'])['preguntas']);
check('Subir una pregunta', $q2, $ids[count($ids) - 2]);
$cues->borrarPregunta($nid, $q2);
check_error('Otro negocio no borra mis preguntas', 'ya no existe', fn() => $cues->borrarPregunta($nidB, $q1));
check_error('Borrar dos veces', 'ya no existe', fn() => $cues->borrarPregunta($nid, $q2));
$cues->guardarAjustes($nid, 7, 30);
check_error('Recordatorio después del cierre', 'antes del cierre', fn() => $cues->guardarAjustes($nid, 10, 5));
check_error('Cierre fuera de rango', 'entre 0 y 90', fn() => $cues->guardarAjustes($nid, 120, 200));

titulo('3. Cada evento copia el formulario de su tipo');
$eBoda = $eventoVendido('Ana Lucía Torres', 'boda', 40, $salon);
check('Evento separado al pagar la reserva', 'separado', $ev->obtener($nid, $eBoda)['estado']);
check('Sin abrir, todavía no tiene copia', null, $cues->deEvento($nid, $eBoda, false));
$f = $cues->deEvento($nid, $eBoda);
$nBoda = count($cues->plantilla($nid, (int) $plBoda['id'])['preguntas']);
check('Copia con todas las preguntas de la plantilla de boda', $nBoda, $f['total']);
check('Incluye la pregunta agregada a la plantilla', true, in_array('¿Habrá mariachis?', array_column($f['preguntas'], 'texto'), true));
check('Nombre del formulario', 'Formulario de boda', $f['cuestionario']['nombre']);
check('Sin respuestas: 0 %', 0, $f['pct']);
check('Abierto para el cliente', true, $f['abierto']);
check('Cierre 7 días antes', $hoy->modify('+33 days')->format('Y-m-d'), $f['cierre']);
$cues->guardarPregunta($nid, (int) $plBoda['id'], null, ['texto' => 'Pregunta nueva de la plantilla', 'tipo' => 'corto']);
check('Cambiar la plantilla no cambia el formulario ya copiado', $nBoda, $cues->deEvento($nid, $eBoda)['total']);
$eQuince = $ev->crear($nid, ['cliente' => ['nombre' => 'Valentina Ruiz', 'telefono' => '0981231234'], 'tipo' => 'quince', 'fecha' => $hoy->modify('+50 days')->format('Y-m-d'),
    'hora_inicio' => '19:00', 'hora_fin' => '01:00', 'invitados' => 90, 'espacios' => [$jardin]], null, $hoy);
check('Quince años copia su propio formulario', 'Nombre de la quinceañera', $cues->deEvento($nid, $eQuince)['preguntas'][0]['texto']);
$plGrad = $cues->plantillaParaTipo($nid, 'graduacion');
$cues->guardarPlantilla($nid, (int) $plGrad['id'], 'Formulario de graduación', false);
$eGrad = $ev->crear($nid, ['cliente' => ['nombre' => 'Promoción 2026', 'telefono' => '0981231299'], 'tipo' => 'graduacion', 'fecha' => $hoy->modify('+70 days')->format('Y-m-d'),
    'hora_inicio' => '19:00', 'hora_fin' => '23:00', 'invitados' => 90, 'espacios' => [$jardin]], null, $hoy);
$ev->cambiarEstado($nid, $eGrad, 'cotizado', [], $dueno, $hoy);
check('Plantilla apagada: usa la de "Otro"', 'Formulario de tu evento', $cues->deEvento($nid, $eGrad)['cuestionario']['nombre']);
check('Copiar dos veces no duplica', $cues->deEvento($nid, $eGrad)['total'], (int) $db->query("SELECT count(*) FROM evento_preguntas WHERE evento_id = $eGrad")->fetchColumn());
check_error('Otro negocio no ve mi formulario', 'No se encontró el evento', fn() => $cues->deEvento($nidB, $eBoda));

titulo('4. Respuestas por tipo de pregunta y avance');
$porTexto = [];
foreach ($cues->deEvento($nid, $eBoda)['preguntas'] as $p) $porTexto[$p['texto']] = $p;
$id = fn(string $t) => (int) $porTexto[$t]['id'];
$musica = $id('Estilos de música para la fiesta');
$r = [
    $id('Canción de entrada de la novia') => '  A thousand   years  ',
    $id('Tipo de ceremonia') => 'Religiosa',
    $musica => ['Reguetón', 'Salsa', 'Salsa'],
    $id('Hora del vals') => '9:05',
    $id('¿Cuántos niños asistirán?') => '1.234,5',
    $id('¿Quieren menú especial para los niños?') => 'Sí',
    $id('¿Hay invitados con alergias o dietas especiales?') => "3 vegetarianos\r\n1 celíaco",
];
$f = $cues->guardarRespuestas($nid, $eBoda, $r, array_keys($r), 'Cliente', true, $hoy);
$porId = array_column($f['preguntas'], null, 'id');
check('Texto corto sin espacios de más', 'A thousand years', $porId[$id('Canción de entrada de la novia')]['respuesta']);
check('Varias opciones: sin repetir y en el orden de la lista', "Salsa\nReguetón", $porId[$musica]['respuesta']);
check('Hora normalizada 09:05', '09:05', $porId[$id('Hora del vals')]['respuesta']);
check('Número 1.234,5 → 1234.5', '1234.5', $porId[$id('¿Cuántos niños asistirán?')]['respuesta']);
check('Número se muestra con coma', '1234,5', Cuestionarios::texto($porId[$id('¿Cuántos niños asistirán?')]));
check('Sí/No guardado como si', 'si', $porId[$id('¿Quieren menú especial para los niños?')]['respuesta']);
check('Sí/No se muestra Sí', 'Sí', Cuestionarios::texto($porId[$id('¿Quieren menú especial para los niños?')]));
check('Varias se muestra con comas', 'Salsa, Reguetón', Cuestionarios::texto($porId[$musica]));
check('Texto largo con saltos de línea', "3 vegetarianos\n1 celíaco", $porId[$id('¿Hay invitados con alergias o dietas especiales?')]['respuesta']);
check('Quién respondió', 'Cliente', $porId[$musica]['respondido_por']);
check('Avance: 7 respondidas', 7, $f['respondidas']);
check('Avance en %', (int) floor(7 * 100 / $nBoda), $f['pct']);
check('Historial: el cliente empezó', 1, (int) $db->query("SELECT count(*) FROM registro_cambios WHERE evento_id = $eBoda AND accion LIKE 'El cliente empezó a llenar el formulario%'")->fetchColumn());
check_error('Opción que no existe', 'Elige una de las opciones', fn() => $cues->guardarRespuestas($nid, $eBoda, [$id('Tipo de ceremonia') => 'Hindú'], [], 'Cliente', true, $hoy));
check_error('Varias con opción inventada', 'Elige una de las opciones', fn() => $cues->guardarRespuestas($nid, $eBoda, [$musica => ['Tango']], [], 'Cliente', true, $hoy));
check_error('Hora mal escrita', 'Revisa la hora', fn() => $cues->guardarRespuestas($nid, $eBoda, [$id('Hora del vals') => '25:00'], [], 'Cliente', true, $hoy));
check_error('Número negativo', 'número válido', fn() => $cues->guardarRespuestas($nid, $eBoda, [$id('¿Cuántos niños asistirán?') => '-3'], [], 'Cliente', true, $hoy));
check_error('Número con letras', 'número válido', fn() => $cues->guardarRespuestas($nid, $eBoda, [$id('¿Cuántos niños asistirán?') => 'diez'], [], 'Cliente', true, $hoy));
check_error('Sí/No con otra cosa', 'sí o no', fn() => $cues->guardarRespuestas($nid, $eBoda, [$id('¿Quieren menú especial para los niños?') => 'tal vez'], [], 'Cliente', true, $hoy));
check_error('Texto corto muy largo', 'muy larga', fn() => $cues->guardarRespuestas($nid, $eBoda, [$id('Canción de entrada de la novia') => str_repeat('a', 201)], [], 'Cliente', true, $hoy));
check('Un error no guarda nada a medias', 'A thousand years', $db->query('SELECT respuesta FROM evento_preguntas WHERE id = ' . $id('Canción de entrada de la novia'))->fetchColumn());
check('Fecha válida', '2026-12-24', Cuestionarios::normalizar(['texto' => 'F', 'tipo' => 'fecha', 'opciones' => null], '2026-12-24'));
check_error('Fecha inválida', 'Revisa la fecha', fn() => Cuestionarios::normalizar(['texto' => 'F', 'tipo' => 'fecha', 'opciones' => null], '2026-02-30'));
// Desmarcar todas las casillas: la pregunta está presente pero no llega valor
$f = $cues->guardarRespuestas($nid, $eBoda, [], [$musica], 'Cliente', true, $hoy);
check('Desmarcar todas las opciones la deja vacía', null, array_column($f['preguntas'], null, 'id')[$musica]['respuesta']);
check('Avance baja a 6', 6, $f['respondidas']);
// Pregunta de otro evento: se ignora
$otraPregunta = (int) $db->query("SELECT id FROM evento_preguntas WHERE evento_id = $eQuince ORDER BY id LIMIT 1")->fetchColumn();
$cues->guardarRespuestas($nid, $eBoda, [$otraPregunta => 'Intruso'], [$otraPregunta], 'Cliente', true, $hoy);
check('Una pregunta de otro evento se ignora', null, $db->query("SELECT respuesta FROM evento_preguntas WHERE id = $otraPregunta")->fetchColumn());
check_error('Otro negocio no guarda respuestas en mi evento', 'No se encontró el evento', fn() => $cues->guardarRespuestas($nidB, $eBoda, [$musica => ['Salsa']], [], 'Cliente', true, $hoy));

titulo('5. Cierre del formulario y estados del evento');
$despues = $hoy->modify('+34 days');   // el cierre fue el día +33
check('Después del cierre: cerrado para el cliente', false, $cues->deEvento($nid, $eBoda, false, $despues)['abierto']);
check('El día del cierre todavía se puede', true, $cues->deEvento($nid, $eBoda, false, $hoy->modify('+33 days'))['abierto']);
check_error('El cliente no cambia después del cierre', 'se cerró', fn() => $cues->guardarRespuestas($nid, $eBoda, [$musica => ['Salsa']], [$musica], 'Cliente', true, $despues));
$f = $cues->guardarRespuestas($nid, $eBoda, [$musica => ['Salsa', 'Cumbia']], [$musica], 'Dimitry Prueba', false, $despues);
check('El local sí puede cambiar después del cierre', "Salsa\nCumbia", array_column($f['preguntas'], null, 'id')[$musica]['respuesta']);
check('Queda quién lo cambió', 'Dimitry Prueba', array_column($f['preguntas'], null, 'id')[$musica]['respondido_por']);
check('Historial: cambio del local', 1, (int) $db->query("SELECT count(*) FROM registro_cambios WHERE evento_id = $eBoda AND accion LIKE 'Cambió 1 respuesta del formulario%'")->fetchColumn());
check('Evento en consulta: el cliente no lo ve', false, $cues->deEvento($nid, $eQuince)['visible_cliente']);
check_error('Evento en consulta: el cliente no puede responder', 'todavía no está disponible', fn() => $cues->guardarRespuestas($nid, $eQuince, [], [], 'Cliente', true, $hoy));
$cues->guardarAjustes($nid, 45, 60);
check('Con 45 días de cierre, ya se cerró', false, $cues->deEvento($nid, $eBoda, false, $hoy)['abierto']);
$cues->guardarAjustes($nid, 7, 30);

titulo('6. "Listo, enviar al local"');
check_error('Falta la obligatoria', 'Te falta responder', fn() => $cues->terminar($nid, $eBoda, $hoy));
$contacto = $id('Persona de contacto el día del evento (nombre y celular)');
$cues->guardarRespuestas($nid, $eBoda, [$contacto => 'Pedro Torres 0991234567'], [$contacto], 'Cliente', true, $hoy);
$antes = $avisos('📝 %terminó el formulario%', $nid);
check('Terminar la primera vez', true, $cues->terminar($nid, $eBoda, $hoy));
check('Aviso al equipo (dueño)', $antes + 1, $avisos('📝 %terminó el formulario%', $nid));
check('Queda la fecha de envío', true, $cues->deEvento($nid, $eBoda)['enviado_en'] !== null);
check('Terminar otra vez no repite el aviso', false, $cues->terminar($nid, $eBoda, $hoy));
check('Sigue un solo aviso', $antes + 1, $avisos('📝 %terminó el formulario%', $nid));
check('El otro negocio no recibe avisos', 0, $avisos('📝 %', $nidB));
check_error('Después del cierre no se puede terminar', 'se cerró', fn() => $cues->terminar($nid, $eBoda, $despues));

titulo('7. Preguntas propias del evento');
$nAntes = $cues->deEvento($nid, $eBoda)['total'];
$pNueva = $cues->agregarPreguntaEvento($nid, $eBoda, ['texto' => '¿Quieren pista iluminada?', 'tipo' => 'sino', 'seccion' => 'Decoración'], $dueno);
check('Pregunta agregada solo a este evento', $nAntes + 1, $cues->deEvento($nid, $eBoda)['total']);
check('La plantilla no cambia', 0, (int) $db->query("SELECT count(*) FROM cuestionario_preguntas WHERE texto = '¿Quieren pista iluminada?'")->fetchColumn());
$cues->borrarPreguntaEvento($nid, $eBoda, $pNueva, $dueno);
check('Pregunta quitada', $nAntes, $cues->deEvento($nid, $eBoda)['total']);
check_error('Quitar una pregunta de otro evento', 'ya no existe', fn() => $cues->borrarPreguntaEvento($nid, $eBoda, $otraPregunta, $dueno));
check_error('Otro negocio no agrega preguntas', 'No se encontró el evento', fn() => $cues->agregarPreguntaEvento($nidB, $eBoda, ['texto' => 'Hackeo', 'tipo' => 'corto']));
check_error('No se recarga si ya hay respuestas', 'ya tiene respuestas', fn() => $cues->recargarPlantilla($nid, $eBoda, $dueno));
$db->exec("UPDATE eventos SET tipo = 'corporativo' WHERE id = $eQuince");
$cues->recargarPlantilla($nid, $eQuince, $dueno);
check('Cambió el tipo: se recarga con el formulario corporativo', 'Empresa y nombre del evento', $cues->deEvento($nid, $eQuince)['preguntas'][0]['texto']);
$h = $cues->respondidas($nid, $eBoda);
check('Respuestas por sección para la hoja', true, isset($h['Música']) && isset($h['El día del evento']));
check('En la hoja la respuesta ya va lista', 'Sí', array_column($h['Invitados'], 'respuesta', 'pregunta')['¿Quieren menú especial para los niños?']);

titulo('8. Recordatorio en Mensajes de hoy');
$eRec = $eventoVendido('Carolina Vega', 'boda', 20, $jardin);
$m = $cob->mensajesDelDia($nid, $hoy);
$refs = array_column($m['cuestionario'], 'ref_id');
check('Evento en 20 días sin formulario: se recuerda', true, in_array($eRec, $refs, true));
check('Evento con el formulario enviado: no se recuerda', false, in_array($eBoda, $refs, true));
$msj = $m['cuestionario'][array_search($eRec, $refs, true)];
check('Mensaje con el enlace del formulario', true, str_contains($msj['texto'], 'r=portal_cuestionario&t=' . $ev->obtener($nid, $eRec)['token_cliente']));
check('Mensaje dice hasta cuándo', true, str_contains($msj['texto'], 'lo puedes completar hasta el'));
check('Motivo: sin empezar', true, str_contains($msj['motivo'], 'sin empezar'));
check('Evento en 5 días (ya cerró): no se recuerda', false, in_array($eRec, array_column($cob->mensajesDelDia($nid, $hoy->modify('+15 days'))['cuestionario'], 'ref_id'), true));
check('Evento en 40 días (falta para el aviso): no se recuerda', false, in_array($eRec, array_column($cob->mensajesDelDia($nid, $hoy->modify('-20 days'))['cuestionario'], 'ref_id'), true));
$cob->marcarEnviado($nid, 'cuestionario', $eRec, $dueno, $hoy);
check('Marcado como enviado: no sale hoy', false, in_array($eRec, array_column($cob->mensajesDelDia($nid, $hoy)['cuestionario'], 'ref_id'), true));
check('Tampoco en 3 días', false, in_array($eRec, array_column($cob->mensajesDelDia($nid, $hoy->modify('+3 days'))['cuestionario'], 'ref_id'), true));
check('A los 4 días vuelve a salir si sigue sin llenar', true, in_array($eRec, array_column($cob->mensajesDelDia($nid, $hoy->modify('+4 days'))['cuestionario'], 'ref_id'), true));
$fr = $cues->deEvento($nid, $eRec);
$todas = [];
foreach ($fr['preguntas'] as $p) {
    $todas[$p['id']] = match ($p['tipo']) {
        'opcion' => Cuestionarios::opciones($p['opciones'])[0], 'varias' => [Cuestionarios::opciones($p['opciones'])[0]], 'sino' => 'no',
        'numero' => '5', 'fecha' => $hoy->format('Y-m-d'), 'hora' => '20:00', default => 'Listo',
    };
}
$fr = $cues->guardarRespuestas($nid, $eRec, $todas, array_keys($todas), 'Cliente', true, $hoy);
check('Todo respondido: completo', true, $fr['completo']);
check('Completo (aunque no tocó Listo): ya no se recuerda', false, in_array($eRec, array_column($cob->mensajesDelDia($nid, $hoy->modify('+4 days'))['cuestionario'], 'ref_id'), true));
check('Otro negocio: sin recordatorios de formulario', 0, count($cob->mensajesDelDia($nidB, $hoy)['cuestionario']));
check_error('Otro negocio no marca mis recordatorios', 'ya no está en la lista', fn() => $cob->marcarEnviado($nidB, 'cuestionario', $eRec, $duenoB, $hoy));

titulo('9. Extras: qué se ofrece en el portal');
$pHora = (int) $db->query("SELECT id FROM productos WHERE negocio_id = $nid AND nombre = 'Hora adicional'")->fetchColumn();
$pLoca = (int) $db->query("SELECT id FROM productos WHERE negocio_id = $nid AND nombre = 'Hora loca'")->fetchColumn();
$pMesero = (int) $db->query("SELECT id FROM productos WHERE negocio_id = $nid AND nombre = 'Mesero adicional'")->fetchColumn();
check('Al inicio no se ofrece nada', 0, count($ext->ofrecidos($nid)));
$ext->configurarProducto($nid, $pHora, true, 'Sigue la fiesta una hora más');
$ext->configurarProducto($nid, $pLoca, true, '', foto_subida());
$of = array_column($ext->ofrecidos($nid), null, 'id');
check('Se ofrecen 2 extras', 2, count($of));
check('Precio final con IVA (150 + 15 %)', 172.5, $of[$pHora]['precio_final']);
check('Texto para el cliente', 'Sigue la fiesta una hora más', $of[$pHora]['texto']);
check('Sin texto propio: usa la descripción del catálogo', 'Animadores, accesorios y zanqueros', $of[$pLoca]['texto']);
check('La foto se guardó', true, $ext->rutaFoto($nid, $pLoca) !== null);
check('Otro negocio no ve la foto', null, $ext->rutaFoto($nidB, $pLoca));
check('Otro negocio no ve mis extras', 0, count($ext->ofrecidos($nidB)));
check_error('Otro negocio no configura mis productos', 'No se encontró el producto', fn() => $ext->configurarProducto($nidB, $pHora, true));
$db->exec("UPDATE productos SET activo = false WHERE id = $pMesero");
check_error('Producto desactivado no se ofrece', 'desactivado', fn() => $ext->configurarProducto($nid, $pMesero, true));
$db->exec("UPDATE productos SET activo = true WHERE id = $pMesero");

titulo('10. El cliente pide extras');
check('Evento con cotización aceptada: puede pedir', null, $ext->motivoNoDisponible($nid, $eBoda));
check('Evento sin cotización aceptada: no puede', 'Podrás pedir extras cuando aceptes tu cotización.', $ext->motivoNoDisponible($nid, $eGrad));
check_error('Pedir sin elegir nada', 'Elige al menos un extra', fn() => $ext->pedir($nid, $eBoda, [$pHora => '0', $pLoca => ''], '', '1.1.1.1'));
check_error('Pedir algo que no se ofrece', 'ya no está disponible', fn() => $ext->pedir($nid, $eBoda, [$pMesero => 2], '', '1.1.1.1'));
check_error('Cantidad exagerada', 'Revisa la cantidad', fn() => $ext->pedir($nid, $eBoda, [$pLoca => 99999], '', '1.1.1.1'));
check_error('Hora loca con decimales', 'número entero', fn() => $ext->pedir($nid, $eBoda, [$pLoca => '1,5'], '', '1.1.1.1'));
check_error('Pedir para un evento sin cotización aceptada', 'cuando aceptes tu cotización', fn() => $ext->pedir($nid, $eGrad, [$pHora => 1]));
$antes = $avisos('🛍️ %', $nid);
$p1 = $ext->pedir($nid, $eBoda, [$pHora => '2', $pLoca => 1], 'Para seguir bailando', '1.1.1.1');
$P1 = $ext->pedido($nid, $p1);
check('Pedido pendiente', 'pendiente', $P1['estado']);
check('Subtotal 2 × 150 + 280', 580.0, (float) $P1['subtotal']);
check('IVA 15 %', 87.0, (float) $P1['iva']);
check('Total aprox. 667', 667.0, (float) $P1['total']);
check('2 líneas con el precio del momento', [150.0, 280.0], array_map(fn($i) => (float) $i['precio_unitario'], $P1['items']));
check('Aviso al local', $antes + 1, $avisos('🛍️ %pidió extras%Hora adicional ×2%', $nid));
check('Historial: el cliente pidió', 1, (int) $db->query("SELECT count(*) FROM registro_cambios WHERE evento_id = $eBoda AND quien = 'Cliente' AND accion LIKE 'Pidió extras%'")->fetchColumn());
$db->exec("UPDATE productos SET precio = 999 WHERE id = $pHora");
check('Si después cambia el precio del catálogo, el pedido conserva el suyo', 150.0, (float) $ext->pedido($nid, $p1)['items'][0]['precio_unitario']);
$db->exec("UPDATE productos SET precio = 150 WHERE id = $pHora");
$p2 = $ext->pedir($nid, $eBoda, [$pHora => 1], '', '1.1.1.1');
$p3 = $ext->pedir($nid, $eBoda, [$pLoca => 1], '', '1.1.1.1');
check_error('Máximo 3 pedidos esperando', 'esperando respuesta', fn() => $ext->pedir($nid, $eBoda, [$pHora => 1], '', '1.1.1.1'));
$ext->cancelar($nid, $eBoda, $p3);
check('El cliente cancela un pedido', 'cancelado', $ext->pedido($nid, $p3)['estado']);
check_error('Cancelar dos veces', 'ya fue respondido', fn() => $ext->cancelar($nid, $eBoda, $p3));
check_error('Cancelar desde otro evento', 'ya fue respondido', fn() => $ext->cancelar($nid, $eRec, $p2));
check('Pendientes del negocio', 2, $ext->cuantosPendientes($nid));
check('Otro negocio: sin pendientes', 0, $ext->cuantosPendientes($nidB));
check('Otro negocio no abre mi pedido', null, $ext->pedido($nidB, $p1));

titulo('11. Aprobar: versión nueva de la cotización, cuota de extras y saldo');
$acept = $cot->aceptadaDe($eBoda);
$total0 = $totalEvento($eBoda);
$res0 = $pag->resumen($eBoda, $hoy);
$cuotas0 = $pag->cuotas($eBoda, $hoy);
$ingreso0 = $cob->ganancia($nid, $eBoda)['ingreso'];
check('Antes: las cuotas suman el total', $total0, $sumaCuotas($eBoda));
check_error('Otro negocio no aprueba mi pedido', 'No se encontró ese pedido', fn() => $ext->aprobar($nidB, $p1, $duenoB));
$r = $ext->aprobar($nid, $p1, $dueno, 'La hora loca llega a las 22:00', $hoy);
$nueva = $cot->aceptadaDe($eBoda);
check('Cotización nueva aceptada', $r['cotizacion_id'], (int) $nueva['id']);
check('Es la versión siguiente', (int) $acept['version'] + 1, (int) $nueva['version']);
check('Número nuevo de cotización', true, (int) $nueva['numero'] > (int) $acept['numero']);
check('La anterior queda reemplazada', 'reemplazada', $db->query('SELECT estado FROM cotizaciones WHERE id = ' . (int) $acept['id'])->fetchColumn());
check('Solo una aceptada', 1, (int) $db->query("SELECT count(*) FROM cotizaciones WHERE evento_id = $eBoda AND estado = 'aceptada'")->fetchColumn());
check('Copia las líneas y suma 2 extras', count($acept['items']) + 2, count($nueva['items']));
check('Los extras van al final', 'Hora loca', end($nueva['items'])['descripcion']);
check('Mantiene el IVA de la cotización', (float) $acept['iva_pct'], (float) $nueva['iva_pct']);
check('Monto agregado = diferencia de totales', round((float) $nueva['total'] - (float) $acept['total'], 2), $r['monto']);
check('Sin descuento: suben exactamente 667', 667.0, $r['monto']);
check('Total del evento = cotización nueva', (float) $nueva['total'], $totalEvento($eBoda));
check('Total del evento sube 667', round($total0 + 667, 2), $totalEvento($eBoda));
$cuotas1 = $pag->cuotas($eBoda, $hoy);
check('Una cuota más', count($cuotas0) + 1, count($cuotas1));
$extraCuota = end($cuotas1);
check('Cuota nueva al final', (int) end($cuotas0)['numero'] + 1, (int) $extraCuota['numero']);
check('Concepto con los extras', 'Extras: Hora adicional, Hora loca', $extraCuota['concepto']);
check('Monto de la cuota = lo agregado', 667.0, (float) $extraCuota['monto']);
check('Vence con el saldo (15 días antes)', $hoy->modify('+25 days')->format('Y-m-d'), $extraCuota['vence']);
check('Las cuotas anteriores no cambian', array_map(fn($c) => [(float) $c['monto'], $c['vence']], $cuotas0),
      array_map(fn($c) => [(float) $c['monto'], $c['vence']], array_slice($cuotas1, 0, count($cuotas0))));
check('Las cuotas suman el nuevo total', $totalEvento($eBoda), $sumaCuotas($eBoda));
$res1 = $pag->resumen($eBoda, $hoy);
check('Lo pagado no cambia', $res0['pagado'], $res1['pagado']);
check('El saldo sube 667', round($res0['saldo'] + 667, 2), $res1['saldo']);
check('La reserva sigue pagada', 'pagada', $cuotas1[0]['estado']);
check('Sigue separado', 'separado', $ev->obtener($nid, $eBoda)['estado']);
check('La ganancia ve los extras (sin IVA)', round($ingreso0 + 580, 2), $cob->ganancia($nid, $eBoda)['ingreso']);
$P1 = $ext->pedido($nid, $p1);
check('Pedido aprobado', 'aprobado', $P1['estado']);
check('Con la nota para el cliente', 'La hora loca llega a las 22:00', $P1['nota_negocio']);
check('Guarda la cuota y la cotización', [(int) $extraCuota['id'], $r['cotizacion_id']], [(int) $P1['cuota_id'], (int) $P1['cotizacion_id']]);
check('Quién respondió', 'Dimitry Prueba', $P1['respondio']);
check('Historial: aprobó los extras', 1, (int) $db->query("SELECT count(*) FROM registro_cambios WHERE evento_id = $eBoda AND accion LIKE 'Aprobó los extras del portal%'")->fetchColumn());
check_error('Aprobar dos veces está bloqueado', 'ya fue aprobado', fn() => $ext->aprobar($nid, $p1, $dueno));
check('Aprobar dos veces no suma otra vez', round($total0 + 667, 2), $totalEvento($eBoda));
check_error('Rechazar uno ya aprobado', 'ya fue aprobado', fn() => $ext->rechazar($nid, $p1, $dueno, 'No se puede'));
check('Mensaje de WhatsApp: aprobado', true, str_contains(Extras::textoRespuesta($P1, 'Quinta Los Arrayanes', 'https://x'), 'Aprobamos los extras'));
// Un pago aplica primero a las cuotas viejas y la de extras queda al final
$pag->registrar($nid, $eBoda, ['monto' => 100, 'metodo' => 'efectivo'], $dueno);
$cuotasB = $pag->cuotas($eBoda, $hoy);
check('Un pago nuevo no va primero a la cuota de extras', 0.0, (float) end($cuotasB)['pagado']);

titulo('12. Rechazar');
check_error('Rechazar sin nota', 'Escribe una nota', fn() => $ext->rechazar($nid, $p2, $dueno, ''));
$ext->rechazar($nid, $p2, $dueno, 'Esa noche termina a las 23:00 por la ordenanza');
check('Pedido rechazado', 'rechazado', $ext->pedido($nid, $p2)['estado']);
check('Rechazar no cambia el total', round($total0 + 667, 2), $totalEvento($eBoda));
check_error('Aprobar uno rechazado', 'ya fue rechazado', fn() => $ext->aprobar($nid, $p2, $dueno));
check_error('Aprobar uno cancelado por el cliente', 'canceló', fn() => $ext->aprobar($nid, $p3, $dueno));
check('Mensaje de WhatsApp: no aprobado con la nota', true, str_contains(Extras::textoRespuesta($ext->pedido($nid, $p2), 'Quinta', 'https://x'), 'ordenanza'));

titulo('13. Aprobar con descuento, sin plan y con el evento pagado');
$eDesc = $eventoVendido('Familia Mora', 'aniversario', 60, $jardin, 'Paquete Boda Plata', 500);
$aDesc = $cot->aceptadaDe($eDesc);
check('Cotización con descuento', 500.0, (float) $aDesc['descuento']);
$pd = $ext->pedir($nid, $eDesc, [$pHora => 1], '', '2.2.2.2');
$rd = $ext->aprobar($nid, $pd, $dueno, '', $hoy);
$nDesc = $cot->aceptadaDe($eDesc);
check('Mantiene el descuento', 500.0, (float) $nDesc['descuento']);
check('Monto = diferencia real de la cotización (el descuento se reparte)', round((float) $nDesc['total'] - (float) $aDesc['total'], 2), $rd['monto']);
check('Las cuotas suman el total con descuento', $totalEvento($eDesc), $sumaCuotas($eDesc));
// Evento sin plan de pagos: se arma el plan completo
$eSin = $eventoVendido('Andrés Salazar', 'cumpleanos', 80, $salon, null);
$db->exec("DELETE FROM cuotas WHERE evento_id = $eSin");
$ps = $ext->pedir($nid, $eSin, [$pLoca => 1], '', '3.3.3.3');
$rs = $ext->aprobar($nid, $ps, $dueno, '', $hoy);
check('Sin plan: se arma el plan completo', true, count($pag->cuotas($eSin, $hoy)) >= 2);
check('Sin plan: no queda cuota suelta de extras', null, $rs['cuota_id']);
check('Sin plan: suma el total nuevo', $totalEvento($eSin), $sumaCuotas($eSin));
// Evento pagado por completo: la cuota de extras queda por cobrar
$ePag = $eventoVendido('Lucía Paz', 'bautizo', 45, $jardin, null);
$pag->registrar($nid, $ePag, ['monto' => $pag->resumen($ePag, $hoy)['saldo'], 'metodo' => 'transferencia'], $dueno);
check('Evento pagado: saldo 0', 0.0, $pag->resumen($ePag, $hoy)['saldo']);
$pp = $ext->pedir($nid, $ePag, [$pHora => 1], '', '4.4.4.4');
$ext->aprobar($nid, $pp, $dueno, '', $hoy);
check('Pagado + extras: el saldo es lo de los extras', 172.5, $pag->resumen($ePag, $hoy)['saldo']);
$cuotasP = $pag->cuotas($ePag, $hoy);
check('Pagado + extras: la cuota de extras está pendiente', 'pendiente', end($cuotasP)['estado']);
// Saldo a favor: cubre los extras
$eFav = $eventoVendido('Marco Ortiz', 'cumpleanos', 55, $salon, null);
$pag->registrar($nid, $eFav, ['monto' => $pag->resumen($eFav, $hoy)['saldo'] + 200, 'metodo' => 'transferencia'], $dueno);
$pf = $ext->pedir($nid, $eFav, [$pHora => 1], '', '5.5.5.5');
$ext->aprobar($nid, $pf, $dueno, '', $hoy);
$cuotasF = $pag->cuotas($eFav, $hoy);
check('Saldo a favor: se aplica a la cuota de extras', 172.5, (float) end($cuotasF)['pagado']);
check('Saldo a favor: queda lo que sobra a favor', 27.5, $pag->resumen($eFav, $hoy)['a_favor']);
// Evento cancelado: no se aprueba
$eCan = $eventoVendido('Pedro Cancelado', 'cumpleanos', 90, $jardin, null);
$pc = $ext->pedir($nid, $eCan, [$pHora => 1], '', '6.6.6.6');
$ev->cambiarEstado($nid, $eCan, 'cancelado', ['motivo' => 'Cambio de planes'], $dueno, $hoy);
check_error('Evento cancelado: no se aprueba', 'ya no se pueden aprobar', fn() => $ext->aprobar($nid, $pc, $dueno));
check('Evento cancelado: el cliente ya no pide', true, str_contains((string) $ext->motivoNoDisponible($nid, $eCan), 'activo'));
// Un borrador nuevo queda reemplazado al aprobar
$eBorr = $eventoVendido('Rosa Borrador', 'cumpleanos', 95, $salon, null);
$cb = $cot->crear($nid, $eBorr, null, (int) $dueno['id'], $hoy);
$pb = $ext->pedir($nid, $eBorr, [$pHora => 1], '', '7.7.7.7');
$ext->aprobar($nid, $pb, $dueno, '', $hoy);
check('Un borrador sin aceptar queda reemplazado', 'reemplazada', $db->query("SELECT estado FROM cotizaciones WHERE id = $cb")->fetchColumn());

titulo('14. Encuesta de satisfacción: enlace y respuesta');
$eFin = $eventoVendido('Gabriela Andrade', 'boda', 30, $salon);
check('Enlace secreto de 48 letras', 1, preg_match('/^[a-f0-9]{48}$/', (string) $ev->obtener($nid, $eFin)['token_encuesta']));
check('Cada evento tiene su propio enlace', (int) $db->query('SELECT count(*) FROM eventos')->fetchColumn(), (int) $db->query('SELECT count(DISTINCT token_encuesta) FROM eventos')->fetchColumn());
$tk = (string) $ev->obtener($nid, $eFin)['token_encuesta'];
check('Enlace falso', null, $enc->porToken(str_repeat('a', 48)));
check('Enlace mal formado', null, $enc->porToken("x' OR 1=1"));
check('El enlace del portal no abre la encuesta', null, $enc->porToken((string) $ev->obtener($nid, $eFin)['token_cliente']));
check('Evento futuro: la encuesta todavía no abre', 'La encuesta se abre el día de tu evento. ¡Te esperamos!', Encuestas::motivoCerrada($enc->porToken($tk)));
check_error('No se responde antes del evento', 'se abre el día', fn() => $enc->responder($tk, ['nps' => 10]));
$db->exec("UPDATE eventos SET fecha = CURRENT_DATE - 1, estado = 'realizado' WHERE id = $eFin");
check('Después del evento: abierta', null, Encuestas::motivoCerrada($enc->porToken($tk)));
check_error('Sin NPS', 'del 0 al 10', fn() => $enc->responder($tk, ['comida' => 5]));
check_error('NPS 11', 'del 0 al 10', fn() => $enc->responder($tk, ['nps' => '11']));
check_error('NPS con letras', 'del 0 al 10', fn() => $enc->responder($tk, ['nps' => 'diez']));
check_error('Estrellas fuera de rango', '1 a 5 estrellas', fn() => $enc->responder($tk, ['nps' => 9, 'comida' => 6]));
check_error('Campo trampa lleno (robot)', 'No se pudo enviar', fn() => $enc->responder($tk, ['nps' => 9, 'sitio_web' => 'http://spam']));
$antesBaja = $avisos('⚠️ Opinión baja%', $nid);
$e1 = $enc->responder($tk, ['nps' => '10', 'comida' => '5', 'atencion' => '4', 'instalaciones' => '', 'organizacion' => '5', 'comentario' => '¡Todo perfecto!'], '9.9.9.9');
check('Respuesta guardada', 10, (int) $e1['nps']);
check('Estrella vacía = sin respuesta', null, $e1['instalaciones']);
check('NPS alto: no avisa al dueño', $antesBaja, $avisos('⚠️ Opinión baja%', $nid));
check_error('Una sola respuesta por evento', 'Ya recibimos tu opinión', fn() => $enc->responder($tk, ['nps' => 2]));
check('Sigue la primera respuesta', 10, (int) $enc->deEvento($nid, $eFin)['nps']);
check('Historial: respondió la encuesta', 1, (int) $db->query("SELECT count(*) FROM registro_cambios WHERE evento_id = $eFin AND accion LIKE 'Respondió la encuesta%10 de 10'")->fetchColumn());
check('Otro negocio no ve la encuesta', null, $enc->deEvento($nidB, $eFin));
// Mensaje de reseña: con encuesta pendiente lleva el enlace; respondida, ya no
$eRes = $eventoVendido('Daniela Ríos', 'quince', 31, $jardin, 'Paquete Quince Años');
$db->exec("UPDATE eventos SET fecha = CURRENT_DATE - 2, estado = 'realizado' WHERE id = $eRes");
$res = array_column($cob->mensajesDelDia($nid, $hoy)['resena'], null, 'ref_id');
check('Reseña con encuesta pendiente: lleva el enlace de la encuesta', true, str_contains($res[$eRes]['texto'], 'r=encuesta&t=' . $ev->obtener($nid, $eRes)['token_encuesta']));
check('Reseña con encuesta respondida: sin enlace de encuesta', false, str_contains($res[$eFin]['texto'], 'r=encuesta'));
$db->exec("UPDATE negocios SET resenas_url = 'https://g.page/r/arrayanes/review' WHERE id = $nid");
$res = array_column($cob->mensajesDelDia($nid, $hoy)['resena'], null, 'ref_id');
check('Con enlace de Google: encuesta y reseña', true, str_contains($res[$eRes]['texto'], 'r=encuesta') && str_contains($res[$eRes]['texto'], 'g.page/r/arrayanes'));
// NPS bajo: aviso inmediato solo al dueño
$db->prepare("INSERT INTO usuarios (negocio_id, nombre, email, password_hash, rol) VALUES (?, 'Vendedor Uno', ?, 'x', 'vendedor')")->execute([$nid, 'vend' . random_int(1, 999999) . '@prueba.ec']);
$enc->responder((string) $ev->obtener($nid, $eRes)['token_encuesta'], ['nps' => '4', 'comida' => '2', 'atencion' => '3', 'organizacion' => '2', 'comentario' => 'La comida llegó fría'], '9.9.9.9');
check('NPS 4: aviso al dueño', $antesBaja + 1, $avisos('⚠️ Opinión baja%La comida llegó fría%', $nid));
check('El aviso llega solo al dueño', 0, (int) $db->query("SELECT count(*) FROM notificaciones n JOIN usuarios u ON u.id = n.usuario_id WHERE u.rol <> 'dueno' AND n.texto LIKE '⚠️ Opinión baja%'")->fetchColumn());
// Freno por IP
foreach ([1, 2] as $k) $ev->crear($nid, ['cliente' => ['nombre' => "Cliente IP $k", 'telefono' => '098765432' . $k], 'tipo' => 'otro', 'fecha' => $hoy->modify('+' . (200 + $k) . ' days')->format('Y-m-d'),
    'hora_inicio' => '10:00', 'hora_fin' => '12:00', 'invitados' => 20, 'espacios' => [$jardin]], null, $hoy);
$db->exec("INSERT INTO encuestas (negocio_id, evento_id, nps, ip) SELECT $nid, id, 8, '8.8.8.8' FROM eventos WHERE negocio_id = $nid AND id NOT IN (SELECT evento_id FROM encuestas) AND id <> $eGrad LIMIT 10");
$db->exec("UPDATE eventos SET fecha = CURRENT_DATE - 3, estado = 'realizado' WHERE id = $eGrad");
check_error('Más de 10 respuestas por hora desde la misma IP', 'muchas respuestas', fn() => $enc->responder((string) $ev->obtener($nid, $eGrad)['token_encuesta'], ['nps' => 9], '8.8.8.8'));
$db->exec("DELETE FROM encuestas WHERE ip = '8.8.8.8'");
// Encuesta cerrada después de 120 días
$db->exec("UPDATE eventos SET fecha = CURRENT_DATE - 121 WHERE id = $eGrad");
check('A los 121 días se cierra', true, str_contains((string) Encuestas::motivoCerrada($enc->porToken((string) $ev->obtener($nid, $eGrad)['token_encuesta'])), 'se cerró'));
$db->exec("UPDATE eventos SET fecha = CURRENT_DATE - 3 WHERE id = $eGrad");
check('Evento cancelado: sin encuesta', true, Encuestas::motivoCerrada($enc->porToken((string) $ev->obtener($nid, $eCan)['token_encuesta'])) !== null);

titulo('15. Cuentas del NPS y reporte de satisfacción');
check('NPS sin respuestas', null, Encuestas::nps([]));
check('NPS: 2 promotores, 2 pasivos, 2 detractores = 0', 0, Encuestas::nps([10, 9, 8, 7, 6, 0]));
check('NPS: 3 promotores y 1 detractor de 4 = 50', 50, Encuestas::nps([10, 10, 9, 5]));
check('NPS todos detractores = −100', -100, Encuestas::nps([0, 3, 6]));
check('NPS redondeado (2 de 3 promotores) = 67', 67, Encuestas::nps([9, 10, 7]));
check('Grupos', ['promotor', 'pasivo', 'detractor', 'detractor'], array_map([Encuestas::class, 'grupo'], [9, 7, 6, 0]));
$enc->responder((string) $ev->obtener($nid, $eGrad)['token_encuesta'], ['nps' => '8', 'comida' => '4', 'atencion' => '5', 'instalaciones' => '3', 'organizacion' => '4'], '7.7.7.1');
$rep = $enc->reporte($nid, $hoy->modify('-30 days')->format('Y-m-d'), $hoy->format('Y-m-d'), $hoy);
check('Reporte: 3 respuestas', 3, $rep['respuestas']);
check('Reporte: 1 promotor, 1 pasivo, 1 detractor', [1, 1, 1], [$rep['promotores'], $rep['pasivos'], $rep['detractores']]);
check('Reporte: NPS 0', 0, $rep['nps']);
check('Reporte: % promotores', 33, $rep['pct_promotores']);
check('Promedio de comida (5, 2, 4)', 3.7, $rep['promedios']['comida']);
check('Promedio de instalaciones ignora la vacía (3)', 3.0, $rep['promedios']['instalaciones']);
check('Promedio de la recomendación', round((10 + 4 + 8) / 3, 1), $rep['promedio_nps']);
check('Comentarios: 2 con texto', 2, count($rep['comentarios']));
check('Comentarios: el más reciente primero', 'La comida llegó fría', $rep['comentarios'][0]['comentario']);
check('Por mes del evento', true, array_sum(array_column($rep['por_mes'], 'respuestas')) === 3);
check('Tasa de respuesta sobre eventos pasados', true, $rep['tasa'] > 0 && $rep['tasa'] <= 100);
check('Otro negocio: reporte vacío', 0, $enc->reporte($nidB, '2000-01-01', '2100-01-01')['respuestas']);
$mesAyer = $hoy->modify('-1 day')->format('Y-m');
$rm = $enc->resumenMes($nid, $mesAyer);
check('Resumen del mes: encuestas del mes', true, $rm['encuestas'] >= 1);
$resumen = (new Reportes($db))->resumenMes($nid, $mesAyer, $hoy);
check('El resumen mensual trae el NPS', $rm['nps'], $resumen['nps']);
check('El texto del reporte mensual muestra el NPS', true, str_contains(Reportes::textoResumenMes($resumen, 'Quinta Los Arrayanes'), 'NPS'));
check('Sin encuestas, el texto no habla de NPS', false, str_contains(Reportes::textoResumenMes((new Reportes($db))->resumenMes($nidB, $mesAyer, $hoy), 'Otro'), 'NPS'));

titulo('16. Anexo de contrato: solo si el contrato ya está firmado');
$con = new Contratos($db);
$anx = new Anexos($db);
$pdfTexto = function (object $pdf): string {
    $tmp = tempnam(sys_get_temp_dir(), 'pdf');
    file_put_contents($tmp, Pdf::comoTexto($pdf));
    $t = (string) shell_exec('pdftotext -layout ' . escapeshellarg($tmp) . ' - 2>/dev/null');
    @unlink($tmp);
    return preg_replace('/\s+/u', ' ', $t);
};
$negocioA = $db->query("SELECT * FROM negocios WHERE id = $nid")->fetch();
// Sin contrato: no hay anexo, los extras entran en el contrato cuando se genere
$eNoK = $eventoVendido('Sofía Sin Contrato', 'boda', 120, $salon);
$rn = $ext->aprobar($nid, $ext->pedir($nid, $eNoK, [$pHora => 1], '', '8.8.1.1'), $dueno, '', $hoy);
check('Sin contrato: no se prepara anexo', null, $rn['anexo_id']);
check('Sin contrato: no avisa de contrato pendiente', null, $rn['contrato_sin_firmar']);
$kNoK = $con->generar($nid, $eNoK, null, $dueno, $hoy);
check('El contrato que se genera después ya trae los extras', true, str_contains($con->obtener($nid, $kNoK)['texto'], 'Hora adicional'));
check('El contrato sigue saliendo de la plantilla de contrato (no la del anexo)', true, str_starts_with($con->obtener($nid, $kNoK)['texto'], 'CONTRATO DE PRESTACIÓN'));
// Contrato enviado pero sin firmar: tampoco hay anexo, pero se avisa que ese contrato no incluye los extras
$con->enviar($nid, $kNoK, $dueno);
$rn2 = $ext->aprobar($nid, $ext->pedir($nid, $eNoK, [$pLoca => 1], '', '8.8.1.2'), $dueno, '', $hoy);
check('Contrato sin firmar: no hay anexo', null, $rn2['anexo_id']);
check('Contrato sin firmar: se avisa cuál no incluye los extras', (int) $con->obtener($nid, $kNoK)['numero'], $rn2['contrato_sin_firmar']);
// Contrato firmado: se prepara el anexo
$eK = $eventoVendido('Isabel Cristina Mora', 'boda', 100, $jardin);
$db->exec("UPDATE clientes SET identificacion = '1712345678' WHERE id = (SELECT cliente_id FROM eventos WHERE id = $eK)");
$kK = $con->generar($nid, $eK, null, $dueno, $hoy);
$con->enviar($nid, $kK, $dueno);
$tokK = (string) $db->query("SELECT token FROM contratos WHERE id = $kK")->fetchColumn();
$con->firmar($tokK, 'Isabel Cristina Mora', '1712345678', firma_png(), true, '1.2.3.4');
$K = $con->obtener($nid, $kK);
check('Contrato firmado', 'firmado', $K['estado']);
$total0K = $totalEvento($eK);
$pk = $ext->pedir($nid, $eK, [$pHora => '2', $pLoca => 1], '', '8.8.1.3');
$rk = $ext->aprobar($nid, $pk, $dueno, '', $hoy);
check('Contrato firmado: se prepara el anexo', true, is_int($rk['anexo_id']));
$A = $anx->obtener($nid, $rk['anexo_id']);
check('Número propio: Anexo 1 al contrato N.º X', 'Anexo 1 al contrato N.º ' . $K['numero'], $A['titulo']);
check('Queda listo para firmar', 'enviado', $A['estado']);
check('Se puede firmar', true, $A['firmable']);
check('Ligado al contrato y al pedido', [$kK, $pk], [(int) $A['contrato_id'], (int) $A['pedido_id']]);
check('Monto del anexo = lo que subió el evento', $rk['monto'], (float) $A['monto']);
check('El dinero no cambia: el evento sube 667 una sola vez', round($total0K + 667, 2), $totalEvento($eK));
$t = $A['texto'];
$cuotaK = $db->query('SELECT * FROM cuotas WHERE id = ' . (int) $rk['cuota_id'])->fetch();
check('Variables: sin {{}} pendientes', false, str_contains($t, '{{'));
check('Variables: título con los números', true, str_starts_with($t, 'ANEXO 1 AL CONTRATO N.º ' . $K['numero']));
check('Variables: cliente', true, str_contains($t, 'Isabel Cristina Mora'));
check('Variables: cédula del cliente', true, str_contains($t, '1712345678'));
check('Variables: evento', true, str_contains($t, $ev->obtener($nid, $eK)['nombre']));
check('Variables: fecha de firma del contrato original', 1, preg_match('/firmado el \\w+ ' . (new DateTimeImmutable(substr((string) $K['firmado_en'], 0, 10)))->format('j') . ' de /u', $t));
check('Variables: lista de extras con cantidad, precio y subtotal', true, str_contains($t, '• Hora adicional — 2 × $150,00 + IVA = $300,00') && str_contains($t, '• Hora loca — 1 × $280,00 + IVA = $280,00'));
check('Variables: subtotal y IVA de los extras', true, str_contains($t, 'suman $580,00 más $87,00 de IVA'));
check('Variables: total de los extras con IVA y en letras', true, str_contains($t, 'es decir $667,00 (seiscientos sesenta y siete dólares'));
check('Variables: total anterior y nuevo total', true, str_contains($t, 'pasa de $' . number_format($total0K, 2, ',', '.') . ' a $' . number_format($total0K + 667, 2, ',', '.')));
check('Variables: cuota de extras con su valor y fecha', true, str_contains($t, '"' . $cuotaK['concepto'] . '" por $667,00, hasta el ' . (new DateTimeImmutable($cuotaK['vence']))->format('d/m/Y')));
check('Variables: plan de pagos completo con la cuota nueva', true, str_contains($t, '• ' . $cuotaK['concepto'] . ': $667,00'));
check('Cláusula: lo demás del contrato sigue igual', true, str_contains($t, 'se mantienen igual y siguen vigentes'));
check('Huella SHA-256 del texto', hash('sha256', $t), $A['hash_sha256']);
check('Íntegro', true, $A['integro']);
check('Código de verificación de 12 letras', 1, preg_match('/^[A-F0-9]{12}$/', (string) $A['codigo_verificacion']));
check('Historial: preparó el anexo', 1, (int) $db->query("SELECT count(*) FROM registro_cambios WHERE evento_id = $eK AND accion LIKE 'Preparó el anexo 1 al contrato%'")->fetchColumn());
check('Un anexo por pedido: crearlo otra vez devuelve el mismo', $rk['anexo_id'], $anx->crearParaPedido($nid, $pk, $dueno, $hoy));
$rk2 = $ext->aprobar($nid, $ext->pedir($nid, $eK, [$pHora => 1], '', '8.8.1.4'), $dueno, '', $hoy);
check('Segundo pedido aprobado: Anexo 2', 'Anexo 2 al contrato N.º ' . $K['numero'], $anx->obtener($nid, $rk2['anexo_id'])['titulo']);
check('Dos anexos por firmar', 2, count($anx->porFirmar($nid, $eK)));
check('El contrato vigente sigue siendo el contrato (no el anexo)', $kK, (int) $con->vigente($nid, $eK)['id']);

titulo('17. Plantilla del anexo');
$pl = $anx->plantilla($nid);
check('Plantilla de tipo anexo creada sola', 'anexo', $pl['tipo']);
check('Una sola plantilla de anexo por negocio', 1, (int) $db->query("SELECT count(*) FROM plantillas_contrato WHERE negocio_id = $nid AND tipo = 'anexo'")->fetchColumn());
check('Las plantillas de contrato no muestran la del anexo', false, in_array('anexo', array_column($con->plantillas($nid), 'tipo'), true));
check_error('Variable que no existe en el anexo', 'no existen en el anexo', fn() => $anx->guardarPlantilla($nid, Anexos::plantillaDefecto() . ' {{garantia}}'));
check_error('Texto muy corto', 'muy corto', fn() => $anx->guardarPlantilla($nid, 'Anexo {{cliente}}'));
check_error('La plantilla del anexo no se edita como contrato', 'No se encontró la plantilla', fn() => $con->guardarPlantilla($nid, (int) $pl['id'], 'X anexo', Contratos::plantillaDefecto(), false));
$anx->guardarPlantilla($nid, "ANEXO ESPECIAL {{numero_anexo}} de {{cliente}}: extras {{total_extras}}. Todo lo demás del contrato {{numero_contrato}} sigue igual.");
$rk3 = $ext->aprobar($nid, $ext->pedir($nid, $eK, [$pLoca => 1], '', '8.8.1.5'), $dueno, '', $hoy);
check('El anexo nuevo usa la plantilla editada', 'ANEXO ESPECIAL 3 de Isabel Cristina Mora: extras $322,00. Todo lo demás del contrato ' . $K['numero'] . ' sigue igual.', $anx->obtener($nid, $rk3['anexo_id'])['texto']);
check('Los anexos ya hechos no cambian', $t, $anx->obtener($nid, $rk['anexo_id'])['texto']);
$anx->restaurarPlantilla($nid);
check('Restaurar el texto de fábrica', Anexos::plantillaDefecto(), $anx->plantilla($nid)['texto']);

titulo('18. Recordatorio por WhatsApp del anexo');
$mA = array_column($cob->mensajesDelDia($nid, $hoy)['anexo'], null, 'ref_id');
check('Mensajes de hoy: los 3 anexos por firmar', 3, count(array_intersect_key($mA, array_flip([$rk['anexo_id'], $rk2['anexo_id'], $rk3['anexo_id']]))));
check('Mensaje con el enlace de firma del anexo', true, str_contains($mA[$rk['anexo_id']]['texto'], 'r=firmar_anexo&t=' . $A['token']));
check('Mensaje con el monto', true, str_contains($mA[$rk['anexo_id']]['texto'], '$667,00'));
check('Motivo con el título', true, str_contains($mA[$rk['anexo_id']]['motivo'], 'Anexo 1 al contrato'));
$cob->marcarEnviado($nid, 'anexo', $rk['anexo_id'], $dueno, $hoy);
$cob->marcarEnviado($nid, 'anexo', $rk['anexo_id'], $dueno, $hoy);
check('Marcar dos veces el mismo día no duplica', 1, (int) $db->query("SELECT count(*) FROM mensajes_enviados WHERE tipo = 'anexo' AND ref_id = {$rk['anexo_id']}")->fetchColumn());
check('Marcado: no sale hoy', false, isset(array_column($cob->mensajesDelDia($nid, $hoy)['anexo'], null, 'ref_id')[$rk['anexo_id']]));
check('Tampoco en 2 días', false, isset(array_column($cob->mensajesDelDia($nid, $hoy->modify('+2 days'))['anexo'], null, 'ref_id')[$rk['anexo_id']]));
check('A los 3 días vuelve a salir si sigue sin firmar', true, isset(array_column($cob->mensajesDelDia($nid, $hoy->modify('+3 days'))['anexo'], null, 'ref_id')[$rk['anexo_id']]));
check('Otro negocio: sin anexos en sus mensajes', 0, count($cob->mensajesDelDia($nidB, $hoy)['anexo']));
check_error('Otro negocio no marca mis anexos', 'ya no está en la lista', fn() => $cob->marcarEnviado($nidB, 'anexo', $rk['anexo_id'], $duenoB, $hoy));

titulo('19. El cliente firma el anexo con su enlace');
check('Enlace falso', null, $anx->porToken(str_repeat('b', 48)));
check('El enlace del contrato no abre el anexo', null, $anx->porToken($tokK));
check_error('Firmar con enlace falso', 'no es válido', fn() => $anx->firmar(str_repeat('b', 48), 'Isabel Mora', '1712345678', firma_png(), true));
check_error('Sin aceptar', 'Marca la casilla', fn() => $anx->firmar($A['token'], 'Isabel Mora', '1712345678', firma_png(), false));
check_error('Nombre muy corto', 'nombre completo', fn() => $anx->firmar($A['token'], 'Isa', '1712345678', firma_png(), true));
check_error('Cédula mal escrita', 'Escribe tu cédula', fn() => $anx->firmar($A['token'], 'Isabel Mora', '12345', firma_png(), true));
check_error('Firma vacía', 'vacía', fn() => $anx->firmar($A['token'], 'Isabel Mora', '1712345678', firma_png(true), true));
$antesF = $avisos('✍️ %firmó el anexo 1%', $nid);
$F = $anx->firmar($A['token'], 'Isabel Cristina Mora', '1712345678', firma_png(), true, '5.6.7.8', 'Prueba');
check('Anexo firmado', 'firmado', $F['estado']);
check('Guarda quién, cuándo y desde dónde', ['Isabel Cristina Mora', '1712345678', '5.6.7.8'], [$F['firmante_nombre'], $F['firmante_identificacion'], $F['firma_ip']]);
check('Guarda la imagen de la firma', true, \TuRenta\Fotos::ruta('firmas', $F['firma_archivo']) !== null);
check('Aviso al local (dueño y vendedores)', $antesF + count((new \TuRenta\Notificaciones($db))->destinos($nid, ['dueno', 'vendedor'])), $avisos('✍️ %firmó el anexo 1%', $nid));
check('Historial: el cliente firmó', 1, (int) $db->query("SELECT count(*) FROM registro_cambios WHERE evento_id = $eK AND accion LIKE 'Firmó el anexo 1 al contrato%'")->fetchColumn());
check_error('Firmar dos veces', 'ya fue firmado', fn() => $anx->firmar($A['token'], 'Isabel Cristina Mora', '1712345678', firma_png(), true));
check('Ya no se recuerda', false, isset(array_column($cob->mensajesDelDia($nid, $hoy->modify('+3 days'))['anexo'], null, 'ref_id')[$rk['anexo_id']]));
check('Sigue íntegro después de firmar', true, $anx->obtener($nid, $rk['anexo_id'])['integro']);
$V = $anx->verificar(strtolower($F['codigo_verificacion']));
check('Verificación por el código del QR', [(int) $rk['anexo_id'], 'Anexo', 'firmado'], [(int) $V['id'], $V['documento_corto'], $V['estado']]);
check('Verificación con código inventado', null, $anx->verificar('ABCDEF123456'));
$pdf = PdfDocumentos::contrato($negocioA, $anx->obtener($nid, $rk['anexo_id']), 'https://prueba.turenta.ec/?r=verificar&c=' . $F['codigo_verificacion']);
check('PDF del anexo', '%PDF', substr(Pdf::comoTexto($pdf), 0, 4));
$tp = $pdfTexto($pdf);
check('El PDF dice que es el anexo firmado', true, str_contains($tp, 'Anexo firmado electrónicamente por el cliente'));
check('El PDF lleva el código y la huella', true, str_contains($tp, $F['codigo_verificacion']) && str_contains($tp, substr($F['hash_sha256'], 0, 20)));
check('El PDF del contrato sigue diciendo contrato', true, str_contains($pdfTexto(PdfDocumentos::contrato($negocioA, $K, null)), 'Contrato firmado electrónicamente'));
// Texto alterado: no se firma
$A2 = $anx->obtener($nid, $rk2['anexo_id']);
$db->exec("UPDATE contrato_anexos SET texto = texto || ' (cambiado)' WHERE id = {$rk2['anexo_id']}");
check('Texto cambiado: la huella ya no coincide', false, $anx->obtener($nid, $rk2['anexo_id'])['integro']);
check_error('Texto cambiado: no se firma', 'fue alterado', fn() => $anx->firmar($A2['token'], 'Isabel Cristina Mora', '1712345678', firma_png(), true));
$db->prepare('UPDATE contrato_anexos SET texto = ? WHERE id = ?')->execute([$A2['texto'], $rk2['anexo_id']]);
// Contrato anulado: los anexos pendientes quedan sin efecto
$con->anular($nid, $kK, 'Cambio de espacio', $dueno);
check('Contrato anulado: el anexo pendiente ya no se firma', false, $anx->obtener($nid, $rk2['anexo_id'])['firmable']);
check('Contrato anulado: no quedan anexos por firmar', 0, count($anx->porFirmar($nid, $eK)));
check('Contrato anulado: ya no se recuerdan', 0, count(array_filter($cob->mensajesDelDia($nid, $hoy->modify('+3 days'))['anexo'], fn($m) => $m['evento_id'] === $eK)));
check_error('Contrato anulado: firmar el anexo', 'ya no se puede firmar', fn() => $anx->firmar($A2['token'], 'Isabel Cristina Mora', '1712345678', firma_png(), true));
check('El anexo firmado se conserva', 'firmado', $anx->obtener($nid, $rk['anexo_id'])['estado']);

titulo('20. Anexos: un negocio nunca ve los de otro');
check('Otro negocio no abre mi anexo', null, $anx->obtener($nidB, $rk['anexo_id']));
check('Otro negocio no ve anexos de mi evento', 0, count($anx->deEvento($nidB, $eK)));
check('Otro negocio no busca anexos por mi pedido', null, $anx->porPedido($nidB, $pk));
check_error('Otro negocio no prepara anexos de mis pedidos', 'no está aprobado', fn() => $anx->crearParaPedido($nidB, $pk));
check('Otro negocio: su plantilla de anexo es propia', true, (int) $anx->plantilla($nidB)['id'] !== (int) $pl['id']);
