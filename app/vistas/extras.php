<?php
use TuRenta\{Cotizaciones, Extras};
/**
 * Extras del portal.
 * @var array $pendientes @var array $catalogo @var array $recientes @var bool $editar
 */
$unis = Cotizaciones::UNIDADES;
$cats = Cotizaciones::CATEGORIAS;
$enPortal = count(array_filter($catalogo, fn($p) => $p['en_portal']));
$etiquetas = ['pendiente' => 'por_revisar', 'aprobado' => 'pagada', 'rechazado' => 'rechazado', 'cancelado' => 'pendiente'];
$lista = fn(array $p) => Extras::resumenLineas($p['items']);
?>
<div class="cabecera">
    <div>
        <h1>Extras del portal</h1>
        <p>Lo que tus clientes pueden pedir desde su portal: hora extra, barra libre, fotocabina, mesa de dulces… Tú apruebas cada pedido y se suma solo a su plan de pagos.</p>
    </div>
    <a class="boton boton-claro" href="<?= e(url('catalogo')) ?>">Catálogo y precios</a>
</div>

<section class="bloque" id="pendientes">
    <h2>Pedidos por responder <?php if ($pendientes): ?><span class="cb-contador pt-alerta"><?= count($pendientes) ?></span><?php endif; ?></h2>
    <?php if (!$pendientes): ?>
        <p class="vacio">No hay pedidos esperando respuesta.</p>
    <?php else: ?>
    <ul class="filas">
        <?php foreach ($pendientes as $p): ?>
        <li>
            <span><strong><?= e($p['cliente']) ?></strong> · <?= e($p['evento']) ?> <small class="suave">(<?= e($p['codigo']) ?>, <?= e(fecha_corta($p['evento_fecha'])) ?>)</small>
                <small class="suave" style="display:block"><?= e($lista($p)) ?> · aprox. <?= dinero($p['total']) ?> · pedido el <?= e(fecha_corta(substr($p['creado_en'], 0, 10))) ?></small></span>
            <a class="boton boton-chico derecha" href="<?= e(url('evento_formulario', ['id' => $p['evento_id']])) ?>#pedido-<?= (int) $p['id'] ?>">Responder</a>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</section>

<section class="bloque" id="productos">
    <h2>¿Qué se puede pedir desde el portal? <small class="suave">(<?= $enPortal ?> de <?= count($catalogo) ?>)</small></h2>
    <p class="suave ayuda-chica">Marca los productos que el cliente puede pedir solo. Se muestran con el precio del catálogo más IVA. Un texto corto y una foto venden más.</p>
    <?php if (!$catalogo): ?>
        <p class="vacio">Tu catálogo está vacío. <a href="<?= e(url('catalogo', ['nuevo' => 1])) ?>">Agrega productos</a> primero.</p>
    <?php endif; ?>
    <ul class="pt-config">
        <?php foreach ($catalogo as $p): $pid = (int) $p['id']; ?>
        <li class="pt-config-fila <?= $p['en_portal'] ? 'pt-config-activo' : '' ?>" id="prod-<?= $pid ?>">
            <form method="post" action="<?= e(url('extras')) ?>" enctype="multipart/form-data" class="pt-config-form">
                <?= campo_csrf() ?><input type="hidden" name="accion" value="producto"><input type="hidden" name="producto_id" value="<?= $pid ?>">
                <div class="pt-config-foto">
                    <?php if ($p['portal_foto']): ?><img src="<?= e(url('extras', ['foto' => $pid])) ?>" alt="Foto de <?= e($p['nombre']) ?>" loading="lazy">
                    <?php else: ?><span aria-hidden="true">Sin foto</span><?php endif; ?>
                </div>
                <div class="pt-config-datos">
                    <label class="opcion pt-config-check"><input type="checkbox" name="en_portal" value="1" <?= $p['en_portal'] ? 'checked' : '' ?> <?= $editar ? '' : 'disabled' ?>>
                        <span><strong><?= e($p['nombre']) ?></strong><small><?= e($cats[$p['categoria']] ?? '') ?> · <?= dinero($p['precio']) ?> <?= e($unis[$p['unidad']] ?? '') ?><?= $p['con_iva'] ? ' + IVA' : '' ?></small></span></label>
                    <?php if ($editar): ?>
                    <details class="pt-config-mas" <?= $p['en_portal'] ? '' : '' ?>>
                        <summary>Texto y foto para el cliente</summary>
                        <div class="campo"><label for="pt-txt-<?= $pid ?>">Texto para el cliente <small>(opcional; si no, sale la descripción del catálogo)</small></label>
                            <input type="text" id="pt-txt-<?= $pid ?>" name="portal_texto" maxlength="300" value="<?= e($p['portal_texto']) ?>" placeholder="<?= e($p['descripcion'] ?: 'Ej.: Fotos ilimitadas impresas al instante') ?>"></div>
                        <div class="campo"><label for="pt-foto-<?= $pid ?>">Foto <small>(opcional)</small></label>
                            <input type="file" id="pt-foto-<?= $pid ?>" name="portal_foto" accept="image/*"></div>
                        <?php if ($p['portal_foto']): ?><label class="doc-check-chico"><input type="checkbox" name="quitar_foto" value="1"> Quitar la foto</label><?php endif; ?>
                    </details>
                    <?php endif; ?>
                </div>
                <?php if ($editar): ?><button class="boton boton-claro boton-chico pt-config-guardar" type="submit">Guardar</button><?php endif; ?>
            </form>
        </li>
        <?php endforeach; ?>
    </ul>
</section>

<?php if ($recientes): ?>
<section class="bloque" id="respondidos">
    <h2>Últimos pedidos respondidos</h2>
    <ul class="filas">
        <?php foreach ($recientes as $p): ?>
        <li>
            <span><span class="cb-etq cb-etq-<?= e($etiquetas[$p['estado']]) ?>"><?= e(Extras::ESTADOS[$p['estado']]) ?></span>
                <a href="<?= e(url('evento_formulario', ['id' => $p['evento_id']])) ?>#pedido-<?= (int) $p['id'] ?>"><?= e($p['codigo']) ?></a> · <?= e($p['cliente']) ?>
                <small class="suave" style="display:block"><?= e($lista($p)) ?><?= $p['estado'] === 'aprobado' ? ' · +' . dinero($p['monto_agregado']) : '' ?><?= $p['respondio'] ? ' · ' . e($p['respondio']) : '' ?></small></span>
        </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>
