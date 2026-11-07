<?php
/**
 * Tarjetas del portal del cliente (migración 070): formulario del evento, extras para pedir en línea y encuesta después del evento.
 * Se incluye desde app/vistas/portal.php. Usa $ev, $negocio, $token y $activo de esa vista.
 */
use TuRenta\{Cuestionarios, Encuestas, Extras};

$ptNid = (int) $ev['negocio_id'];
$ptEid = (int) $ev['id'];
$ptHoy = date('Y-m-d');
$ptMod = modulo_activo($ptNid, 'cuestionarios');   // Anexo B: formulario, extras y encuesta son un módulo
$ptForm = $ptMod && $activo && in_array($ev['estado'], Cuestionarios::ESTADOS_CLIENTE, true) ? (new Cuestionarios(db()))->deEvento($ptNid, $ptEid) : null;
$ptExtras = new Extras(db());
$ptOfrecidos = $ptMod && $activo ? $ptExtras->ofrecidos($ptNid) : [];
$ptMotivo = $ptExtras->motivoNoDisponible($ptNid, $ptEid);
$ptPedidos = $ptMod ? $ptExtras->deEvento($ptNid, $ptEid) : [];
$ptPend = count(array_filter($ptPedidos, fn($p) => $p['estado'] === 'pendiente'));
$ptEncuesta = $ptMod && in_array($ev['estado'], Encuestas::ESTADOS, true) && $ev['fecha'] < $ptHoy && Encuestas::motivoCerrada($ev + ['cuenta' => $negocio['estado']]) === null;
$ptRespondio = $ptEncuesta ? (new Encuestas(db()))->deEvento($ptNid, $ptEid) : null;
$ptAnexos = modulo_activo($ptNid, 'contratos') ? (new \TuRenta\Anexos(db()))->deEvento($ptNid, $ptEid) : [];   // anexos por extras aprobados (090)
$ptPorFirmar = array_values(array_filter($ptAnexos, fn($a) => $a['firmable']));
$ptFirmados = array_values(array_filter($ptAnexos, fn($a) => $a['estado'] === 'firmado'));
?>
<?php if ($ptPorFirmar && $activo): ?>
<section class="bloque pt-tarjeta pt-tarjeta-anexo" id="anexo">
    <div class="pt-tarjeta-icono" aria-hidden="true">✍️</div>
    <div class="pt-tarjeta-cuerpo">
        <h2><?= count($ptPorFirmar) === 1 ? 'Tienes un anexo por firmar' : 'Tienes ' . count($ptPorFirmar) . ' anexos por firmar' ?></h2>
        <?php foreach ($ptPorFirmar as $a): ?>
            <p><strong><?= e($a['titulo']) ?></strong>: suma a tu contrato los extras que aprobamos (<?= dinero($a['monto']) ?>). Toma un minuto desde tu celular.</p>
            <a class="boton" href="<?= e(url('firmar_anexo', ['t' => $a['token']])) ?>">Leer y firmar el anexo</a>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($ptEncuesta): ?>
<section class="bloque pt-tarjeta pt-tarjeta-encuesta" id="encuesta">
    <div class="pt-tarjeta-icono" aria-hidden="true">💛</div>
    <div class="pt-tarjeta-cuerpo">
        <?php if ($ptRespondio): ?>
            <h2>¡Gracias por tu opinión!</h2>
            <p>Nos ayuda mucho a seguir mejorando.</p>
        <?php else: ?>
            <h2>¿Cómo te fue en tu evento?</h2>
            <p>Cuéntanos en un minuto qué te pareció la comida, la atención y la organización.</p>
            <a class="boton" href="<?= e(url('encuesta', ['t' => $ev['token_encuesta']])) ?>">Dar mi opinión</a>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($ptForm && $ptForm['total'] > 0 && $ev['fecha'] >= $ptHoy): ?>
<section class="bloque pt-tarjeta" id="formulario">
    <div class="pt-tarjeta-icono" aria-hidden="true">📝</div>
    <div class="pt-tarjeta-cuerpo">
        <h2>Formulario de tu evento</h2>
        <p><?= $ptForm['completo'] ? '¡Respondiste todo! Puedes revisar y cambiar tus respuestas.' : 'Cuéntanos la música, la decoración, el menú y los detalles para preparar todo a tu gusto.' ?></p>
        <div class="pt-avance-mini">
            <div class="barra" role="progressbar" aria-valuenow="<?= (int) $ptForm['pct'] ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Avance del formulario"><span style="width:<?= (int) $ptForm['pct'] ?>%"></span></div>
            <small><strong><?= (int) $ptForm['respondidas'] ?> de <?= (int) $ptForm['total'] ?></strong> respondidas<?= $ptForm['abierto'] ? ' · hasta el ' . e(fecha_corta($ptForm['cierre'])) : ' · ya se cerró' ?></small>
        </div>
        <a class="boton <?= $ptForm['completo'] || !$ptForm['abierto'] ? 'boton-claro' : '' ?>" href="<?= e(url('portal_cuestionario', ['t' => $token])) ?>">
            <?= !$ptForm['abierto'] ? 'Ver mis respuestas' : ($ptForm['respondidas'] === 0 ? 'Llenar el formulario' : ($ptForm['completo'] ? 'Revisar mis respuestas' : 'Seguir llenando')) ?></a>
    </div>
</section>
<?php endif; ?>

<?php if ($ptOfrecidos && ($ptMotivo === null || $ptPedidos)): ?>
<section class="bloque pt-tarjeta" id="extras">
    <div class="pt-tarjeta-icono" aria-hidden="true">✨</div>
    <div class="pt-tarjeta-cuerpo">
        <h2>Extras para tu evento</h2>
        <p><?= e(implode(' · ', array_map(fn($x) => $x['nombre'], array_slice($ptOfrecidos, 0, 4)))) ?><?= count($ptOfrecidos) > 4 ? ' y más' : '' ?></p>
        <?php if ($ptPend): ?><p class="pt-tarjeta-estado"><span class="cb-etq cb-etq-por_revisar"><?= $ptPend === 1 ? '1 pedido esperando respuesta' : $ptPend . ' pedidos esperando respuesta' ?></span></p><?php endif; ?>
        <a class="boton boton-claro" href="<?= e(url('portal_extras', ['t' => $token])) ?>"><?= $ptMotivo === null ? 'Ver y pedir extras' : 'Ver mis pedidos' ?></a>
    </div>
</section>
<?php endif; ?>
