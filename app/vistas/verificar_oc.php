<?php
use TuRenta\Compras;

/** @var string $codigo @var ?array $oc @var ?array $marca */
?>
<header class="publico-cabeza">
    <span class="marca-sello dia-sello" aria-hidden="true">✓</span>
    <div><h1>Verificar orden de compra</h1><p>Revisa que una orden de compra sea auténtica.</p></div>
</header>
<?php if ($oc): ?>
<section class="bloque pv-verificada">
    <p class="aviso aviso-ok">✓ Esta orden de compra es auténtica.</p>
    <dl class="ficha-datos">
        <dt>Emitida por</dt><dd><?= e($marca['nombre']) ?><?= $oc['negocio_razon'] && $oc['negocio_razon'] !== $marca['nombre'] ? ' (' . e($oc['negocio_razon']) . ')' : '' ?><?= $oc['negocio_ruc'] ? ' · RUC ' . e($oc['negocio_ruc']) : '' ?></dd>
        <dt>Número</dt><dd><?= e($oc['codigo']) ?></dd>
        <dt>Fecha</dt><dd><?= e(fecha_larga($oc['fecha'])) ?></dd>
        <dt>Proveedor</dt><dd><?= e($oc['proveedor']) ?></dd>
        <?php if ($oc['evento']): ?><dt>Evento</dt><dd><?= e($oc['evento']) ?> (<?= e(fecha_corta($oc['evento_fecha'])) ?>)</dd><?php endif; ?>
        <dt>Estado</dt><dd><?= e(Compras::ESTADOS[$oc['estado']]) ?></dd>
        <dt>Total</dt><dd><?= dinero($oc['total']) ?></dd>
    </dl>
    <?php if ($oc['estado'] === 'cancelada'): ?><p class="aviso aviso-error">Ojo: esta orden fue cancelada.</p><?php endif; ?>
</section>
<?php else: ?>
<section class="bloque">
    <?php if ($codigo !== ''): ?><p class="aviso aviso-error">No encontramos una orden con el código <?= e($codigo) ?>. Puede que no sea auténtica.</p><?php endif; ?>
    <form method="get"><input type="hidden" name="r" value="verificar_oc">
        <div class="campo"><label for="voc">Código de verificación <small>(está debajo del QR)</small></label><input type="text" id="voc" name="c" maxlength="20" value="<?= e($codigo) ?>" required></div>
        <button class="boton" type="submit">Verificar</button>
    </form>
</section>
<?php endif; ?>
