<?php
declare(strict_types=1);

/**
 * Carga (o actualiza) el negocio de demostración Zombie Ice (fiestas de espuma y nieve en el sur de Florida) con sus
 * 6 fiestas, precios, equipos, extras, fotos y la reserva en línea en inglés. Se puede correr varias veces.
 *   php /var/www/turenta/bin/cargar_zombieice.php [correo-del-dueño]
 * La primera vez crea el usuario dueño con una contraseña nueva y la muestra UNA vez (anótela).
 * Las claves de Stripe NO se cargan aquí: se pegan en Configuración → Reserva en línea y Stripe.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
define('RAIZ', dirname(__DIR__));
$envFile = RAIZ . '/config/.env';
if (is_file($envFile)) {
    foreach (parse_ini_file($envFile, false, INI_SCANNER_RAW) ?: [] as $k => $v) {
        if (getenv($k) === false) putenv("$k=$v");
    }
}
date_default_timezone_set(getenv('TURENTA_ZONA') ?: 'America/New_York');
spl_autoload_register(function (string $c): void {
    if (str_starts_with($c, 'TuRenta\\') && is_file($f = RAIZ . '/src/' . substr($c, 8) . '.php')) require $f;
});
try {
    $db = TuRenta\Db::conectar();
} catch (Throwable $e) {
    fwrite(STDERR, "No se pudo conectar a la base de datos. Revisa config/.env (TURENTA_DB_*).\n");
    exit(1);
}

$WEB = 'https://zombieice.qfproducciones.com';
$correo = strtolower(trim($argv[1] ?? 'produccion@qfproducciones.com'));

// Fiestas de zombieice.com (precios y duraciones de su página, revisados el 7 nov 2026). Equipo: truck = ZombieTruck, movil = espuma portátil.
$FIESTAS = [
    ['signature-experience', 'Signature Experience', 495, 60, 'truck', 'signature', 1,
     '45 minutes of foam with high-energy music, the Funtastik Picture Moment with confetti, 20 Zombie Icees served last and one custom video made from your pictures.'],
    ['zombie-truck-foam-experience', 'ZombieTruck Foam Experience', 395, 60, 'truck', 'hero', 2,
     'The ZombieTruck blasts foam at your home or venue, with music and your custom video on the big screen.'],
    ['mobile-foam-experience', 'Mobile Foam Experience', 375, 60, 'movil', 'mobilefoam', 3,
     'Portable foam cannon brought to your yard or venue. You need a water spigot and a power outlet nearby.'],
    ['zombie-truck-snow-experience', 'ZombieTruck Snow Experience', 375, 60, 'truck', 'snow', 4,
     'Real snow made on site by the truck, holiday and snowfall videos on the big screen and one custom video.'],
    ['zombie-truck-event-experience', 'ZombieTruck Event Experience', 350, 60, 'truck', 'event', 5,
     'The truck as the centerpiece: music, LED screen, a snow photo moment and 50 shaved-ice treats. Fully self-contained.'],
    ['beyond-happiness-experience', 'Beyond Happiness Experience', 195, 30, 'truck', 'beyond', 6,
     'A 30-minute snow celebration with 12 Icees and one custom video. Great before or after a main party.'],
];
$EXTRAS = [
    ['UV Glow Package', 'Lights, lasers and glow powder that makes the foam glow', 199, 'evento'],
    ['Lights & Laser Package', 'Party lights and lasers', 100, 'evento'],
    ['Extra Zombie Icee', 'Each', 3.75, 'unidad'],
    ['50 Snowballs', 'A pile of ready-to-throw snowballs', 30, 'evento'],
    ['Colored Foam', 'Foam in color', 70, 'evento'],
    ['Green FC1 Cannon', 'Per hour', 100, 'hora'],
];

$db->beginTransaction();
try {
    $st = $db->prepare("SELECT id FROM negocios WHERE slug = 'zombieice'");
    $st->execute();
    $nid = $st->fetchColumn();
    $clave = null;
    if ($nid === false) {
        $clave = bin2hex(random_bytes(6)) . 'Zi1';
        $alta = (new TuRenta\Altas($db))->crearNegocioConDueno('Zombie Ice', 'Zombie Ice (demo QF)', '7547770423', $correo, $clave, 'quinta', 'Weston');
        $nid = (int) $alta['negocio_id'];
        // Fuera los datos de ejemplo de salones (espacios, catálogo, paquetes, mobiliario, contrato y tareas de bodas)
        foreach (['paquetes', 'productos', 'espacios', 'mobiliario', 'plantilla_tareas', 'plantillas_contrato'] as $t) {
            $db->prepare("DELETE FROM $t WHERE negocio_id = ?")->execute([$nid]);
        }
        $db->prepare("UPDATE negocios SET slug = 'zombieice' WHERE id = ?")->execute([$nid]);
    }
    $nid = (int) $nid;
    $db->prepare("UPDATE negocios SET nombre = 'Zombie Ice', telefono = '17547770423', whatsapp = '17547770423', email = 'info@zombieice.com',
                         ciudad = 'Weston', direccion = 'Weston, FL', color = '#1259C3', iva_pct = 0, reserva_pct = 30, cuotas_defecto = 2,
                         dias_saldo_antes = 2, garantia_defecto = 0, horas_montaje = 1, horas_desmontaje = 1, hora_limite = '23:00',
                         dias_retencion = 1, validez_cotizacion = 30,
                         condiciones = 'Prices in US dollars. Your date is locked once the deposit is paid. The balance is due before the party.',
                         reserva_en_linea = true, idioma_publico = 'en', reserva_hora_desde = '10:00', reserva_hora_hasta = '18:00',
                         reserva_paso_min = 30, reserva_dias_min = 2, sitio_externo = ?, logo_url = ?,
                         reserva_condiciones = ?, estado = 'activo', activo_hasta = (CURRENT_DATE + 365), prueba_hasta = NULL
                   WHERE id = ?")
       ->execute([$WEB, "$WEB/assets/img/logo.webp",
                  "DEMO TERMS (Zombie Ice will set its own):\n• Your date and time are locked once the deposit is paid.\n• The balance is due before the party.\n• Private parties are for up to 50 kids.\n• Mobile Foam needs a water spigot and a power outlet nearby.",
                  $nid]);

    // Equipos (espacios): un ZombieTruck y un equipo de espuma portátil. Se agregan más camiones en Espacios.
    $equipo = function (string $nombre, string $desc, int $orden) use ($db, $nid): int {
        $st = $db->prepare('SELECT id FROM espacios WHERE negocio_id = ? AND nombre = ?');
        $st->execute([$nid, $nombre]);
        $id = $st->fetchColumn();
        if ($id !== false) return (int) $id;
        $st = $db->prepare("INSERT INTO espacios (negocio_id, nombre, descripcion, color, orden) VALUES (?,?,?, '#1259C3', ?) RETURNING id");
        $st->execute([$nid, $nombre, $desc, $orden]);
        return (int) $st->fetchColumn();
    };
    $EQ = ['truck' => $equipo('ZombieTruck 1', 'Foam, snow and event truck with LED screen', 1),
           'movil' => $equipo('Mobile Foam kit 1', 'Portable foam cannon', 2)];

    $producto = function (string $nombre, string $desc, float $precio, string $unidad, int $orden) use ($db, $nid): int {
        $st = $db->prepare('SELECT id FROM productos WHERE negocio_id = ? AND nombre = ?');
        $st->execute([$nid, $nombre]);
        $id = $st->fetchColumn();
        if ($id !== false) {
            $db->prepare('UPDATE productos SET descripcion = ?, precio = ?, unidad = ?, con_iva = false, activo = true WHERE id = ?')->execute([$desc, $precio, $unidad, $id]);
            return (int) $id;
        }
        $st = $db->prepare("INSERT INTO productos (negocio_id, categoria, nombre, descripcion, unidad, precio, costo, con_iva, orden) VALUES (?, 'otros', ?,?,?,?, 0, false, ?) RETURNING id");
        $st->execute([$nid, $nombre, $desc, $unidad, $precio, $orden]);
        return (int) $st->fetchColumn();
    };
    foreach ($EXTRAS as $i => [$n, $d, $p, $u]) $producto($n, $d, (float) $p, $u, 100 + $i);

    foreach ($FIESTAS as [$slug, $nombre, $precio, $min, $eq, $foto, $orden, $desc]) {
        $prod = $producto($nombre, $desc, (float) $precio, 'evento', $orden);
        $st = $db->prepare('SELECT id FROM paquetes WHERE negocio_id = ? AND (slug = ? OR nombre = ?) ORDER BY id LIMIT 1');
        $st->execute([$nid, $slug, $nombre]);
        $paq = $st->fetchColumn();
        if ($paq === false) {
            $st = $db->prepare("INSERT INTO paquetes (negocio_id, nombre, descripcion, tipo_evento, minimo_invitados) VALUES (?,?,?, 'cumpleanos', 0) RETURNING id");
            $st->execute([$nid, $nombre, $desc]);
            $paq = (int) $st->fetchColumn();
        }
        $paq = (int) $paq;
        $db->prepare("UPDATE paquetes SET nombre = ?, descripcion = ?, activo = true, publico = true, slug = ?, duracion_min = ?, maximo_invitados = 50,
                             foto_url = ?, orden = ? WHERE id = ?")
           ->execute([$nombre, $desc, $slug, $min, "$WEB/assets/img/$foto.webp", $orden, $paq]);
        $db->prepare('DELETE FROM paquete_items WHERE paquete_id = ?')->execute([$paq]);
        $db->prepare('INSERT INTO paquete_items (paquete_id, producto_id, cantidad) VALUES (?,?,1)')->execute([$paq, $prod]);
        $db->prepare('DELETE FROM paquete_espacios WHERE paquete_id = ?')->execute([$paq]);
        $db->prepare('INSERT INTO paquete_espacios (paquete_id, espacio_id) VALUES (?,?)')->execute([$paq, $EQ[$eq]]);
    }
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}

$base = rtrim((string) (getenv('TURENTA_URL') ?: 'https://turenta.tukanec.com'), '/');
echo "LISTO: Zombie Ice cargado (6 fiestas, 6 extras, 2 equipos, reserva en linea en ingles).\n";
echo "Reserva en linea: $base/?r=reservar&s=zombieice\n";
if ($clave !== null) {
    echo "Entrar al sistema: $base/?r=login\n";
    echo "  Correo:     $correo\n";
    echo "  Contrasena: $clave   (anotela ahora; se cambia en Mi cuenta)\n";
}
echo "Falta: pegar las claves de prueba de Stripe en Configuracion -> Reserva en linea y Stripe.\n";
