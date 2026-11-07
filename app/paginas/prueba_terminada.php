<?php
/** Prueba vencida, plan vencido o cuenta suspendida: los datos se guardan y se muestra cómo pagar. */
if (planes()->alDia($u)) {
    redirigir('inicio');
}
$estadoCuenta = planes()->estadoCuenta($u);
$st = db()->prepare("SELECT (SELECT count(*) FROM eventos WHERE negocio_id = ?) AS eventos,
                            (SELECT count(*) FROM eventos WHERE negocio_id = ? AND estado IN ('separado','confirmado') AND fecha >= CURRENT_DATE) AS proximos,
                            (SELECT count(*) FROM clientes WHERE negocio_id = ?) AS clientes");
$st->execute([$u['negocio_id'], $u['negocio_id'], $u['negocio_id']]);
$guardado = $st->fetch();
vista('prueba_terminada', compact('estadoCuenta', 'guardado'), 'Tu cuenta · TuRenta');
