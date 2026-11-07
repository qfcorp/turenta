<?php
declare(strict_types=1);

/**
 * Crea (o cambia la contraseña de) un super administrador de TuRenta (panel Tukán).
 * Se corre en el servidor:
 *   php /var/www/turenta/bin/crear_admin.php
 * Pregunta nombre, correo y contraseña (la contraseña no se ve al escribir).
 * Para pruebas automáticas: TURENTA_ADMIN_NOMBRE, TURENTA_ADMIN_EMAIL, TURENTA_ADMIN_CLAVE.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
define('RAIZ', dirname(__DIR__));
$envFile = RAIZ . '/config/.env';
if (is_file($envFile)) {
    foreach (parse_ini_file($envFile, false, INI_SCANNER_RAW) ?: [] as $k => $v) {
        if (getenv($k) === false) putenv("$k=$v");
    }
}
require RAIZ . '/src/Db.php';
try {
    $db = TuRenta\Db::conectar();
} catch (Throwable $e) {
    fwrite(STDERR, "No se pudo conectar a la base de datos. Revisa config/.env (TURENTA_DB_*).\n");
    exit(1);
}

$preguntar = function (string $texto, bool $oculto = false): string {
    echo $texto;
    $tty = $oculto && DIRECTORY_SEPARATOR === '/' && function_exists('posix_isatty') && posix_isatty(STDIN);
    if ($tty) system('stty -echo');
    $r = trim((string) fgets(STDIN));
    if ($tty) { system('stty echo'); echo "\n"; }
    return $r;
};

$nombre = trim((string) (getenv('TURENTA_ADMIN_NOMBRE') ?: $preguntar('Tu nombre: ')));
$email = strtolower(trim((string) (getenv('TURENTA_ADMIN_EMAIL') ?: $preguntar('Tu correo (con este entras al panel): '))));
$clave = (string) (getenv('TURENTA_ADMIN_CLAVE') ?: '');
if ($clave === '') {
    $clave = $preguntar('Elige una contrasena (minimo 10 caracteres, no se ve al escribir): ', true);
    $otra = $preguntar('Escribela otra vez: ', true);
    if ($clave !== $otra) { fwrite(STDERR, "Las contrasenas no coinciden. Vuelve a intentar.\n"); exit(1); }
}
if (mb_strlen($nombre) < 2) { fwrite(STDERR, "Escribe tu nombre.\n"); exit(1); }
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { fwrite(STDERR, "El correo no es valido.\n"); exit(1); }
if (strlen($clave) < 10) { fwrite(STDERR, "La contrasena debe tener al menos 10 caracteres.\n"); exit(1); }

$st = $db->prepare('INSERT INTO superadmins (nombre, email, password_hash) VALUES (?, ?, ?)
                    ON CONFLICT (email) DO UPDATE SET nombre = EXCLUDED.nombre, password_hash = EXCLUDED.password_hash, activo = true
                    RETURNING (xmax = 0) AS nuevo');
$st->execute([$nombre, $email, password_hash($clave, PASSWORD_DEFAULT)]);
echo $st->fetchColumn() ? "Listo: super usuario creado para $email.\n" : "Listo: contrasena cambiada para $email.\n";
$url = rtrim((string) (getenv('TURENTA_URL') ?: 'https://turenta.tukanec.com'), '/');
echo "Entra en: $url/?r=admin_entrar\n";
// Ingreso solo desde el Panel Tukan Central: este comando es la puerta de emergencia
if (trim((string) getenv('TUKAN_SOLO_CENTRAL')) === '1' && strlen(trim((string) getenv('TUKAN_CENTRAL_SECRET'))) >= 32
    && preg_match('/^[a-z0-9_-]{2,40}$/', trim((string) getenv('TUKAN_CENTRAL_ID')))) {
    echo "AVISO: TUKAN_SOLO_CENTRAL=1, el ingreso local del super usuario esta apagado.\n";
    echo "Para entrar con esta contrasena pon TUKAN_SOLO_CENTRAL=0 en " . RAIZ . "/config/.env\n";
}
