<?php /** Pedido de demo (no hay registro abierto). @var array $errores @var array $d @var bool $listo @var array $catalogo */
use TuRenta\Modulos;
$waTukan = 'https://wa.me/' . whatsapp_ventas() . '?text=' . rawurlencode('Hola, quiero una demo de TuRenta' . ($d['negocio'] !== '' ? ' para ' . $d['negocio'] : '') . '.');
?>
<div class="acceso-marca">
    <span class="marca-sello" aria-hidden="true">Te</span>
    <div>
        <h1>Solicitar demo</h1>
        <p>Te armamos una demo de TuRenta con los módulos que necesitas</p>
    </div>
</div>
<?php if ($listo): ?>
<section class="bloque" role="status">
    <h2>¡Recibimos tu pedido!</h2>
    <p>Gracias, <?= e(explode(' ', $d['nombre'])[0]) ?>. Te escribimos por WhatsApp al <strong><?= e($d['whatsapp']) ?></strong> para preparar tu demo de
        <strong><?= e($d['negocio']) ?></strong> y mandarte tus accesos.</p>
    <div class="acciones"><a class="boton boton-wa" href="<?= e($waTukan) ?>" target="_blank" rel="noopener">Escribir ahora por WhatsApp</a></div>
</section>
<p><a href="<?= e(url('login')) ?>">Volver a Entrar</a></p>
<?php else: ?>
<form method="post" class="bloque" novalidate>
    <?= campo_csrf() ?>
    <?php if ($errores): ?>
        <div class="aviso aviso-error" role="alert"><?php foreach ($errores as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
    <?php endif; ?>
    <p class="suave" style="margin-top:0">Cuéntanos de tu negocio. Te contactamos, preparamos la demo con datos de ejemplo y te la mostramos.</p>
    <div class="campo">
        <label for="negocio">Nombre de tu salón, quinta o hacienda</label>
        <input type="text" id="negocio" name="negocio" value="<?= e($d['negocio']) ?>" maxlength="120" required autofocus>
    </div>
    <div class="fila-campos">
        <div class="campo">
            <label for="nombre">Tu nombre</label>
            <input type="text" id="nombre" name="nombre" value="<?= e($d['nombre']) ?>" maxlength="80" autocomplete="name" required>
        </div>
        <div class="campo">
            <label for="ciudad">Ciudad</label>
            <input type="text" id="ciudad" name="ciudad" value="<?= e($d['ciudad']) ?>" maxlength="80" placeholder="Quito">
        </div>
    </div>
    <div class="fila-campos">
        <div class="campo">
            <label for="whatsapp">Tu WhatsApp</label>
            <input type="tel" id="whatsapp" name="whatsapp" value="<?= e($d['whatsapp']) ?>" maxlength="30" placeholder="0991234567" autocomplete="tel" required>
        </div>
        <div class="campo">
            <label for="email">Correo</label>
            <input type="email" id="email" name="email" value="<?= e($d['email']) ?>" maxlength="120" autocomplete="email" required>
        </div>
    </div>
    <div class="campo">
        <label for="necesita">¿Qué necesitas? <small>(opcional)</small></label>
        <textarea id="necesita" name="necesita" rows="3" maxlength="1000" placeholder="Ej.: tenemos 2 salones y un jardín, hacemos unas 8 bodas al mes y queremos dejar el Excel."><?= e($d['necesita']) ?></textarea>
    </div>
    <fieldset class="campo mod-elegir" style="border:0;padding:0;margin:0 0 16px">
        <legend class="etiqueta" style="margin-bottom:6px">¿Qué módulos te interesan? <small>(opcional)</small></legend>
        <div class="mod-elegir-lista">
            <?php foreach ($catalogo as $m): ?>
                <label class="mod-elegir-item"><input type="checkbox" name="modulos[]" value="<?= e($m['id']) ?>" <?= in_array($m['id'], $d['modulos'], true) ? 'checked' : '' ?>>
                    <span><span aria-hidden="true"><?= e($m['icono']) ?></span> <?= e($m['nombre']) ?></span></label>
            <?php endforeach; ?>
        </div>
        <p class="g-nota" style="margin-top:6px">Todos los planes traen la base: <?= e(mb_strtolower(implode(', ', array_slice(Modulos::BASE, 0, 4)))) ?>…</p>
    </fieldset>
    <div class="trampa" aria-hidden="true"><label>Sitio web <input type="text" name="sitio_web" tabindex="-1" autocomplete="off"></label></div>
    <button class="boton boton-ancho" type="submit">Solicitar demo</button>
</form>
<p>¿Ya tienes cuenta? <a href="<?= e(url('login')) ?>">Entrar</a> · <a href="<?= e($waTukan) ?>" target="_blank" rel="noopener">Pedir demo por WhatsApp</a></p>
<?php endif; ?>
