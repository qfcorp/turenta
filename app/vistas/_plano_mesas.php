<?php
/**
 * Plano del salón (solo para mirar) dentro de Invitados → Mesas: en el sistema y en el portal de los novios.
 * Se incluye desde invitados_panel.php. Usa $ev, $esNegocio y, en el portal, $token. No pisa variables de la vista (todo con prefijo $pl).
 */
use TuRenta\Planos;

$plPlanos = new Planos(db());
$plPlano = null;
try {
    $plPlano = $plPlanos->delEvento((int) $ev['negocio_id'], (int) $ev['id']);
} catch (RuntimeException $plError) {
    $plPlano = null;
}
if ($plPlano):
    $plDatos = $plPlanos->datos((int) $ev['negocio_id'], (int) $plPlano['id']);
    $plPdf = $esNegocio ? url('pdf', ['tipo' => 'plano', 'id' => $plPlano['id']]) : url('portal_invitados', ['t' => $token ?? '', 'plano' => 'pdf']); ?>
<section class="bloque pl-en-mesas" aria-labelledby="pl-en-mesas-t">
    <div class="pl-en-mesas-cab">
        <h2 id="pl-en-mesas-t">Plano del salón</h2>
        <div class="acciones">
            <?php if ($esNegocio): ?><a class="boton boton-claro boton-chico" href="<?= e(url('plano', ['id' => $ev['id']])) ?>">Abrir el plano</a><?php endif; ?>
            <a class="boton boton-claro boton-chico" href="<?= e($plPdf) ?>" target="_blank" rel="noopener">PDF</a>
        </div>
    </div>
    <?= Planos::svg($plDatos, ['id' => 'mesas' . $plPlano['id'], 'ocupacion' => true, 'clase' => 'pl-svg-fijo']) ?>
    <p class="pl-leyenda" style="margin-top:10px">
        <span class="pl-chip pl-chip-vacia">Vacía</span><span class="pl-chip pl-chip-parcial">Con lugar</span>
        <span class="pl-chip pl-chip-llena">Llena</span><span class="pl-chip pl-chip-pasa">Se pasa</span>
        <small class="suave"><?= (int) $plDatos['resumen']['mesas'] ?> mesas · <?= (int) $plDatos['resumen']['puestos'] ?> puestos · en cada mesa: sentados / puestos</small>
    </p>
</section>
<?php elseif ($esNegocio && in_array($u['rol'] ?? '', ['dueno', 'admin', 'vendedor', 'coordinador'], true)): ?>
<p class="suave pl-sin-plano"><small>¿Quieres verlo dibujado? <a href="<?= e(url('plano', ['id' => $ev['id']])) ?>">Arma el plano del salón</a>: las mesas que crees ahí aparecen aquí.</small></p>
<?php endif; ?>
