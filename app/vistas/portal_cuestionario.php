<?php
/**
 * Formulario del evento para el cliente (portal).
 * @var array $ev @var array $negocio @var string $token @var ?array $form @var bool $disponible @var string $primerNombre
 */
require_once RAIZ . '/app/vistas/_pt_campos.php';
$logo = $negocio['logo'] ? url('imagen_publica', ['tipo' => 'logo', 's' => $negocio['slug']]) : null;
$editable = $form && $form['abierto'];
?>
<header class="publico-cabeza cb-portal-cabeza">
    <?php if ($logo): ?><img class="pub-logo cb-logo" src="<?= e($logo) ?>" alt="Logo de <?= e($negocio['nombre']) ?>">
    <?php else: ?><span class="marca-sello" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($negocio['nombre'], 0, 1))) ?></span><?php endif; ?>
    <div><h1><?= e($negocio['nombre']) ?></h1><p>Portal de tu evento</p></div>
</header>

<p class="pt-volver"><a href="<?= e(url('portal', ['t' => $token])) ?>">← Volver a mi evento</a></p>

<?php if (!$disponible || !$form || !$form['total']): ?>
    <section class="bloque pt-vacio">
        <h2>Formulario de tu evento</h2>
        <p><?= !$disponible ? 'El formulario se abre cuando tu fecha queda separada (al pagar la reserva). Ahí podrás contarnos la música, la decoración, el menú y cada detalle.'
                            : 'El local todavía está preparando el formulario de tu evento. Te avisaremos cuando esté listo.' ?></p>
    </section>
<?php else: ?>

<section class="pub-hero pt-hero">
    <p class="pt-hero-saludo">Hola, <?= e($primerNombre) ?></p>
    <h2 class="pt-hero-titulo"><?= e($form['cuestionario']['nombre'] ?: 'Formulario de tu evento') ?></h2>
    <p class="pt-hero-texto"><?= e($ev['nombre']) ?> · <?= e(fecha_corta($ev['fecha'])) ?></p>
    <div class="pt-avance" data-pt-avance>
        <div class="pt-avance-barra" role="progressbar" aria-valuemin="0" aria-valuemax="<?= (int) $form['total'] ?>" aria-valuenow="<?= (int) $form['respondidas'] ?>"
             aria-label="Preguntas respondidas"><span style="width:<?= (int) $form['pct'] ?>%" data-pt-barra></span></div>
        <p><strong data-pt-respondidas><?= (int) $form['respondidas'] ?></strong> de <strong data-pt-total><?= (int) $form['total'] ?></strong> preguntas respondidas</p>
    </div>
    <?php if ($editable): ?>
        <p class="pt-hero-cierre">Puedes llenarlo y cambiarlo hasta el <strong><?= e(fecha_larga($form['cierre'])) ?></strong>.</p>
    <?php else: ?>
        <p class="pt-hero-cierre">El formulario se cerró el <?= e(fecha_corta($form['cierre'])) ?>. Si necesitas cambiar algo, escríbele al local.</p>
    <?php endif; ?>
</section>

<?php if ($form['enviado_en']): ?>
    <p class="aviso aviso-ok pt-enviado">✅ Enviaste tus respuestas el <?= e(fecha_corta(substr($form['enviado_en'], 0, 10))) ?>. <?= $editable ? 'Si cambias algo, se actualiza solo.' : '' ?></p>
<?php endif; ?>

<?php if ($editable): ?>
<form method="post" action="<?= e(url('portal_cuestionario', ['t' => $token])) ?>" class="pt-form" data-pt-autoguardar>
    <?= campo_csrf() ?>
    <p class="suave pt-nota">Se guarda solo mientras escribes. Responde lo que ya sepas y vuelve cuando quieras.</p>
    <?php foreach ($form['secciones'] as $seccion => $preguntas): ?>
    <section class="bloque pt-seccion">
        <h2><?= e($seccion) ?></h2>
        <?php foreach ($preguntas as $p) echo pt_campo($p, true); ?>
    </section>
    <?php endforeach; ?>
    <div class="pt-barra-guardar">
        <span class="pt-estado" data-pt-estado aria-live="polite"></span>
        <button class="boton boton-claro" type="submit" name="accion" value="guardar">Guardar</button>
        <button class="boton" type="submit" name="accion" value="terminar"><?= $form['enviado_en'] ? 'Guardar y avisar' : 'Listo, enviar al local' ?></button>
    </div>
    <?php if ($form['faltan_obligatorias']): ?><p class="suave pt-nota">Las preguntas con <span class="pt-obl">*</span> son necesarias para enviar.</p><?php endif; ?>
</form>
<?php else: ?>
    <?php foreach ($form['secciones'] as $seccion => $preguntas): ?>
    <section class="bloque pt-seccion">
        <h2><?= e($seccion) ?></h2>
        <?php foreach ($preguntas as $p) echo pt_campo($p, false); ?>
    </section>
    <?php endforeach; ?>
<?php endif; ?>
<?php endif; ?>
