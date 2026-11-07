<?php
use TuRenta\Fotos;

/** Imágenes que se ven en la página pública: /?r=imagen_publica&tipo=logo&s=<slug>  o  &tipo=espacio&id=<id> */
$tipo = (string) ($_GET['tipo'] ?? '');
$ruta = null;
if ($tipo === 'logo') {
    $st = db()->prepare('SELECT logo FROM negocios WHERE slug = ?');
    $st->execute([(string) ($_GET['s'] ?? '')]);
    $ruta = Fotos::ruta('logos', (string) $st->fetchColumn());
} elseif ($tipo === 'espacio') {
    $st = db()->prepare('SELECT foto FROM espacios WHERE id = ? AND activo');
    $st->execute([(int) ($_GET['id'] ?? 0)]);
    $ruta = Fotos::ruta('espacios', (string) $st->fetchColumn());
}
if ($ruta === null) {
    http_response_code(404);
    exit;
}
Fotos::enviar($ruta, false);
