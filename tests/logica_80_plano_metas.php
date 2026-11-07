<?php
/**
 * Plano visual del salón (plantillas por espacio, plano del evento unido a las mesas de invitados, validación,
 * ocupación, aislamiento entre negocios, PDF) y metas de ventas (meta de siempre + meses distintos, avance del mes,
 * comparación con el año anterior, por vendedor, CSV).
 */
use TuRenta\{Cotizaciones, Eventos, Invitados, Metas, Pdf, PdfPlano, Planos, Reportes, Usuarios};

$db = base_limpia();
$hoy = new DateTimeImmutable('2026-10-13');
[$nid, $dueno] = negocio_prueba($db);
[$nid2, $dueno2] = negocio_prueba($db, 'quinta', 'Hacienda Ajena');
$salon = espacio_id($db, $nid, 'Salón principal');
$jardin = espacio_id($db, $nid, 'Jardín');
$salonAjeno = espacio_id($db, $nid2, 'Salón principal');
$pl = new Planos($db);
$ev = new Eventos($db);
$inv = new Invitados($db);
$cuenta = fn(string $sql) => (int) $db->query($sql)->fetchColumn();
$mesa = fn(string $tipo, float $x, float $y, string $etq = '', ?int $puestos = 10, ?int $mesaId = null, ?int $id = null) =>
    ['id' => $id, 'tipo' => $tipo, 'x' => $x, 'y' => $y, 'an' => Planos::TIPOS[$tipo]['ancho'], 'al' => Planos::TIPOS[$tipo]['alto'], 'rot' => 0,
     'etq' => $etq, 'puestos' => $puestos, 'mesa_id' => $mesaId];

titulo('1. Ayudas');
check('Medida con coma', 12.5, Planos::medida('12,5', 'el ancho'));
check('Medida con "m"', 20.0, Planos::medida('20 m', 'el ancho'));
check_error('Medida vacía', 'en metros', fn() => Planos::medida('', 'el ancho'));
check_error('Salón muy chico', 'entre 3 y 300', fn() => Planos::medida('2', 'el ancho'));
check('Etiqueta corta "Mesa 12" -> 12', '12', Planos::etiquetaCorta('Mesa 12'));
check('Etiqueta corta deja los nombres', 'Mesa de honor', Planos::etiquetaCorta('Mesa de honor'));
check('Redonda de 10: 10 sillas', 10, count(Planos::sillas('redonda', 1.8, 1.8, 10)));
check('Rectangular de 8: 8 sillas', 8, count(Planos::sillas('rectangular', 2.4, 0.9, 8)));
check('Mesa angosta (escuela): sillas de un solo lado', 1, count(array_unique(array_map(fn($s) => $s[1], Planos::sillas('rectangular', 1.8, 0.5, 3)))));
check('Más de 24 puestos: no se dibujan sillas', 0, count(Planos::sillas('imperial', 6, 1.2, 30)));
check('Texto de una barra parada no queda de cabeza', 180, Planos::giroTexto('barra', 270));
check('Texto de las mesas siempre derecho', -45, Planos::giroTexto('redonda', 45));

titulo('2. Plantillas de distribución por espacio');
check_error('Sin nombre', 'Ponle un nombre', fn() => $pl->crearPlantilla($nid, $salon, '', 20, 30));
check_error('Espacio de otro negocio', 'No se encontró el espacio', fn() => $pl->crearPlantilla($nid, $salonAjeno, 'Boda', 20, 30));
check_error('Ejemplo que no existe', 'cómo empezar', fn() => $pl->crearPlantilla($nid, $salon, 'Boda', 20, 30, null, 'otro'));
$db->prepare('UPDATE espacios SET capacidad_banquete = 150 WHERE id = ?')->execute([$salon]);
$t1 = $pl->crearPlantilla($nid, $salon, 'Boda 150 redondas', '20', '30', (int) $dueno['id'], 'boda');
$d1 = $pl->datos($nid, $t1);
check('Ejemplo de boda: tiene tarima, pista y mesa de honor', 3, count(array_filter($d1['elementos'], fn($e) => in_array($e['tipo'], ['tarima', 'pista', 'imperial'], true))));
check('Ejemplo de boda: no pasa la capacidad del salón (150)', true, $d1['resumen']['puestos'] <= 150 && $d1['resumen']['puestos'] >= 140);
$dentro = array_filter($d1['elementos'], fn($e) => $e['x'] >= 0 && $e['x'] <= 20 && $e['y'] >= 0 && $e['y'] <= 30);
check('Ejemplo de boda: todo dentro del salón', count($d1['elementos']), count($dentro));
$t2 = $pl->crearPlantilla($nid, $salon, 'Corporativo escuela', 15, 20, null, 'escuela');
$d2 = $pl->datos($nid, $t2);
check('Ejemplo escuela: mesas angostas de 3', true, count($d2['elementos']) > 10 && $d2['elementos'][2]['al'] == 0.5 && $d2['elementos'][2]['puestos'] === 3);
$t3 = $pl->crearPlantilla($nid, $jardin, 'Cóctel', 12, 12);
check('Plantilla vacía', 0, count($pl->elementos($t3)));
check('Lista: 3 plantillas', 3, count($pl->plantillas($nid)));
check('Lista de un espacio', 2, count($pl->plantillas($nid, $salon)));
check('Lista: cuenta mesas y puestos', (int) $d1['resumen']['puestos'], (int) array_values(array_filter($pl->plantillas($nid), fn($p) => (int) $p['id'] === $t1))[0]['puestos']);
check('Otro negocio no ve las plantillas', 0, count($pl->plantillas($nid2)));
$copia = $pl->duplicarPlantilla($nid, $t1);
check('Copiar una plantilla copia todo', count($d1['elementos']), count($pl->elementos($copia)));
check('La copia dice "(copia)"', 'Boda 150 redondas (copia)', $pl->plano($nid, $copia)['nombre']);
$pl->guardar($nid, $t3, [$mesa('redonda', 11, 11, 'Mesa 1'), $mesa('pista', 6, 6, '', null)]);
$pl->editarDatos($nid, $t3, 'Cóctel jardín', '8', '8');
$els = $pl->elementos($t3);
check('Achicar el salón acerca a la pared lo que quedó afuera', [8.0, 8.0], [$els[0]['x'], $els[0]['y']]);
check('Achicar no mueve lo que ya estaba adentro', [6.0, 6.0], [$els[1]['x'], $els[1]['y']]);
$pl->borrar($nid, $copia);
check('Borrar una plantilla', 0, $cuenta("SELECT count(*) FROM planos WHERE id = $copia"));
check('Borrar la plantilla borra sus elementos', 0, $cuenta("SELECT count(*) FROM plano_elementos WHERE plano_id = $copia"));

titulo('3. Guardar y revisar lo que llega del editor');
$v = $pl->plano($nid, $t3)['version'];
$r = $pl->guardar($nid, $t3, [$mesa('redonda', 2, 2, 'Mesa 1'), $mesa('rectangular', 5, 5, 'Mesa 2', 8)], $v);
check('Guardar sube la versión', $v + 1, $r['plano']['version']);
check('Guardar devuelve los elementos con su número', true, $r['elementos'][0]['id'] > 0 && $r['elementos'][1]['id'] > 0);
$id1 = $r['elementos'][0]['id'];
$r2 = $pl->guardar($nid, $t3, [array_merge($r['elementos'][0], ['x' => 3.5, 'rot' => 405]), $mesa('barra', 4, 7, 'Barra', null)], $r['plano']['version']);
check('Mover: se actualiza el mismo elemento (mismo número)', $id1, $r2['elementos'][0]['id']);
check('Mover: nueva posición', 3.5, $r2['elementos'][0]['x']);
check('Giro de 405° = 45°', 45, $r2['elementos'][0]['rot']);
check('Lo que ya no viene se borra', 2, count($pl->elementos($t3)));
check('La barra no lleva puestos', null, $r2['elementos'][1]['puestos']);
check_error('Si otra persona guardó antes: aviso', 'Otra persona cambió este plano', fn() => $pl->guardar($nid, $t3, [], $r['plano']['version']));
check('El aviso de otra persona no borra nada', 2, count($pl->elementos($t3)));
check_error('Fuera del salón', 'fuera del salón', fn() => $pl->guardar($nid, $t3, [$mesa('redonda', 9, 2, 'Mesa 9')]));
check_error('Fuera del salón (negativo)', 'fuera del salón', fn() => $pl->guardar($nid, $t3, [$mesa('pista', 2, -1, '', null)]));
check_error('Tipo que no existe', 'tipo que no existe', fn() => $pl->guardar($nid, $t3, [['tipo' => 'piscina', 'x' => 1, 'y' => 1, 'an' => 1, 'al' => 1]]));
check_error('Puestos 0', 'van de 1 a 100', fn() => $pl->guardar($nid, $t3, [$mesa('redonda', 2, 2, 'Mesa 1', 0)]));
check_error('Puestos 101', 'van de 1 a 100', fn() => $pl->guardar($nid, $t3, [$mesa('redonda', 2, 2, 'Mesa 1', 101)]));
check_error('Puestos con decimales', 'van de 1 a 100', fn() => $pl->guardar($nid, $t3, [array_merge($mesa('redonda', 2, 2, 'Mesa 1'), ['puestos' => '7.5'])]));
check_error('Tamaño más grande que el salón', 'tamaño', fn() => $pl->guardar($nid, $t3, [array_merge($mesa('pista', 4, 4, '', null), ['an' => 9])]));
check_error('Tamaño muy chico', 'tamaño', fn() => $pl->guardar($nid, $t3, [array_merge($mesa('columna', 4, 4, '', null), ['al' => 0.1])]));
check_error('Posición que no es número', 'posición', fn() => $pl->guardar($nid, $t3, [array_merge($mesa('dj', 4, 4, '', null), ['x' => 'abc'])]));
check_error('No es una lista', 'incompleto', fn() => $pl->guardar($nid, $t3, 'hola'));
check_error('Máximo 300 elementos', 'hasta 300 elementos', fn() => $pl->guardar($nid, $t3, array_fill(0, 301, $mesa('columna', 4, 4, '', null))));
$r3 = $pl->guardar($nid, $t3, [array_merge($mesa('redonda', 4, 4, '', 10), ['al' => 3]), array_merge($mesa('texto', 4, 6, "Novios\x00 <b>aquí</b>", null), ['etq' => "  Novios\x07 <b>aquí</b>  "])]);
check('Redonda: alto = ancho', 1.8, $r3['elementos'][0]['al']);
check('Texto sin caracteres raros ni espacios de más', 'Novios <b>aquí</b>', $r3['elementos'][1]['etq']);
check('El SVG escapa el texto', true, str_contains(Planos::svg($pl->datos($nid, $t3)), 'Novios &lt;b&gt;aquí&lt;/b&gt;'));
$con300 = array_fill(0, 300, $mesa('columna', 4, 4, '', null));
check('300 elementos sí se pueden', 300, count($pl->guardar($nid, $t3, $con300)['elementos']));
$pl->guardar($nid, $t3, []);
check('Plano vacío', 0, count($pl->elementos($t3)));

titulo('4. Plano del evento desde una plantilla, unido a las mesas de invitados');
$fecha = '2026-12-12';
$e1 = $ev->crear($nid, ['cliente' => ['nombre' => 'Ana Torres', 'telefono' => '0991112233'], 'tipo' => 'boda', 'fecha' => $fecha, 'invitados' => 150, 'espacios' => [$salon]], (int) $dueno['id'], $hoy);
$inv->crearMesas($nid, $e1, 2, 8);                      // Mesa 1 y Mesa 2 ya existen en invitados (8 puestos)
$db->prepare("UPDATE mesas SET nombre = 'Mesa 5' WHERE evento_id = ? AND nombre = 'Mesa 2'")->execute([$e1]);
check('Sin plano todavía', null, $pl->delEvento($nid, $e1));
check_error('Desde cero: hay que poner el tamaño', 'en metros', fn() => $pl->crearParaEvento($nid, $e1, null, '', ''));
check_error('Plantilla de otro negocio', 'No se encontró el plano', fn() => $pl->crearParaEvento($nid, $e1, $pl->crearPlantilla($nid2, $salonAjeno, 'Ajena', 10, 10)));
$p1 = $pl->crearParaEvento($nid, $e1, $t1, null, null, $dueno);
$P = $pl->plano($nid, $p1);
check('El plano es del evento', [$e1, 'evento', $t1], [(int) $P['evento_id'], $P['tipo'], (int) $P['plantilla_id']]);
check('Copia el tamaño de la plantilla', [20.0, 30.0], [$P['ancho'], $P['largo']]);
check('Copia todos los elementos', count($d1['elementos']), count($pl->elementos($p1)));
$mesasPlano = count(array_filter($d1['elementos'], fn($e) => Planos::esMesa($e['tipo'])));
check('Cada mesa del plano es una mesa de invitados', $mesasPlano, $cuenta("SELECT count(*) FROM mesas WHERE evento_id = $e1"));
check('Ninguna mesa del plano quedó sin unir', 0, $cuenta("SELECT count(*) FROM plano_elementos WHERE plano_id = $p1 AND tipo IN ('redonda','rectangular','imperial') AND mesa_id IS NULL"));
$m1 = (int) $db->query("SELECT id FROM mesas WHERE evento_id = $e1 AND nombre = 'Mesa 1'")->fetchColumn();
$m5 = (int) $db->query("SELECT id FROM mesas WHERE evento_id = $e1 AND nombre = 'Mesa 5'")->fetchColumn();
$elDe = fn(int $mesaId) => array_values(array_filter($pl->datos($nid, $p1)['elementos'], fn($e) => $e['mesa_id'] === $mesaId))[0] ?? null;
check('La "Mesa 1" que ya había quedó en la Mesa 1 del plano (mismo nombre)', 'Mesa 1', $elDe($m1)['etq'] ?? null);
check('La "Mesa 5" que ya había quedó en la Mesa 5 del plano', 'Mesa 5', $elDe($m5)['etq'] ?? null);
check('Se respetan los puestos que ya tenía en invitados', 8, $elDe($m1)['puestos']);
check('La mesa de honor se creó con su nombre', 1, $cuenta("SELECT count(*) FROM mesas WHERE evento_id = $e1 AND nombre = 'Mesa de honor'"));
check('Los nombres de las mesas no se repiten', 0, $cuenta("SELECT count(*) FROM (SELECT nombre FROM mesas WHERE evento_id = $e1 GROUP BY nombre HAVING count(*) > 1) x"));
check('Queda en el historial del evento', 1, $cuenta("SELECT count(*) FROM registro_cambios WHERE evento_id = $e1 AND accion LIKE 'Armó el plano del salón%'"));
check_error('Un solo plano por evento', 'ya tiene su plano', fn() => $pl->crearParaEvento($nid, $e1, $t1));
check('El evento encuentra su plano', $p1, (int) $pl->delEvento($nid, $e1)['id']);

titulo('5. Mesa del plano <-> mesa de invitados (en los dos sentidos)');
$D = $pl->datos($nid, $p1);
$base = $D['elementos'];
$antesMesas = $cuenta("SELECT count(*) FROM mesas WHERE evento_id = $e1");
$r = $pl->guardar($nid, $p1, array_merge($base, [$mesa('redonda', 18, 28, 'Mesa de los primos', 6)]), $D['plano']['version'], $dueno);
$nueva = end($r['elementos']);
check('Mesa nueva en el plano -> mesa nueva en invitados', $antesMesas + 1, $cuenta("SELECT count(*) FROM mesas WHERE evento_id = $e1"));
check('Con su nombre y sus puestos', ['Mesa de los primos', 6], array_values($db->query("SELECT nombre, capacidad FROM mesas WHERE id = {$nueva['mesa_id']}")->fetch()));
check('Aparece en la lista de mesas de invitados', true, in_array('Mesa de los primos', array_column($inv->mesas($nid, $e1), 'nombre'), true));
$els = $r['elementos'];
$i = array_key_last($els);
$els[$i]['etq'] = 'Primos';
$els[$i]['puestos'] = 8;
$r = $pl->guardar($nid, $p1, $els, $r['plano']['version']);
check('Cambiar nombre y puestos en el plano cambia la mesa', ['Primos', 8], array_values($db->query("SELECT nombre, capacidad FROM mesas WHERE id = {$nueva['mesa_id']}")->fetch()));
check('Sigue siendo la misma mesa', $nueva['mesa_id'], end($r['elementos'])['mesa_id']);
$inv->editarMesa($nid, $nueva['mesa_id'], 'Mesa Primos', 9);
$x = $elDe($nueva['mesa_id']);
check('Cambiar la mesa en invitados se ve en el plano', ['Mesa Primos', 9], [$x['etq'], $x['puestos']]);
$inv->crearMesas($nid, $e1, 1, 12, 'Mesa');
$D = $pl->datos($nid, $p1);
check('Mesa creada en invitados = mesa sin ubicar en el plano', 1, count($D['sin_ubicar']));
$su = $D['sin_ubicar'][0];
check('Sin ubicar trae nombre y puestos', 12, $su['puestos']);
$r = $pl->guardar($nid, $p1, array_merge($D['elementos'], [$mesa('imperial', 10, 27, $su['etq'], 12, $su['mesa_id'])]), $D['plano']['version']);
check('Ponerla en el plano la une (no crea otra)', [$su['mesa_id'], 0], [end($r['elementos'])['mesa_id'], count($r['sin_ubicar'])]);
check('No se crearon mesas de más', $antesMesas + 2, $cuenta("SELECT count(*) FROM mesas WHERE evento_id = $e1"));
// La misma mesa dos veces (por ejemplo, al duplicar mal): la segunda es otra mesa
$els = $r['elementos'];
$copiaMal = array_merge(end($els), ['id' => null, 'x' => 4, 'y' => 27, 'etq' => '']);
$r = $pl->guardar($nid, $p1, array_merge($els, [$copiaMal]), $r['plano']['version']);
check('Si dos mesas traen la misma mesa de invitados, la segunda es nueva', true, end($r['elementos'])['mesa_id'] !== $su['mesa_id']);
check('Y se llama "Mesa N"', 1, preg_match('/^Mesa \d+$/', end($r['elementos'])['etq']));
// Una mesa de OTRO evento no se puede unir: se crea una nueva para este evento
$e2 = $ev->crear($nid, ['cliente' => ['nombre' => 'Bruno Paz', 'telefono' => '0992223344'], 'tipo' => 'boda', 'fecha' => '2026-12-19', 'invitados' => 80, 'espacios' => [$jardin]], null, $hoy);
$inv->crearMesas($nid, $e2, 1, 10);
$mOtro = (int) $db->query("SELECT id FROM mesas WHERE evento_id = $e2")->fetchColumn();
$r = $pl->guardar($nid, $p1, array_merge($r['elementos'], [$mesa('redonda', 2, 29, 'Robada', 10, $mOtro)]), $r['plano']['version']);
check('Mesa de otro evento: no se une', true, end($r['elementos'])['mesa_id'] !== $mOtro);
check('Mesa de otro evento: sigue intacta en su evento', [$e2, null], [(int) $db->query("SELECT evento_id FROM mesas WHERE id = $mOtro")->fetchColumn(),
      $db->query("SELECT plano_id FROM plano_elementos WHERE mesa_id = $mOtro")->fetchColumn() ?: null]);

titulo('6. Invitados sentados en el plano');
$inv->importar($nid, $e1, "Ana Pérez; 0991000001; 3\nLuis Mora; 0991000002; 4\nTía Rosa; 0991000003; 2\nPrimo Juan; 0991000004; 1\nNo Viene; 0991000005; 5");
$id = fn(string $n) => (int) $db->query("SELECT id FROM invitados WHERE evento_id = $e1 AND nombre = '$n'")->fetchColumn();
$mp = $nueva['mesa_id'];   // Mesa Primos (9 puestos)
foreach (['Ana Pérez', 'Luis Mora', 'Tía Rosa', 'No Viene'] as $n) $inv->asignarMesa($nid, $id($n), $mp);
$inv->guardar($nid, $e1, $id('Ana Pérez'), ['nombre' => 'Ana Pérez', 'pases' => 3, 'estado' => 'confirmado', 'confirmados' => 2]);
$inv->guardar($nid, $e1, $id('No Viene'), ['nombre' => 'No Viene', 'pases' => 5, 'estado' => 'no_asiste']);
$x = $elDe($mp);
check('Ocupados: confirmados (2) + por confirmar con todos sus pases (4 + 2); los que no van no cuentan', 8, $x['ocupados']);
check('Lista de los sentados (sin los que no van)', ['Ana Pérez', 'Luis Mora', 'Tía Rosa'], array_column($x['invitados'], 'n'));
check('Marca quién todavía no confirma', [false, true, true], array_column($x['invitados'], 'p'));
check('Mesa con lugar = parcial', 'parcial', Planos::ocupacion($x));
check('Mesa vacía', 'vacia', Planos::ocupacion(['ocupados' => 0, 'puestos' => 10]));
check('Mesa llena', 'llena', Planos::ocupacion(['ocupados' => 10, 'puestos' => 10]));
check('Mesa que se pasa', 'pasa', Planos::ocupacion(['ocupados' => 11, 'puestos' => 10]));
$D = $pl->datos($nid, $p1);
$c = $inv->contadores($nid, $e1);
check('Resumen: puestos del plano', array_sum(array_map(fn($e) => Planos::esMesa($e['tipo']) ? $e['puestos'] : 0, $D['elementos'])), $D['resumen']['puestos']);
check('Resumen: confirmados y esperados como en invitados', [$c['confirmados'], $c['esperados']], [$D['resumen']['confirmados'], $D['resumen']['esperados']]);
check('Resumen: invitados sin mesa', 1, $D['resumen']['sin_mesa']);
check('El SVG colorea las mesas por ocupación', true, str_contains(Planos::svg($D, ['ocupacion' => true]), Planos::COLORES['parcial'][0]) && str_contains(Planos::svg($D, ['ocupacion' => true]), '8/9'));
// Bajar los puestos por debajo de los sentados: se guarda pero avisa
$els = $D['elementos'];
foreach ($els as &$el) if ($el['mesa_id'] === $mp) $el['puestos'] = 6;
unset($el);
$r = $pl->guardar($nid, $p1, $els, $D['plano']['version']);
check('Bajar puestos debajo de los sentados avisa', true, (bool) array_filter($r['avisos'], fn($a) => str_contains($a, 'Mesa Primos tiene 8 personas sentadas y solo 6 puestos')));
check('Y ahora se pasa', 'pasa', Planos::ocupacion($elDe($mp)));
// Quitar del plano una mesa con invitados: la mesa se queda (sin ubicar); una vacía se borra
$vacia = array_values(array_filter($r['elementos'], fn($e) => $e['etq'] === 'Robada'))[0];
$sinDos = array_values(array_filter($r['elementos'], fn($e) => !in_array($e['mesa_id'], [$mp, $vacia['mesa_id']], true)));
$r = $pl->guardar($nid, $p1, $sinDos, $r['plano']['version']);
check('Quitar una mesa con invitados: la mesa sigue en invitados', 1, $cuenta("SELECT count(*) FROM mesas WHERE id = $mp"));
check('…sus invitados siguen sentados', 4, $cuenta("SELECT count(*) FROM invitados WHERE mesa_id = $mp"));
check('…y queda en "sin ubicar"', true, in_array($mp, array_column($r['sin_ubicar'], 'mesa_id'), true));
check('…con un aviso claro', true, (bool) array_filter($r['avisos'], fn($a) => str_contains($a, 'Mesa Primos tiene invitados sentados')));
check('Quitar una mesa vacía la borra de invitados', 0, $cuenta("SELECT count(*) FROM mesas WHERE id = {$vacia['mesa_id']}"));
// Borrar la mesa en invitados la quita del plano
$r = $pl->guardar($nid, $p1, array_merge($r['elementos'], [$mesa('redonda', 18, 2, 'Mesa Primos', 9, $mp)]), $r['plano']['version']);
check('Volver a ponerla en el plano', $mp, end($r['elementos'])['mesa_id']);
$inv->borrarMesa($nid, $mp);
check('Borrar la mesa en invitados la quita del plano', 0, $cuenta("SELECT count(*) FROM plano_elementos WHERE mesa_id = $mp"));
check('…y sus invitados quedan sin mesa', 0, $cuenta("SELECT count(*) FROM invitados WHERE mesa_id = $mp"));
// Deshacer en el editor puede mandar una mesa que ya no existe: se crea otra
$r = $pl->guardar($nid, $p1, array_merge($pl->elementos($p1), [$mesa('redonda', 18, 2, 'Mesa Primos', 9, $mp)]));
check('Mesa que ya no existe: se crea de nuevo con su nombre', 'Mesa Primos', $db->query('SELECT nombre FROM mesas WHERE id = ' . (int) end($r['elementos'])['mesa_id'])->fetchColumn());
// Cambiar una mesa a otra cosa (pista): suelta la mesa
$els = $pl->elementos($p1);
$cuantasMesas = $cuenta("SELECT count(*) FROM mesas WHERE evento_id = $e1");
$els[array_key_last($els)] = array_merge(end($els), ['tipo' => 'dj', 'an' => 2, 'al' => 1.5]);
$r = $pl->guardar($nid, $p1, $els);
check('Una mesa que pasa a ser otra cosa ya no lleva mesa', null, end($r['elementos'])['mesa_id']);
check('…y su mesa vacía se borra', $cuantasMesas - 1, $cuenta("SELECT count(*) FROM mesas WHERE evento_id = $e1"));
// Borrar el plano del evento: las mesas y los invitados se quedan
$mesasAntes = $cuenta("SELECT count(*) FROM mesas WHERE evento_id = $e1");
$pl->borrar($nid, $p1, $dueno);
check('Borrar el plano del evento no borra las mesas', $mesasAntes, $cuenta("SELECT count(*) FROM mesas WHERE evento_id = $e1"));
check('El evento ya no tiene plano', null, $pl->delEvento($nid, $e1));
$p1 = $pl->crearParaEvento($nid, $e1, null, '18', '22', $dueno);
$D = $pl->datos($nid, $p1);
check('Plano desde cero: vacío y con su tamaño', [0, 18.0, 22.0], [count($D['elementos']), $D['plano']['ancho'], $D['plano']['largo']]);
check('Plano desde cero: todas las mesas quedan sin ubicar', $mesasAntes, count($D['sin_ubicar']));
$pl->editarDatos($nid, $p1, 'Plano de Ana', '20', '25');
check('El plano del evento también cambia de medidas', 25.0, $pl->plano($nid, $p1)['largo']);

titulo('7. Un negocio no toca el plano de otro');
check_error('No lo abre', 'No se encontró el plano', fn() => $pl->plano($nid2, $p1));
check_error('No lee sus datos', 'No se encontró el plano', fn() => $pl->datos($nid2, $p1));
check_error('No lo guarda', 'No se encontró el plano', fn() => $pl->guardar($nid2, $p1, []));
check_error('No guarda una plantilla ajena', 'No se encontró el plano', fn() => $pl->guardar($nid2, $t1, []));
check_error('No cambia sus medidas', 'No se encontró el plano', fn() => $pl->editarDatos($nid2, $t1, 'X', 10, 10));
check_error('No la copia', 'No se encontró el plano', fn() => $pl->duplicarPlantilla($nid2, $t1));
check_error('No la borra', 'No se encontró el plano', fn() => $pl->borrar($nid2, $t1));
check_error('No busca el plano de un evento ajeno', 'No se encontró el evento', fn() => $pl->delEvento($nid2, $e1));
check_error('No arma el plano de un evento ajeno', 'No se encontró el evento', fn() => $pl->crearParaEvento($nid2, $e1, null, 10, 10));
check('Nada cambió', count($d1['elementos']), count($pl->elementos($t1)));

titulo('8. PDF del plano');
$neg = $db->query("SELECT * FROM negocios WHERE id = $nid")->fetch();
$pl->guardar($nid, $p1, Planos::ejemplo('boda', 20, 25, 120));
$pdfEv = Pdf::comoTexto(PdfPlano::plano($neg, $pl->datos($nid, $p1), $ev->obtener($nid, $e1)));
check('PDF del evento', '%PDF', substr($pdfEv, 0, 4));
check('PDF del evento: dibujo + lista de mesas (2 páginas o más)', true, preg_match_all('#/Type\s*/Page[^s]#', $pdfEv) >= 2);
$pdfT = Pdf::comoTexto(PdfPlano::plano($neg, $pl->datos($nid, $t2)));
check('PDF de una plantilla (una página)', ['%PDF', 1], [substr($pdfT, 0, 4), preg_match_all('#/Type\s*/Page[^s]#', $pdfT)]);
check('Un salón ancho va en hoja acostada', 1, preg_match('#/MediaBox \[0[.0]* 0[.0]* 841\.89\d* 595\.27#', Pdf::comoTexto(PdfPlano::plano($neg, $pl->datos($nid, $t3)))));
check('Un salón largo va en hoja parada', 1, preg_match('#/MediaBox \[0[.0]* 0[.0]* 595\.27\d* 841\.89#', $pdfEv));

// =====================================================================
// Metas de ventas
// =====================================================================
$met = new Metas($db);
$cot = new Cotizaciones($db);
$us = new Usuarios($db);
$carla = $us->crear($nid, 'Carla Vendedora', 'carla80@prueba.ec', '', 'vendedor')['id'];
$diego = $us->crear($nid, 'Diego Vendedor', 'diego80@prueba.ec', '', 'vendedor')['id'];
$ajeno = $us->crear($nid2, 'Vendedor Ajeno', 'ajeno80@prueba.ec', '', 'vendedor')['id'];
$fechaEv = new DateTimeImmutable('2027-01-02');
// Evento vendido (cotización aceptada) con su fecha de venta: Salón $1.500 + IVA = $1.725 ; Jardín $1.200 + IVA = $1.380
$vender = function (string $nombre, array $esp, ?int $vendedor, string $vendidoEl) use ($db, $ev, $cot, $nid, $dueno, $hoy, &$fechaEv): int {
    $fechaEv = $fechaEv->modify('+7 days');
    $id = $ev->crear($nid, ['cliente' => ['nombre' => $nombre, 'telefono' => '09' . random_int(10000000, 99999999)], 'tipo' => 'boda', 'fecha' => $fechaEv->format('Y-m-d'),
        'hora_inicio' => '18:00', 'hora_fin' => '02:00', 'invitados' => 100, 'espacios' => $esp, 'vendedor_id' => $vendedor], (int) $dueno['id'], $hoy);
    $c = $cot->crear($nid, $id, null, null, $hoy);
    $cot->aceptar($nid, $c, $nombre, '', $dueno, $hoy);
    $ev->cambiarEstado($nid, $id, 'separado', [], $dueno, $hoy);
    $db->prepare("UPDATE registro_cambios SET creado_en = ? WHERE evento_id = ? AND accion LIKE '%a Separado%'")->execute([$vendidoEl . ' 10:00', $id]);
    $db->prepare('UPDATE eventos SET creado_en = ? WHERE id = ?')->execute([$vendidoEl . ' 09:00', $id]);
    return $id;
};
// Año anterior: octubre 2025 = 1725 (Carla) + 1380 ; septiembre 2025 = 1380
$vender('Ana 2025', [$salon], $carla, '2025-10-05');
$vender('Bruno 2025', [$jardin], null, '2025-10-20');
$vender('Carmen 2025', [$jardin], $carla, '2025-09-15');
// Este año: octubre 2026 = 1725 + 1725 (Carla) + 1380 (Diego) ; septiembre 2026 = 1725 (Carla)
$vender('Diana 2026', [$salon], $carla, '2026-10-02');
$vender('Elena 2026', [$salon], $carla, '2026-10-09');
$vender('Fabián 2026', [$jardin], $diego, '2026-10-11');
$vender('Gina 2026', [$salon], $carla, '2026-09-25');
// Una consulta no vendida y un evento de otro negocio no cuentan
$ev->crear($nid, ['cliente' => ['nombre' => 'Hugo Consulta', 'telefono' => '0993334455'], 'tipo' => 'boda', 'fecha' => '2027-06-05', 'invitados' => 90, 'espacios' => [$salon]], null, $hoy);

titulo('9. Guardar metas');
check('Sin metas: no hay avance', null, $met->progreso($nid, null, $hoy));
check_error('Mes mal escrito', 'Revisa el mes', fn() => $met->guardar($nid, null, '2026-13', 1000));
check_error('Monto con letras', 'en dólares', fn() => $met->guardar($nid, null, null, 'mucho'));
check_error('Vendedor de otro negocio', 'no es de tu negocio', fn() => $met->guardar($nid, $ajeno, null, 1000));
check_error('El coordinador no lleva meta', 'no es de tu negocio', fn() => $met->guardar($nid, (int) $db->query("SELECT id FROM usuarios WHERE negocio_id = $nid AND rol = 'dueno'")->fetchColumn() + 100000, null, 1000));
check_error('Solo eventos, sin dólares', 'Pon también la meta en dólares', fn() => $met->guardar($nid, null, null, '', '5'));
$met->guardar($nid, null, null, '6.000', '3');
check('Meta de siempre (6.000 con punto de miles)', ['monto' => 6000.0, 'eventos' => 3, 'del_mes' => false], $met->metaDe($nid, null, '2026-07'));
$met->guardar($nid, null, '2026-10', '4.000,00', '4');
check('Un mes distinto reemplaza a la de siempre', ['monto' => 4000.0, 'eventos' => 4, 'del_mes' => true], $met->metaDe($nid, null, '2026-10'));
check('Los demás meses siguen con la de siempre', 6000.0, $met->metaDe($nid, null, '2026-11')['monto']);
$met->guardar($nid, null, '2026-10', '4000', '5');
check('Guardar otra vez el mismo mes lo cambia (no duplica)', [5, 1], [$met->metaDe($nid, null, '2026-10')['eventos'], $cuenta("SELECT count(*) FROM metas_ventas WHERE negocio_id = $nid AND mes = '2026-10' AND vendedor_id IS NULL")]);
$met->guardar($nid, null, null, '6000', '3');
check('La de siempre tampoco se duplica', 1, $cuenta("SELECT count(*) FROM metas_ventas WHERE negocio_id = $nid AND mes IS NULL AND vendedor_id IS NULL"));
$met->guardar($nid, null, '2026-12', '9000');
$met->guardar($nid, null, '2026-12', '');
check('Vaciar un mes lo vuelve a la de siempre', 6000.0, $met->metaDe($nid, null, '2026-12')['monto']);
$met->guardar($nid, $carla, null, '3000');
$met->guardar($nid, $carla, '2026-10', '3500', '2');
check('Meta de un vendedor (con su mes distinto)', [3000.0, 3500.0], [$met->metaDe($nid, $carla, '2026-09')['monto'], $met->metaDe($nid, $carla, '2026-10')['monto']]);
check('La meta del vendedor no cambia la del local', 4000.0, $met->metaDe($nid, null, '2026-10')['monto']);
check('Otro vendedor sin meta', null, $met->metaDe($nid, $diego, '2026-10'));
check('Otro negocio no ve estas metas', null, $met->metaDe($nid2, null, '2026-10'));
check('Lista de quienes venden: dueño y vendedores', 3, count($met->vendedores($nid)));

titulo('10. Avance del mes en el Inicio');
$p = $met->progreso($nid, null, $hoy);
$ventasOct = (new Reportes($db))->ventas($nid, '2026-10-01', '2026-10-31');
check('Vendido = lo mismo que Reportes → Ventas', [$ventasOct['valor'], $ventasOct['cantidad']], [$p['vendido'], $p['cantidad']]);
check('Vendido en octubre 2026', 4830.0, $p['vendido']);
check('Meta de octubre', 4000.0, $p['meta']);
check('Cumplimiento 120 %', 120, $p['pct']);
check('Ya no falta nada', 0.0, $p['falta']);
check('Eventos: 3 de 5', [3, 5, 60, 2], [$p['cantidad'], $p['meta_eventos'], $p['pct_eventos'], $p['faltan_eventos']]);
check('Días que faltan (13 de octubre)', 18, $p['dias_faltan']);
check('Cuánto del mes ya pasó', 42, $p['pct_mes']);
check('Octubre del año pasado', 3105.0, $p['anio_pasado']);
check('Cambio contra el año pasado: +56 %', 56, $p['variacion']);
$pn = $met->progreso($nid, null, new DateTimeImmutable('2026-11-30'));
check('Noviembre: meta de siempre, nada vendido, último día', [6000.0, 0.0, 0, 6000.0, 0], [$pn['meta'], $pn['vendido'], $pn['pct'], $pn['falta'], $pn['dias_faltan']]);
check('Sin ventas el año anterior: sin cambio', null, $pn['variacion']);
$pc = $met->progreso($nid, $carla, $hoy);
check('Avance de Carla: solo lo que ella vendió', [3450.0, 2, 3500.0, 98, 50.0], [$pc['vendido'], $pc['cantidad'], $pc['meta'], $pc['pct'], $pc['falta']]);
check('Carla el año pasado en octubre', 1725.0, $pc['anio_pasado']);
check('Diego sin meta: no hay avance', null, $met->progreso($nid, $diego, $hoy));
check('El porcentaje no redondea para arriba (99,99 % no es 100 %)', 99, (function () use ($met, $nid, $hoy, $db) {
    $met->guardar($nid, null, '2026-10', '4830,01');
    $r = $met->progreso($nid, null, $hoy)['pct'];
    $met->guardar($nid, null, '2026-10', '4000', '5');
    return $r;
})());

titulo('11. Reporte de metas con el año anterior');
$r = $met->reporte($nid, '2026-09-01', '2026-10-31', null, $hoy);
check('Meses del periodo', ['2026-09', '2026-10'], array_keys($r['meses']));
$sep = $r['meses']['2026-09'];
$oct = $r['meses']['2026-10'];
check('Septiembre: meta de siempre, vendido, cumplimiento', [6000.0, 1725.0, 1, 28], [$sep['meta'], $sep['vendido'], $sep['cantidad'], $sep['pct']]);
check('Septiembre del año anterior y cambio', [1380.0, 25], [$sep['anterior'], $sep['variacion']]);
check('Octubre: meta del mes', [4000.0, 4830.0, 120, 3105.0, 2, 56], [$oct['meta'], $oct['vendido'], $oct['pct'], $oct['anterior'], $oct['anterior_cantidad'], $oct['variacion']]);
check('Totales del periodo', [10000.0, 6555.0, 65, 4485.0, 46], [$r['total']['meta'], $r['total']['vendido'], $r['total']['pct'], $r['total']['anterior'], $r['total']['variacion']]);
$porMesReportes = (new Reportes($db))->ventas($nid, '2026-09-01', '2026-10-31');
check('Mismo vendido que Reportes en el periodo', $porMesReportes['valor'], $r['total']['vendido']);
$vend = array_column($r['vendedores'], null, 'nombre');
check('Por vendedor: Carla con su meta (3000 + 3500)', [6500.0, 5175.0, 3, 79], [$vend['Carla Vendedora']['meta'], $vend['Carla Vendedora']['vendido'], $vend['Carla Vendedora']['cantidad'], $vend['Carla Vendedora']['pct']]);
check('Carla: año anterior y cambio', [3105.0, 67], [$vend['Carla Vendedora']['anterior'], $vend['Carla Vendedora']['variacion']]);
check('Diego: sin meta pero con ventas', [false, 1380.0, null], [$vend['Diego Vendedor']['con_meta'], $vend['Diego Vendedor']['vendido'], $vend['Diego Vendedor']['pct']]);
check('El dueño aparece con lo que vendió él (año anterior)', 1380.0, $vend['Dimitry Prueba']['anterior'] ?? null);
check('La suma por vendedor da el total', $r['total']['vendido'], round(array_sum(array_column($r['vendedores'], 'vendido')), 2));
check('Ordenado por lo vendido', 'Carla Vendedora', $r['vendedores'][0]['nombre']);
check('Detalle mes a mes por vendedor', [3000.0, 1725.0, 57], [$vend['Carla Vendedora']['meses']['2026-09']['meta'], $vend['Carla Vendedora']['meses']['2026-09']['vendido'], $vend['Carla Vendedora']['meses']['2026-09']['pct']]);
$solo = $met->reporte($nid, '2026-09-01', '2026-10-31', $carla, $hoy);
check('El vendedor ve solo su fila', ['Carla Vendedora'], array_column($solo['vendedores'], 'nombre'));
$anio = $met->reporte($nid, '2026-01-01', '2026-12-31', null, $hoy);
check('Todo el año: 12 meses', 12, count($anio['meses']));
check('Diciembre usa la de siempre', 6000.0, $anio['meses']['2026-12']['meta']);
check('Los meses que vienen no tienen % todavía', [true, null, null], [$anio['meses']['2026-12']['futuro'], $anio['meses']['2026-12']['pct'], $anio['meses']['2026-11']['variacion']]);
check('El total del año cuenta la meta solo hasta este mes (9 × 6000 + 4000)', [58000.0, 2], [$anio['total']['meta'], $anio['total']['meses_futuros']]);
check('Mes con ventas el año pasado y nada este año: -100 %', -100, (function () use ($met, $nid) {
    return $met->reporte($nid, '2027-10-01', '2027-10-31', null, new DateTimeImmutable('2027-11-01'))['meses']['2027-10']['variacion'];
})());
check('Otro negocio: sin metas ni ventas', [false, 0.0], (function () use ($met, $nid2, $hoy) { $x = $met->reporte($nid2, '2026-09-01', '2026-10-31', null, $hoy); return [$x['hay_metas'], $x['total']['vendido']]; })());
check('Periodo de un mes = un solo mes (no los 6 anteriores)', 1, count($met->reporte($nid, '2026-10-01', '2026-10-31', null, $hoy)['meses']));
check('Máximo 36 meses', 36, count(Metas::meses('2020-01-01', '2029-12-31')));

titulo('12. Metas para Excel');
[$enc, $filas] = $met->exportar($nid, '2026-09-01', '2026-10-31', null, $hoy);
check('Encabezados', ['Quién', 'Mes', 'Meta'], array_slice($enc, 0, 3));
check('Filas: 2 meses del local + 2 meses por cada persona', 2 + 2 * count($r['vendedores']), count($filas));
check('Fila de octubre del local', ['Todo el local', 'Octubre 2026', '4000,00', 5, '4830,00', 3, '120 %', '3105,00', 2, '56 %'], $filas[1]);
$csv = Reportes::csv($enc, $filas);
check('CSV con BOM y punto y coma', true, str_starts_with($csv, "\xEF\xBB\xBF") && str_contains($csv, '"Todo el local";"Octubre 2026";4000,00;5;4830,00'));
[, $filasCarla] = $met->exportar($nid, '2026-09-01', '2026-10-31', $carla, $hoy);
check('El vendedor descarga solo lo suyo', ['Carla Vendedora'], array_values(array_unique(array_column($filasCarla, 0))));
