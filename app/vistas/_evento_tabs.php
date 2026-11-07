<?php
/**
 * Cabecera y pestañas de un evento. Se incluye al inicio de cada pantalla del evento:
 *   require RAIZ . '/app/vistas/_evento_tabs.php';   con $ev (evento de Eventos::obtener) y $tab ('evento','cotizacion',...)
 */
$pestanas = [
    'evento'       => 'Resumen',
    'cotizacion'   => 'Cotización',
    'contrato'     => 'Contrato',
    'evento_pagos' => 'Pagos',
    'evento_dia'   => 'Día del evento',
    'invitados'    => 'Invitados',
];
if ($u['rol'] === 'coordinador') unset($pestanas['cotizacion'], $pestanas['contrato'], $pestanas['evento_pagos']);
$pestanas['evento_formulario'] = $u['rol'] === 'coordinador' ? 'Formulario' : 'Formulario y extras';   // portal del cliente (módulo 070)
$pestanas['plano'] = 'Plano';      // plano del salón (módulo 080)
// Anexo B: las pestañas de un módulo apagado no salen (sus datos siguen guardados)
foreach (array_keys($pestanas) as $r) if (!modulo_activo($u, \TuRenta\Modulos::RUTAS[$r] ?? '')) unset($pestanas[$r]);
?>
<div class="evento-cabeza">
    <div>
        <p class="evento-sub" style="margin:0"><a href="<?= e(url('eventos')) ?>">Eventos</a> · <?= e($ev['codigo']) ?></p>
        <h1><?= e($ev['nombre']) ?></h1>
        <p class="evento-sub"><?= e(fecha_corta($ev['fecha'])) ?> · <?= e(hora_corta($ev['hora_inicio'])) ?> a <?= e(hora_corta($ev['hora_fin'])) ?>
            · <?= e($ev['espacios_texto']) ?> · <?= (int) $ev['invitados'] ?> invitados</p>
    </div>
    <span class="ev-estado ev-<?= e($ev['estado']) ?>" title="<?= e(\TuRenta\Eventos::ESTADOS_AYUDA[$ev['estado']]) ?>"><?= e(\TuRenta\Eventos::ESTADOS[$ev['estado']]) ?></span>
</div>
<nav class="pestanas" aria-label="Secciones del evento">
    <?php foreach ($pestanas as $r => $txt): ?>
        <a href="<?= e(url($r, ['id' => $ev['id']])) ?>" class="<?= $tab === $r ? 'activa' : '' ?>" <?= $tab === $r ? 'aria-current="page"' : '' ?>>
            <?= e($txt) ?>
        </a>
    <?php endforeach; ?>
</nav>
