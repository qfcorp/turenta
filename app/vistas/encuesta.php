<?php
use TuRenta\Encuestas;
/**
 * Encuesta de satisfacción.
 * @var array $ev @var string $token @var ?array $respuesta @var array $borrador @var bool $recien @var ?string $cerrada
 * @var string $primerNombre @var string $resenas
 */
$logo = $ev['logo'] ? url('imagen_publica', ['tipo' => 'logo', 's' => $ev['slug']]) : null;
$npsElegido = (string) ($borrador['nps'] ?? '');
?>
<header class="publico-cabeza cb-portal-cabeza">
    <?php if ($logo): ?><img class="pub-logo cb-logo" src="<?= e($logo) ?>" alt="Logo de <?= e($ev['negocio']) ?>">
    <?php else: ?><span class="marca-sello" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($ev['negocio'], 0, 1))) ?></span><?php endif; ?>
    <div><h1><?= e($ev['negocio']) ?></h1><p>Tu opinión nos ayuda a mejorar</p></div>
</header>

<?php if ($respuesta): $grupo = Encuestas::grupo((int) $respuesta['nps']); ?>
<section class="bloque pt-gracias pt-gracias-<?= e($grupo) ?>">
    <p class="pt-gracias-icono" aria-hidden="true"><?= $grupo === 'detractor' ? '🤝' : '💛' ?></p>
    <h2><?= $recien ? '¡Gracias, ' . e($primerNombre) . '!' : 'Ya recibimos tu opinión' ?></h2>
    <?php if ($grupo === 'promotor'): ?>
        <p>Nos alegra muchísimo que <?= e($ev['nombre']) ?> haya sido como lo soñaste.</p>
        <?php if ($resenas !== ''): ?>
            <p>¿Nos ayudas con una reseña en Google? Toma un minuto y ayuda a otras familias a encontrarnos.</p>
            <a class="boton boton-ancho pt-boton-resena" href="<?= e($resenas) ?>" target="_blank" rel="noopener">⭐ Dejar mi reseña en Google</a>
        <?php endif; ?>
    <?php elseif ($grupo === 'detractor'): ?>
        <p>Lamentamos que no todo haya salido como esperabas. <?= e($ev['negocio']) ?> leyó tu opinión y se comunicará contigo para escucharte.</p>
    <?php else: ?>
        <p>Tu opinión nos ayuda a que el próximo evento sea todavía mejor.</p>
    <?php endif; ?>
</section>

<?php elseif ($cerrada): ?>
<section class="bloque pt-vacio"><h2>Encuesta</h2><p><?= e($cerrada) ?></p></section>

<?php else: ?>
<section class="pub-hero pt-hero">
    <p class="pt-hero-saludo">Hola, <?= e($primerNombre) ?></p>
    <h2 class="pt-hero-titulo">¿Cómo te fue en tu evento?</h2>
    <p class="pt-hero-texto"><?= e($ev['nombre']) ?> · <?= e(fecha_corta($ev['fecha'])) ?>. Son unas preguntas rápidas.</p>
</section>

<form method="post" action="<?= e(url('encuesta', ['t' => $token])) ?>" class="pt-encuesta">
    <?= campo_csrf() ?>
    <div class="pt-trampa" aria-hidden="true"><label for="sitio_web">No llenar</label><input type="text" id="sitio_web" name="sitio_web" tabindex="-1" autocomplete="off"></div>

    <fieldset class="bloque pt-nps">
        <legend><strong>Del 0 al 10, ¿qué tanto nos recomendarías a un amigo o familiar?</strong></legend>
        <div class="pt-nps-escala">
            <?php for ($i = 0; $i <= 10; $i++): ?>
                <label class="pt-nps-<?= $i <= 6 ? 'bajo' : ($i <= 8 ? 'medio' : 'alto') ?>"><input type="radio" name="nps" value="<?= $i ?>" required <?= $npsElegido === (string) $i ? 'checked' : '' ?>><span><?= $i ?></span></label>
            <?php endfor; ?>
        </div>
        <div class="pt-nps-extremos"><small>Nada probable</small><small>Muy probable</small></div>
    </fieldset>

    <section class="bloque pt-calificar">
        <h2>Califica de 1 a 5 estrellas</h2>
        <?php foreach (Encuestas::ASPECTOS as $k => $txt): $elegido = (string) ($borrador[$k] ?? ''); ?>
        <fieldset class="pt-aspecto">
            <legend><?= e($txt) ?></legend>
            <div class="pt-estrellas">
                <?php for ($i = 5; $i >= 1; $i--): ?>
                    <input type="radio" id="<?= e($k . $i) ?>" name="<?= e($k) ?>" value="<?= $i ?>" <?= $elegido === (string) $i ? 'checked' : '' ?>>
                    <label for="<?= e($k . $i) ?>" title="<?= $i ?> de 5"><span class="sr-only"><?= $i ?> de 5</span>★</label>
                <?php endfor; ?>
            </div>
        </fieldset>
        <?php endforeach; ?>
    </section>

    <section class="bloque">
        <div class="campo"><label for="comentario"><strong>¿Algo que quieras contarnos?</strong> <small>(opcional)</small></label>
            <textarea id="comentario" name="comentario" rows="4" maxlength="1000" placeholder="Lo que más te gustó, lo que podríamos mejorar…"><?= e($borrador['comentario'] ?? '') ?></textarea></div>
        <button class="boton boton-ancho" type="submit">Enviar mi opinión</button>
    </section>
</form>
<?php endif; ?>
