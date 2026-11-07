<?php
use TuRenta\{Catalogo, Cotizaciones};

/** @var array $productos @var array $filtros @var array $conteo @var ?array $editar @var bool $puede @var bool $dueno */
$cats = Cotizaciones::CATEGORIAS;
$unis = Cotizaciones::UNIDADES;
$grupos = [];
foreach ($productos as $p) $grupos[$p['categoria']][] = $p;
$f = $editar ?? [];
$hayFiltro = $filtros['buscar'] !== '' || $filtros['categoria'] !== '';
?>
<div class="cabecera">
    <div>
        <h1>Catálogo y precios</h1>
        <p>Todo lo que vendes: menús, bebidas, decoración, música… Con esto armas paquetes y cotizaciones en segundos.</p>
    </div>
    <?php if ($puede): ?>
    <div class="acciones">
        <a class="boton" href="<?= e(url('catalogo', ['nuevo' => 1] + array_filter(['cat' => $filtros['categoria']]))) ?>#form-producto">+ Agregar producto</a>
        <?php if (modulo_activo($u, 'paquetes')): ?><a class="boton boton-claro" href="<?= e(url('paquetes')) ?>">Paquetes</a><?php endif; ?>
        <?php if (modulo_activo($u, 'cuestionarios')): ?><a class="boton boton-claro" href="<?= e(url('extras')) ?>">Extras del portal</a><?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php if ($puede && $abrirForm): ?>
<form method="post" action="<?= e(url('catalogo', ['accion' => 'guardar'] + array_filter(['q' => $filtros['buscar'], 'cat' => $filtros['categoria']]))) ?>" class="bloque doc-form-producto" id="form-producto">
    <?= campo_csrf() ?>
    <?php if (!empty($f['id'])): ?><input type="hidden" name="producto_id" value="<?= (int) $f['id'] ?>"><?php endif; ?>
    <h2><?= !empty($f['id']) ? 'Editar: ' . e($f['nombre']) : 'Nuevo producto o servicio' ?></h2>
    <div class="rejilla-form">
        <div class="campo ancho"><label for="pnom">Nombre</label>
            <input type="text" id="pnom" name="nombre" maxlength="150" required value="<?= e($f['nombre'] ?? '') ?>" placeholder="Ej.: Menú tres tiempos" autofocus></div>
        <div class="campo"><label for="pcat">Categoría</label>
            <select id="pcat" name="categoria">
                <?php foreach ($cats as $k => $v): ?><option value="<?= e($k) ?>" <?= ($f['categoria'] ?? $filtros['categoria'] ?: 'menu') === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
            </select></div>
        <div class="campo"><label for="puni">Se cobra</label>
            <select id="puni" name="unidad">
                <?php foreach ($unis as $k => $v): ?><option value="<?= e($k) ?>" <?= ($f['unidad'] ?? 'persona') === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
            </select>
            <small>"Por persona" se multiplica solo por los invitados en la cotización.</small></div>
        <div class="campo"><label for="ppre">Precio al cliente <small>(sin IVA)</small></label>
            <input type="text" inputmode="decimal" id="ppre" name="precio" required value="<?= e(isset($f['precio']) ? number_format((float) str_replace(',', '.', (string) $f['precio']), 2, ',', '') : '') ?>" placeholder="0,00" data-doc-precio></div>
        <?php if ($dueno): ?>
        <div class="campo"><label for="pcos">Lo que te cuesta <small>(solo lo ves tú)</small></label>
            <input type="text" inputmode="decimal" id="pcos" name="costo" value="<?= e(isset($f['costo']) ? number_format((float) str_replace(',', '.', (string) $f['costo']), 2, ',', '') : '') ?>" placeholder="0,00" data-doc-costo>
            <small data-doc-margen>Sirve para ver cuánto ganas en cada evento.</small></div>
        <?php endif; ?>
        <div class="campo ancho"><label for="pdes">Descripción <small>(opcional, sale en la cotización)</small></label>
            <textarea id="pdes" name="descripcion" rows="2" maxlength="600" placeholder="Qué incluye. Ej.: Entrada, plato fuerte y postre"><?= e($f['descripcion'] ?? '') ?></textarea></div>
        <label class="opcion"><input type="checkbox" name="con_iva" value="1" <?= ($f['con_iva'] ?? true) ? 'checked' : '' ?>>
            <span><strong>Cobra IVA</strong><small>Desmárcalo si este servicio no paga IVA (0 %).</small></span></label>
        <div class="campo"><label for="pord">Orden en la lista <small>(opcional)</small></label>
            <input type="number" id="pord" name="orden" min="0" max="999" value="<?= e($f['orden'] ?? '') ?>" placeholder="Automático"></div>
    </div>
    <div class="acciones">
        <button class="boton" type="submit"><?= !empty($f['id']) ? 'Guardar cambios' : 'Agregar al catálogo' ?></button>
        <a class="boton boton-claro" href="<?= e(url('catalogo', array_filter(['q' => $filtros['buscar'], 'cat' => $filtros['categoria']]))) ?>">Cancelar</a>
    </div>
</form>
<?php endif; ?>

<form method="get" class="bloque doc-filtros" role="search">
    <input type="hidden" name="r" value="catalogo">
    <div class="doc-filtros-fila">
        <input type="search" name="q" value="<?= e($filtros['buscar']) ?>" placeholder="Buscar en el catálogo…" aria-label="Buscar producto">
        <select name="cat" aria-label="Categoría" data-autoenviar>
            <option value="">Todas las categorías (<?= (int) $totalActivos ?>)</option>
            <?php foreach ($cats as $k => $v): ?><option value="<?= e($k) ?>" <?= $filtros['categoria'] === $k ? 'selected' : '' ?>><?= e($v) ?> (<?= (int) ($conteo[$k] ?? 0) ?>)</option><?php endforeach; ?>
        </select>
        <button class="boton boton-claro" type="submit">Buscar</button>
    </div>
    <label class="doc-check-chico"><input type="checkbox" name="inactivos" value="1" <?= $filtros['inactivos'] ? 'checked' : '' ?> data-autoenviar> Mostrar también los desactivados</label>
</form>

<?php if (!$productos): ?>
    <section class="bloque vacio">
        <?php if ($hayFiltro): ?>
            <p><strong>No hay productos con ese filtro.</strong></p>
            <a class="boton boton-claro" href="<?= e(url('catalogo')) ?>">Ver todo el catálogo</a>
        <?php else: ?>
            <p><strong>Tu catálogo está vacío.</strong><br>Agrega lo que vendes (menú, barra, DJ, decoración…) con su precio. Después lo juntas en paquetes.</p>
            <?php if ($puede): ?><a class="boton" href="<?= e(url('catalogo', ['nuevo' => 1])) ?>#form-producto">Agregar el primero</a><?php endif; ?>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php foreach ($grupos as $catKey => $lista): ?>
<section class="bloque doc-grupo">
    <h2 class="doc-grupo-titulo"><?= e($cats[$catKey] ?? 'Otros') ?> <small>(<?= count($lista) ?>)</small></h2>
    <ul class="doc-productos">
        <?php foreach ($lista as $p):
            $margen = Catalogo::margen((float) $p['precio'], (float) $p['costo']); ?>
        <li class="doc-producto<?= $p['activo'] ? '' : ' doc-inactivo' ?><?= $ver === (int) $p['id'] ? ' doc-resaltado' : '' ?>" id="prod-<?= (int) $p['id'] ?>">
            <div class="doc-producto-info">
                <strong><?= e($p['nombre']) ?></strong>
                <?php if (!$p['activo']): ?><span class="ev-estado ev-perdido">Desactivado</span><?php endif; ?>
                <?php if ($p['descripcion']): ?><small><?= e($p['descripcion']) ?></small><?php endif; ?>
                <small class="doc-etiquetas">
                    <?= e($unis[$p['unidad']] ?? '') ?><?= $p['con_iva'] ? '' : ' · <b>0 % IVA</b>' ?>
                    <?= (int) $p['en_paquetes'] > 0 ? ' · en ' . (int) $p['en_paquetes'] . ' ' . ((int) $p['en_paquetes'] === 1 ? 'paquete' : 'paquetes') : '' ?>
                    <?= !empty($p['en_portal']) ? ' · <a href="' . e(url('extras')) . '#prod-' . (int) $p['id'] . '">se pide en el portal</a>' : '' ?>
                </small>
            </div>
            <div class="doc-producto-precio">
                <span class="monto"><?= dinero($p['precio']) ?></span>
                <?php if ($dueno): ?>
                    <small>Costo <?= dinero($p['costo']) ?></small>
                    <?php if ($margen !== null): ?><small class="doc-margen <?= $margen < 20 ? 'doc-margen-bajo' : '' ?>">Margen <?= e(number_format($margen, 1, ',', '.')) ?> %</small><?php endif; ?>
                <?php endif; ?>
            </div>
            <?php if ($puede): ?>
            <div class="doc-producto-acciones">
                <a class="boton boton-claro boton-chico" href="<?= e(url('catalogo', ['editar' => $p['id']] + array_filter(['q' => $filtros['buscar'], 'cat' => $filtros['categoria']]))) ?>#form-producto">Editar</a>
                <form method="post" action="<?= e(url('catalogo', ['accion' => $p['activo'] ? 'desactivar' : 'activar'] + array_filter(['q' => $filtros['buscar'], 'cat' => $filtros['categoria'], 'inactivos' => $filtros['inactivos'] ? 1 : null]))) ?>">
                    <?= campo_csrf() ?><input type="hidden" name="producto_id" value="<?= (int) $p['id'] ?>">
                    <?php if ($p['activo']): ?>
                        <button class="boton boton-peligro boton-chico" type="submit" data-confirmar="¿Desactivar «<?= e($p['nombre']) ?>»? Ya no saldrá para cotizar. Lo puedes volver a activar cuando quieras.">Desactivar</button>
                    <?php else: ?>
                        <button class="boton boton-claro boton-chico" type="submit">Activar</button>
                    <?php endif; ?>
                </form>
            </div>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endforeach; ?>

<?php if ($dueno && $totalActivos > 0): ?>
<details class="bloque doc-desplegar" id="subir-precios">
    <summary><strong>Subir precios en bloque</strong> <small>— por ejemplo +5 % a todo el menú a inicio de año</small></summary>
    <form method="post" action="<?= e(url('catalogo', ['accion' => 'subir_precios'])) ?>" data-doc-bloque='<?= e(json_encode($paraBloque, JSON_UNESCAPED_UNICODE)) ?>'>
        <?= campo_csrf() ?>
        <div class="fila-campos">
            <div class="campo"><label for="bpct">Porcentaje</label>
                <input type="text" inputmode="decimal" id="bpct" name="pct" required placeholder="5" data-doc-bloque-pct>
                <small>5 = sube 5 %. Para bajar, escribe con menos: −10.</small></div>
            <div class="campo"><label for="bcat">¿Qué productos?</label>
                <select id="bcat" name="categoria_bloque" data-doc-bloque-cat>
                    <option value="">Todo el catálogo</option>
                    <?php foreach ($cats as $k => $v): if (empty($conteo[$k])) continue; ?><option value="<?= e($k) ?>"><?= e($v) ?> (<?= (int) $conteo[$k] ?>)</option><?php endforeach; ?>
                </select></div>
        </div>
        <label class="opcion" style="margin-bottom:12px"><input type="checkbox" name="paquetes" value="1" checked>
            <span><strong>Subir también los precios especiales de los paquetes</strong><small>Así los paquetes no se quedan con el precio viejo.</small></span></label>
        <p class="doc-vista-previa" data-doc-bloque-vista aria-live="polite">Escribe el porcentaje para ver cómo quedan los precios.</p>
        <button class="boton" type="submit">Cambiar precios</button>
        <small style="display:block;margin-top:8px">Las cotizaciones que ya enviaste no cambian. Los precios se redondean al centavo.</small>
    </form>
</details>
<?php endif; ?>
