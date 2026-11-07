<?php
declare(strict_types=1);
/**
 * Local de ejemplo para las capturas del manual: «Quinta Demo Tukanec» con eventos en todas las etapas.
 * Solo en bases cuyo nombre tenga "manual" o "prueba".
 *   TURENTA_DB_NAME=turenta_manual_prueba TURENTA_DB_USER=turenta TURENTA_DB_PASS=prueba php docs/manual/generar/datos_ejemplo.php
 * Entra el dueño con demo@turenta.tukanec.com y la clave EventoDemo2026 (vendedor, coordinador y consulta, la misma clave).
 */
define('RAIZ', dirname(__DIR__, 3));
date_default_timezone_set('America/Guayaquil');
spl_autoload_register(function (string $c): void {
    if (str_starts_with($c, 'TuRenta\\') && is_file($f = RAIZ . '/src/' . substr($c, 9) . '.php')) require $f;
});
if (is_file(RAIZ . '/vendor/autoload.php')) require RAIZ . '/vendor/autoload.php';
$base = (string) getenv('TURENTA_DB_NAME');
if (!str_contains($base, 'manual') && !str_contains($base, 'prueba')) exit("Por seguridad, la base debe llamarse *manual* o *prueba*.\n");

use TuRenta\{Altas, Eventos, Ventas, Cotizaciones, Pagos, Contratos, DiaEvento, Invitados, Planos, Metas, Cuestionarios, Extras,
              Encuestas, Compras, ProveedoresFicha, Incidencias, Planes};

$db = \TuRenta\Db::conectar();
$hoy = new DateTimeImmutable('today');
$d = fn(int $n) => $hoy->modify(($n >= 0 ? '+' : '') . $n . ' days')->format('Y-m-d');
$CLAVE = 'EventoDemo2026';

/** Firma dibujada (PNG en base64), como la que hace el cliente con el dedo. */
$firma = function (): string {
    $img = imagecreatetruecolor(420, 150);
    imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
    $tinta = imagecolorallocate($img, 20, 30, 80);
    imagesetthickness($img, 4);
    $x0 = 30; $y0 = 95;
    for ($t = 0; $t < 60; $t++) {
        $x1 = 30 + $t * 6; $y1 = (int) (90 + 35 * sin($t / 3.2) - $t * 0.3);
        imageline($img, $x0, $y0, $x1, $y1, $tinta); $x0 = $x1; $y0 = $y1;
    }
    ob_start(); imagepng($img); imagedestroy($img);
    return 'data:image/png;base64,' . base64_encode((string) ob_get_clean());
};
$foto = function (array $rgb = [210, 225, 240]): array {
    $ruta = tempnam(sys_get_temp_dir(), 'foto') . '.jpg';
    $img = imagecreatetruecolor(480, 320);
    imagefill($img, 0, 0, imagecolorallocate($img, ...$rgb));
    imagejpeg($img, $ruta); imagedestroy($img);
    return ['name' => 'foto.jpg', 'type' => 'image/jpeg', 'tmp_name' => $ruta, 'error' => UPLOAD_ERR_OK, 'size' => filesize($ruta)];
};

// ------------------------------------------------------------------ Negocio, plan y equipo
$alta = (new Altas($db))->crearNegocioConDueno('Quinta Demo Tukanec', 'Andrea Salazar', '0983662181', 'demo@turenta.tukanec.com', $CLAVE, 'quinta', 'Quito', 'super');
$nid = (int) $alta['negocio_id'];
$db->prepare("UPDATE negocios SET razon_social = 'Quinta Demo Tukanec S.A.', ruc = '1790000000001', direccion = 'Vía a Tumbaco km 5, Cumbayá',
              horas_montaje = 3, horas_desmontaje = 1, garantia_defecto = 300 WHERE id = ?")->execute([$nid]);
(new Planes($db))->suscribir($nid, 'quinta', 'anual', $hoy->modify('-20 days'), 'transferencia');
$dueno = $db->query("SELECT * FROM usuarios WHERE id = {$alta['usuario_id']}")->fetch();
$usr = new \TuRenta\Usuarios($db);
$clave = password_hash($CLAVE, PASSWORD_DEFAULT);
$equipo = [];
foreach ([['Paola Mena', 'paola@turenta.tukanec.com', '0991112233', 'vendedor'], ['Carla Ruiz', 'carla@turenta.tukanec.com', '0992223344', 'coordinador'],
          ['Jorge Contador', 'contador@turenta.tukanec.com', '', 'consulta'], ['Lucía Andrade', 'lucia@turenta.tukanec.com', '0993334455', 'admin']] as [$n, $m, $t, $r]) {
    $x = $usr->crear($nid, $n, $m, $t, $r);
    $db->prepare('UPDATE usuarios SET password_hash = ?, clave_temporal = false WHERE id = ?')->execute([$clave, $x['id']]);
    $equipo[$r] = $db->query("SELECT * FROM usuarios WHERE id = {$x['id']}")->fetch();
}

$ev = new Eventos($db); $ve = new Ventas($db); $cot = new Cotizaciones($db); $pag = new Pagos($db); $con = new Contratos($db);
$dia = new DiaEvento($db); $inv = new Invitados($db); $pl = new Planos($db); $cues = new Cuestionarios($db); $ext = new Extras($db);
$co = new Compras($db); $fi = new ProveedoresFicha($db); $inc = new Incidencias($db);
$esp = fn(string $n) => (int) $db->query("SELECT id FROM espacios WHERE negocio_id = $nid AND nombre = " . $db->quote($n))->fetchColumn();
$paq = fn(string $n) => (int) $db->query("SELECT id FROM paquetes WHERE negocio_id = $nid AND nombre = " . $db->quote($n))->fetchColumn();
$prod = fn(string $n) => (int) $db->query("SELECT id FROM productos WHERE negocio_id = $nid AND nombre = " . $db->quote($n))->fetchColumn();
$salon = $esp('Salón principal'); $jardin = $esp('Jardín');
$db->prepare("INSERT INTO espacios (negocio_id, nombre, descripcion, capacidad_banquete, capacidad_coctel, precio_base, color, orden) VALUES (?,?,?,?,?,?,?,?)")
   ->execute([$nid, 'Terraza', 'Terraza con vista a los valles, para cóctel y ceremonias pequeñas.', 80, 120, 600, '#22457A', 3]);

/** Evento vendido: cotización (paquete o en blanco) enviada y aceptada, con pagos. */
$vender = function (int $id, ?int $paquete, float $pagado, string $hace = '-30 days', bool $aceptar = true) use ($cot, $pag, $nid, $dueno, $hoy): int {
    $c = $cot->crear($nid, $id, $paquete, (int) $dueno['id'], $hoy->modify($hace));
    $cot->enviar($nid, $c, $dueno);
    if (!$aceptar) return $c;
    $cot->aceptar($nid, $c, 'Cliente', '190.152.0.10', null, $hoy->modify($hace));
    if ($pagado > 0) $pag->registrar($nid, $id, ['monto' => $pagado, 'metodo' => 'transferencia', 'fecha' => $hoy->modify($hace)->modify('+2 days')->format('Y-m-d 10:30')], $dueno);
    return $c;
};

// ------------------------------------------------------------------ 1. La boda (evento completo)
$boda = $ev->crear($nid, ['cliente' => ['nombre' => 'Ana Torres', 'telefono' => '0991234567', 'identificacion' => '1712345678', 'email' => 'ana.torres@correo.ec'],
    'nombre' => 'Boda de Ana y Diego', 'tipo' => 'boda', 'fecha' => $d(18), 'hora_inicio' => '17:00', 'hora_fin' => '01:00', 'invitados' => 150,
    'espacios' => [$salon], 'origen' => 'instagram', 'montaje' => 'redondas', 'vendedor_id' => $equipo['vendedor']['id']], (int) $dueno['id'], $hoy->modify('-70 days'));
$cBoda = $cot->crear($nid, $boda, $paq('Paquete Boda Oro'), (int) $equipo['vendedor']['id'], $hoy->modify('-60 days'));
$C = $cot->obtener($nid, $cBoda);
$cot->guardar($nid, $cBoda, $C['items'], ['descuento' => '520', 'invitados' => 150]);
$cot->enviar($nid, $cBoda, $dueno);
$cot->aceptar($nid, $cBoda, 'Ana Torres', '190.152.0.10', null, $hoy->modify('-58 days'));
$cuotas = $pag->cuotas($boda, $hoy);
$pag->registrar($nid, $boda, ['monto' => $cuotas[0]['monto'], 'metodo' => 'transferencia', 'referencia' => 'Banco Pichincha 4581', 'fecha' => $d(-56) . ' 11:00'], $dueno);
$pag->registrar($nid, $boda, ['monto' => 2000, 'metodo' => 'deuna', 'fecha' => $d(-12) . ' 16:20'], $dueno);
$pag->registrar($nid, $boda, ['monto' => 300, 'metodo' => 'efectivo', 'tipo' => 'garantia', 'fecha' => $d(-5) . ' 09:00'], $dueno);
$k = $con->generar($nid, $boda, null, $dueno, $hoy->modify('-55 days'));
$con->enviar($nid, $k, $dueno);
$K = $con->obtener($nid, $k);
$con->firmar($K['token'], 'Ana Lucía Torres Vega', '1712345678', $firma(), true, '190.152.0.10', 'Chrome en Android');
$pag->subirComprobante($boda, $foto([235, 245, 235]), 500, 'transferencia', 'Produbanco 99812');
$ve->asignarCoordinador($nid, $boda, (int) $equipo['coordinador']['id']);
$ve->guardarDetalles($nid, $boda, 'Menú tres tiempos: crema de zapallo, lomo fino en salsa de vino y tres leches.', 'Colores dorado y blanco. Ceremonia en el jardín a las 17:00.');
$ev->agregarActividad($nid, $boda, null, 'degustacion', 'Degustación del menú con los novios', $d(3) . ' 11:00', (int) $equipo['vendedor']['id']);
$ev->agregarActividad($nid, $boda, null, 'llamada', 'Confirmar número final de invitados', $d(0) . ' 16:00', (int) $equipo['vendedor']['id']);
// Día del evento
$dia->cargarEjemplo($nid, $boda);
$pers = [];
foreach ([['Carlos Andrade', '0981111111', 'capitan', 50], ['María Quishpe', '0982222222', 'mesero', 35], ['Luis Cabrera', '0983333333', 'mesero', 35],
          ['Rosa Tipán', '0984444444', 'cocina', 40], ['Pedro Salinas', '0985555555', 'seguridad', 30], ['Diana Vela', '0986666666', 'bartender', 40]] as [$n, $t, $r, $p]) {
    $pers[] = $pid = $dia->guardarPersona($nid, null, ['nombre' => $n, 'telefono' => $t, 'rol' => $r, 'pago_evento' => $p]);
    $a = $dia->asignar($nid, $boda, $pid, ['hora_entrada' => '14:00'], $dueno);
    if ($r !== 'bartender') $dia->marcar($nid, $a, 'confirmado', true);
}
// Proveedores
$pDj = $dia->guardarProveedor($nid, null, ['nombre' => 'DJ Fiesta Total', 'servicio' => 'musica', 'telefono' => '0993334444', 'contacto' => 'Javier León']);
$pFlor = $dia->guardarProveedor($nid, null, ['nombre' => 'Florería El Valle', 'servicio' => 'decoracion', 'telefono' => '0998765432', 'contacto' => 'Rosa Pérez']);
$pCat = $dia->guardarProveedor($nid, null, ['nombre' => 'Catering Delicias', 'servicio' => 'menu', 'telefono' => '0981112222', 'contacto' => 'Hernán Vaca']);
$pFoto = $dia->guardarProveedor($nid, null, ['nombre' => 'Fotografía Momentos', 'servicio' => 'foto_video', 'telefono' => '0987771234', 'contacto' => 'Sofía Ortiz']);
$fi->guardarDatos($nid, $pDj, ['ruc' => '1712345678001', 'razon_social' => 'Javier León Música', 'banco' => 'Banco Pichincha', 'tipo_cuenta' => 'ahorros',
    'numero_cuenta' => '2200456789', 'credito_dias' => '15', 'cupo' => '1500'], $dueno);
$fi->guardarDatos($nid, $pCat, ['credito_dias' => '30', 'anticipo_pct' => '40'], $dueno);
$pdf = function (): array { $r = tempnam(sys_get_temp_dir(), 'doc'); file_put_contents($r, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
    return ['name' => 'permiso.pdf', 'type' => 'application/pdf', 'tmp_name' => $r, 'error' => UPLOAD_ERR_OK, 'size' => filesize($r)]; };
$fi->agregarDocumento($nid, $pCat, ['tipo' => 'permiso_sanitario', 'vence' => $d(12)], $pdf(), $dueno);
$fi->agregarDocumento($nid, $pCat, ['tipo' => 'poliza', 'nombre' => 'Seguro de responsabilidad civil', 'vence' => $d(-3)], $pdf(), $dueno);
$c1 = $co->crear($nid, ['proveedor_id' => $pDj, 'evento_id' => $boda, 'estado' => 'pedida'], [
    ['descripcion' => 'DJ 6 horas con sonido e iluminación', 'cantidad' => '1', 'precio_unitario' => '550'],
    ['descripcion' => 'Luces robóticas', 'cantidad' => '4', 'precio_unitario' => '25'],
], $dueno, $hoy->modify('-15 days'));
$co->cambiarEstado($nid, $c1, 'confirmada', $dueno);
$co->registrarPago($nid, $c1, ['monto' => '200', 'metodo' => 'transferencia', 'referencia' => 'TRF-889'], null, $dueno, $hoy->modify('-10 days'));
$c2 = $co->crear($nid, ['proveedor_id' => $pFlor, 'evento_id' => $boda, 'estado' => 'pedida'], [
    ['descripcion' => 'Centros de mesa con flores naturales', 'cantidad' => '15', 'precio_unitario' => '18'],
    ['descripcion' => 'Arco floral para la ceremonia', 'cantidad' => '1', 'precio_unitario' => '220'],
], $dueno, $hoy->modify('-8 days'));
$c3 = $co->crear($nid, ['proveedor_id' => $pCat, 'evento_id' => $boda, 'estado' => 'pedida', 'anticipo' => '1200'], [
    ['descripcion' => 'Menú tres tiempos', 'cantidad' => '150', 'precio_unitario' => '13'],
], $dueno, $hoy->modify('-12 days'));
$co->cambiarEstado($nid, $c3, 'confirmada', $dueno);
$co->registrarFactura($nid, $c3, ['numero' => '001-002-000004512', 'fecha' => $d(-6), 'total' => '2242.50'], null, $dueno);
$co->registrarPago($nid, $c3, ['monto' => '1200', 'metodo' => 'cheque', 'referencia' => 'Cheque 000145', 'cheque_fecha' => $d(8)], null, $dueno, $hoy);
$dia->agregarProveedorEvento($nid, $boda, ['proveedor_id' => $pFoto, 'hora_llegada' => '15:30']);
$dia->agregarProveedorEvento($nid, $boda, ['nombre' => 'Mariachi Los Reyes', 'servicio' => 'musica', 'telefono' => '0995556666', 'lo_trae_cliente' => 1, 'hora_llegada' => '22:00']);
foreach ($db->query("SELECT id FROM evento_proveedores WHERE evento_id = $boda")->fetchAll(PDO::FETCH_COLUMN) as $i => $epId) {
    if ($i < 2) $dia->confirmarProveedorEvento($nid, (int) $epId, true);
}
// Mobiliario y transmisión
$mob = fn(string $n) => (int) $db->query("SELECT id FROM mobiliario WHERE negocio_id = $nid AND nombre = " . $db->quote($n))->fetchColumn();
$dia->reservarMobiliario($nid, $boda, [$mob('Silla Tiffany') => 150, $mob('Mesa redonda (10 personas)') => 15, $mob('Mantel blanco redondo') => 15,
    $mob('Plato base') => 150, $mob('Copa de vino') => 150, $mob('Copa de agua') => 150, $mob('Juego de cubiertos') => 150]);
$dia->pedirTransmision($nid, $boda, $dueno);
$dia->guardarTransmision($nid, $boda, 'https://youtube.com/live/demo-boda-ana-diego', 'confirmada');
// Invitados y mesas
$nombres = ['Rosa Vega', 'Manuel Torres', 'Tía Carmen Torres', 'Lucía Salazar', 'Esteban Salazar', 'Abuela Teresa', 'Daniela Mora', 'Andrés Cevallos',
    'Camila Paredes', 'Santiago Ortiz', 'Fernanda León', 'Ricardo Almeida', 'Gabriela Ruiz', 'Pablo Benítez', 'Valeria Jácome', 'Marco Zambrano',
    'Isabel Chávez', 'Tomás Herrera', 'Paula Guerrero', 'Diego Montalvo', 'Natalia Rivas', 'Sebastián Lara', 'Carolina Espinosa', 'Juan Pablo Vásquez',
    'Mónica Andrade', 'Felipe Recalde', 'Verónica Pazmiño', 'Alejandro Haro', 'Cristina Naranjo', 'Mateo Villacís', 'Patricia Egas', 'Héctor Yépez',
    'Lorena Proaño', 'Iván Cordero', 'Silvia Maldonado', 'Raúl Tapia', 'Elena Granda', 'Francisco Montero', 'Adriana Salvador', 'Gustavo Peña',
    'Mariana Endara', 'Oswaldo Narváez', 'Karina Mejía', 'Bernardo Ponce', 'Doris Arteaga', 'Wilson Calderón', 'Ximena Carrión', 'Jaime Bustos',
    'Pamela Romero', 'Nicolás Jaramillo', 'Gloria Freire', 'Eduardo Rosero', 'Sandra Vinueza', 'Rodrigo Báez', 'Tatiana Ochoa'];
$grupos = ['Familia de la novia', 'Familia del novio', 'Amigos', 'Trabajo'];
$texto = '';
foreach ($nombres as $i => $n) $texto .= $n . '; ' . ($i === 5 ? '' : '09912300' . str_pad((string) $i, 2, '0', STR_PAD_LEFT)) . '; ' . [2, 2, 3, 1, 2, 4, 2, 3][$i % 8] . '; ' . $grupos[intdiv($i, 14) % 4] . "\n";
$inv->importar($nid, $boda, $texto, 'Amigos');
$lista = $db->query("SELECT id, nombre, token, pases FROM invitados WHERE evento_id = $boda ORDER BY id")->fetchAll();
foreach ($lista as $i => $x) {
    if ($i % 5 === 3) $inv->responder($x['token'], false, 0, '', 'No podremos ir, ¡muchas felicidades!');
    elseif ($i % 5 !== 4) $inv->responder($x['token'], true, (int) $x['pases'], $i === 2 ? 'Vegetariana' : ($i === 7 ? 'Sin gluten' : ''), $i === 0 ? '¡Qué emoción, ahí estaremos!' : '');
}
$tBoda = $pl->crearPlantilla($nid, $salon, 'Boda 150 mesas redondas', '20', '30', (int) $dueno['id'], 'boda');
$pl->crearPlantilla($nid, $jardin, 'Conferencia en el jardín', '18', '24', (int) $dueno['id'], 'escuela');
$pl->crearParaEvento($nid, $boda, $tBoda, null, null, $dueno);
$mesas = $inv->mesas($nid, $boda);
$asig = []; $m = 0; $ocup = 0;
foreach ($lista as $i => $x) {
    if ($i % 5 === 3 || $i >= 50) continue;
    if ($ocup + (int) $x['pases'] > 10) { $m++; $ocup = 0; }
    if (!isset($mesas[$m])) break;
    $asig[(int) $x['id']] = (int) $mesas[$m]['id']; $ocup += (int) $x['pases'];
}
$inv->asignarMesas($nid, $boda, $asig);
// Formulario del evento y extras
$cues->asegurarPlantillas($nid);
$f = $cues->deEvento($nid, $boda);
$por = []; foreach ($f['preguntas'] as $p) $por[$p['texto']] = (int) $p['id'];
$resp = array_filter([
    $por['Canción de entrada de la novia'] ?? null => 'A thousand years',
    $por['Tipo de ceremonia'] ?? null => 'Religiosa',
    $por['Estilos de música para la fiesta'] ?? null => ['Salsa', 'Reguetón'],
    $por['Hora del vals'] ?? null => '21:00',
    $por['¿Cuántos niños asistirán?'] ?? null => '8',
], fn($v, $k) => $k !== '' && $k !== 0, ARRAY_FILTER_USE_BOTH);
if ($resp) $cues->guardarRespuestas($nid, $boda, $resp, array_keys($resp), 'Ana Torres', true, $hoy);
$ext->configurarProducto($nid, $prod('Hora adicional'), true, 'Sigue la fiesta una hora más');
$ext->configurarProducto($nid, $prod('Hora loca'), true, 'Animadores, zanqueros y accesorios para todos');
$ext->configurarProducto($nid, $prod('Mesero adicional'), true, 'Un mesero más para tu evento');
$ext->pedir($nid, $boda, [$prod('Hora adicional') => 1, $prod('Mesero adicional') => 2], 'Para seguir bailando un rato más', '190.152.0.10', $hoy);

// ------------------------------------------------------------------ 2. Quince años (cotizado)
$quince = $ev->crear($nid, ['cliente' => ['nombre' => 'Valentina Ruiz', 'telefono' => '0981231234', 'email' => 'vruiz@correo.ec'], 'tipo' => 'quince', 'fecha' => $d(40),
    'hora_inicio' => '19:00', 'hora_fin' => '02:00', 'invitados' => 120, 'espacios' => [$salon], 'origen' => 'facebook'], (int) $equipo['vendedor']['id'], $hoy->modify('-6 days'));
$vender($quince, $paq('Paquete Quince Años'), 0, '-2 days', false);
$ev->agregarActividad($nid, $quince, null, 'whatsapp', 'Preguntar si revisó la cotización', $d(1) . ' 10:00', (int) $equipo['vendedor']['id']);

// ------------------------------------------------------------------ 3. Corporativo separado con cuota atrasada
$corp = $ev->crear($nid, ['cliente' => ['nombre' => 'Comercial Andina Cía. Ltda.', 'telefono' => '0977777777', 'identificacion' => '1790011223001'],
    'nombre' => 'Aniversario Comercial Andina', 'tipo' => 'corporativo', 'fecha' => $d(9), 'hora_inicio' => '12:00', 'hora_fin' => '17:00', 'invitados' => 80,
    'espacios' => [$jardin], 'origen' => 'referido', 'montaje' => 'imperial'], (int) $dueno['id'], $hoy->modify('-50 days'));
$cC = $cot->crear($nid, $corp, null, (int) $dueno['id'], $hoy->modify('-48 days'));
$cot->agregarProducto($nid, $cC, $prod('Menú buffet'));
$cot->agregarProducto($nid, $cC, $prod('Gaseosas, agua y hielo ilimitado'));
$cot->agregarProducto($nid, $cC, $prod('DJ con sonido e iluminación (6 horas)'));
$cot->enviar($nid, $cC, $dueno);
$cot->aceptar($nid, $cC, 'Comercial Andina', '', $dueno, $hoy->modify('-45 days'));
$cu = $pag->cuotas($corp, $hoy);
$pag->registrar($nid, $corp, ['monto' => $cu[0]['monto'], 'metodo' => 'transferencia', 'fecha' => $d(-14) . ' 12:00'], $dueno);
$kc = $con->generar($nid, $corp, null, $dueno, $hoy->modify('-2 days'));
$con->enviar($nid, $kc, $dueno);

// ------------------------------------------------------------------ 4. Bautizo de hoy (día del evento en vivo, empieza a esta hora)
$ahora = new DateTimeImmutable();
$hh = min(18, max(8, (int) $ahora->format('G')));
$hIni = sprintf('%02d:%02d', $hh, $hh === (int) $ahora->format('G') ? intdiv((int) $ahora->format('i'), 15) * 15 : 0);
$hFin = sprintf('%02d:00', $hh + 5);
$baut = $ev->crear($nid, ['cliente' => ['nombre' => 'Familia Ríos', 'telefono' => '0966666666'], 'nombre' => 'Bautizo de Mateo Ríos', 'tipo' => 'bautizo',
    'fecha' => $d(0), 'hora_inicio' => $hIni, 'hora_fin' => $hFin, 'invitados' => 60, 'espacios' => [$esp('Terraza')], 'origen' => 'cliente_anterior'], (int) $dueno['id'], $hoy->modify('-40 days'));
$cB = $vender($baut, null, 0, '-38 days');
$tb = (float) $db->query("SELECT total FROM eventos WHERE id = $baut")->fetchColumn();
$pag->registrar($nid, $baut, ['monto' => $tb, 'metodo' => 'transferencia', 'fecha' => $d(-15) . ' 10:00'], $dueno);
$kb = $con->generar($nid, $baut, null, $dueno, $hoy->modify('-30 days')); $con->enviar($nid, $kb, $dueno);
$con->firmar($con->obtener($nid, $kb)['token'], 'Gabriel Ríos', '1712345678', $firma(), true);
$dia->cargarEjemplo($nid, $baut);
$ve->asignarCoordinador($nid, $baut, (int) $equipo['coordinador']['id']);
$a = $dia->asignar($nid, $baut, $pers[0], ['hora_entrada' => sprintf('%02d:00', $hh - 2)], $dueno); $dia->marcar($nid, $a, 'confirmado', true); $dia->marcar($nid, $a, 'asistio', true);
$a = $dia->asignar($nid, $baut, $pers[1], ['hora_entrada' => sprintf('%02d:00', $hh - 1)], $dueno); $dia->marcar($nid, $a, 'confirmado', true);
$epB = $dia->agregarProveedorEvento($nid, $baut, ['proveedor_id' => $pFoto, 'hora_llegada' => sprintf('%02d:30', $hh - 1)]);
$dia->confirmarProveedorEvento($nid, $epB, true);
$fi->marcarLlegada($nid, $epB, sprintf('%02d:40', $hh - 1), $equipo['coordinador']);

// ------------------------------------------------------------------ 5. Consultas, perdidos y lista de espera
$ve->crearConsultaWeb($nid, ['nombre' => 'María Pérez', 'telefono' => '0997776655', 'email' => 'maria.perez@correo.ec', 'tipo' => 'boda', 'fecha' => $d(120),
    'invitados' => 180, 'mensaje' => 'Hola, queremos casarnos en el jardín. ¿Qué paquetes tienen?'], '190.152.0.20', $hoy);
$ve->crearConsultaWeb($nid, ['nombre' => 'Carlos Jaramillo', 'telefono' => '0984561230', 'tipo' => 'graduacion', 'fecha' => $d(75), 'invitados' => 200], '190.152.0.21', $hoy);
$cum = $ev->crear($nid, ['cliente' => ['nombre' => 'Jorge Vega', 'telefono' => '0995554433'], 'nombre' => 'Cumpleaños 50 de Jorge', 'tipo' => 'cumpleanos', 'fecha' => $d(25),
    'hora_inicio' => '20:00', 'hora_fin' => '02:00', 'invitados' => 70, 'espacios' => [$jardin], 'origen' => 'google', 'presupuesto_cliente' => 3500], (int) $equipo['vendedor']['id'], $hoy->modify('-3 days'));
$ev->agregarActividad($nid, $cum, null, 'visita', 'Visita al local con su esposa', $d(2) . ' 17:00', (int) $equipo['vendedor']['id']);
$ev->agregarActividad($nid, $cum, null, 'llamada', 'Llamar para enviar la cotización', $d(-1) . ' 10:00', (int) $equipo['vendedor']['id']);
$com = $ev->crear($nid, ['cliente' => ['nombre' => 'Lorena Castillo', 'telefono' => '0973332211'], 'tipo' => 'primera_comunion', 'fecha' => $d(60),
    'hora_inicio' => '12:00', 'hora_fin' => '17:00', 'invitados' => 90, 'espacios' => [$jardin], 'origen' => 'tiktok'], (int) $dueno['id'], $hoy->modify('-1 days'));
$per = $ev->crear($nid, ['cliente' => ['nombre' => 'Luis Mora', 'telefono' => '0987654321'], 'tipo' => 'boda', 'fecha' => $d(95),
    'hora_inicio' => '18:00', 'hora_fin' => '02:00', 'invitados' => 200, 'espacios' => [$salon], 'origen' => 'feria'], (int) $equipo['vendedor']['id'], $hoy->modify('-25 days'));
$vender($per, $paq('Paquete Boda Plata'), 0, '-24 days', false);
$ev->cambiarEstado($nid, $per, 'perdido', ['motivo' => 'Eligió otro local más barato'], $dueno, $hoy);

// ------------------------------------------------------------------ 6. Eventos ya realizados (reportes, encuesta, incidencias)
$hechos = [
    ['Graduación Colegio San Gabriel', 'Promoción 2026', 'graduacion', -12, $salon, 180, 'Paquete Quince Años'],
    ['Boda de Sofía y Andrés', 'Sofía Andrade', 'boda', -33, $salon, 140, 'Paquete Boda Plata'],
    ['Aniversario de bodas Pérez', 'Hugo Pérez', 'aniversario', -47, $jardin, 60, null],
    ['Quince años de Emilia', 'Emilia Cárdenas', 'quince', -75, $salon, 110, 'Paquete Quince Años'],
    ['Cena de fin de año Grupo Norte', 'Grupo Norte S.A.', 'corporativo', -300, $salon, 150, null],
];
$realizados = [];
foreach ($hechos as $i => [$nom, $cli, $tipo, $dias, $espacio, $invitados, $paquete]) {
    $id = $ev->crear($nid, ['cliente' => ['nombre' => $cli, 'telefono' => '09' . (80000000 + $i * 1111)], 'nombre' => $nom, 'tipo' => $tipo, 'fecha' => $d($dias),
        'hora_inicio' => '18:00', 'hora_fin' => '23:30', 'invitados' => $invitados, 'espacios' => [$espacio], 'origen' => ['instagram', 'referido', 'google', 'facebook', 'pagina'][$i],
        'vendedor_id' => $i % 2 ? $equipo['vendedor']['id'] : $dueno['id']], (int) $dueno['id'], $hoy->modify(($dias - 90) . ' days'));
    $cid = $cot->crear($nid, $id, $paquete ? $paq($paquete) : null, (int) $dueno['id'], $hoy->modify(($dias - 80) . ' days'));
    if (!$paquete) { $cot->agregarProducto($nid, $cid, $prod('Menú tres tiempos')); $cot->agregarProducto($nid, $cid, $prod('Barra libre nacional (5 horas)')); }
    $cot->enviar($nid, $cid, $dueno);
    $cot->aceptar($nid, $cid, $cli, '', $dueno, $hoy->modify(($dias - 78) . ' days'));
    $total = (float) $db->query("SELECT total FROM eventos WHERE id = $id")->fetchColumn();
    $pag->registrar($nid, $id, ['monto' => round($total * 0.3, 2), 'metodo' => 'transferencia', 'fecha' => $d($dias - 75) . ' 10:00'], $dueno);
    $pag->registrar($nid, $id, ['monto' => round($total - round($total * 0.3, 2), 2), 'metodo' => $i % 2 ? 'deuna' : 'transferencia', 'fecha' => $d($dias - 15) . ' 15:00'], $dueno);
    $ev->cambiarEstado($nid, $id, 'realizado', [], $dueno, $hoy);
    $db->prepare("INSERT INTO gastos (negocio_id, evento_id, concepto, monto, fecha) VALUES (?,?,?,?,?)")
       ->execute([$nid, $id, 'Compras de cocina', round($total * 0.22, 2), $d($dias - 1)]);
    $realizados[] = $id;
}
$grad = $realizados[0];
$epG = $dia->agregarProveedorEvento($nid, $grad, ['proveedor_id' => $pDj, 'hora_llegada' => '16:00']);
$fi->marcarLlegada($nid, $epG, '16:35', $dueno);
$fi->evaluar($nid, $epG, ['cumplimiento' => 'observaciones', 'calificacion' => '3', 'comentario' => 'Llegó tarde y sin un micrófono'], $dueno);
$epG2 = $dia->agregarProveedorEvento($nid, $grad, ['proveedor_id' => $pFoto, 'hora_llegada' => '17:00']);
$fi->marcarLlegada($nid, $epG2, '16:50', $dueno);
$fi->evaluar($nid, $epG2, ['cumplimiento' => 'cumplio', 'calificacion' => '5'], $dueno);
$inc->crear($nid, ['evento_id' => $grad, 'proveedor_id' => $pDj, 'gravedad' => 'media', 'descripcion' => 'Llegó sin el micrófono inalámbrico para los discursos'], $foto([240, 220, 220]), $dueno);
$inc->crear($nid, ['evento_id' => $grad, 'area' => 'instalaciones', 'gravedad' => 'leve', 'descripcion' => 'Se fundió un foco del baño de damas'], null, $dueno);
$enc = new Encuestas($db);
foreach ([[$grad, 10, 5, 'Todo excelente, la comida deliciosa.'], [$realizados[1], 9, 5, 'Nos encantó, gracias por todo.'], [$realizados[2], 6, 3, 'El servicio se demoró con los platos.']] as [$id, $nps, $est, $txt]) {
    $tk = (string) $db->query("SELECT token_encuesta FROM eventos WHERE id = $id")->fetchColumn();
    $enc->responder($tk, ['nps' => $nps, 'comida' => $est, 'atencion' => $est, 'instalaciones' => 5, 'organizacion' => $est, 'comentario' => $txt]);
}

// ------------------------------------------------------------------ 7. Metas, fecha cerrada y aviso
$met = new Metas($db);
$met->guardar($nid, null, null, 12000, 3);
$met->guardar($nid, (int) $equipo['vendedor']['id'], null, 6000, 2);
// Fechas reales de venta: el día del primer pago (así Reportes y Metas cuentan cada evento en su mes)
$db->exec("UPDATE registro_cambios rc SET creado_en = x.f FROM (SELECT evento_id, min(fecha) AS f FROM pagos WHERE tipo = 'evento' GROUP BY evento_id) x
           WHERE x.evento_id = rc.evento_id AND rc.negocio_id = $nid AND rc.accion LIKE 'Cambió el estado de % a Separado%'");
$db->exec("UPDATE eventos e SET confirmado_en = x.f + interval '1 day' FROM (SELECT evento_id, min(fecha) AS f FROM pagos WHERE tipo = 'evento' GROUP BY evento_id) x
           WHERE x.evento_id = e.id AND e.confirmado_en IS NOT NULL AND e.negocio_id = $nid");
$db->exec("UPDATE eventos e SET creado_en = LEAST(e.creado_en, (SELECT min(c.fecha) FROM cotizaciones c WHERE c.evento_id = e.id), e.fecha - 60) WHERE e.negocio_id = $nid AND e.estado <> 'consulta'");
$ve->agregarFechaCerrada($nid, $d(30), null, 'Mantenimiento general de la quinta', $hoy);
// ------------------------------------------------------------------ 8. Ayuda e IA (110): preguntas frecuentes de ejemplo
require_once RAIZ . '/src/tukan_ia.php';
// (el plan Quinta ya trae el Asistente IA incluido: migración 111)
$faqs = new \TukanIaFaqPdo($db);
$faqs->agregar('¿Cómo registro un pago?', "1. Abra el evento y toque la pestaña **Pagos**.\n2. En **Registrar un pago recibido** escriba el valor, la forma de pago y la fecha.\n3. Toque **Guardar pago y generar recibo**.\nCuando lo pagado completa la reserva, la fecha queda **separada** para ese espacio.");
$faqs->agregar('¿Cómo envío la cotización por WhatsApp?', "1. Abra el evento y toque la pestaña **Cotización**.\n2. En **Enlace para el cliente** toque **Enviar por WhatsApp**.\n3. El cliente la ve en su celular y la acepta con **Aceptar cotización**.");
echo "Listo: negocio $nid · boda $boda · quince $quince · corporativo $corp · bautizo $baut · cumpleaños $cum · graduación $grad\n";
