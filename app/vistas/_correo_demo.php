<?php
/** «Enviar por correo» de la demo lista (LEEME_CORREO.md). @var array $correoDemo: listo, para, sello, error, estado (null | enviado | usado) */
$cd = $correoDemo;
?>
<div class="ia-correo" id="correo-demo">
    <h3>Enviar por correo</h3>
    <?php if (($cd['estado'] ?? null) === 'enviado'): ?>
        <p class="aviso aviso-ok" role="status">Correo enviado a <strong><?= e($cd['para']) ?></strong> desde info@tukansoftware.com. Que revise también la carpeta de spam.</p>
    <?php elseif (($cd['estado'] ?? null) === 'usado'): ?>
        <p class="aviso aviso-alerta" role="status">Este correo ya se envió. La contraseña se muestra una sola vez: si hace falta otra, usa <strong>Nueva contraseña del dueño</strong> en la ficha del negocio.</p>
    <?php elseif (!$cd['listo']): ?>
        <p><button class="boton" type="button" disabled>Configure el correo de Tukán</button></p>
        <p class="suave">En el servidor, una sola vez:</p>
        <pre class="ia-mono"><?= e(\TuRenta\CorreoDemo::COMANDO) ?></pre>
    <?php else: ?>
        <?php if (!empty($cd['error'])): ?><p class="aviso aviso-error" role="alert"><?= e($cd['error']) ?></p><?php endif; ?>
        <form method="post" action="<?= e(url('admin_demo_correo')) ?>" id="form-correo-demo">
            <?= campo_csrf() ?><input type="hidden" name="sello" value="<?= e($cd['sello']) ?>">
            <div class="campo"><label for="correo-para">Correo del cliente</label>
                <input id="correo-para" name="para" type="email" required maxlength="254" autocomplete="off" value="<?= e($cd['para']) ?>">
                <small>Le llega «Su demo de TuRenta está lista» con sus datos de entrada, desde info@tukansoftware.com.</small></div>
            <button class="boton" type="submit">Enviar por correo</button>
        </form>
    <?php endif; ?>
</div>
