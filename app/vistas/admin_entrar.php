<div class="acceso-marca">
    <span class="marca-sello" aria-hidden="true">Tk</span>
    <div><h1>Panel Tukán</h1><p>Administración de TuRenta</p></div>
</div>
<?php if (!empty($soloCentral)): ?>
<section class="bloque acceso-caja">
    <h2>Entrar</h2>
    <p class="aviso aviso-ok" role="status">El ingreso del super usuario es solo desde el Panel Tukán.</p>
    <a class="boton boton-ancho" href="<?= e($soloCentral) ?>">Ir al Panel Tukán</a>
</section>
<?php else: ?>
<form method="post" class="bloque acceso-caja">
    <?= campo_csrf() ?>
    <h2>Entrar</h2>
    <?php if ($error): ?><p class="aviso aviso-error" role="alert"><?= e($error) ?></p><?php endif; ?>
    <div class="campo"><label for="email">Correo</label>
        <input type="email" id="email" name="email" value="<?= e($email) ?>" autocomplete="username" required autofocus></div>
    <div class="campo"><label for="clave">Contraseña</label>
        <input type="password" id="clave" name="clave" autocomplete="current-password" required></div>
    <button class="boton boton-ancho" type="submit">Entrar al panel</button>
</form>
<p class="suave" style="max-width:420px">Las cuentas del panel se crean en el servidor con <code>php bin/crear_admin.php</code>.</p>
<?php endif; ?>
