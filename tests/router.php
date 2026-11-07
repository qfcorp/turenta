<?php
// Solo para pruebas con "php -S": imita a nginx (archivo real si existe; si no, index.php).
$ruta = __DIR__ . '/../public' . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($ruta !== __DIR__ . '/../public/' && is_file($ruta)) return false;
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/../public/index.php';
