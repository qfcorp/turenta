<?php
use TuRenta\{Compras, Cotizaciones, ProveedoresFicha};

/** @var array $lista @var array $filtros @var array $provs @var array $puntajes @var array $eventosSel @var array $grupos @var ?array $comparar
 *  @var ?array $xmlInfo @var bool $puede @var ?array $previo @var bool $abrirNueva @var float $ivaPct @var array $pre */
$hoyTxt = date('Y-m-d');
$pv = fn(string $k, $def = '') => $previo[$k] ?? $def;
$filasItems = $previo['items'] ?? [];
while (count($filasItems) < 4) $filasItems[] = ['descripcion' => '', 'cantidad' => '', 'precio_unitario' => '', 'con_iva' => '1'];
$provSel = (int) ($previo['proveedor_id'] ?? $pre['proveedor']);
$evSel = (int) ($previo['evento_id'] ?? $pre['evento']);
$estSel = (string) ($previo['estado'] ?? ($pre['estado'] ?: 'pedida'));
$opcionProv = function (array $p) use ($puntajes): string {
    $t = ProveedoresFicha::textoPuntaje($puntajes[(int) $p['id']] ?? null);
    return ($p['preferido'] ? '★ ' : '') . $p['nombre'] . ' — ' . (Cotizaciones::CATEGORIAS[$p['servicio']] ?? $p['servicio']) . ($t ? ' · ' . $t : '')
         . ' · ' . ProveedoresFicha::textoCredito((int) $p['credito_dias']);
};
?>
<div class="cabecera">
    <div>
        <h1>Compras</h1>
        <p>Órdenes de compra y pedidos de precio a tus proveedores, con su factura y sus pagos.</p>
    </div>
    <div class="acciones">
        <a class="boton boton-claro" href="<?= e(url('por_pagar')) ?>">Por pagar</a>
        <a class="boton boton-claro" href="<?= e(url('proveedores')) ?>">Proveedores</a>
    </div>
</div>

<?php if ($xmlInfo): $f = $xmlInfo['f']; $pr = $xmlInfo['proveedor']; ?>
<form method="post" class="bloque pv-xml" id="xml"><?= campo_csrf() ?><input type="hidden" name="accion" value="xml_guardar">
    <h2>Factura electrónica leída</h2>
    <dl class="ficha-datos">
        <dt>Factura</dt><dd><?= e($f['numero']) ?> · <?= e(fecha_corta($f['fecha'])) ?></dd>
        <dt>Proveedor</dt><dd><?= e($f['razon_emisor']) ?><?= $f['nombre_comercial'] ? ' (' . e($f['nombre_comercial']) . ')' : '' ?> · RUC <?= e($f['ruc_emisor']) ?></dd>
        <dt>A nombre de</dt><dd><?= e($f['razon_comprador'] ?: '—') ?> <?= $f['ruc_comprador'] ? '· ' . e($f['ruc_comprador']) : '' ?></dd>
        <dt>Subtotal</dt><dd><?= dinero($f['subtotal']) ?> · IVA <?= dinero($f['iva']) ?></dd>
        <dt>Total</dt><dd><strong><?= dinero($f['total']) ?></strong> · <?= count($f['items']) ?> <?= count($f['items']) === 1 ? 'ítem' : 'ítems' ?></dd>
    </dl>
    <?php if ($pr): ?>
        <p class="aviso aviso-ok">Es de tu proveedor <strong><?= e($pr['nombre']) ?></strong>.</p>
        <input type="hidden" name="proveedor_id" value="<?= (int) $pr['id'] ?>">
        <div class="campo"><label for="xdest">¿Dónde la guardo?</label>
            <select id="xdest" name="compra_id" data-mostrar="pv-xml-nueva">
                <option value="0">En una compra nueva (ya entregada)</option>
                <?php foreach ($xmlInfo['abiertas'] as $c): ?><option value="<?= (int) $c['id'] ?>">En <?= e($c['codigo']) ?> · <?= dinero($c['total']) ?><?= $c['evento'] ? ' · ' . e($c['evento']) : '' ?></option><?php endforeach; ?>
            </select></div>
    <?php else: ?>
        <p class="aviso pv-aviso-amarillo">El RUC <?= e($f['ruc_emisor']) ?> no es de ningún proveedor de tu lista. Se creará <strong><?= e(mb_convert_case(mb_strtolower($f['nombre_comercial'] ?: $f['razon_emisor']), MB_CASE_TITLE)) ?></strong> con los datos de la factura.</p>
        <div class="fila-campos">
            <div class="campo"><label for="xser">Servicio</label><select id="xser" name="servicio">
                <?php foreach (Cotizaciones::CATEGORIAS as $k => $t): ?><option value="<?= e($k) ?>"><?= e($t) ?></option><?php endforeach; ?></select></div>
            <div class="campo"><label for="xtel">Celular <small>(opcional)</small></label><input type="tel" id="xtel" name="telefono" placeholder="0991234567"></div>
        </div>
        <input type="hidden" name="compra_id" value="0">
    <?php endif; ?>
    <div class="pv-xml-nueva" data-si="0">
        <div class="campo"><label for="xev">¿Es de un evento?</label>
            <select id="xev" name="evento_id"><option value="0">— Compra general del negocio —</option>
                <?php foreach ($eventosSel as $x): ?><option value="<?= (int) $x['id'] ?>"><?= e(fecha_corta($x['fecha'])) ?> · <?= e($x['nombre']) ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="acciones">
        <button class="boton" type="submit">Guardar la factura</button>
        <button class="boton boton-claro" type="submit" name="accion" value="xml_cancelar" formnovalidate>No guardar</button>
    </div>
</form>
<?php endif; ?>

<?php if ($comparar): ?>
<section class="bloque" id="comparar">
    <h2>Comparar precios: <?= e(Cotizaciones::CATEGORIAS[$comparar['categoria']]) ?></h2>
    <p class="suave">Para <a href="<?= e(url('evento', ['id' => $comparar['evento']['id']])) ?>"><?= e($comparar['evento']['nombre']) ?></a> (<?= e(fecha_corta($comparar['evento']['fecha'])) ?>).
        Al elegir uno, pasa a compra y los demás se cancelan.</p>
    <?php if (count($comparar['lista']) < 2): ?>
        <p class="vacio">Necesitas al menos 2 pedidos de precio de este rubro para comparar.</p>
    <?php else: $min = min(array_column($comparar['lista'], 'total')); ?>
    <div class="pv-comparar">
        <?php foreach ($comparar['lista'] as $c): $sc = $puntajes[(int) $c['proveedor_id']] ?? null; ?>
        <article class="pv-oferta <?= abs($c['total'] - $min) < 0.005 ? 'pv-mas-barato' : '' ?>">
            <?php if (abs($c['total'] - $min) < 0.005): ?><span class="pv-chip pv-chip-verde">El más barato</span><?php endif; ?>
            <h3><a href="<?= e(url('proveedor', ['id' => $c['proveedor_id']])) ?>"><?= e($c['proveedor']) ?></a></h3>
            <p class="pv-oferta-total"><?= dinero($c['total']) ?><?= $c['total'] > $min ? ' <small>(+' . dinero($c['total'] - $min) . ')</small>' : '' ?></p>
            <p><small><?= ($t = ProveedoresFicha::textoPuntaje($sc)) ? e($t) . ' · ' : 'Sin calificaciones · ' ?><?= e(ProveedoresFicha::textoCredito((int) $c['credito_dias'])) ?></small></p>
            <ul class="pv-oferta-items">
                <?php foreach ($c['items'] as $it): ?><li><?= e(rtrim(rtrim(number_format((float) $it['cantidad'], 2, ',', '.'), '0'), ',')) ?> × <?= e($it['descripcion']) ?> <span><?= dinero($it['subtotal']) ?></span></li><?php endforeach; ?>
            </ul>
            <div class="acciones">
                <a class="boton boton-claro boton-chico" href="<?= e(url('compra', ['id' => $c['id']])) ?>"><?= e($c['codigo']) ?></a>
                <?php if ($puede): ?>
                <form method="post"><?= campo_csrf() ?><input type="hidden" name="accion" value="elegir"><input type="hidden" name="compra_id" value="<?= (int) $c['id'] ?>">
                    <button class="boton boton-chico" type="submit" data-confirmar="¿Elegir a <?= e($c['proveedor']) ?> por <?= e(dinero($c['total'])) ?>? Los otros pedidos de precio se cancelan.">Elegir este</button></form>
                <?php endif; ?>
            </div>
        </article>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($grupos && !$comparar): ?>
    <?php foreach ($grupos as $g): ?>
    <a class="g-franja pv-franja" href="<?= e(url('compras', ['comparar' => 1, 'evento' => $g['evento_id'], 'categoria' => $g['categoria']])) ?>#comparar">
        ⚖️ Tienes <?= (int) $g['cuantos'] ?> precios de <?= e(mb_strtolower(Cotizaciones::CATEGORIAS[$g['categoria']] ?? $g['categoria'])) ?> para <?= e($g['evento']) ?>
        (de <?= dinero($g['minimo']) ?> a <?= dinero($g['maximo']) ?>). Compararlos</a>
    <?php endforeach; ?>
<?php endif; ?>

<?php if ($puede): ?>
<div class="rejilla rejilla-2 pv-acciones-compra">
    <details class="bloque pv-detalles-bloque" id="nueva" <?= $abrirNueva ? 'open' : '' ?>>
        <summary><h2>Nueva compra o pedido de precio</h2></summary>
        <?php if (!$provs): ?>
            <p class="vacio">Primero agrega tus proveedores. <a href="<?= e(url('proveedores')) ?>">Ir a Proveedores</a></p>
        <?php else: ?>
        <form method="post" class="pv-form-compra" data-iva="<?= e((string) $ivaPct) ?>"><?= campo_csrf() ?><input type="hidden" name="accion" value="crear">
            <div class="campo"><label for="cprov">Proveedor</label>
                <select id="cprov" name="proveedor_id" required><option value="">Elige…</option>
                    <?php foreach ($provs as $p): ?><option value="<?= (int) $p['id'] ?>" <?= $provSel === (int) $p['id'] ? 'selected' : '' ?>><?= e($opcionProv($p)) ?></option><?php endforeach; ?>
                </select></div>
            <div class="fila-campos">
                <div class="campo"><label for="cev">Evento</label>
                    <select id="cev" name="evento_id"><option value="0">— Compra general del negocio —</option>
                        <?php foreach ($eventosSel as $x): ?><option value="<?= (int) $x['id'] ?>" <?= $evSel === (int) $x['id'] ? 'selected' : '' ?>><?= e(fecha_corta($x['fecha'])) ?> · <?= e($x['nombre']) ?></option><?php endforeach; ?>
                    </select></div>
                <div class="campo"><label for="cest">¿Qué es?</label>
                    <select id="cest" name="estado">
                        <option value="pedida" <?= $estSel === 'pedida' ? 'selected' : '' ?>>Compra (orden de compra)</option>
                        <option value="cotizacion" <?= $estSel === 'cotizacion' ? 'selected' : '' ?>>Solo pedir el precio</option>
                        <option value="confirmada" <?= $estSel === 'confirmada' ? 'selected' : '' ?>>Compra ya confirmada por el proveedor</option>
                    </select></div>
            </div>
            <fieldset class="pv-items">
                <legend>Qué compras</legend>
                <div class="pv-items-cab" aria-hidden="true"><span>Descripción</span><span>Cant.</span><span>Precio unit.</span><span>IVA</span></div>
                <?php foreach ($filasItems as $i => $it): ?>
                <div class="pv-item">
                    <input type="text" name="items[<?= $i ?>][descripcion]" value="<?= e($it['descripcion'] ?? '') ?>" maxlength="300" placeholder="<?= $i === 0 ? '200 sillas Tiffany' : 'Descripción' ?>" aria-label="Descripción de la línea <?= $i + 1 ?>">
                    <input type="text" name="items[<?= $i ?>][cantidad]" value="<?= e($it['cantidad'] ?? '') ?>" inputmode="decimal" placeholder="1" aria-label="Cantidad">
                    <input type="text" name="items[<?= $i ?>][precio_unitario]" value="<?= e($it['precio_unitario'] ?? '') ?>" inputmode="decimal" placeholder="0,00" aria-label="Precio unitario">
                    <select name="items[<?= $i ?>][con_iva]" aria-label="IVA"><option value="1" <?= ($it['con_iva'] ?? '1') !== '0' ? 'selected' : '' ?>><?= e(rtrim(rtrim(number_format($ivaPct, 2, ',', ''), '0'), ',')) ?> %</option><option value="0" <?= ($it['con_iva'] ?? '1') === '0' ? 'selected' : '' ?>>0 %</option></select>
                </div>
                <?php endforeach; ?>
                <button type="button" class="boton boton-claro boton-chico" data-pv-agregar-item>+ Otra línea</button>
                <p class="pv-total-vivo" data-pv-total hidden></p>
            </fieldset>
            <details class="pv-detalles"><summary>Fecha, pago y notas</summary>
                <div class="fila-campos">
                    <div class="campo"><label for="ccat">Rubro <small>(para comparar precios)</small></label>
                        <select id="ccat" name="categoria"><option value="">El del proveedor</option>
                            <?php foreach (Cotizaciones::CATEGORIAS as $k => $t): ?><option value="<?= e($k) ?>" <?= ($pv('categoria', $pre['categoria'])) === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
                    <div class="campo"><label for="cfec">Fecha</label><input type="date" id="cfec" name="fecha" value="<?= e($pv('fecha', $hoyTxt)) ?>"></div>
                </div>
                <div class="fila-campos">
                    <div class="campo"><label for="cven">Pagar hasta <small>(vacío = según su crédito)</small></label><input type="date" id="cven" name="vence" value="<?= e($pv('vence')) ?>"></div>
                    <div class="campo"><label for="cant">Anticipo pactado <small>($; vacío = el habitual)</small></label><input type="text" id="cant" name="anticipo" inputmode="decimal" value="<?= e($pv('anticipo')) ?>"></div>
                </div>
                <div class="campo"><label for="cnot">Notas para el proveedor</label><textarea id="cnot" name="notas" rows="2" maxlength="2000"><?= e($pv('notas')) ?></textarea></div>
            </details>
            <button class="boton boton-ancho" type="submit">Guardar</button>
        </form>
        <?php endif; ?>
    </details>
    <form method="post" enctype="multipart/form-data" class="bloque"><?= campo_csrf() ?><input type="hidden" name="accion" value="xml_leer">
        <h2>Subir factura electrónica</h2>
        <p class="suave">Sube el XML que te mandó el proveedor (o que bajaste del SRI): se llenan solos el número, la fecha, los valores y el RUC.</p>
        <div class="campo"><label for="xmlf">Archivo XML</label><input type="file" id="xmlf" name="xml" accept=".xml,text/xml,application/xml" required></div>
        <button class="boton boton-claro" type="submit">Leer la factura</button>
    </form>
</div>
<?php endif; ?>

<section class="bloque">
    <form method="get" class="pv-filtros">
        <input type="hidden" name="r" value="compras">
        <div class="campo"><label for="fest">Estado</label>
            <select id="fest" name="estado" data-autoenviar>
                <option value="">Todas</option>
                <option value="pendientes" <?= $filtros['estado'] === 'pendientes' ? 'selected' : '' ?>>Con saldo por pagar</option>
                <option value="activas" <?= $filtros['estado'] === 'activas' ? 'selected' : '' ?>>Pedidas, confirmadas y entregadas</option>
                <?php foreach (Compras::ESTADOS as $k => $t): ?><option value="<?= e($k) ?>" <?= $filtros['estado'] === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
            </select></div>
        <div class="campo"><label for="fprov">Proveedor</label>
            <select id="fprov" name="proveedor" data-autoenviar><option value="0">Todos</option>
                <?php foreach ($provs as $p): ?><option value="<?= (int) $p['id'] ?>" <?= $filtros['proveedor_id'] === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['nombre']) ?></option><?php endforeach; ?>
            </select></div>
        <div class="campo"><label for="fev">Evento</label>
            <select id="fev" name="evento" data-autoenviar><option value="0">Todos</option>
                <?php foreach ($eventosSel as $x): ?><option value="<?= (int) $x['id'] ?>" <?= $filtros['evento_id'] === (int) $x['id'] ? 'selected' : '' ?>><?= e(fecha_corta($x['fecha'], false)) ?> · <?= e($x['nombre']) ?></option><?php endforeach; ?>
            </select></div>
        <div class="campo"><label for="fq">Buscar</label><input type="search" id="fq" name="q" value="<?= e($filtros['q']) ?>" placeholder="OC-0003, factura, proveedor…"></div>
    </form>
    <?php if (!$lista): ?>
        <div class="vacio"><p><strong><?= array_filter($filtros) ? 'No hay compras con ese filtro.' : 'Todavía no hay compras.' ?></strong></p>
            <p>Registra lo que les compras a tus proveedores para saber cuánto les debes y cuánto ganas de verdad en cada evento.</p></div>
    <?php else: $listaCompras = $lista; require RAIZ . '/app/vistas/_pv_compras.php'; ?>
    <?php endif; ?>
</section>
