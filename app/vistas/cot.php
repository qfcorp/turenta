<?php
use TuRenta\{Eventos, Fotos};

/** @var array $c @var array $negocio @var ?array $ev @var bool $vencida @var ?string $waDudas @var ?string $waActualizar @var string $portal @var string $token */
$logo = Fotos::ruta('logos', $negocio['logo'] ?? null) ? url('imagen_publica', ['tipo' => 'logo', 's' => $negocio['slug']]) : null;
$inicial = mb_strtoupper(mb_substr((string) $negocio['nombre'], 0, 1));
$puedeAceptar = in_array($c['estado'], ['borrador', 'enviada'], true) && !$vencida;
$nombre = trim(explode(' ', trim((string) $c['cliente']))[0]);
?>
<header class="doc-pub-hero">
    <div class="doc-pub-marca">
        <?php if ($logo): ?><img class="pub-logo" src="<?= e($logo) ?>" alt="<?= e($negocio['nombre']) ?>"><?php else: ?><span class="marca-sello doc-pub-sello" aria-hidden="true"><?= e($inicial) ?></span><?php endif; ?>
        <div><strong><?= e($negocio['nombre']) ?></strong><?php if ($negocio['ciudad']): ?><small><?= e($negocio['ciudad']) ?></small><?php endif; ?></div>
    </div>
    <p class="doc-pub-saludo">Hola <?= e($nombre) ?>, esta es tu cotización</p>
    <h1><?= e($c['evento']) ?></h1>
    <p class="doc-pub-datos"><?= e(fecha_larga($c['evento_fecha'])) ?> · <?= e(hora_corta($c['hora_inicio'])) ?> a <?= e(hora_corta($c['hora_fin'])) ?>
        <?php if ($ev && $ev['espacios_texto']): ?> · <?= e($ev['espacios_texto']) ?><?php endif; ?> · <?= (int) $c['invitados'] ?> invitados</p>
    <div class="doc-pub-cifras">
        <div><small>Total con IVA</small><strong><?= dinero($c['total']) ?></strong></div>
        <div><small>Por persona</small><strong><?= dinero($c['por_persona']) ?></strong></div>
    </div>
</header>

<?php if ($c['estado'] === 'aceptada'): ?>
<section class="bloque doc-pub-estado doc-pub-ok">
    <h2>✓ Cotización aceptada</h2>
    <p>La aceptó <strong><?= e($c['aceptada_por']) ?></strong> el <?= e(date('d/m/Y \a \l\a\s H:i', strtotime((string) $c['aceptada_en']))) ?>.</p>
    <p>En tu portal puedes ver el plan de pagos, pagar la reserva para separar la fecha y seguir todo tu evento.</p>
    <a class="boton boton-ancho doc-pub-boton" href="<?= e($portal) ?>">Ir a mi portal del evento</a>
</section>
<?php elseif ($c['estado'] === 'reemplazada'): ?>
<section class="bloque doc-pub-estado doc-pub-aviso">
    <h2>Esta cotización fue actualizada</h2>
    <p><?= $hayNueva ? 'El local te preparó una versión más nueva.' : 'El local está preparando una versión nueva.' ?> Pídeles el enlace actualizado para verla y aceptarla.</p>
    <?php if ($waDudas): ?><a class="boton boton-wa boton-ancho" href="<?= e($waDudas) ?>" target="_blank" rel="noopener">Pedir la cotización nueva por WhatsApp</a><?php endif; ?>
</section>
<?php elseif ($c['estado'] === 'rechazada'): ?>
<section class="bloque doc-pub-estado doc-pub-aviso">
    <h2>Esta cotización quedó sin efecto</h2>
    <p>Si todavía te interesa, escríbenos y te preparamos una nueva.</p>
    <?php if ($waDudas): ?><a class="boton boton-wa boton-ancho" href="<?= e($waDudas) ?>" target="_blank" rel="noopener">Escribir por WhatsApp</a><?php endif; ?>
</section>
<?php elseif ($vencida): ?>
<section class="bloque doc-pub-estado doc-pub-aviso">
    <h2>Esta cotización venció el <?= e(fecha_corta($c['valida_hasta'])) ?></h2>
    <p>Los precios pueden haber cambiado. Pide una cotización actualizada y te la enviamos enseguida.</p>
    <?php if ($waActualizar): ?><a class="boton boton-wa boton-ancho" href="<?= e($waActualizar) ?>" target="_blank" rel="noopener">Pedir una actualizada por WhatsApp</a><?php endif; ?>
</section>
<?php endif; ?>

<section class="bloque">
    <h2>Qué incluye</h2>
    <?php $verCostos = false; require RAIZ . '/app/vistas/cotizacion_detalle.php'; ?>
</section>

<?php if (trim((string) $c['notas']) !== '' || trim((string) $c['condiciones']) !== ''): ?>
<section class="bloque">
    <?php if (trim((string) $c['notas']) !== ''): ?><h2>Notas</h2><p class="doc-texto"><?= nl2br(e($c['notas'])) ?></p><?php endif; ?>
    <?php if (trim((string) $c['condiciones']) !== ''): ?><h2>Condiciones</h2><p class="doc-texto"><?= nl2br(e($c['condiciones'])) ?></p><?php endif; ?>
    <p class="suave">Cotización N.º <?= (int) $c['numero'] ?><?= (int) $c['version'] > 1 ? ' (versión ' . (int) $c['version'] . ')' : '' ?> · hecha el <?= e(fecha_corta($c['fecha'])) ?> · válida hasta el <strong><?= e(fecha_corta($c['valida_hasta'])) ?></strong>.</p>
</section>
<?php endif; ?>

<?php if ($puedeAceptar): ?>
<form method="post" action="<?= e(url('cot', ['t' => $token])) ?>" class="bloque doc-pub-aceptar" id="aceptar">
    <?= campo_csrf() ?>
    <h2>¿Todo bien? Acepta tu cotización</h2>
    <p class="suave">Al aceptar, el local prepara tu plan de pagos. La fecha queda separada cuando pagues la reserva.</p>
    <div class="campo"><label for="anombre">Tu nombre completo</label>
        <input type="text" id="anombre" name="nombre" required minlength="3" maxlength="150" value="<?= e($c['cliente']) ?>" autocomplete="name"></div>
    <label class="opcion" style="margin-bottom:14px"><input type="checkbox" name="acepto" value="1" required>
        <span><strong>Acepto la cotización por <?= dinero($c['total']) ?></strong><small>con lo que incluye y sus condiciones.</small></span></label>
    <button class="boton boton-ancho doc-pub-boton" type="submit">Aceptar cotización</button>
</form>
<?php endif; ?>

<div class="doc-pub-acciones">
    <a class="boton boton-claro boton-ancho" href="<?= e(url('cot', ['t' => $token, 'pdf' => 1])) ?>" target="_blank" rel="noopener">Descargar en PDF</a>
    <?php if ($waDudas && !in_array($c['estado'], ['reemplazada', 'rechazada'], true)): ?>
        <a class="boton boton-wa boton-ancho" href="<?= e($waDudas) ?>" target="_blank" rel="noopener">Tengo dudas · WhatsApp</a>
    <?php endif; ?>
</div>
