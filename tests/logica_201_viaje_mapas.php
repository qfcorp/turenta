<?php
/**
 * TuRenta (migración 201): tiempo de viaje con mapa (Google Routes / OSRM / aproximado, como TuGuardería)
 * y montaje/desmontaje por equipo. Los mapas se simulan: nunca se llama a Google, OSRM, el Censo ni OpenStreetMap.
 */
use TuRenta\{Reservas, Eventos, Mapas, Configuracion, Ventas, Pagos};

$db = base_limpia();
$hoy = new DateTimeImmutable('today');
$fecha = $hoy->modify('+20 days')->format('Y-m-d');
[$nid, $dueno] = negocio_prueba($db, 'quinta', 'Zombie Ice');
$db->prepare("UPDATE negocios SET slug = 'zombieice', reserva_en_linea = true, idioma_publico = 'en', horas_montaje = 1, horas_desmontaje = 1,
                     reserva_pct = 30, reserva_hora_desde = '10:00', reserva_hora_hasta = '17:00', reserva_paso_min = 60, reserva_dias_min = 2,
                     telefono = '(754) 777-0423' WHERE id = ?")->execute([$nid]);
$esp = function (string $nombre, int $orden) use ($db, $nid): int {
    $st = $db->prepare('INSERT INTO espacios (negocio_id, nombre, orden) VALUES (?,?,?) RETURNING id');
    $st->execute([$nid, $nombre, $orden]);
    return (int) $st->fetchColumn();
};
$grande = $esp('ZombieTruck grande', 1);
$st = $db->prepare("INSERT INTO productos (negocio_id, categoria, nombre, unidad, precio, con_iva) VALUES (?, 'otros', 'Signature Experience (1 hour)', 'evento', 495, false) RETURNING id");
$st->execute([$nid]);
$prod = (int) $st->fetchColumn();
$st = $db->prepare("INSERT INTO paquetes (negocio_id, nombre, slug, publico, duracion_min, maximo_invitados, orden) VALUES (?, 'Signature Experience', 'signature-experience', true, 60, 50, 1) RETURNING id");
$st->execute([$nid]);
$paq = (int) $st->fetchColumn();
$db->prepare('INSERT INTO paquete_items (paquete_id, producto_id, cantidad) VALUES (?,?,1)')->execute([$paq, $prod]);
$db->prepare('INSERT INTO paquete_espacios (paquete_id, espacio_id) VALUES (?,?)')->execute([$paq, $grande]);
$ev = new Eventos($db);

titulo('1. Montaje y desmontaje por equipo');
check('Sin minutos propios: los de Reglas (60 y 60)', [60, 60], $ev->minutosMontaje($nid, $grande));
check('Periodo de siempre (sin viaje): 1 h antes y 1 h después', ["$fecha 12:00:00", "$fecha 15:00:00"], $ev->periodo($nid, $fecha, '13:00', '14:00', 0, $grande));
$ventas = new Ventas($db);
$ventas->guardarEspacio($nid, ['nombre' => 'ZombieTruck grande', 'montaje_min' => '45', 'desmontaje_min' => '30', 'orden' => '1'], $grande);
check('Camión grande: 45 de montaje y 30 de desmontaje', [45, 30], $ev->minutosMontaje($nid, $grande));
$chico = $ventas->guardarEspacio($nid, ['nombre' => 'Espuma portátil', 'montaje_min' => '15', 'desmontaje_min' => '', 'orden' => '2']);
check('Equipo chico: 15 propios y el desmontaje de Reglas', [15, 60], $ev->minutosMontaje($nid, $chico));
check('Periodo del camión grande con 25 min de viaje', ["$fecha 11:50:00", "$fecha 14:55:00"], $ev->periodo($nid, $fecha, '13:00', '14:00', 25, $grande));
check_error('Minutos de montaje inválidos', 'minutos de montaje', fn() => $ventas->guardarEspacio($nid, ['nombre' => 'ZombieTruck grande', 'montaje_min' => '5000'], $grande));
check('Sin el negocio, el de otro negocio no se mezcla', [60, 60], $ev->minutosMontaje($nid, 999999));

titulo('2. Mapa simulado: Google, gratis y aproximado');
$llamadas = [];
$respuestas = [];   // host => fn(url, cuerpo) => ?array
$http = function (string $url, ?array $cuerpo, array $cab) use (&$llamadas, &$respuestas) {
    $host = (string) parse_url($url, PHP_URL_HOST);
    $llamadas[] = $host;
    return isset($respuestas[$host]) ? ($respuestas[$host])($url, $cuerpo, $cab) : null;
};
$mapas = new Mapas($db, $http);
$base = ['id' => $nid, 'idioma_publico' => 'en', 'base_lat' => '26.100000', 'base_lng' => '-80.400000', 'google_maps_key' => 'AIzaPruebaClaveDeGoogle1234567890abcd'];
$respuestas['maps.googleapis.com'] = fn($u) => str_contains($u, 'Main') ? ['status' => 'OK', 'results' => [['geometry' => ['location' => ['lat' => 26.15, 'lng' => -80.30]]]]] : ['status' => 'ZERO_RESULTS', 'results' => []];
$respuestas['routes.googleapis.com'] = function ($u, $c, $cab) {
    return in_array('X-Goog-FieldMask: routes.distanceMeters,routes.duration', $cab, true) && $c['routingPreference'] === 'TRAFFIC_AWARE'
        ? ['routes' => [['distanceMeters' => 18400, 'duration' => '1290s']]] : null;
};
$p = $mapas->punto($base, '1200 Main St, Weston, FL', '33326');
check('Google encuentra la dirección', [26.15, -80.30, 'Google'], [$p['lat'], $p['lng'], $p['metodo']]);
$v = $mapas->viaje($base, $p['lat'], $p['lng'], $fecha);
check('Google Routes con tráfico: 1290 s = 22 min', [22, 18.4, 'Google (tráfico)'], [$v['min'], $v['km'], $v['metodo']]);
$n = count($llamadas);
$mapas->punto($base, '1200  main st, weston, fl', '33326');
$mapas->viaje($base, $p['lat'], $p['lng'], $fecha);
check('La segunda vez sale de lo guardado (no se paga otra vez a Google)', $n, count($llamadas));

$sinClave = ['google_maps_key' => null] + $base;
$respuestas['geocoding.geo.census.gov'] = fn($u) => ['result' => ['addressMatches' => [['coordinates' => ['x' => -80.25, 'y' => 26.20]]]]];
$respuestas['router.project-osrm.org'] = fn($u) => ['code' => 'Ok', 'routes' => [['duration' => 1500.0, 'distance' => 21000.0]]];
$p2 = $mapas->punto($sinClave, '500 Oak Ave, Davie, FL', '33314');
check('Sin clave en EE.UU.: geocodificador del Censo', [26.20, -80.25, 'Censo EE.UU.'], [$p2['lat'], $p2['lng'], $p2['metodo']]);
$v2 = $mapas->viaje($sinClave, $p2['lat'], $p2['lng'], $fecha);
check('Sin clave: calles reales con OSRM (25 min)', [25, 'Calles reales'], [$v2['min'], $v2['metodo']]);
check('Sin clave nunca se llama a Google', false, in_array('routes.googleapis.com', array_slice($llamadas, $n), true));

unset($respuestas['router.project-osrm.org'], $respuestas['routes.googleapis.com']);
$v3 = $mapas->viaje($base, 26.30, -80.20, $fecha);
check('Si todo falla: aproximado (nunca sin número)', 'Aproximado', $v3['metodo']);
check('Aproximado: línea recta × 1,35 a 40 km/h', Mapas::viajeAproximado(26.10, -80.40, 26.30, -80.20)['min'], $v3['min']);
$n = count($llamadas);
$mapas->viaje($base, 26.30, -80.20, $fecha);
check('El aproximado no se guarda (se vuelve a intentar el mapa)', true, count($llamadas) > $n);
unset($respuestas['geocoding.geo.census.gov']);
check('Dirección que no existe en ningún mapa', null, $mapas->punto($sinClave, 'zzzz qqqq', '00000'));
check('Sin base no hay viaje', null, $mapas->viaje(['base_lat' => null, 'base_lng' => null] + $base, 26.1, -80.1));
check('Clave de Google válida', true, Mapas::claveValida('AIzaPruebaClaveDeGoogle1234567890abcd'));
check('Clave de Google cortada', false, Mapas::claveValida('AIza123'));

titulo('3. Configuración del viaje');
$cfg = new Configuracion($db);
$mapasBase = new Mapas($db, function (string $url) {
    if (str_contains($url, 'geocode') || str_contains($url, 'census') || str_contains($url, 'nominatim')) {
        return str_contains($url, 'Commerce') ? ['status' => 'OK', 'results' => [['geometry' => ['location' => ['lat' => 26.1, 'lng' => -80.4]]]]] : null;
    }
    return null;
});
check_error('Encender sin base', 'dirección de tu base', fn() => $cfg->guardarViaje($nid, ['viaje_mapa' => '1'], $mapasBase));
check_error('Base que no se encuentra', 'No encontramos la dirección', fn() => $cfg->guardarViaje($nid, ['viaje_mapa' => '1', 'base_direccion' => 'Calle que no existe 123', 'google_maps_key' => 'AIzaPruebaClaveDeGoogle1234567890abcd'], $mapasBase));
check_error('Clave de Google cortada', 'no parece completa', fn() => $cfg->guardarViaje($nid, ['google_maps_key' => 'AIza12'], $mapasBase));
check_error('Solo latitud sin longitud', 'las dos', fn() => $cfg->guardarViaje($nid, ['base_lat' => '26.1'], $mapasBase));
check_error('Margen fuera de rango', 'de 0 a 120', fn() => $cfg->guardarViaje($nid, ['viaje_extra_min' => '500'], $mapasBase));
$cfg->guardarViaje($nid, ['viaje_mapa' => '1', 'base_direccion' => '2000 N Commerce Pkwy, Weston, FL 33326', 'google_maps_key' => 'AIzaPruebaClaveDeGoogle1234567890abcd',
                          'viaje_extra_min' => '10', 'viaje_max_min' => '60'], $mapasBase);
$fila = $db->query("SELECT viaje_mapa, base_lat, base_lng, google_maps_key, viaje_extra_min, viaje_max_min FROM negocios WHERE id = $nid")->fetch();
check('Guarda la base buscada en el mapa', [true, 26.1, -80.4], [$fila['viaje_mapa'], (float) $fila['base_lat'], (float) $fila['base_lng']]);
check('Guarda margen y máximo', [10, 60], [(int) $fila['viaje_extra_min'], (int) $fila['viaje_max_min']]);
$cfg->guardarViaje($nid, ['viaje_mapa' => '1', 'base_direccion' => '2000 N Commerce Pkwy, Weston, FL 33326', 'google_maps_key' => '', 'viaje_extra_min' => '10', 'viaje_max_min' => '60'], $mapasBase);
check('Clave vacía conserva la guardada', 'AIzaPruebaClaveDeGoogle1234567890abcd', $db->query("SELECT google_maps_key FROM negocios WHERE id = $nid")->fetchColumn());
$cfg->guardarViaje($nid, ['viaje_mapa' => '1', 'base_direccion' => 'Mi bodega', 'base_lat' => '26,100000', 'base_lng' => '-80.400000', 'viaje_extra_min' => '10', 'viaje_max_min' => '60'], $mapasBase);
check('Latitud y longitud escritas a mano (con coma) se usan tal cual', [26.1, -80.4], array_map('floatval', $db->query("SELECT base_lat, base_lng FROM negocios WHERE id = $nid")->fetch(PDO::FETCH_NUM)));

titulo('4. Reserva en línea con viaje');
// Dos direcciones: cerca (20 min por Google) y lejos (75 min). Margen 10 → 30 y 85 (máximo 60).
$mapaReserva = new Mapas($db, function (string $url, ?array $c) {
    if (str_contains($url, 'geocode/json')) {
        if (str_contains($url, 'Cerca')) return ['status' => 'OK', 'results' => [['geometry' => ['location' => ['lat' => 26.11, 'lng' => -80.39]]]]];
        if (str_contains($url, 'Lejos')) return ['status' => 'OK', 'results' => [['geometry' => ['location' => ['lat' => 26.90, 'lng' => -80.90]]]]];
        return ['status' => 'ZERO_RESULTS'];
    }
    if (str_contains($url, 'computeRoutes')) {
        $lat = $c['destination']['location']['latLng']['latitude'];
        return ['routes' => [['distanceMeters' => 10000, 'duration' => ($lat < 26.5 ? 1200 : 4500) . 's']]];
    }
    return null;
});
$res = new Reservas($db, $mapaReserva);
$neg = $res->negocio('zombieice');
check('El negocio usa el mapa', true, $res->usaMapa($neg));
$p = $res->paquete($nid, 'signature-experience');
check_error('Dirección lejos (85 min > 60)', 'outside our online booking area (about 85 minutes away). Please call or text us at (754) 777-0423', fn() => $res->viaje($neg, '9 Lejos Rd, Naples', '34102', $fecha));
check_error('Dirección que no está en el mapa', 'could not find that address', fn() => $res->viaje($neg, '1 Nowhere Lane', '33326', $fecha));
$vc = $res->viaje($neg, '10 Cerca St, Weston', '33326', $fecha);
check('Cerca: 20 min + 10 de margen = 30 de ida', 30, $vc['min']);
check('Sin viaje: las 8 horas de siempre', 8, count($res->horarios($neg, $p, $fecha, $hoy, 0)));

$datos = ['paquete' => 'signature-experience', 'fecha' => $fecha, 'hora' => '13:00', 'nombre' => 'Laura Gómez', 'telefono' => '(954) 555-0123',
          'email' => 'laura@example.com', 'direccion' => '10 Cerca St, Weston', 'zip' => '33326', 'ninos' => 25, 'acepto' => '1'];
check_error('Reserva lejos no se crea', 'outside our online booking area', fn() => $res->crear($neg, ['direccion' => '9 Lejos Rd, Naples', 'zip' => '34102'] + $datos, '1.1.1.1', $hoy));
$e1 = $res->crear($neg, $datos, '1.1.1.1', $hoy);
$fila = $db->query("SELECT viaje_min, viaje_metodo, lat, lng FROM eventos WHERE id = $e1")->fetch();
check('Guarda el viaje en el evento', [30, 'Google (tráfico)', 26.11, -80.39], [(int) $fila['viaje_min'], $fila['viaje_metodo'], (float) $fila['lat'], (float) $fila['lng']]);
$per = $db->query("SELECT lower(periodo)::text, upper(periodo)::text FROM evento_espacios WHERE evento_id = $e1")->fetch(PDO::FETCH_NUM);
// Camión grande: 13:00 − 30 viaje − 45 montaje = 11:45; 14:00 + 30 desmontaje + 30 viaje = 15:00
check('El camión queda ocupado con viaje + montaje del equipo', ["$fecha 11:45:00", "$fecha 15:00:00"], $per);
check('Queda en el registro del evento', true, (bool) $db->query("SELECT 1 FROM registro_cambios WHERE evento_id = $e1 AND accion LIKE 'Viaje desde la base: 30 min%'")->fetchColumn());

// Otra fiesta cerca el mismo día: su bloque necesita 30 + 45 antes → la de 15:00 empezaría a las 13:45 (choca); la de 16:00 a las 14:45 (choca: hasta 15:00); 17:00 → 15:45 (libre)
$horas = array_column($res->horarios($neg, $p, $fecha, $hoy, $vc['min']), 'hora');
check('Ocupado por la primera fiesta y su viaje: 10:00 a 16:00 no se ofrecen', ['17:00'], $horas);
check('Sin contar viaje habrían salido más horas', true, count($res->horarios($neg, $p, $fecha, $hoy, 0)) > count($horas));

titulo('5. El viaje se conserva al separar y al editar');
(new Pagos($db))->registrar($nid, $e1, ['monto' => 148.5, 'metodo' => 'efectivo'], $dueno);
check('Separado con el depósito', 'separado', $ev->obtener($nid, $e1)['estado']);
$ev->actualizar($nid, $e1, ['tipo' => 'cumpleanos', 'fecha' => $fecha, 'hora_inicio' => '13:30', 'hora_fin' => '14:30', 'invitados' => 25]);
$per = $db->query("SELECT lower(periodo)::text, upper(periodo)::text FROM evento_espacios WHERE evento_id = $e1")->fetch(PDO::FETCH_NUM);
check('Al mover la hora desde el panel el viaje sigue contando', ["$fecha 12:15:00", "$fecha 15:30:00"], $per);

titulo('6. Cambiar el montaje de un equipo vuelve a calcular sus eventos');
$ventas->guardarEspacio($nid, ['nombre' => 'ZombieTruck grande', 'montaje_min' => '60', 'desmontaje_min' => '30', 'orden' => '1'], $grande);
$per = $db->query("SELECT lower(periodo)::text FROM evento_espacios WHERE evento_id = $e1")->fetchColumn();
check('Montaje 60: empieza a ocupar a las 12:00', "$fecha 12:00:00", $per);
// Segundo evento separado a las 17:00 (bloque 15:30 → 18:30 con 0 viaje y 60/30... ) y luego un montaje enorme que haría chocar
$e2 = $ev->crear($nid, ['cliente' => ['nombre' => 'Pedro Paz', 'telefono' => '0991234567'], 'tipo' => 'cumpleanos', 'fecha' => $fecha,
                        'hora_inicio' => '17:00', 'hora_fin' => '18:00', 'invitados' => 20, 'espacios' => [$grande]], null, $hoy);
$ev->cambiarEstado($nid, $e2, 'separado', [], $dueno, $hoy);
check_error('Un montaje que haría cruzar dos eventos separados no se guarda', 'se cruzaría', fn() => $ventas->guardarEspacio($nid, ['nombre' => 'ZombieTruck grande', 'montaje_min' => '180', 'desmontaje_min' => '30', 'orden' => '1'], $grande));
check('Y se queda con el montaje de antes', [60, 30], $ev->minutosMontaje($nid, $grande));

titulo('7. Sin viaje con mapa todo sigue como antes');
$db->exec("UPDATE negocios SET viaje_mapa = false WHERE id = $nid");
$neg = $res->negocio('zombieice');
check('No usa el mapa', false, $res->usaMapa($neg));
check('Viaje 0 sin preguntar al mapa', 0, $res->viaje($neg, '9 Lejos Rd, Naples', '34102', $fecha)['min']);
