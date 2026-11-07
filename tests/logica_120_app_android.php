<?php
/**
 * App de Android en Google Play: huellas de assetlinks.json (la de nuestra llave siempre + la de Google desde config/.env)
 * y la cuenta del revisor de Google (negocio de ejemplo aparte que no toca a ningún otro negocio).
 */
use TuRenta\{AppAndroid, DemoGoogle, Planes};

$db = base_limpia();
$subida = AppAndroid::HUELLA_SUBIDA;
$google = '6D:FA:23:74:41:FF:85:59:B6:7F:3C:79:69:5E:97:98:4A:E2:DD:A8:2C:40:37:63:B3:09:D6:1D:75:33:6F:CF';

titulo('1. assetlinks.json');
putenv(AppAndroid::VARIABLE);
$a = AppAndroid::assetlinks();
check('Paquete de la app', 'com.tukansoftware.turenta', $a[0]['target']['package_name']);
check('Relación para abrir a pantalla completa', ['delegate_permission/common.handle_all_urls'], $a[0]['relation']);
check('Sin configurar: solo la huella de la llave de subida', [$subida], AppAndroid::huellas());
putenv(AppAndroid::VARIABLE . '=' . strtolower($google));
check('Con la huella de Google (en minúsculas): las dos, en mayúsculas', [$subida, $google], AppAndroid::huellas());
putenv(AppAndroid::VARIABLE . "=$google, $google ,$subida");
check('Sin repetidas', [$subida, $google], AppAndroid::huellas());
putenv(AppAndroid::VARIABLE . '=NO-ES-HUELLA,12:34');
check('Las huellas mal escritas se ignoran', [$subida], AppAndroid::huellas());
check('Huella válida', true, AppAndroid::huellaValida($google));
check('Huella corta no vale', false, AppAndroid::huellaValida('39:0C:08'));
putenv(AppAndroid::VARIABLE);

titulo('2. Cuenta del revisor de Google: crear');
[$otro, $duenoOtro] = negocio_prueba($db, 'quinta', 'Quinta De Verdad');
$huellaOtros = fn() => $db->query("SELECT md5(string_agg(x, '|' ORDER BY x)) FROM (
    SELECT 'n'||id||nombre||estado||coalesce(prueba_hasta::text,'')||plan_codigo AS x FROM negocios WHERE nombre <> '" . DemoGoogle::NEGOCIO . "'
    UNION ALL SELECT 'u'||id||email||password_hash FROM usuarios WHERE lower(email) <> '" . DemoGoogle::CORREO . "'
    UNION ALL SELECT 'e'||id||negocio_id||estado FROM eventos WHERE negocio_id = $otro
    UNION ALL SELECT 'p'||id||negocio_id||monto FROM pagos WHERE negocio_id = $otro) t")->fetchColumn();
$antes = $huellaOtros();
$cobradoAntes = (int) $db->query('SELECT count(*) FROM suscripciones')->fetchColumn();
$demo = new DemoGoogle($db);
check('Sin negocio de ejemplo todavía', null, $demo->negocioId());
$r = $demo->crear();
check('Contraseña Demo- y 6 números', 1, preg_match('/^Demo-\d{6}$/', $r['clave']));
$nid = $r['negocio_id'];
$n = $db->query("SELECT * FROM negocios WHERE id = $nid")->fetch();
check('Negocio de ejemplo aparte', DemoGoogle::NEGOCIO, $n['nombre']);
check('En demo (estado prueba)', 'prueba', $n['estado']);
check('La demo dura un año (no vence durante la revisión)', true, $n['prueba_hasta'] >= (new DateTimeImmutable('+360 days'))->format('Y-m-d'));
check('Plan Completo (todos los módulos para revisar)', 'quinta', $n['plan_codigo']);
check('Sin pagos a Tukán (no cuenta en lo cobrado)', $cobradoAntes, (int) $db->query('SELECT count(*) FROM suscripciones')->fetchColumn());
check('Está al día (puede entrar)', true, (new Planes($db))->alDia($n));
$us = $db->query("SELECT email, rol, password_hash, clave_temporal FROM usuarios WHERE negocio_id = $nid")->fetchAll();
check('Un solo usuario: el revisor, dueño del negocio de ejemplo', [[DemoGoogle::CORREO, 'dueno']], array_map(fn($x) => [$x['email'], $x['rol']], $us));
check('La contraseña que dio sirve', true, password_verify($r['clave'], $us[0]['password_hash']));
check('Sin clave temporal (entra directo)', false, (bool) $us[0]['clave_temporal']);
check('6 eventos de ejemplo', 6, (int) $db->query("SELECT count(*) FROM eventos WHERE negocio_id = $nid")->fetchColumn());
check('Eventos en varias etapas', true, (int) $db->query("SELECT count(DISTINCT estado) FROM eventos WHERE negocio_id = $nid")->fetchColumn() >= 4);
check('Clientes solo con correos de example.com', 0, (int) $db->query("SELECT count(*) FROM clientes WHERE negocio_id = $nid AND coalesce(email,'') NOT LIKE '%@example.com'")->fetchColumn());
check('Hay pagos de los clientes del ejemplo', true, (int) $db->query("SELECT count(*) FROM pagos WHERE negocio_id = $nid")->fetchColumn() >= 3);
check('No tocó ningún otro negocio', $antes, $huellaOtros());
check('esRevisor reconoce al revisor', true, DemoGoogle::esRevisor(['email' => 'Revisor.Google@tukanec.com']));
check('esRevisor no reconoce a otro', false, DemoGoogle::esRevisor(['email' => $duenoOtro['email']]));

titulo('3. Volver a crear y borrar');
$r2 = $demo->crear();
check('Crear otra vez: un solo negocio de ejemplo', 1, (int) $db->query("SELECT count(*) FROM negocios WHERE nombre = '" . DemoGoogle::NEGOCIO . "'")->fetchColumn());
check('Un solo revisor', 1, (int) $db->query("SELECT count(*) FROM usuarios WHERE email = '" . DemoGoogle::CORREO . "'")->fetchColumn());
check('Siguen 6 eventos (no 12)', 6, (int) $db->query("SELECT count(*) FROM eventos WHERE negocio_id = {$r2['negocio_id']}")->fetchColumn());
check('La contraseña nueva sirve', true, password_verify($r2['clave'], (string) $db->query("SELECT password_hash FROM usuarios WHERE email = '" . DemoGoogle::CORREO . "'")->fetchColumn()));
check('No tocó ningún otro negocio', $antes, $huellaOtros());
check('Borrar', 1, $demo->borrar());
check('No queda el negocio de ejemplo', 0, (int) $db->query("SELECT count(*) FROM negocios WHERE nombre = '" . DemoGoogle::NEGOCIO . "'")->fetchColumn());
check('No queda el revisor', 0, (int) $db->query("SELECT count(*) FROM usuarios WHERE email = '" . DemoGoogle::CORREO . "'")->fetchColumn());
check('No quedan sus eventos ni clientes', 0, (int) $db->query("SELECT count(*) FROM eventos WHERE negocio_id = {$r2['negocio_id']}")->fetchColumn()
    + (int) $db->query("SELECT count(*) FROM clientes WHERE negocio_id = {$r2['negocio_id']}")->fetchColumn());
check('Borrar dos veces no falla', 0, $demo->borrar());
check('Borrar no tocó ningún otro negocio', $antes, $huellaOtros());

titulo('4. Nunca toca un negocio real con ese correo');
$db->prepare("UPDATE usuarios SET email = ? WHERE id = ?")->execute([DemoGoogle::CORREO, $duenoOtro['id']]);
check_error('Crear se niega', 'NO es de ejemplo', fn() => $demo->crear());
check_error('Borrar se niega', 'NO es de ejemplo', fn() => $demo->borrar());
check('El negocio real sigue ahí', 'Quinta De Verdad', $db->query("SELECT nombre FROM negocios WHERE id = $otro")->fetchColumn());
$db->prepare("UPDATE usuarios SET email = ? WHERE id = ?")->execute([$duenoOtro['email'], $duenoOtro['id']]);
