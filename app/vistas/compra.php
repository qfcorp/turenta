<?php
use TuRenta\{Adjuntos, Compras, Cotizaciones, Incidencias, ProveedoresFicha};

/** Una compra. @var array $c @var array $items @var array $pagos @var array $negocio @var bool $puede @var array $eventosSel @var array $historia
 *  @var array $incidenciasCompra @var string $wa @var ?string $portal */
$id = (int) $c['id'];
$activa = in_array($c['estado'], Compras::ACTIVAS, true);
$esPrecio = $c['estado'] === 'cotizacion';
$hoyTxt = date('Y-m-d');
$num = fn($x) => $x === null || $x === '' ? '' : rtrim(rtrim(number_format((float) $x, 4, ',', ''), '0'), ',');
$ivaTxt = rtrim(rtrim(number_format((float) $c['iva_pct'], 2, ',', ''), '0'), ',');
$botonesEstado = [
    'pedida' => $c['estado'] === 'confirmada' ? ['Volver a pedida', 'boton-claro'] : ['Pasar a compra (pedida)', ''],
    'confirmada' => $c['estado'] === 'entregada' ? ['Deshacer la entrega', 'boton-claro'] : ['El proveedor confirmó', ''],
    'entregada' => ['Entregó / cumplió', ''],
    'cotizacion' => ['Reabrir como pedido de precio', 'boton-claro'],
];
$clsPago = ['pagada' => 'bien', 'vencida' => 'alerta', 'parcial' => '', 'pendiente' => '', 'sin_valor' => '', 'no_aplica' => ''];
$filasItems = $items ?: [];
$filasItems[] = ['descripcion' => '', 'cantidad' => '', 'precio_unitario' => '', 'con_iva' => true];
$filasItems[] = ['descripcion' => '', 'cantidad' => '', 'precio_unitario' => '', 'con_iva' => true];
$sugerido = $c['anticipo_pendiente'] > 0 ? min($c['anticipo_pendiente'], $c['saldo']) : $c['saldo'];
?>
<div class="evento-cabeza">
    <div>
        <p class="evento-sub" style="margin:0"><a href="<?= e(url('compras')) ?>">Compras</a> · <?= e($esPrecio ? 'Pedido de precio' : 'Orden de compra') ?></p>
        <h1><?= e($c['codigo']) ?> · <a href="<?= e(url('proveedor', ['id' => $c['proveedor_id']])) ?>"><?= e($c['proveedor']) ?></a></h1>
        <p class="evento-sub"><?= e(fecha_corta($c['fecha'])) ?> · <?= e(Cotizaciones::CATEGORIAS[$c['categoria']] ?? $c['categoria']) ?> ·
            <?= $c['evento'] ? '<a href="' . e(url('evento', ['id' => $c['evento_id']])) . '">' . e($c['evento']) . '</a> (' . e(fecha_corta($c['evento_fecha'])) . ')' : 'Compra general del negocio' ?></p>
    </div>
    <span class="pv-estado pv-estado-<?= e($c['estado']) ?>" title="<?= e(Compras::ESTADOS_AYUDA[$c['estado']]) ?>"><?= e(Compras::ESTADOS[$c['estado']]) ?></span>
</div>
<?php if ($c['estado'] === 'cancelada' && $c['motivo_cancelacion']): ?><p class="aviso aviso-error">Cancelada: <?= e($c['motivo_cancelacion']) ?></p><?php endif; ?>
<?php if ($c['respuesta_proveedor'] && $c['estado'] !== 'cancelada'): ?><p class="aviso aviso-ok">El proveedor dijo: “<?= e($c['respuesta_proveedor']) ?>”</p><?php endif; ?>

<div class="cifras">
    <div class="cifra-tarjeta"><small><?= $c['factura_total'] !== null ? 'Valor facturado' : 'Total' ?></small><strong><?= dinero($c['base']) ?></strong></div>
    <?php if ($activa): ?>
    <div class="cifra-tarjeta bien"><small>Pagado</small><strong><?= dinero($c['pagado']) ?></strong></div>
    <div class="cifra-tarjeta <?= $clsPago[$c['estado_pago']] ?>"><small>Saldo · <?= e(Compras::ESTADOS_PAGO[$c['estado_pago']]) ?></small><strong><?= dinero($c['saldo']) ?></strong></div>
    <div class="cifra-tarjeta <?= $c['estado_pago'] === 'vencida' ? 'alerta' : '' ?>"><small>Pagar hasta</small><strong><?= $c['vence'] ? e(fecha_corta($c['vence'])) : '—' ?></strong></div>
    <?php endif; ?>
</div>

<div class="acciones pv-barra">
    <a class="boton boton-wa boton-chico" href="<?= e($wa) ?>" target="_blank" rel="noopener"><?= $esPrecio ? 'Pedir el precio por WhatsApp' : 'Enviar por WhatsApp' ?></a>
    <a class="boton boton-claro boton-chico" href="<?= e(url('pdf', ['tipo' => 'orden_compra', 'id' => $id])) ?>" target="_blank" rel="noopener">PDF</a>
    <?php if ($portal && !$esPrecio): ?><button type="button" class="boton boton-claro boton-chico" data-copiar="<?= e($portal) ?>">Copiar enlace del portal</button><?php endif; ?>
    <?php if ($puede): foreach (Compras::PASOS[$c['estado']] as $paso): if ($paso === 'cancelada') continue; [$txt, $cls] = $botonesEstado[$paso]; ?>
        <form method="post"><?= campo_csrf() ?><input type="hidden" name="accion" value="estado"><input type="hidden" name="nuevo" value="<?= e($paso) ?>">
            <button class="boton boton-chico <?= e($cls) ?>" type="submit"><?= e($txt) ?></button></form>
    <?php endforeach; endif; ?>
</div>
<?php if ($puede && in_array('cancelada', Compras::PASOS[$c['estado']], true)): ?>
<details class="pv-detalles pv-cancelar"><summary>Cancelar <?= $esPrecio ? 'el pedido de precio' : 'la compra' ?></summary>
    <form method="post" class="dia-form-bloque"><?= campo_csrf() ?><input type="hidden" name="accion" value="estado"><input type="hidden" name="nuevo" value="cancelada">
        <div class="campo"><label for="cmot">¿Por qué se cancela?</label><input type="text" id="cmot" name="motivo" maxlength="300" required minlength="3" placeholder="El cliente cambió el menú"></div>
        <button class="boton boton-peligro boton-chico" type="submit">Cancelar</button>
    </form>
</details>
<?php endif; ?>

<div class="rejilla rejilla-2">
<section class="bloque" id="detalle">
    <h2>Detalle</h2>
    <?php if (!$items): ?><p class="vacio">Sin ítems.</p><?php else: ?>
    <ul class="filas pv-items-lista">
        <?php foreach ($items as $it): ?>
        <li><div><strong><?= e(rtrim(rtrim(number_format((float) $it['cantidad'], 2, ',', '.'), '0'), ',')) ?> ×</strong> <?= e($it['descripcion']) ?>
            <br><small><?= dinero($it['precio_unitario']) ?> c/u<?= $it['con_iva'] ? '' : ' · sin IVA' ?></small></div>
            <div class="derecha monto"><?= dinero($it['subtotal']) ?></div></li>
        <?php endforeach; ?>
        <li><span>Subtotal</span><span class="monto"><?= dinero($c['subtotal']) ?></span></li>
        <li><span>IVA <?= e($ivaTxt) ?> %</span><span class="monto"><?= dinero($c['iva']) ?></span></li>
        <li><strong>Total de la orden</strong><strong class="monto"><?= dinero($c['total']) ?></strong></li>
    </ul>
    <?php endif; ?>
    <?php if ($puede && $c['estado'] !== 'cancelada'): ?>
    <details class="pv-detalles"><summary>Cambiar el detalle</summary>
        <form method="post" class="pv-form-compra" data-iva="<?= e((string) $c['iva_pct']) ?>"><?= campo_csrf() ?><input type="hidden" name="accion" value="items">
            <fieldset class="pv-items">
                <legend class="sr-only">Ítems</legend>
                <div class="pv-items-cab" aria-hidden="true"><span>Descripción</span><span>Cant.</span><span>Precio unit.</span><span>IVA</span></div>
                <?php foreach ($filasItems as $i => $it): ?>
                <div class="pv-item">
                    <input type="text" name="items[<?= $i ?>][descripcion]" value="<?= e($it['descripcion']) ?>" maxlength="300" aria-label="Descripción de la línea <?= $i + 1 ?>">
                    <input type="text" name="items[<?= $i ?>][cantidad]" value="<?= e($num($it['cantidad'])) ?>" inputmode="decimal" aria-label="Cantidad">
                    <input type="text" name="items[<?= $i ?>][precio_unitario]" value="<?= e($num($it['precio_unitario'])) ?>" inputmode="decimal" aria-label="Precio unitario">
                    <select name="items[<?= $i ?>][con_iva]" aria-label="IVA"><option value="1" <?= $it['con_iva'] ? 'selected' : '' ?>><?= e($ivaTxt) ?> %</option><option value="0" <?= !$it['con_iva'] ? 'selected' : '' ?>>0 %</option></select>
                </div>
                <?php endforeach; ?>
                <button type="button" class="boton boton-claro boton-chico" data-pv-agregar-item>+ Otra línea</button>
                <p class="pv-total-vivo" data-pv-total hidden></p>
            </fieldset>
            <button class="boton boton-chico" type="submit">Guardar detalle</button>
        </form>
    </details>
    <?php endif; ?>
</section>

<section class="bloque" id="condiciones">
    <h2>Condiciones</h2>
    <dl class="ficha-datos">
        <dt>Crédito del proveedor</dt><dd><?= e(ProveedoresFicha::textoCredito((int) $c['credito_dias']) === 'contado' ? 'De contado' : ProveedoresFicha::textoCredito((int) $c['credito_dias'])) ?></dd>
        <dt>Pagar hasta</dt><dd><?= $c['vence'] ? e(fecha_corta($c['vence'])) : '—' ?><?= $c['vence_manual'] ? ' <small>(a mano)</small>' : '' ?></dd>
        <dt>Anticipo pactado</dt><dd><?= dinero($c['anticipo']) ?><?= $c['anticipo'] > 0 && $c['anticipo_pendiente'] > 0 && $activa ? ' <small class="cb-rojo">(falta ' . dinero($c['anticipo_pendiente']) . ')</small>' : '' ?></dd>
        <dt>Retención</dt><dd><?= dinero($c['retencion']) ?></dd>
        <?php if ($c['descuentos'] > 0): ?><dt>Descuentos por problemas</dt><dd><?= dinero($c['descuentos']) ?></dd><?php endif; ?>
        <?php if ($c['enviada_en']): ?><dt>Enviada</dt><dd><?= e(fecha_corta(substr((string) $c['enviada_en'], 0, 10))) ?></dd><?php endif; ?>
        <?php if ($c['confirmada_en']): ?><dt>Confirmada</dt><dd><?= e(fecha_corta(substr((string) $c['confirmada_en'], 0, 10))) ?><?= $c['confirmada_por'] === 'proveedor' ? ' <small>(por el proveedor)</small>' : '' ?></dd><?php endif; ?>
        <?php if ($c['entregada_en']): ?><dt>Entregada</dt><dd><?= e(fecha_corta(substr((string) $c['entregada_en'], 0, 10))) ?></dd><?php endif; ?>
    </dl>
    <?php if ($c['notas']): ?><p class="suave"><?= nl2br(e($c['notas'])) ?></p><?php endif; ?>
    <?php if ($puede && $c['estado'] !== 'cancelada'): ?>
    <details class="pv-detalles"><summary>Cambiar condiciones o evento</summary>
        <form method="post" class="dia-form-bloque"><?= campo_csrf() ?><input type="hidden" name="accion" value="editar">
            <div class="campo"><label for="eev">Evento</label>
                <select id="eev" name="evento_id"><option value="0">— Compra general del negocio —</option>
                    <?php foreach ($eventosSel as $x): ?><option value="<?= (int) $x['id'] ?>" <?= (int) $c['evento_id'] === (int) $x['id'] ? 'selected' : '' ?>><?= e(fecha_corta($x['fecha'])) ?> · <?= e($x['nombre']) ?></option><?php endforeach; ?>
                </select></div>
            <div class="fila-campos">
                <div class="campo"><label for="ecat">Rubro</label><select id="ecat" name="categoria">
                    <?php foreach (Cotizaciones::CATEGORIAS as $k => $t): ?><option value="<?= e($k) ?>" <?= $c['categoria'] === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
                <div class="campo"><label for="efec">Fecha</label><input type="date" id="efec" name="fecha" value="<?= e($c['fecha']) ?>"></div>
            </div>
            <div class="fila-campos">
                <div class="campo"><label for="even">Pagar hasta <small>(vacío = según su crédito)</small></label><input type="date" id="even" name="vence" value="<?= e($c['vence_manual'] ? $c['vence'] : '') ?>"></div>
                <div class="campo"><label for="eant">Anticipo pactado ($)</label><input type="text" id="eant" name="anticipo" inputmode="decimal" value="<?= e($num($c['anticipo'])) ?>"></div>
            </div>
            <div class="campo"><label for="eret">Retención ($) <small>(lo que le retienes; baja lo que le pagas)</small></label><input type="text" id="eret" name="retencion" inputmode="decimal" value="<?= e($num($c['retencion'])) ?>"></div>
            <div class="campo"><label for="enot">Notas</label><textarea id="enot" name="notas" rows="2" maxlength="2000"><?= e($c['notas']) ?></textarea></div>
            <button class="boton boton-chico" type="submit">Guardar</button>
        </form>
    </details>
    <?php endif; ?>
</section>
</div>

<?php if (!$esPrecio): ?>
<section class="bloque" id="factura">
    <h2>Factura del proveedor</h2>
    <?php if ($c['factura_numero'] || $c['factura_archivo'] || $c['factura_xml']): ?>
        <dl class="ficha-datos">
            <dt>Número</dt><dd><?= e($c['factura_numero'] ?: '—') ?></dd>
            <dt>Fecha</dt><dd><?= $c['factura_fecha'] ? e(fecha_corta($c['factura_fecha'])) : '—' ?></dd>
            <dt>Total facturado</dt><dd><?= $c['factura_total'] !== null ? dinero($c['factura_total']) : '—' ?><?= $c['factura_total'] !== null && abs((float) $c['factura_total'] - $c['total']) > 0.009 ? ' <small class="cb-rojo">(la orden era de ' . dinero($c['total']) . ')</small>' : '' ?></dd>
            <?php if ($c['factura_clave']): ?><dt>Clave de acceso</dt><dd class="pv-clave"><?= e($c['factura_clave']) ?></dd><?php endif; ?>
            <dt>La subió</dt><dd><?= $c['factura_origen'] === 'proveedor' ? 'El proveedor (desde su portal)' : 'Tu equipo' ?></dd>
        </dl>
        <div class="acciones">
            <?php if ($c['factura_archivo']): ?><a class="boton boton-claro boton-chico" href="<?= e(url('proveedor_archivo', ['tipo' => 'factura', 'id' => $id])) ?>" target="_blank" rel="noopener">Ver <?= Adjuntos::esPdf($c['factura_archivo']) ? 'PDF' : 'foto' ?></a><?php endif; ?>
            <?php if ($c['factura_xml']): ?><a class="boton boton-claro boton-chico" href="<?= e(url('proveedor_archivo', ['tipo' => 'xml', 'id' => $id])) ?>">Descargar XML</a><?php endif; ?>
        </div>
    <?php else: ?>
        <p class="suave">Todavía no hay factura.<?= $portal ? ' El proveedor también la puede subir desde su portal.' : '' ?></p>
    <?php endif; ?>
    <?php if ($puede && $activa): ?>
    <div class="rejilla rejilla-2 pv-factura-forms">
        <form method="post" enctype="multipart/form-data" class="pv-caja"><?= campo_csrf() ?><input type="hidden" name="accion" value="xml">
            <h3>Con el XML (lo más fácil)</h3>
            <p class="suave"><small>Se llenan solos el número, la fecha y el total. Revisa que el RUC sea el del proveedor.</small></p>
            <div class="campo"><label for="fxml">Archivo XML</label><input type="file" id="fxml" name="xml" accept=".xml,text/xml,application/xml" required></div>
            <button class="boton boton-chico" type="submit">Leer XML</button>
        </form>
        <form method="post" enctype="multipart/form-data" class="pv-caja"><?= campo_csrf() ?><input type="hidden" name="accion" value="factura">
            <h3>A mano</h3>
            <div class="campo"><label for="fnum">Número</label><input type="text" id="fnum" name="factura_numero" value="<?= e($c['factura_numero']) ?>" placeholder="001-001-000000123" maxlength="17"></div>
            <div class="fila-campos">
                <div class="campo"><label for="ffec">Fecha</label><input type="date" id="ffec" name="factura_fecha" value="<?= e($c['factura_fecha']) ?>"></div>
                <div class="campo"><label for="ftot">Total ($)</label><input type="text" id="ftot" name="factura_total" inputmode="decimal" value="<?= e($num($c['factura_total'])) ?>" placeholder="<?= e($num($c['total'])) ?>"></div>
            </div>
            <div class="campo"><label for="farc">PDF o foto <small>(opcional)</small></label><input type="file" id="farc" name="archivo" accept="image/*,application/pdf"></div>
            <button class="boton boton-claro boton-chico" type="submit">Guardar factura</button>
        </form>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($activa || $pagos): ?>
<section class="bloque" id="pagos">
    <h2>Pagos al proveedor</h2>
    <?php if (!$pagos): ?><p class="vacio">Todavía no le has pagado.</p><?php else: ?>
    <ul class="filas pv-pagos">
        <?php foreach ($pagos as $x): ?>
        <li class="<?= $x['estado'] === 'anulado' ? 'pv-anulado' : '' ?>">
            <div><strong><?= dinero($x['monto']) ?></strong> · <?= e(Compras::METODOS[$x['metodo']] ?? $x['metodo']) ?><?= $x['referencia'] ? ' · ' . e($x['referencia']) : '' ?>
                <br><small><?= e(fecha_corta($x['fecha'])) ?><?= $x['cheque_fecha'] ? ' · cheque para el ' . e(fecha_corta($x['cheque_fecha'], false)) . ($x['cheque_fecha'] >= $hoyTxt ? ' (por cobrar)' : '') : '' ?>
                    <?= $x['registrado'] ? ' · ' . e($x['registrado']) : '' ?><?= $x['notas'] ? ' · ' . e($x['notas']) : '' ?></small>
                <?php if ($x['estado'] === 'anulado'): ?><br><small class="cb-rojo">ANULADO<?= $x['anulado_nombre'] ? ' por ' . e($x['anulado_nombre']) : '' ?>: <?= e($x['anulado_motivo']) ?></small><?php endif; ?>
            </div>
            <div class="derecha acciones">
                <?php if ($x['comprobante']): ?><a class="enlace-chico" href="<?= e(url('proveedor_archivo', ['tipo' => 'comprobante', 'id' => $x['id']])) ?>" target="_blank" rel="noopener">Comprobante</a><?php endif; ?>
                <?php if ($puede && $x['estado'] === 'valido'): ?>
                <details class="pv-anular"><summary>Anular</summary>
                    <form method="post"><?= campo_csrf() ?><input type="hidden" name="accion" value="anular_pago"><input type="hidden" name="pago_id" value="<?= (int) $x['id'] ?>">
                        <label class="sr-only" for="am<?= (int) $x['id'] ?>">Motivo</label>
                        <input type="text" id="am<?= (int) $x['id'] ?>" name="motivo" required minlength="3" maxlength="300" placeholder="¿Por qué se anula?">
                        <button class="boton boton-peligro boton-chico" type="submit">Anular pago</button></form>
                </details>
                <?php endif; ?>
            </div>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <?php if ($puede && $activa && $c['saldo'] > 0): ?>
    <form method="post" enctype="multipart/form-data" class="rejilla-form pv-form-pago"><?= campo_csrf() ?><input type="hidden" name="accion" value="pago">
        <h3 class="ancho">Registrar un pago</h3>
        <div class="campo"><label for="pmon">Valor ($) <small>(saldo <?= dinero($c['saldo']) ?>)</small></label><input type="text" id="pmon" name="monto" inputmode="decimal" required value="<?= e($num($sugerido)) ?>"></div>
        <div class="campo"><label for="pfec">Fecha</label><input type="date" id="pfec" name="fecha" value="<?= e($hoyTxt) ?>" max="<?= e($hoyTxt) ?>"></div>
        <div class="campo"><label for="pmet">Forma de pago</label>
            <select id="pmet" name="metodo" data-mostrar="pv-cheque"><?php foreach (Compras::METODOS as $k => $t): ?><option value="<?= e($k) ?>"><?= e($t) ?></option><?php endforeach; ?></select></div>
        <div class="campo"><label for="pref">Referencia <small>(n.º de transferencia o de cheque)</small></label><input type="text" id="pref" name="referencia" maxlength="120"></div>
        <div class="campo pv-cheque" data-si="cheque" hidden><label for="pche">Cheque para el <small>(si es posfechado)</small></label><input type="date" id="pche" name="cheque_fecha"></div>
        <div class="campo"><label for="pcom">Comprobante <small>(foto o PDF, opcional)</small></label><input type="file" id="pcom" name="comprobante" accept="image/*,application/pdf"></div>
        <div class="campo ancho"><label for="pnot">Notas <small>(opcional)</small></label><input type="text" id="pnot" name="notas" maxlength="300"></div>
        <div class="ancho"><button class="boton" type="submit">Guardar pago</button></div>
    </form>
    <?php elseif ($activa && $c['saldo'] <= 0 && $c['base'] > 0): ?>
        <p class="aviso aviso-ok">✓ Esta compra está pagada por completo.</p>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($incidenciasCompra): ?>
<section class="bloque">
    <h2>Problemas con esta compra</h2>
    <ul class="filas">
        <?php foreach ($incidenciasCompra as $i): ?>
        <li><div><span class="pv-chip pv-grav-<?= e($i['gravedad']) ?>"><?= e(Incidencias::GRAVEDADES[$i['gravedad']]) ?></span> <?= e($i['descripcion']) ?><br><small><?= e(Incidencias::ESTADOS[$i['estado']]) ?></small></div>
            <div class="derecha monto"><?= (float) $i['descuento'] > 0 ? '−' . dinero($i['descuento']) : '' ?></div></li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<section class="bloque">
    <h2>Historia de la compra</h2>
    <?php if (!$historia): ?><p class="vacio">Sin movimientos.</p><?php else: ?>
    <ol class="pv-linea">
        <?php foreach ($historia as $m): ?>
        <li class="pv-mov-<?= e($m['tipo']) ?>"><span class="pv-linea-punto" aria-hidden="true"></span>
            <div><p><?= e($m['accion']) ?></p><small><?= e(fecha_corta(substr((string) $m['creado_en'], 0, 10))) ?> <?= e(substr((string) $m['creado_en'], 11, 5)) ?> · <span class="pv-origen-<?= e($m['origen']) ?>"><?= e($m['quien']) ?></span></small></div></li>
        <?php endforeach; ?>
    </ol>
    <?php endif; ?>
</section>
