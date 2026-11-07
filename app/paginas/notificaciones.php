<?php
use TuRenta\Notificaciones;

/** Avisos del usuario (campana): pagos, firmas, consultas nuevas y avisos automáticos. */
$avisos = new Notificaciones(db());
if (es_post()) {
    verificar_csrf();
    if (($_GET['accion'] ?? '') === 'leidas') {
        $avisos->marcarLeidas((int) $u['id']);
        aviso('Listo: todos los avisos quedaron como leídos.');
    }
    redirigir('notificaciones');
}
$lista = $avisos->recientes((int) $u['id'], 100);
$sinLeer = count(array_filter($lista, fn($n) => !$n['leida']));
vista('notificaciones', compact('lista', 'sinLeer'), 'Avisos · TuRenta');
