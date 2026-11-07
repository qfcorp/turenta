<?php
declare(strict_types=1);
/**
 * Pruebas de pantallas: crea una cuenta en demo como lo hace Tukán (ya no hay registro abierto con periodo de prueba).
 * Uso: php tests/crear_cuenta.php <plan> <nombre del negocio> <correo>   → imprime el id del negocio. Clave: claveSegura1
 */
require __DIR__ . '/base.php';
if (!str_contains((string) getenv('TURENTA_DB_NAME'), 'prueba') && !str_contains((string) getenv('TURENTA_DB_NAME'), '_w')) exit("Solo en bases de prueba.\n");
[$_, $plan, $nombre, $email] = $argv + [null, 'quinta', 'Quinta de Prueba', 'dueno@prueba.ec'];
$db = \TuRenta\Db::conectar();
$a = (new \TuRenta\Altas($db))->crearNegocioConDueno($nombre, 'Dimitry Prueba', '0990000000', $email, 'claveSegura1', $plan, 'Quito', 'super');
echo $a['negocio_id'];
