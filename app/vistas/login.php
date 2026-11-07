<div class="acceso-marca">
    <span class="marca-sello" aria-hidden="true">Te</span>
    <div>
        <h1>TuRenta</h1>
        <p>Fechas, cotizaciones, contratos y cobros de tu salón o quinta</p>
    </div>
</div>
<form method="post" class="bloque" novalidate>
    <?= campo_csrf() ?>
    <h2>Entrar</h2>
    <?php if ($error): ?><p class="aviso aviso-error" role="alert"><?= e($error) ?></p><?php endif; ?>
    <div class="campo">
        <label for="email">Correo</label>
        <input type="email" id="email" name="email" value="<?= e($email) ?>" autocomplete="email" required autofocus>
    </div>
    <div class="campo">
        <label for="clave">Contraseña</label>
        <input type="password" id="clave" name="clave" autocomplete="current-password" required>
    </div>
    <button class="boton boton-ancho" type="submit">Entrar</button>
</form>
<p>¿Todavía no tienes cuenta? <a href="<?= e(url('registro')) ?>">Solicitar demo</a></p>
<p><small><a href="<?= e(url('privacidad')) ?>">Política de privacidad</a></small></p>
