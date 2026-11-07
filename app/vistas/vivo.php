<?php
/** Cronograma en vivo. @var array $ev @var array $negocio @var array $v @var string $token @var callable $hora */
$act = $v['actual'] !== null ? $v['bloques'][$v['actual']] : null;
$sig = $v['siguiente'] !== null ? $v['bloques'][$v['siguiente']] : null;
$atraso = (int) $v['atraso'];
$claseAtraso = $v['prueba'] ? 'dia-amarillo' : ($atraso > 10 ? 'dia-rojo' : ($atraso > 0 ? 'dia-amarillo' : 'dia-verde'));
$textoAtraso = \TuRenta\DiaEvento::textoAtraso($v);
?>
<div class="dia-vivo" data-dia-vivo="<?= e(url('vivo', ['t' => $token, 'json' => 1])) ?>" data-version="<?= e($v['version']) ?>">
    <header class="dia-vivo-cabeza">
        <div>
            <p class="suave" style="margin:0">En vivo · <?= e(fecha_corta($ev['fecha'])) ?></p>
            <h1><?= e($ev['nombre']) ?></h1>
        </div>
        <p class="dia-reloj" data-dia-reloj><?= date('H:i') ?></p>
    </header>

    <?php if (!$v['bloques']): ?>
        <section class="bloque"><p class="vacio">Todavía no hay cronograma para este evento. Pídele al lugar que lo arme en TuRenta.</p></section>
    <?php else: ?>

    <?php if ($v['empezado'] || $atraso > 0): ?>
        <p class="dia-atraso <?= $claseAtraso ?>"><?= e($textoAtraso) ?></p>
    <?php endif; ?>

    <section class="dia-ahora">
        <p class="dia-etiqueta">Ahora</p>
        <?php if ($act): ?>
            <h2><?= e($act['actividad']) ?></h2>
            <p>Planificado <?= e($hora($act['plan'])) ?> · empezó <strong><?= e(substr((string) $act['inicio_real'], 11, 5)) ?></strong><?= $act['responsable'] ? ' · ' . e($act['responsable']) : '' ?></p>
            <p class="dia-quedan <?= $v['min_restantes'] < 0 ? 'dia-alerta-texto' : '' ?>">
                <?= $v['min_restantes'] >= 0 ? 'Quedan ' . (int) $v['min_restantes'] . ' min' : 'Pasado por ' . abs((int) $v['min_restantes']) . ' min' ?> (de <?= (int) $act['duracion_min'] ?>)</p>
            <?php if ($act['notas']): ?><p class="dia-nota"><?= e($act['notas']) ?></p><?php endif; ?>
        <?php elseif ($v['terminado']): ?>
            <h2>¡Terminado!</h2><p>Todos los momentos del cronograma se cumplieron.</p>
        <?php else: ?>
            <h2>Todavía no empieza</h2><p>Toca el botón cuando empiece el primer momento.</p>
        <?php endif; ?>
    </section>

    <?php if ($sig): ?>
    <section class="dia-sigue">
        <p class="dia-etiqueta">Sigue</p>
        <h2><?= e($sig['actividad']) ?></h2>
        <p>Planificado a las <strong><?= e($hora($sig['plan'])) ?></strong><?= $sig['responsable'] ? ' · ' . e($sig['responsable']) : '' ?></p>
        <?php if ($sig['notas']): ?><p class="dia-nota"><?= e($sig['notas']) ?></p><?php endif; ?>
    </section>
    <?php endif; ?>

    <form method="post" class="dia-vivo-botones">
        <?= campo_csrf() ?>
        <input type="hidden" name="version" value="<?= e($v['version']) ?>">
        <?php if (!$v['terminado']): ?>
        <button class="boton dia-boton-enorme" type="submit" name="accion" value="siguiente">
            <?= $sig ? 'Empezar: ' . e($sig['actividad']) : 'Terminar el evento' ?></button>
        <?php endif; ?>
        <?php if ($v['empezado']): ?>
        <button class="boton boton-claro" type="submit" name="accion" value="deshacer" data-confirmar="¿Deshacer el último paso?">Deshacer</button>
        <?php endif; ?>
    </form>

    <section class="bloque">
        <h2>Todo el cronograma</h2>
        <ol class="dia-crono dia-crono-vivo">
            <?php foreach ($v['bloques'] as $b): ?>
            <li class="dia-crono-item dia-est-<?= e($b['estado']) ?>">
                <div class="dia-crono-hora"><strong><?= e($hora($b['plan'])) ?></strong>
                    <small><?= $b['inicio_real'] ? 'real ' . e(substr((string) $b['inicio_real'], 11, 5)) : (int) $b['duracion_min'] . ' min' ?></small></div>
                <div class="dia-crono-texto"><strong><?= e($b['actividad']) ?></strong>
                    <?php if ($b['atraso'] !== null && $b['atraso'] !== 0 && !$v['prueba']): ?><span class="etq <?= $b['atraso'] > 0 ? 'etq-pendiente' : 'etq-confirmada' ?>"><?= $b['atraso'] > 0 ? '+' : '' ?><?= (int) $b['atraso'] ?> min</span><?php endif; ?>
                    <?php if ($b['responsable']): ?><br><small><?= e($b['responsable']) ?></small><?php endif; ?></div>
            </li>
            <?php endforeach; ?>
        </ol>
    </section>
    <?php endif; ?>
    <p class="suave dia-vivo-pie" data-dia-estado>Se actualiza solo cada 20 segundos.</p>
</div>
