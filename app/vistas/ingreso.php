<?php
/** Resultado del escaneo en la puerta. @var string $estado (invalido|mostrar|verde|rojo|cuantos) @var ?array $ev @var ?array $i */
?>
<?php if ($estado === 'invalido'): ?>
    <div class="dia-resultado dia-rojo dia-pantalla" role="alert">
        <p class="dia-resultado-icono" aria-hidden="true">✕</p>
        <p class="dia-resultado-nombre">Código no válido</p>
        <p>Este código no es de ningún invitado. Busca a la persona por su nombre en el modo puerta.</p>
    </div>

<?php elseif ($estado === 'mostrar'): ?>
    <section class="bloque dia-inv-respuesta dia-inv-ok">
        <p class="suave" style="margin:0"><?= e($ev['nombre']) ?></p>
        <h1><?= e($i['nombre']) ?></h1>
        <p><strong>Muestra este código en la entrada.</strong></p>
        <div class="dia-qr"><?= $qrSvg ?></div>
        <p class="suave"><?= (int) $i['pases'] ?> <?= (int) $i['pases'] === 1 ? 'pase' : 'pases' ?><?= $i['mesa'] ? ' · ' . e($i['mesa']) : '' ?></p>
        <a class="boton boton-claro boton-chico" href="<?= e(url('invitacion', ['t' => $i['token']])) ?>">Ver mi invitación</a>
    </section>

<?php elseif ($estado === 'cuantos'): ?>
    <div class="dia-resultado dia-verde dia-pantalla">
        <p class="dia-resultado-nombre"><?= e($i['nombre']) ?></p>
        <?php if ($i['mesa']): ?><p class="dia-resultado-mesa"><?= e($i['mesa']) ?></p><?php endif; ?>
        <p>Tiene <?= (int) $i['pases'] ?> pases<?= (int) $i['ingresaron'] ? ' · ya entraron ' . (int) $i['ingresaron'] : '' ?>. ¿Cuántos entran ahora?</p>
        <form method="post" class="dia-cuantos-entran"><?= campo_csrf() ?>
            <?php for ($k = $faltan; $k >= 1; $k--): ?>
                <button class="boton dia-boton-grande" type="submit" name="cuantos" value="<?= $k ?>"><?= $k ?><?= $k === $faltan ? ' (todos)' : '' ?></button>
            <?php endfor; ?>
        </form>
    </div>
    <p style="text-align:center"><a class="boton boton-claro" href="<?= e($enlacePuerta) ?>">Volver a la puerta</a></p>

<?php else: ?>
    <div class="dia-resultado <?= $estado === 'verde' ? 'dia-verde' : 'dia-rojo' ?> dia-pantalla" role="alert">
        <p class="dia-resultado-icono" aria-hidden="true"><?= $estado === 'verde' ? '✓' : '!' ?></p>
        <p class="dia-resultado-nombre"><?= e($i['nombre']) ?></p>
        <p class="dia-resultado-msg"><?= e($mensaje) ?></p>
        <?php if ($i['mesa']): ?><p class="dia-resultado-mesa"><?= e($i['mesa']) ?></p><?php endif; ?>
        <p><?= (int) $i['ingresaron'] ?> de <?= (int) $i['pases'] ?> <?= (int) $i['pases'] === 1 ? 'pase usado' : 'pases usados' ?></p>
    </div>
    <?php if (!empty($enlacePuerta)): ?><p style="text-align:center"><a class="boton boton-claro" href="<?= e($enlacePuerta) ?>">Volver a la puerta</a></p><?php endif; ?>
<?php endif; ?>
