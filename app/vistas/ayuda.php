<?php
use TuRenta\Ia;
/** Ayuda (todos los planes). @var string $pregunta @var ?array $respuesta @var ?string $error */
$waAyuda = 'https://wa.me/593983662181?text=' . rawurlencode('Hola, necesito ayuda con TuRenta' . ($pregunta !== '' ? ': ' . $pregunta : ''));
?>
<div class="cabecera">
    <div><h1>Ayuda</h1><p>¿En qué le ayudamos? Pregunte cómo hacer algo en TuRenta y le respondemos al instante con el manual.</p></div>
</div>

<section class="bloque ia-ayuda" aria-labelledby="ia-t-pregunta">
    <form method="post" action="<?= e(url('ayuda')) ?>" id="form-ayuda">
        <?= campo_csrf() ?>
        <div class="campo">
            <label for="pregunta" id="ia-t-pregunta"><strong>¿Qué necesita hacer?</strong></label>
            <textarea id="pregunta" name="pregunta" rows="3" maxlength="<?= Ia::MAX_PREGUNTA ?>" required
                      placeholder="¿Cómo registro un pago?"><?= e($pregunta) ?></textarea>
            <small>Escriba su pregunta (máximo <?= Ia::MAX_PREGUNTA ?> letras).</small>
        </div>
        <button class="boton" type="submit">Preguntar</button>
    </form>
</section>

<?php if ($error): ?><p class="aviso aviso-error" role="alert"><?= e($error) ?></p><?php endif; ?>

<?php if ($respuesta): ?>
    <section class="bloque ia-respuesta" aria-labelledby="ia-t-respuesta" aria-live="polite">
        <h2 id="ia-t-respuesta">Respuesta</h2>
        <div class="ia-texto"><?= Ia::html((string) $respuesta['respuesta']) ?></div>
        <p class="ia-pie">¿Le sirvió? Si no, <a href="<?= e($waAyuda) ?>" target="_blank" rel="noopener">escríbanos por WhatsApp 098 366 2181</a>.</p>
    </section>
<?php endif; ?>

<section class="bloque ia-consejos">
    <h2>Para preguntar mejor</h2>
    <ul>
        <li>Diga qué quiere hacer: «¿Cómo envío la cotización por WhatsApp?», «¿Cómo separo una fecha?».</li>
        <li>Una pregunta a la vez.</li>
        <li>¿Algo urgente o que no funciona? <a href="<?= e($waAyuda) ?>" target="_blank" rel="noopener">Escríbanos por WhatsApp 098 366 2181</a>.</li>
    </ul>
</section>
