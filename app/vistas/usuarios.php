<?php
use TuRenta\{Usuarios, WhatsApp};
/** @var array $lista @var ?array $claveNueva @var ?array $form @var bool $puedeAgregar @var array $plan @var int $activos @var int $admins @var bool $puedeAdmin */
$form ??= ['nombre' => '', 'email' => '', 'telefono' => '', 'rol' => $puedeAgregar ? 'vendedor' : 'admin'];
$puedeCrear = $puedeAgregar || $puedeAdmin;                       // los administradores no cuentan en el plan
$rolDisponible = fn(string $r): bool => $r === 'admin' ? $puedeAdmin : $puedeAgregar;
$nombreRol = fn(string $r): string => $r === 'admin' ? Usuarios::OPCION_ADMIN : Usuarios::ROLES[$r];
?>
<div class="cabecera">
    <div><h1>Usuarios</h1>
        <p>Las personas de tu equipo que entran a TuRenta, cada una con su correo y su contraseña.</p></div>
</div>

<?php if ($claveNueva):
    $msg = 'Hola ' . explode(' ', trim($claveNueva['nombre']))[0] . ', ya tienes acceso a TuRenta de ' . $u['negocio'] . ".\n"
         . 'Entra aquí: ' . url_completa('login') . "\nCorreo: " . $claveNueva['email'] . "\nContraseña temporal: " . $claveNueva['clave']
         . "\nCuando entres, cámbiala en Mi cuenta.";
    $wa = WhatsApp::enlace($claveNueva['telefono'] ?? '', $msg); ?>
<section class="bloque g-clave-nueva" role="status">
    <h2><?= $claveNueva['nuevo'] ? 'Acceso de ' . e($claveNueva['nombre']) : 'Contraseña nueva de ' . e($claveNueva['nombre']) ?></h2>
    <p>Correo: <strong><?= e($claveNueva['email']) ?></strong><br>Contraseña temporal: <strong class="clave-temporal"><?= e($claveNueva['clave']) ?></strong></p>
    <p class="suave">Se muestra <strong>solo esta vez</strong>. Envíasela ahora; cuando entre, el sistema le pedirá cambiarla.</p>
    <div class="acciones">
        <?php if ($wa): ?><a class="boton boton-wa" href="<?= e($wa) ?>" target="_blank" rel="noopener">Enviar por WhatsApp</a><?php endif; ?>
        <button class="boton boton-claro" type="button" data-copiar="<?= e($msg) ?>">Copiar el mensaje</button>
    </div>
</section>
<?php endif; ?>

<div class="rejilla rejilla-2">
    <section class="bloque">
        <div class="g-bloque-cab"><h2>Tu equipo</h2>
            <span class="g-limite suave"><?= $activos ?> <?= $plan['max_usuarios'] ? 'de ' . (int) $plan['max_usuarios'] : '' ?> activos del plan
                · <span class="g-admins">Administradores: <?= (int) $admins ?> de <?= Usuarios::MAX_ADMINS ?></span></span></div>
        <p class="g-nota">El dueño y hasta <?= Usuarios::MAX_ADMINS ?> administradores manejan todo. Los administradores no cuentan en el límite de usuarios de tu plan.</p>
        <?php foreach ($lista as $x):
            $soyYo = (int) $x['id'] === (int) $u['id']; ?>
            <div class="g-usuario <?= $x['activo'] ? '' : 'inactivo' ?>">
                <div class="g-usuario-cab">
                    <div>
                        <strong><?= e($x['nombre']) ?></strong><?= $soyYo ? ' <small>(tú)</small>' : '' ?>
                        <div class="g-fila-sub"><?= e($x['email']) ?><?= $x['telefono'] ? ' · ' . e($x['telefono']) : '' ?></div>
                        <div class="g-fila-sub">
                            <?= $x['ultimo_ingreso'] ? 'Entró por última vez el ' . e(fecha_corta(substr((string) $x['ultimo_ingreso'], 0, 10), false)) : 'Todavía no ha entrado' ?>
                            <?= $x['telegram'] ? ' · Telegram ✓' : '' ?><?= $x['clave_temporal'] && $x['activo'] ? ' · con contraseña temporal' : '' ?>
                        </div>
                    </div>
                    <span class="g-rol <?= e($x['rol']) ?>"><?= e(Usuarios::ROLES[$x['rol']]) ?><?= $x['activo'] ? '' : ' · desactivado' ?></span>
                </div>
                <?php if (!$soyYo && $x['rol'] !== 'dueno'): ?>
                <div class="g-usuario-acciones">
                    <?php if ($x['activo']): ?>
                        <form method="post" action="<?= e(url('usuarios', ['accion' => 'rol'])) ?>" class="g-usuario-acciones"><?= campo_csrf() ?>
                            <input type="hidden" name="id" value="<?= (int) $x['id'] ?>">
                            <label class="sr-only" for="rol-<?= (int) $x['id'] ?>">Qué hace <?= e($x['nombre']) ?></label>
                            <select id="rol-<?= (int) $x['id'] ?>" name="rol">
                                <?php foreach (Usuarios::ROLES_ASIGNABLES as $r): ?><option value="<?= $r ?>" <?= $x['rol'] === $r ? 'selected' : '' ?>><?= e($nombreRol($r)) ?></option><?php endforeach; ?>
                            </select>
                            <button class="boton boton-claro boton-chico" type="submit">Cambiar</button>
                        </form>
                        <form method="post" action="<?= e(url('usuarios', ['accion' => 'clave'])) ?>"><?= campo_csrf() ?><input type="hidden" name="id" value="<?= (int) $x['id'] ?>">
                            <button class="boton boton-claro boton-chico" type="submit" data-confirmar="¿Crear una contraseña nueva para <?= e($x['nombre']) ?>? La anterior deja de funcionar.">Nueva contraseña</button></form>
                    <?php endif; ?>
                    <form method="post" action="<?= e(url('usuarios', ['accion' => 'activar'])) ?>"><?= campo_csrf() ?>
                        <input type="hidden" name="id" value="<?= (int) $x['id'] ?>"><input type="hidden" name="activo" value="<?= $x['activo'] ? '0' : '1' ?>">
                        <?php if ($x['activo']): ?>
                            <button class="boton boton-peligro boton-chico" type="submit" data-confirmar="¿Desactivar a <?= e($x['nombre']) ?>? Ya no podrá entrar. Lo que hizo se guarda.">Desactivar</button>
                        <?php else: ?>
                            <button class="boton boton-chico" type="submit">Activar</button>
                        <?php endif; ?>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </section>

    <div>
        <form method="post" action="<?= e(url('usuarios', ['accion' => 'crear'])) ?>" class="bloque" novalidate>
            <?= campo_csrf() ?>
            <h2>Agregar a alguien</h2>
            <?php if (!$puedeAgregar): ?>
                <p class="aviso aviso-error">Tu plan <?= e($plan['nombre']) ?> permite <?= (int) $plan['max_usuarios'] ?> usuarios activos y ya los tienes.
                    <?php if (en_app_android()): ?>Desactiva a alguien que ya no lo use.<?php else: ?>Desactiva a alguien que ya no lo use o <a href="<?= e(url('planes')) ?>">cambia de plan en Mi plan</a> (el plan Completo no tiene límite de usuarios).<?php endif; ?>
                    <?= $puedeAdmin ? 'Todavía puedes agregar administradores: no cuentan en el plan.' : '' ?></p>
            <?php endif; ?>
            <?php if (!$puedeAdmin): ?>
                <p class="aviso"><?= e(Usuarios::MENSAJE_MAX_ADMINS) ?> Ya tienes <?= Usuarios::MAX_ADMINS ?>.</p>
            <?php endif; ?>
            <div class="campo"><label for="u-nombre">Nombre</label>
                <input type="text" id="u-nombre" name="nombre" value="<?= e($form['nombre']) ?>" required <?= $puedeCrear ? '' : 'disabled' ?>></div>
            <div class="fila-campos">
                <div class="campo"><label for="u-email">Correo <small>(con este entra)</small></label>
                    <input type="email" id="u-email" name="email" value="<?= e($form['email']) ?>" required <?= $puedeCrear ? '' : 'disabled' ?>></div>
                <div class="campo"><label for="u-telefono">Celular <small>(para enviarle el acceso)</small></label>
                    <input type="tel" id="u-telefono" name="telefono" value="<?= e($form['telefono']) ?>" placeholder="0991234567" <?= $puedeCrear ? '' : 'disabled' ?>></div>
            </div>
            <fieldset class="campo"><legend>¿Qué va a hacer?</legend>
                <div class="opciones">
                    <?php foreach (Usuarios::ROLES_ASIGNABLES as $r): ?>
                        <label class="opcion"><input type="radio" name="rol" value="<?= $r ?>" <?= $form['rol'] === $r && $rolDisponible($r) ? 'checked' : '' ?> <?= $rolDisponible($r) ? '' : 'disabled' ?>>
                            <span><strong><?= e($nombreRol($r)) ?></strong><small><?= e(Usuarios::ROLES_AYUDA[$r]) ?><?= $r === 'admin' ? ' Llevas ' . (int) $admins . ' de ' . Usuarios::MAX_ADMINS . '.' : '' ?></small></span></label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
            <button class="boton boton-ancho" type="submit" <?= $puedeCrear ? '' : 'disabled' ?>>Crear usuario</button>
            <p class="g-nota" style="margin-top:8px">El sistema crea una contraseña temporal y te la muestra para que se la envíes.</p>
        </form>
    </div>
</div>
