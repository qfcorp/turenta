<?php
/** Modo puerta. @var array $ev @var array $negocio @var string $token @var string $q @var ?array $resultado @var array $cont @var array $lista @var array $ultimos @var bool $esHoy */
$fila = function (array $x) use ($token, $q): string {
    $faltan = (int) $x['pases'] - (int) $x['ingresaron'];
    ob_start(); ?>
    <li class="dia-puerta-fila <?= $faltan <= 0 ? 'dia-ya-entro' : '' ?>">
        <div class="dia-puerta-nombre">
            <strong><?= e($x['nombre']) ?></strong>
            <small><?= $x['mesa'] ? e($x['mesa']) . ' · ' : '' ?><?= (int) $x['pases'] ?> <?= (int) $x['pases'] === 1 ? 'pase' : 'pases' ?>
                <?= $x['estado'] === 'no_asiste' ? ' · <span class="dia-alerta-texto">dijo que no venía</span>' : ($x['estado'] === 'pendiente' ? ' · no confirmó' : '') ?>
                <?= (int) $x['ingresaron'] ? ' · entraron ' . (int) $x['ingresaron'] : '' ?></small>
        </div>
        <?php if ($faltan > 0): ?>
        <form method="post" class="dia-puerta-entrar">
            <?= campo_csrf() ?><input type="hidden" name="invitado_id" value="<?= (int) $x['id'] ?>"><input type="hidden" name="q" value="<?= e($q) ?>">
            <?php if ($faltan > 1): ?>
                <label class="sr-only" for="c<?= (int) $x['id'] ?>">Cuántos entran</label>
                <select id="c<?= (int) $x['id'] ?>" name="cuantos"><?php for ($k = $faltan; $k >= 1; $k--): ?><option value="<?= $k ?>"><?= $k ?></option><?php endfor; ?></select>
            <?php else: ?><input type="hidden" name="cuantos" value="1"><?php endif; ?>
            <button class="boton dia-boton-entra" type="submit">Entra</button>
        </form>
        <?php else: ?>
            <span class="dia-check" aria-label="Ya entró completo">✓</span>
        <?php endif; ?>
    </li>
    <?php return ob_get_clean();
};
?>
<div class="dia-puerta" data-dia-puerta="<?= e(url('puerta', ['t' => $token, 'json' => 1])) ?>">
    <header class="dia-puerta-cabeza">
        <div>
            <p class="suave" style="margin:0">Puerta · <?= e($negocio['nombre']) ?></p>
            <h1><?= e($ev['nombre']) ?></h1>
        </div>
        <div class="dia-contador" aria-live="polite">
            <strong data-dia-ingresaron><?= (int) $cont['ingresaron'] ?></strong><span>/ <span data-dia-esperados><?= (int) $cont['esperados'] ?></span></span>
            <small>ingresaron</small>
        </div>
    </header>
    <?php if (!$esHoy): ?><p class="aviso dia-aviso-amarillo">El evento es el <?= e(fecha_larga($ev['fecha'])) ?>. Puedes probar el modo puerta, pero lo que marques cuenta como ingreso.</p><?php endif; ?>

    <?php if ($resultado): ?>
    <div class="dia-resultado <?= $resultado['ok'] ? 'dia-verde' : 'dia-rojo' ?>" role="alert">
        <p class="dia-resultado-icono" aria-hidden="true"><?= $resultado['ok'] ? '✓' : '!' ?></p>
        <p class="dia-resultado-nombre"><?= e($resultado['nombre']) ?></p>
        <p><?= $resultado['ok'] ? 'Entraron ' . (int) $resultado['entraron'] . ' · lleva ' . (int) $resultado['ingresaron'] . ' de ' . (int) $resultado['pases'] : e($resultado['mensaje']) ?></p>
        <?php if ($resultado['mesa']): ?><p class="dia-resultado-mesa"><?= e($resultado['mesa']) ?></p><?php endif; ?>
        <?php if ($resultado['ok']): ?>
        <form method="post" class="dia-en-linea"><?= campo_csrf() ?><input type="hidden" name="accion" value="deshacer"><input type="hidden" name="invitado_id" value="<?= (int) $resultado['id'] ?>">
            <button class="boton-enlace" type="submit">Me equivoqué: restar 1</button></form>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <form method="get" class="dia-puerta-buscar" role="search" data-dia-buscar>
        <input type="hidden" name="r" value="puerta"><input type="hidden" name="t" value="<?= e($token) ?>">
        <label for="q" class="sr-only">Buscar invitado</label>
        <input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="Escribe el nombre…" autocomplete="off" autofocus>
        <button class="boton" type="submit">Buscar</button>
    </form>
    <p class="suave dia-puerta-ayuda">También puedes escanear el código QR de la invitación con la cámara de este celular.</p>

    <ul class="dia-puerta-lista" data-dia-resultados>
        <?php if ($q !== '' && !$lista): ?><li class="vacio">No hay nadie con ese nombre en la lista. Revisa cómo se escribe o busca por el apellido.</li><?php endif; ?>
        <?php foreach ($lista as $x) echo $fila($x); ?>
    </ul>
    <template data-dia-plantilla>
        <?= $fila(['id' => 0, 'nombre' => '', 'mesa' => '', 'pases' => 2, 'ingresaron' => 0, 'estado' => 'confirmado']) ?>
    </template>

    <?php if ($ultimos && $q === ''): ?>
    <section class="dia-ultimos">
        <h2>Últimos que entraron</h2>
        <ul class="filas">
            <?php foreach ($ultimos as $x): ?>
            <li><div><strong><?= e($x['nombre']) ?></strong><br><small><?= $x['mesa'] ? e($x['mesa']) . ' · ' : '' ?><?= (int) $x['ingresaron'] ?> de <?= (int) $x['pases'] ?></small></div>
                <small class="derecha"><?= e(substr((string) $x['ingreso_en'], 11, 5)) ?></small></li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>
</div>
