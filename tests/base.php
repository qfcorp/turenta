<?php
declare(strict_types=1);

/**
 * Base común de las pruebas de lógica de TuRenta (entorno simulado).
 * Borra y recrea la base de prueba con db/schema.sql + db/migraciones/*.sql.
 * Uso: TURENTA_DB_NAME=turenta_prueba TURENTA_DB_USER=turenta TURENTA_DB_PASS=prueba php tests/run_tests.php
 */

define('RAIZ', dirname(__DIR__));
date_default_timezone_set('America/Guayaquil');
spl_autoload_register(function (string $clase): void {
    if (str_starts_with($clase, 'TuRenta\\')) {
        $archivo = RAIZ . '/src/' . substr($clase, 9) . '.php';
        if (is_file($archivo)) require $archivo;
    }
});
if (is_file(RAIZ . '/vendor/autoload.php')) require RAIZ . '/vendor/autoload.php';

// Archivos subidos de las pruebas: carpeta temporal
if (getenv('TURENTA_UPLOADS') === false) {
    $tmp = sys_get_temp_dir() . '/turenta_pruebas_' . getmypid();
    putenv("TURENTA_UPLOADS=$tmp");
}

// IA y correo de Tukán: las pruebas NUNCA leen /etc/tukan-ia.env ni /etc/tukan-correo.env (no gastan ni mandan correos de verdad)
if (getenv('TUKAN_IA_ENV') === false) putenv('TUKAN_IA_ENV=' . sys_get_temp_dir() . '/turenta_pruebas_sin_ia.env');
if (getenv('TUKAN_CORREO_ENV') === false) putenv('TUKAN_CORREO_ENV=' . sys_get_temp_dir() . '/turenta_pruebas_sin_correo.env');

$GLOBALS['ok'] = 0;
$GLOBALS['fallos'] = [];

function check(string $nombre, mixed $esperado, mixed $real): void
{
    $igual = is_float($esperado) || is_float($real)
        ? abs((float) $esperado - (float) $real) < 0.001
        : $esperado === $real;
    if ($igual) {
        $GLOBALS['ok']++;
        echo "  OK    $nombre\n";
    } else {
        $GLOBALS['fallos'][] = $nombre;
        echo "  FALLA $nombre — esperado " . var_export($esperado, true) . ', salió ' . var_export($real, true) . "\n";
    }
}

/** Verifica que la función lance un error cuyo mensaje contenga $texto. */
function check_error(string $nombre, string $texto, callable $f): void
{
    try {
        $f();
        $GLOBALS['fallos'][] = $nombre;
        echo "  FALLA $nombre — esperaba un error con [$texto] y no hubo error\n";
    } catch (\Throwable $e) {
        if (str_contains($e->getMessage(), $texto)) {
            $GLOBALS['ok']++;
            echo "  OK    $nombre\n";
        } else {
            $GLOBALS['fallos'][] = $nombre;
            echo "  FALLA $nombre — el error fue [" . $e->getMessage() . "]\n";
        }
    }
}

function titulo(string $t): void
{
    echo "\n$t\n";
}

function db_prueba(): PDO
{
    static $db = null;
    if ($db) return $db;
    if (!str_contains((string) getenv('TURENTA_DB_NAME'), 'prueba')) {
        exit("Por seguridad, las pruebas solo corren en una base cuyo nombre contenga 'prueba'.\n");
    }
    $db = \TuRenta\Db::conectar();
    return $db;
}

/** Deja la base vacía con todas las tablas. */
function base_limpia(): PDO
{
    $db = db_prueba();
    $db->exec('DROP SCHEMA public CASCADE; CREATE SCHEMA public;');
    $db->exec(file_get_contents(RAIZ . '/db/schema.sql'));
    foreach (glob(RAIZ . '/db/migraciones/*.sql') ?: [] as $m) {
        $db->exec(file_get_contents($m));
    }
    return $db;
}

/**
 * Emula los planes de ANTES de la migración 112 (para las pruebas escritas con ese modelo): Esencial $19 (solo la base, sin
 * módulos a elegir), Salón $39 (2 espacios, 3 usuarios, con sus módulos) y Quinta $69 (todo + el Asistente IA).
 * Los planes de $20 y $45 de verdad (Básico y Completo) se prueban en tests/logica_112_planes_20_45.php.
 */
/**
 * (6 nov 2026) La instalación nueva trae los precios de $12 y $25 (migración 113: cada módulo $5, IA $3). Las pruebas escritas
 * antes hacen sus cuentas con los precios del catálogo (src/Modulos.php): esto los vuelve a poner. Los de $12 y $25: logica_113.
 */
function precios_de_antes(PDO $db): void
{
    $st = $db->prepare('UPDATE modulos SET precio_mensual = ? WHERE id = ?');
    foreach (\TuRenta\Modulos::CATALOGO as $id => $m) $st->execute([$m[3], $id]);
    $db->exec("UPDATE modulos SET precio_mensual = 5.00 WHERE id = 'asistente_ia'");
}

/** Los planes de $20 y $45 (migración 112, antes de la 113) con los precios de antes de cada módulo. */
function planes_20_45(PDO $db): void
{
    precios_de_antes($db);
    $db->exec("UPDATE planes SET precio_mensual = 20.00, modulos_elegir = 3, precio_modulo_extra = 5.00 WHERE codigo = 'esencial';
               UPDATE planes SET precio_mensual = 45.00 WHERE codigo = 'quinta'");
}

function planes_de_antes(PDO $db): void
{
    precios_de_antes($db);
    $db->exec("UPDATE planes SET nombre = 'Esencial', precio_mensual = 19, modulos = '', modulos_elegir = 0, precio_modulo_extra = NULL,
                                 max_espacios = 1, max_usuarios = 2 WHERE codigo = 'esencial';
               INSERT INTO planes (codigo, nombre, precio_mensual, max_espacios, max_usuarios, descripcion, modulos)
               VALUES ('salon', 'Salón', 39.00, 2, 3, 'Para salones de recepciones (plan de antes de la migración 112).',
                       'paquetes,contratos,portal_cliente,cobros_linea,cuestionarios,proveedores,incidencias,metas,telegram,exportar')
               ON CONFLICT (codigo) DO NOTHING;
               UPDATE planes SET nombre = 'Quinta', precio_mensual = 69 WHERE codigo = 'quinta';");
}

/** Crea un negocio de prueba (plan quinta por defecto) con sus datos de ejemplo. Devuelve [negocio_id, usuario (array)]. */
function negocio_prueba(PDO $db, string $plan = 'quinta', string $nombre = 'Quinta Los Arrayanes'): array
{
    $alta = (new \TuRenta\Altas($db))->crearNegocioConDueno($nombre, 'Dimitry Prueba', '0990000000',
        'dueno' . bin2hex(random_bytes(3)) . '@prueba.ec', 'claveSegura1', $plan);
    $u = $db->query('SELECT * FROM usuarios WHERE id = ' . (int) $alta['usuario_id'])->fetch();
    $db->prepare("UPDATE negocios SET razon_social = 'Arrayanes Eventos S.A.', ruc = '1790000000001', direccion = 'Vía a Tumbaco km 5', horas_montaje = 3, horas_desmontaje = 1 WHERE id = ?")
       ->execute([$alta['negocio_id']]);
    return [$alta['negocio_id'], $u];
}

function espacio_id(PDO $db, int $negocioId, string $nombre): int
{
    $st = $db->prepare('SELECT id FROM espacios WHERE negocio_id = ? AND nombre = ?');
    $st->execute([$negocioId, $nombre]);
    return (int) $st->fetchColumn();
}

/** PNG de una firma dibujada (para probar la firma del contrato). */
function firma_png(bool $vacia = false): string
{
    $img = imagecreatetruecolor(400, 150);
    imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
    if (!$vacia) {
        $negro = imagecolorallocate($img, 10, 10, 40);
        imagesetthickness($img, 4);
        for ($i = 0; $i < 6; $i++) imageline($img, 20 + $i * 50, 120 - ($i % 2) * 80, 70 + $i * 50, 40 + ($i % 2) * 80, $negro);
    }
    ob_start();
    imagepng($img);
    imagedestroy($img);
    return 'data:image/png;base64,' . base64_encode((string) ob_get_clean());
}

/** Archivo de foto simulado para subir (como $_FILES). */
function foto_subida(): array
{
    $ruta = tempnam(sys_get_temp_dir(), 'foto') . '.jpg';
    $img = imagecreatetruecolor(300, 200);
    imagefill($img, 0, 0, imagecolorallocate($img, 200, 220, 240));
    imagejpeg($img, $ruta);
    imagedestroy($img);
    return ['name' => 'comprobante.jpg', 'type' => 'image/jpeg', 'tmp_name' => $ruta, 'error' => UPLOAD_ERR_OK, 'size' => filesize($ruta)];
}
