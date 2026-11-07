<?php
use TuRenta\Eventos;

/**
 * Invitación digital. @var array $i @var array $ev @var array $negocio @var ?string $mapa @var ?string $qrSvg @var string $qrUrl
 * @var bool $paso @var bool $esHoy @var bool $cambiar @var bool $cerrado
 */
$pases = (int) $i['pases'];
$nombreCorto = trim(explode(' ', trim((string) $i['nombre']))[0]);
$mostrarForm = !$paso && !(int) $i['ingresaron'] && ($i['estado'] === 'pendiente' || $cambiar);
$lugar = trim(implode(', ', array_filter([$negocio['direccion'] ?? '', $negocio['ciudad'] ?? ''])));
?>
<article class="dia-inv">
    <header class="dia-inv-cabeza">
        <p class="dia-inv-tipo"><?= e(Eventos::TIPOS[$ev['tipo']] ?? '') ?></p>
        <h1><?= e($ev['nombre']) ?></h1>
        <p class="dia-inv-para">Invitación para <strong><?= e($i['nombre']) ?></strong><?= $pases > 1 ? " · $pases personas" : '' ?></p>
        <?php if (!empty($ev['invitacion_texto'])): ?><p class="dia-inv-texto"><?= nl2br(e($ev['invitacion_texto'])) ?></p><?php endif; ?>
    </header>

    <dl class="dia-inv-datos">
        <div><dt>Fecha</dt><dd><?= e(ucfirst(fecha_larga($ev['fecha']))) ?></dd></div>
        <div><dt>Hora</dt><dd><?= e(hora_corta($ev['hora_inicio'])) ?></dd></div>
        <div><dt>Lugar</dt><dd><?= e($negocio['nombre']) ?><?= $lugar ? '<br><small>' . e($lugar) . '</small>' : '' ?></dd></div>
        <?php if ($i['mesa'] && $i['estado'] === 'confirmado'): ?><div><dt>Tu mesa</dt><dd><?= e($i['mesa']) ?></dd></div><?php endif; ?>
    </dl>
    <div class="acciones dia-inv-botones">
        <?php if ($mapa): ?><a class="boton boton-claro" href="<?= e($mapa) ?>" target="_blank" rel="noopener">Cómo llegar (mapa)</a><?php endif; ?>
        <?php if (!empty($ev['transmision_url']) && modulo_activo((int) $ev['negocio_id'], 'boda_vivo')): ?><a class="boton" href="<?= e($ev['transmision_url']) ?>" target="_blank" rel="noopener">Ver en vivo</a><?php endif; ?>
    </div>
</article>

<?php if ($paso): ?>
    <section class="bloque dia-inv-respuesta"><p>Este evento ya pasó. ¡Gracias por acompañarnos!</p></section>

<?php elseif ((int) $i['ingresaron'] > 0): ?>
    <section class="bloque dia-inv-respuesta dia-inv-ok"><h2>¡Bienvenido(a)!</h2><p>Ya registramos tu ingreso. Disfruta la fiesta.</p></section>

<?php elseif ($mostrarForm && $cerrado): ?>
    <section class="bloque dia-inv-respuesta">
        <p>La fecha para confirmar era hasta el <?= e(fecha_larga($ev['confirmar_hasta'])) ?>. Escribe directamente a quien te invitó.</p>
    </section>

<?php elseif ($mostrarForm): ?>
    <form method="post" class="bloque dia-inv-form">
        <?= campo_csrf() ?>
        <h2>¿Nos acompañas?</h2>
        <?php if (!empty($ev['confirmar_hasta'])): ?><p class="suave">Por favor confirma hasta el <?= e(fecha_larga($ev['confirmar_hasta'])) ?>.</p><?php endif; ?>
        <div class="modos dia-inv-opciones" data-dia-asiste>
            <label class="modo"><input type="radio" name="respuesta" value="si" <?= $i['estado'] !== 'no_asiste' ? 'checked' : '' ?>><span><strong>Sí, voy</strong><small>¡Con gusto!</small></span></label>
            <label class="modo"><input type="radio" name="respuesta" value="no" <?= $i['estado'] === 'no_asiste' ? 'checked' : '' ?>><span><strong>No podré asistir</strong><small>Gracias por la invitación</small></span></label>
        </div>
        <div class="dia-si-asiste">
            <?php if ($pases > 1): ?>
            <fieldset class="campo">
                <legend>¿Cuántos van? <small>(tu invitación es para <?= $pases ?>)</small></legend>
                <div class="dia-cuantos">
                    <?php for ($k = 1; $k <= $pases; $k++): ?>
                    <label><input type="radio" name="cuantos" value="<?= $k ?>" <?= $k === (int) ($i['confirmados'] ?: $pases) ? 'checked' : '' ?>><span><?= $k ?></span></label>
                    <?php endfor; ?>
                </div>
            </fieldset>
            <?php else: ?>
                <input type="hidden" name="cuantos" value="1">
            <?php endif; ?>
            <div class="campo"><label for="res">¿Alguna comida especial? <small>(opcional)</small></label>
                <input type="text" id="res" name="restricciones" value="<?= e($i['restricciones']) ?>" maxlength="200" placeholder="Vegetariano, alergia al maní, sin gluten…"></div>
        </div>
        <div class="campo"><label for="msj">Un mensaje para los anfitriones <small>(opcional)</small></label>
            <input type="text" id="msj" name="mensaje" value="<?= e($i['mensaje']) ?>" maxlength="400" placeholder="¡Felicidades!"></div>
        <button class="boton boton-ancho dia-boton-grande" type="submit">Enviar mi respuesta</button>
    </form>

<?php elseif ($i['estado'] === 'confirmado'): ?>
    <section class="bloque dia-inv-respuesta dia-inv-ok">
        <h2>¡Te esperamos, <?= e($nombreCorto) ?>!</h2>
        <p>Confirmaste <?= (int) $i['confirmados'] === 1 ? '1 persona' : (int) $i['confirmados'] . ' personas' ?>. <strong>Muestra este código en la entrada:</strong></p>
        <div class="dia-qr"><?= $qrSvg ?></div>
        <p class="suave"><small>Toma una captura de pantalla o guarda esta página. <?= $esHoy ? '¡Es hoy!' : '' ?></small></p>
        <div class="acciones" style="justify-content:center">
            <a class="boton boton-claro boton-chico" href="<?= e($qrUrl) ?>" download="entrada-<?= e(preg_replace('/[^a-z0-9]+/i', '-', $nombreCorto)) ?>.svg">Descargar código</a>
            <a class="boton boton-claro boton-chico" href="<?= e(url('invitacion', ['t' => $i['token'], 'cambiar' => 1])) ?>">Cambiar mi respuesta</a>
        </div>
    </section>

<?php else: ?>
    <section class="bloque dia-inv-respuesta">
        <p>Nos avisaste que no podrás asistir. ¡Gracias!</p>
        <a class="boton boton-claro boton-chico" href="<?= e(url('invitacion', ['t' => $i['token'], 'cambiar' => 1])) ?>">Al final sí puedo ir</a>
    </section>
<?php endif; ?>
