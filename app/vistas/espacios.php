<?php
/** @var array $espacios @var ?array $form @var array $cerradas @var ?int $max @var bool $puedeAgregar @var bool $puedeEditar */
$colores = ['#6B2D3C' => 'Vino', '#3E7C59' => 'Verde', '#22457A' => 'Azul', '#B8862B' => 'Dorado', '#7A3E8C' => 'Morado', '#B53D33' => 'Rojo', '#2E7C80' => 'Turquesa', '#5B5054' => 'Gris'];
$activosLista = array_filter($espacios, fn($e) => $e['activo']);
?>
<div class="cabecera">
    <div>
        <h1>Espacios</h1>
        <p>Salones, jardines, capillas, terrazas… Cada uno se reserva por separado: nunca se vende dos veces el mismo a la misma hora.</p>
    </div>
    <?php if ($puedeEditar && !$form): ?>
        <?php if ($puedeAgregar): ?>
            <a class="boton" href="<?= e(url('espacios', ['nuevo' => 1])) ?>">Nuevo espacio</a>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($max !== null): ?>
    <p class="<?= !$puedeAgregar ? 'aviso aviso-alerta' : 'suave' ?>">
        Tu plan <?= e($u['plan_nombre'] ?? '') ?> permite <?= $max ?> <?= $max === 1 ? 'espacio activo' : 'espacios activos' ?> (tienes <?= $activos ?>).
        <?php if (!$puedeAgregar && en_app_android()): /* app de Google Play: sin invitar a cambiar de plan */ ?>Para agregar más, desactiva uno.
        <?php elseif (!$puedeAgregar): ?>Para agregar más, desactiva uno o cambia de plan (el plan <strong>Completo</strong> no tiene límite de espacios).
            <?php if (es_admin_total($u)): ?><a href="<?= e(url('planes')) ?>">Ver Mi plan</a><?php endif; ?>
        <?php endif; ?>
    </p>
<?php endif; ?>

<?php if ($form): $esNuevo = empty($form['id']); ?>
<section class="bloque esp-form" aria-labelledby="t-form">
    <h2 id="t-form"><?= $esNuevo ? 'Nuevo espacio' : 'Editar ' . e($form['nombre']) ?></h2>
    <?php if ($error): ?><p class="aviso aviso-error" role="alert"><?= e($error) ?></p><?php endif; ?>
    <form method="post" action="<?= e(url('espacios', ['accion' => 'guardar'])) ?>" enctype="multipart/form-data" novalidate>
        <?= campo_csrf() ?>
        <input type="hidden" name="id" value="<?= e((string) $form['id']) ?>">
        <div class="rejilla-form">
            <div class="campo">
                <label for="nombre">Nombre</label>
                <input type="text" id="nombre" name="nombre" value="<?= e($form['nombre']) ?>" required maxlength="80" placeholder="Ej. Salón Cristal, Jardín de los Arrayanes">
            </div>
            <div class="campo">
                <label for="precio_base">Alquiler del espacio <small>(precio base, opcional)</small></label>
                <input type="text" id="precio_base" name="precio_base" inputmode="decimal"
                       value="<?= e(($form['precio_base'] ?? '') !== '' && (float) $form['precio_base'] > 0 ? rtrim(rtrim(number_format((float) $form['precio_base'], 2, '.', ''), '0'), '.') : '') ?>" placeholder="Ej. 1500">
            </div>
            <div class="campo">
                <label for="capacidad_banquete">Capacidad sentados <small>(banquete)</small></label>
                <input type="number" id="capacidad_banquete" name="capacidad_banquete" min="0" max="20000" value="<?= e((string) $form['capacidad_banquete']) ?>">
            </div>
            <div class="campo">
                <label for="capacidad_coctel">Capacidad de pie <small>(cóctel)</small></label>
                <input type="number" id="capacidad_coctel" name="capacidad_coctel" min="0" max="20000" value="<?= e((string) $form['capacidad_coctel']) ?>">
            </div>
            <div class="campo ancho">
                <label for="descripcion">Descripción <small>(sale en tu página pública)</small></label>
                <textarea id="descripcion" name="descripcion" rows="2" maxlength="500" placeholder="Ej. Salón cubierto con pista de baile, tarima y aire acondicionado."><?= e($form['descripcion']) ?></textarea>
            </div>
            <fieldset class="campo">
                <legend>Color en el calendario</legend>
                <div class="colores">
                    <?php $actual = strtoupper((string) $form['color']); $hay = isset($colores[$actual]);
                    foreach ($colores as $hex => $nom): ?>
                        <label class="color-op" title="<?= e($nom) ?>">
                            <input type="radio" name="color" value="<?= e($hex) ?>" <?= $actual === $hex ? 'checked' : '' ?>>
                            <span style="background:<?= e($hex) ?>"></span><span class="sr-only"><?= e($nom) ?></span>
                        </label>
                    <?php endforeach; ?>
                    <?php if (!$hay && preg_match('/^#[0-9A-F]{6}$/', $actual)): ?>
                        <label class="color-op" title="Actual"><input type="radio" name="color" value="<?= e($actual) ?>" checked><span style="background:<?= e($actual) ?>"></span><span class="sr-only">Color actual</span></label>
                    <?php endif; ?>
                </div>
            </fieldset>
            <div class="campo">
                <label for="orden">Orden <small>(1 = primero)</small></label>
                <input type="number" id="orden" name="orden" min="0" max="999" value="<?= e((string) $form['orden']) ?>">
            </div>
            <div class="campo ancho">
                <label for="foto">Foto <small>(JPG o PNG, se ve en tu página pública)</small></label>
                <?php if (!$esNuevo && !empty($form['foto'])): ?>
                    <img class="esp-foto-actual" src="<?= e(url('archivo', ['tipo' => 'espacio', 'id' => $form['id']])) ?>" alt="Foto actual de <?= e($form['nombre']) ?>">
                <?php endif; ?>
                <input type="file" id="foto" name="foto" accept="image/jpeg,image/png,image/webp">
            </div>
        </div>
        <div class="acciones">
            <button class="boton" type="submit"><?= $esNuevo ? 'Crear espacio' : 'Guardar' ?></button>
            <a class="boton boton-claro" href="<?= e(url('espacios')) ?>">Cancelar</a>
        </div>
    </form>
    <?php if (!$esNuevo && !empty($form['foto'])): ?>
        <form method="post" action="<?= e(url('espacios', ['accion' => 'quitar_foto'])) ?>" style="margin-top:10px">
            <?= campo_csrf() ?><input type="hidden" name="id" value="<?= (int) $form['id'] ?>">
            <button class="boton boton-peligro boton-chico" type="submit" data-confirmar="¿Quitar la foto de este espacio?">Quitar foto</button>
        </form>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if (!$espacios): ?>
    <section class="bloque vacio">
        <p><strong>Todavía no tienes espacios.</strong></p>
        <p>Crea al menos uno (por ejemplo "Salón principal") para empezar a registrar eventos.</p>
        <?php if ($puedeEditar): ?><a class="boton" href="<?= e(url('espacios', ['nuevo' => 1])) ?>">Crear el primer espacio</a><?php endif; ?>
    </section>
<?php else: ?>
<div class="esp-lista">
    <?php foreach ($espacios as $es): ?>
        <article class="bloque esp-tarjeta <?= !$es['activo'] ? 'inactivo' : '' ?>" style="--color-esp: <?= e($es['color']) ?>">
            <?php if ($es['foto']): ?>
                <img src="<?= e(url('archivo', ['tipo' => 'espacio', 'id' => $es['id']])) ?>" alt="<?= e($es['nombre']) ?>" loading="lazy">
            <?php else: ?>
                <div class="esp-sin-foto" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($es['nombre'], 0, 1))) ?></div>
            <?php endif; ?>
            <div class="esp-cuerpo">
                <h3><?= e($es['nombre']) ?> <?= !$es['activo'] ? '<span class="etq etq-reservada">Desactivado</span>' : '' ?></h3>
                <p class="suave"><?= $es['capacidad_banquete'] ? (int) $es['capacidad_banquete'] . ' sentados' : 'Sin capacidad escrita' ?><?= $es['capacidad_coctel'] ? ' · ' . (int) $es['capacidad_coctel'] . ' de pie' : '' ?>
                    <?= (float) $es['precio_base'] > 0 ? ' · ' . dinero($es['precio_base']) : '' ?></p>
                <?php if ($es['descripcion']): ?><p class="esp-desc"><?= e($es['descripcion']) ?></p><?php endif; ?>
                <p class="suave ayuda-chica"><?= (int) $es['eventos_futuros'] ?> <?= (int) $es['eventos_futuros'] === 1 ? 'evento próximo' : 'eventos próximos' ?></p>
                <?php if ($es['activo'] && modulo_activo($u, 'plano')): ?><p class="ayuda-chica"><a href="<?= e(url('plano', ['espacio' => $es['id']])) ?>#esp-<?= (int) $es['id'] ?>">Plano y distribuciones</a></p><?php endif; ?>
                <?php if ($puedeEditar): ?>
                <div class="acciones">
                    <a class="boton boton-claro boton-chico" href="<?= e(url('espacios', ['editar' => $es['id']])) ?>">Editar</a>
                    <form method="post" action="<?= e(url('espacios', ['accion' => $es['activo'] ? 'desactivar' : 'activar'])) ?>">
                        <?= campo_csrf() ?><input type="hidden" name="id" value="<?= (int) $es['id'] ?>">
                        <button class="boton <?= $es['activo'] ? 'boton-peligro' : 'boton-claro' ?> boton-chico" type="submit"
                            <?= $es['activo'] ? 'data-confirmar="¿Desactivar ' . e($es['nombre']) . '? Ya no se podrá elegir para eventos nuevos ni saldrá en tu página. Los eventos que ya tiene se mantienen."' : '' ?>>
                            <?= $es['activo'] ? 'Desactivar' : 'Activar' ?></button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </article>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<section class="bloque" id="cerradas" aria-labelledby="t-cerradas">
    <h2 id="t-cerradas">Fechas cerradas</h2>
    <p class="suave">Días que no alquilas (mantenimiento, feriado propio, uso familiar). No se pueden separar y en tu página salen como ocupados.</p>
    <?php if ($puedeEditar && $activosLista): ?>
    <form method="post" action="<?= e(url('espacios', ['accion' => 'cerrar'])) ?>" class="form-en-linea form-cerrar">
        <?= campo_csrf() ?>
        <div class="campo">
            <label for="cerrar-fecha">Fecha</label>
            <input type="date" id="cerrar-fecha" name="fecha" min="<?= e($hoyTxt) ?>" required>
        </div>
        <div class="campo">
            <label for="cerrar-espacio">¿Qué se cierra?</label>
            <select id="cerrar-espacio" name="espacio_id">
                <option value="">Todo el local</option>
                <?php foreach ($activosLista as $es): ?><option value="<?= (int) $es['id'] ?>">Solo <?= e($es['nombre']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="campo">
            <label for="cerrar-motivo">Motivo <small>(opcional)</small></label>
            <input type="text" id="cerrar-motivo" name="motivo" maxlength="150" placeholder="Ej. Mantenimiento del jardín">
        </div>
        <button class="boton boton-claro" type="submit">Cerrar fecha</button>
    </form>
    <?php endif; ?>
    <?php if (!$cerradas): ?>
        <p class="vacio">No tienes fechas cerradas.</p>
    <?php else: ?>
    <ul class="filas">
        <?php foreach ($cerradas as $c): ?>
            <li>
                <span><strong><?= e(fecha_corta($c['fecha'])) ?></strong> · <?= e($c['espacio'] ?? 'Todo el local') ?>
                    <?= $c['motivo'] ? '<small class="suave" style="display:block">' . e($c['motivo']) . '</small>' : '' ?></span>
                <?php if ($puedeEditar): ?>
                <form method="post" action="<?= e(url('espacios', ['accion' => 'abrir'])) ?>" class="derecha">
                    <?= campo_csrf() ?><input type="hidden" name="cerrada_id" value="<?= (int) $c['id'] ?>">
                    <button class="boton boton-peligro boton-chico" type="submit" data-confirmar="¿Abrir otra vez el <?= e(fecha_corta($c['fecha'])) ?>? Se podrá vender.">Abrir</button>
                </form>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</section>
