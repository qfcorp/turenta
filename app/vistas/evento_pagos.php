<?php
use TuRenta\{Pagos, Cobranza};
/**
 * Pagos del evento.
 * @var array $ev @var array $res @var array $lista @var array $porRevisar @var array $ganancia @var array $gastos
 * @var array $proveedores @var string $reservaPct @var string $portal @var ?string $recordatorio @var ?string $waPortal @var bool $puede @var int $ultimoPago
 */
require RAIZ . '/app/vistas/_evento_tabs.php';
$estadosCuota = ['pagada' => 'Pagada', 'parcial' => 'Abonada', 'pendiente' => 'Pendiente', 'vencida' => 'Vencida'];
$g = $res['garantia'];
$hoy = date('Y-m-d');
$sinValor = $res['total'] <= 0;
$sugerido = $res['proxima'] ? (float) $res['proxima']['saldo'] : $res['saldo'];
$activo = !in_array($ev['estado'], ['perdido', 'cancelado'], true);
$num = fn(float $v) => number_format($v, 2, ',', '');
?>
<?php if (!$activo): ?>
    <p class="aviso aviso-error">Este evento está <?= e(mb_strtolower(\TuRenta\Eventos::ESTADOS[$ev['estado']])) ?>. Puedes ver sus pagos, devolver la garantía o anular pagos, pero ya no se le cobran cuotas.</p>
<?php endif; ?>

<?php if ($sinValor): ?>
    <section class="bloque vacio cb-vacio">
        <h2>Este evento todavía no tiene valor</h2>
        <p>Cuando el cliente acepte una cotización, aquí aparece su plan de pagos (reserva, cuotas y saldo).</p>
        <a class="boton" href="<?= e(url('cotizacion', ['id' => $ev['id']])) ?>">Ir a la cotización</a>
    </section>
<?php else: ?>
<section class="cifras cb-cifras" aria-label="Resumen de pagos">
    <div class="cifra-tarjeta"><small>Valor del evento</small><strong><?= dinero($res['total']) ?></strong></div>
    <div class="cifra-tarjeta bien"><small>Pagado</small><strong><?= dinero($res['pagado']) ?></strong></div>
    <div class="cifra-tarjeta"><small>Falta pagar</small><strong><?= dinero($res['saldo']) ?></strong></div>
    <div class="cifra-tarjeta <?= $res['vencido'] > 0 ? 'alerta' : '' ?>"><small>Vencido</small><strong><?= dinero($res['vencido']) ?></strong></div>
    <?php if ($res['a_favor'] > 0): ?><div class="cifra-tarjeta bien"><small>Saldo a favor del cliente</small><strong><?= dinero($res['a_favor']) ?></strong></div><?php endif; ?>
    <?php if ($res['por_revisar'] > 0): ?><div class="cifra-tarjeta cb-revisar"><small>Comprobantes por revisar</small><strong><?= dinero($res['por_revisar']) ?></strong></div><?php endif; ?>
</section>
<div class="cb-avance">
    <div class="barra" role="progressbar" aria-valuenow="<?= (int) $res['pct'] ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Avance de pagos"><span style="width:<?= (int) $res['pct'] ?>%"></span></div>
    <p class="suave"><?= (int) $res['pct'] ?> % pagado<?php if ($res['proxima']): ?> · Próximo pago: <strong><?= e($res['proxima']['concepto']) ?></strong>
        por <?= dinero($res['proxima']['saldo']) ?>, <?= $res['proxima']['vence'] < $hoy ? '<span class="cb-rojo">venció el ' . e(fecha_corta($res['proxima']['vence'])) . '</span>' : 'vence el ' . e(fecha_corta($res['proxima']['vence'])) ?><?php elseif ($res['saldo'] <= 0): ?> · <strong class="cb-verde">¡Evento pagado por completo!</strong><?php endif; ?></p>
</div>
<?php endif; ?>

<div class="acciones cb-acciones no-imprimir">
    <?php if ($puede && $activo && !$sinValor && $res['saldo'] > 0): ?><a class="boton" href="#registrar">Registrar un pago</a><?php endif; ?>
    <?php if ($recordatorio && $res['saldo'] > 0): ?><a class="boton boton-wa" href="<?= e($recordatorio) ?>" target="_blank" rel="noopener">Recordar el pago por WhatsApp</a><?php endif; ?>
    <a class="boton boton-claro" href="<?= e(url('pdf', ['tipo' => 'estado_cuenta', 'id' => $ev['id']])) ?>" target="_blank">Estado de cuenta (PDF)</a>
    <a class="boton boton-claro" href="<?= e($portal) ?>" target="_blank" rel="noopener">Ver el portal del cliente</a>
    <button type="button" class="boton boton-claro" data-copiar="<?= e($portal) ?>">Copiar enlace del portal</button>
    <?php if ($waPortal): ?><a class="boton boton-claro" href="<?= e($waPortal) ?>" target="_blank" rel="noopener">Enviar portal por WhatsApp</a><?php endif; ?>
</div>
<?php if (!$ev['cliente_telefono']): ?><p class="suave cb-nota">El cliente no tiene celular registrado: agrégalo en el evento para mandarle recordatorios por WhatsApp.</p><?php endif; ?>

<?php if ($porRevisar): ?>
<section class="bloque cb-por-revisar" id="revisar">
    <h2>Comprobantes por revisar (<?= count($porRevisar) ?>)</h2>
    <p class="suave">El cliente los subió desde su portal. Revisa en tu banco que el dinero llegó y confírmalos; recién ahí suman a lo pagado.</p>
    <?php foreach ($porRevisar as $p): ?>
        <article class="cb-comprobante">
            <?php if ($p['comprobante']): ?>
                <a class="cb-foto" href="<?= e(url('archivo', ['tipo' => 'comprobante', 'id' => $p['id']])) ?>" target="_blank" title="Ver la foto grande">
                    <img src="<?= e(url('archivo', ['tipo' => 'comprobante', 'id' => $p['id']])) ?>" alt="Foto del comprobante de <?= e(dinero($p['monto'])) ?>" loading="lazy">
                </a>
            <?php endif; ?>
            <div class="cb-comprobante-datos">
                <p class="cb-monto"><?= dinero($p['monto']) ?> <small>· <?= e(Pagos::METODOS[$p['metodo']]) ?></small></p>
                <p class="suave">Subido el <?= e(fecha_corta($p['creado_en'])) ?> a las <?= e(substr($p['creado_en'], 11, 5)) ?><?= $p['referencia'] ? ' · Ref. ' . e($p['referencia']) : '' ?></p>
                <?php if ($puede): ?>
                <form method="post" action="<?= e(url('evento_pagos', ['id' => $ev['id'], 'accion' => 'confirmar'])) ?>" class="cb-fila-form">
                    <?= campo_csrf() ?><input type="hidden" name="pago_id" value="<?= (int) $p['id'] ?>">
                    <label class="sr-only" for="ref<?= (int) $p['id'] ?>">Número de comprobante</label>
                    <input type="text" id="ref<?= (int) $p['id'] ?>" name="referencia" maxlength="120" placeholder="N.º de comprobante (opcional)" value="<?= e($p['referencia']) ?>">
                    <button class="boton boton-chico" type="submit">Confirmar: el dinero llegó</button>
                </form>
                <details class="cb-detalles">
                    <summary>Rechazar</summary>
                    <form method="post" action="<?= e(url('evento_pagos', ['id' => $ev['id'], 'accion' => 'rechazar'])) ?>" class="cb-fila-form">
                        <?= campo_csrf() ?><input type="hidden" name="pago_id" value="<?= (int) $p['id'] ?>">
                        <label class="sr-only" for="mot<?= (int) $p['id'] ?>">Motivo</label>
                        <input type="text" id="mot<?= (int) $p['id'] ?>" name="motivo" maxlength="200" placeholder="Motivo (ej.: no llegó a la cuenta)">
                        <button class="boton boton-chico boton-peligro" type="submit" data-confirmar="¿Rechazar este comprobante? No sumará a lo pagado.">Rechazar comprobante</button>
                    </form>
                </details>
                <?php endif; ?>
            </div>
        </article>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<?php if (!$sinValor): ?>
<section class="bloque" id="plan">
    <h2>Plan de pagos</h2>
    <?php if ($res['cuotas']): ?>
    <div class="tabla-scroll">
        <table class="tabla cb-tabla-cuotas">
            <thead><tr><th>Pago</th><th>Vence</th><th class="num">Valor</th><th class="num">Pagado</th><th class="num">Falta</th><th>Estado</th></tr></thead>
            <tbody>
            <?php foreach ($res['cuotas'] as $c): ?>
                <tr class="cb-cuota-<?= e($c['estado']) ?>">
                    <td><strong><?= e($c['concepto']) ?></strong></td>
                    <td><?= e(fecha_corta($c['vence'])) ?></td>
                    <td class="num"><?= dinero($c['monto']) ?></td>
                    <td class="num"><?= dinero($c['pagado']) ?></td>
                    <td class="num"><?= dinero($c['saldo']) ?></td>
                    <td><span class="cb-etq cb-etq-<?= e($c['estado']) ?>"><?= $estadosCuota[$c['estado']] ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr class="fila-total"><td colspan="2">Total</td><td class="num"><?= dinero($res['total']) ?></td><td class="num"><?= dinero(min($res['pagado'], $res['total'])) ?></td><td class="num"><?= dinero($res['saldo']) ?></td><td></td></tr></tfoot>
        </table>
    </div>
    <?php else: ?>
        <p class="vacio">Todavía no hay plan de pagos. Ármalo abajo con el número de pagos que acordaste con el cliente.</p>
    <?php endif; ?>

    <?php if ($puede): ?>
    <div class="cb-plan-acciones no-imprimir">
        <details class="cb-detalles" <?= $res['cuotas'] ? '' : 'open' ?>>
            <summary>Armar el plan automático</summary>
            <form method="post" action="<?= e(url('evento_pagos', ['id' => $ev['id'], 'accion' => 'regenerar'])) ?>">
                <?= campo_csrf() ?>
                <p class="suave">Reserva hoy + cuotas repartidas hasta el saldo, que vence unos días antes del evento (según tu configuración). Lo ya pagado se vuelve a repartir.</p>
                <div class="fila-campos">
                    <div class="campo"><label for="cuotas">Número de pagos (con reserva y saldo)</label>
                        <input type="number" id="cuotas" name="cuotas" min="1" max="24" value="<?= max(1, count($res['cuotas']) ?: 3) ?>" required></div>
                    <div class="campo"><label for="reserva_pct">Reserva (%)</label>
                        <input type="number" id="reserva_pct" name="reserva_pct" min="1" max="100" step="0.5" value="<?= e($reservaPct) ?>" required></div>
                </div>
                <button class="boton boton-claro" type="submit" <?= $res['cuotas'] ? 'data-confirmar="Se reemplaza el plan actual por uno nuevo. ¿Seguir?"' : '' ?>>Armar plan</button>
            </form>
        </details>
        <details class="cb-detalles">
            <summary>Cambiar el plan a mano</summary>
            <form method="post" action="<?= e(url('evento_pagos', ['id' => $ev['id'], 'accion' => 'plan'])) ?>" class="cb-plan-mano" data-total="<?= e((string) $res['total']) ?>">
                <?= campo_csrf() ?>
                <p class="suave">Escribe cada pago con su fecha. La suma debe ser igual al valor del evento: <strong><?= dinero($res['total']) ?></strong>. Para quitar un pago, deja su valor vacío.</p>
                <div class="cb-plan-filas">
                    <?php $filas = $res['cuotas'] ?: [['concepto' => 'Reserva', 'vence' => $hoy, 'monto' => $res['total']]];
                    foreach ($filas as $i => $c): ?>
                    <div class="cb-plan-fila">
                        <div class="campo"><label for="pc<?= $i ?>">Pago</label><input type="text" id="pc<?= $i ?>" name="concepto[]" maxlength="80" value="<?= e($c['concepto']) ?>"></div>
                        <div class="campo"><label for="pv<?= $i ?>">Vence</label><input type="date" id="pv<?= $i ?>" name="vence[]" value="<?= e($c['vence']) ?>"></div>
                        <div class="campo"><label for="pm<?= $i ?>">Valor</label><input type="text" inputmode="decimal" id="pm<?= $i ?>" name="monto_cuota[]" value="<?= e($num((float) $c['monto'])) ?>" class="cb-monto-cuota"></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <p class="cb-suma" aria-live="polite"></p>
                <div class="acciones">
                    <button type="button" class="boton boton-claro boton-chico" data-cb-agregar-fila>+ Agregar otro pago</button>
                    <button class="boton" type="submit">Guardar plan</button>
                </div>
            </form>
        </details>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($puede && $activo && !$sinValor): ?>
<section class="bloque" id="registrar">
    <h2>Registrar un pago recibido</h2>
    <?php if ($res['saldo'] <= 0): ?><p class="aviso aviso-ok">El evento ya está pagado por completo. Si recibes algo más, quedará como saldo a favor del cliente.</p><?php endif; ?>
    <form method="post" action="<?= e(url('evento_pagos', ['id' => $ev['id'], 'accion' => 'pago'])) ?>" enctype="multipart/form-data" class="rejilla-form">
        <?= campo_csrf() ?>
        <div class="campo"><label for="monto">Valor recibido ($)</label>
            <input type="text" inputmode="decimal" id="monto" name="monto" required value="<?= $sugerido > 0 ? e($num($sugerido)) : '' ?>">
            <?php if ($res['proxima']): ?><small class="suave">Sugerido: lo que falta de "<?= e($res['proxima']['concepto']) ?>".</small><?php endif; ?></div>
        <div class="campo"><label for="metodo">Forma de pago</label>
            <select id="metodo" name="metodo">
                <?php foreach (Pagos::METODOS as $k => $t): if ($k === 'payphone') continue; ?><option value="<?= e($k) ?>" <?= $k === 'transferencia' ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
            </select></div>
        <div class="campo"><label for="fecha">Fecha del pago</label>
            <input type="date" id="fecha" name="fecha" value="<?= e($hoy) ?>" max="<?= e($hoy) ?>"></div>
        <div class="campo"><label for="referencia">N.º de comprobante o referencia <small>(opcional)</small></label>
            <input type="text" id="referencia" name="referencia" maxlength="120"></div>
        <div class="campo"><label for="foto">Foto del comprobante <small>(opcional)</small></label>
            <input type="file" id="foto" name="foto" accept="image/*"></div>
        <div class="campo"><label for="notas">Notas <small>(opcional)</small></label>
            <input type="text" id="notas" name="notas" maxlength="300"></div>
        <div class="ancho"><button class="boton" type="submit">Guardar pago y generar recibo</button></div>
    </form>
</section>
<?php endif; ?>

<section class="bloque" id="pagos">
    <h2>Pagos del evento</h2>
    <?php if (!$lista): ?>
        <p class="vacio">Todavía no hay pagos. Cuando registres uno (o el cliente suba su comprobante), aparece aquí con su recibo en PDF.</p>
    <?php else: ?>
    <ul class="filas cb-pagos">
        <?php foreach ($lista as $p):
            $neg = in_array($p['tipo'], ['devolucion_garantia', 'cargo_danos'], true); ?>
        <li class="cb-pago cb-pago-<?= e($p['estado']) ?> <?= $ultimoPago === (int) $p['id'] ? 'cb-nuevo' : '' ?>">
            <div>
                <strong><?= e(Pagos::TIPOS[$p['tipo']]) ?></strong>
                <?php if ($p['estado'] !== 'confirmado'): ?><span class="cb-etq cb-etq-<?= e($p['estado']) ?>"><?= e(Pagos::ESTADOS[$p['estado']]) ?></span><?php endif; ?>
                <br><small class="suave"><?= e(fecha_corta($p['fecha'])) ?> · <?= e(Pagos::METODOS[$p['metodo']]) ?><?= $p['referencia'] && $p['metodo'] !== 'payphone' ? ' · Ref. ' . e($p['referencia']) : '' ?>
                    <?= $p['numero_recibo'] ? ' · Recibo N.º ' . (int) $p['numero_recibo'] : '' ?><?= $p['origen'] === 'cliente' ? ' · subido por el cliente' : '' ?><?= $p['origen'] === 'payphone' ? ' · pagó con tarjeta' : '' ?>
                    <?= $p['registrado'] ? ' · ' . e($p['registrado']) : '' ?></small>
                <?php if ($p['notas']): ?><br><small class="suave"><?= e($p['notas']) ?></small><?php endif; ?>
            </div>
            <div class="derecha">
                <span class="monto <?= $neg ? 'monto-neg' : '' ?> <?= in_array($p['estado'], ['anulado', 'rechazado'], true) ? 'cb-tachado' : '' ?>"><?= $neg ? '−' : '' ?><?= dinero($p['monto']) ?></span>
                <div class="cb-pago-acciones">
                    <?php if ($p['comprobante']): ?><a class="enlace-chico" href="<?= e(url('archivo', ['tipo' => 'comprobante', 'id' => $p['id']])) ?>" target="_blank">Ver foto</a><?php endif; ?>
                    <?php if ($p['estado'] === 'confirmado' && $p['numero_recibo']): ?><a class="enlace-chico" href="<?= e(url('pdf', ['tipo' => 'recibo', 'id' => $p['id']])) ?>" target="_blank">Recibo PDF</a><?php endif; ?>
                    <?php if ($puede && $p['estado'] === 'confirmado'): ?>
                    <details class="cb-detalles cb-anular">
                        <summary>Anular</summary>
                        <form method="post" action="<?= e(url('evento_pagos', ['id' => $ev['id'], 'accion' => 'anular'])) ?>" class="cb-fila-form">
                            <?= campo_csrf() ?><input type="hidden" name="pago_id" value="<?= (int) $p['id'] ?>">
                            <label class="sr-only" for="an<?= (int) $p['id'] ?>">Motivo</label>
                            <input type="text" id="an<?= (int) $p['id'] ?>" name="motivo" maxlength="200" required minlength="3" placeholder="¿Por qué se anula?">
                            <button class="boton boton-chico boton-peligro" type="submit" data-confirmar="¿Anular este pago de <?= e(dinero($p['monto'])) ?>? Se resta de lo pagado y no se puede deshacer.">Anular pago</button>
                        </form>
                    </details>
                    <?php endif; ?>
                </div>
            </div>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</section>

<section class="bloque" id="garantia">
    <h2>Garantía por daños</h2>
    <p class="suave">Es un depósito aparte del valor del evento: se recibe antes, y después del evento se devuelve o se retiene lo que se dañó.</p>
    <dl class="ficha-datos cb-garantia">
        <dt>Garantía pedida</dt><dd><?= dinero($g['pedida']) ?></dd>
        <dt>Recibida</dt><dd><?= dinero($g['recibida']) ?></dd>
        <?php if ($g['devuelta'] > 0): ?><dt>Devuelta</dt><dd><?= dinero($g['devuelta']) ?></dd><?php endif; ?>
        <?php if ($g['retenida'] > 0): ?><dt>Retenida por daños</dt><dd><?= dinero($g['retenida']) ?></dd><?php endif; ?>
        <dt>En manos del local</dt><dd><strong><?= dinero($g['saldo']) ?></strong></dd>
    </dl>
    <?php if ($g['pedida'] > $g['recibida'] && $activo): ?><p class="cb-nota">Falta recibir <?= dinero($g['pedida'] - $g['recibida']) ?> de garantía.</p><?php endif; ?>
    <?php if ($g['saldo'] > 0 && $ev['fecha'] < $hoy): ?><p class="aviso aviso-error">El evento ya pasó: devuelve la garantía o registra lo retenido por daños.</p><?php endif; ?>
    <?php if ($puede): ?>
    <div class="cb-plan-acciones no-imprimir">
        <details class="cb-detalles">
            <summary>Cambiar el valor pedido</summary>
            <form method="post" action="<?= e(url('evento_pagos', ['id' => $ev['id'], 'accion' => 'garantia_monto'])) ?>" class="cb-fila-form">
                <?= campo_csrf() ?>
                <label class="sr-only" for="garantia">Garantía ($)</label>
                <input type="text" inputmode="decimal" id="garantia" name="garantia" value="<?= e($num($g['pedida'])) ?>">
                <button class="boton boton-chico boton-claro" type="submit">Guardar</button>
            </form>
        </details>
        <?php if ($activo): ?>
        <details class="cb-detalles">
            <summary>Registrar garantía recibida</summary>
            <form method="post" action="<?= e(url('evento_pagos', ['id' => $ev['id'], 'accion' => 'garantia_recibir'])) ?>" class="rejilla-form">
                <?= campo_csrf() ?>
                <div class="campo"><label for="gr_monto">Valor ($)</label><input type="text" inputmode="decimal" id="gr_monto" name="monto" required value="<?= $g['pedida'] > $g['recibida'] ? e($num($g['pedida'] - $g['recibida'])) : '' ?>"></div>
                <div class="campo"><label for="gr_metodo">Forma de pago</label><select id="gr_metodo" name="metodo"><?php foreach (Pagos::METODOS as $k => $t): if ($k === 'payphone') continue; ?><option value="<?= e($k) ?>"><?= e($t) ?></option><?php endforeach; ?></select></div>
                <div class="ancho"><button class="boton boton-claro" type="submit">Guardar garantía recibida</button></div>
            </form>
        </details>
        <?php endif; ?>
        <?php if ($g['saldo'] > 0): ?>
        <details class="cb-detalles" <?= $ev['fecha'] < $hoy ? 'open' : '' ?>>
            <summary>Devolver la garantía</summary>
            <form method="post" action="<?= e(url('evento_pagos', ['id' => $ev['id'], 'accion' => 'garantia_devolver'])) ?>" class="rejilla-form">
                <?= campo_csrf() ?>
                <div class="campo"><label for="gd_monto">Valor devuelto ($)</label><input type="text" inputmode="decimal" id="gd_monto" name="monto" required value="<?= e($num($g['saldo'])) ?>"></div>
                <div class="campo"><label for="gd_metodo">Cómo se devolvió</label><select id="gd_metodo" name="metodo"><?php foreach (['transferencia', 'efectivo', 'deuna', 'cheque', 'otro'] as $k): ?><option value="<?= e($k) ?>"><?= e(Pagos::METODOS[$k]) ?></option><?php endforeach; ?></select></div>
                <div class="campo ancho"><label for="gd_ref">Referencia <small>(opcional)</small></label><input type="text" id="gd_ref" name="referencia" maxlength="120"></div>
                <div class="ancho"><button class="boton" type="submit" data-confirmar="¿Registrar la devolución de la garantía?">Registrar devolución</button></div>
            </form>
        </details>
        <details class="cb-detalles">
            <summary>Retener por daños</summary>
            <form method="post" action="<?= e(url('evento_pagos', ['id' => $ev['id'], 'accion' => 'garantia_retener'])) ?>" class="rejilla-form">
                <?= campo_csrf() ?>
                <input type="hidden" name="metodo" value="efectivo">
                <div class="campo"><label for="gt_monto">Valor que se retiene ($)</label><input type="text" inputmode="decimal" id="gt_monto" name="monto" required></div>
                <div class="campo"><label for="gt_notas">¿Qué se dañó?</label><input type="text" id="gt_notas" name="notas" maxlength="300" required minlength="3" placeholder="Ej.: 3 copas rotas y un mantel manchado"></div>
                <div class="ancho"><button class="boton boton-peligro" type="submit" data-confirmar="¿Retener este valor de la garantía por daños?">Retener por daños</button></div>
            </form>
        </details>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</section>

<?php /* Compras a proveedores del evento (060) */ $comprasEv ??= []; $activasEv = array_filter($comprasEv, fn($c) => in_array($c['estado'], \TuRenta\Compras::ACTIVAS, true)); ?>
<?php if (modulo_activo($u, 'proveedores')): /* Anexo B: módulo "Proveedores y compras" */ ?>
<section class="bloque" id="compras">
    <div class="dia-titulo-acciones"><h2>Compras a proveedores</h2>
        <?php if ($puede): ?><a class="boton boton-claro boton-chico" href="<?= e(url('compras', ['nueva' => 1, 'evento' => $ev['id']])) ?>#nueva">Nueva compra</a><?php endif; ?></div>
    <p class="suave">Órdenes de compra de este evento (DJ, catering, flores…). Las pedidas, confirmadas y entregadas cuentan en la ganancia real; los pedidos de precio y las canceladas, no.</p>
    <?php if (!$comprasEv): ?>
        <p class="vacio">Sin compras para este evento.</p>
    <?php else: $listaCompras = $comprasEv; $ocultarEvento = true; require RAIZ . '/app/vistas/_pv_compras.php'; ?>
    <ul class="filas">
        <li><strong>Total en compras</strong><span class="monto"><?= dinero($ganancia['compras'] ?? 0) ?></span></li>
        <li><span>Saldo por pagar a proveedores</span><span class="monto"><?= dinero(array_sum(array_column($activasEv, 'saldo'))) ?></span></li>
    </ul>
    <?php endif; ?>
</section>
<?php endif; ?>

<section class="bloque" id="gastos">
    <h2>Gastos del evento</h2>
    <p class="suave">Lo que pagas a proveedores o compras para este evento. Sirve para saber cuánto ganaste de verdad.</p>
    <?php if ($gastos): ?>
    <ul class="filas">
        <?php foreach ($gastos as $gs): ?>
        <li>
            <div><strong><?= e($gs['concepto']) ?></strong><br>
                <small class="suave"><?= e(fecha_corta($gs['fecha'])) ?> · <?= e(Cobranza::CATEGORIAS_GASTO[$gs['categoria']] ?? 'Otros') ?><?= $gs['proveedor'] ? ' · ' . e($gs['proveedor']) : '' ?></small></div>
            <div class="derecha"><span class="monto"><?= dinero($gs['monto']) ?></span>
                <?php if ($puede): ?>
                <form method="post" action="<?= e(url('evento_pagos', ['id' => $ev['id'], 'accion' => 'gasto_borrar'])) ?>">
                    <?= campo_csrf() ?><input type="hidden" name="gasto_id" value="<?= (int) $gs['id'] ?>">
                    <button class="enlace-chico cb-boton-enlace" type="submit" data-confirmar="¿Borrar el gasto &quot;<?= e($gs['concepto']) ?>&quot;?">Borrar</button>
                </form>
                <?php endif; ?></div>
        </li>
        <?php endforeach; ?>
        <li><strong>Total de gastos</strong><span class="monto"><?= dinero($ganancia['gastos']) ?></span></li>
    </ul>
    <?php else: ?>
        <p class="vacio">Sin gastos registrados todavía.</p>
    <?php endif; ?>
    <?php if ($puede): ?>
    <details class="cb-detalles no-imprimir">
        <summary>+ Registrar un gasto</summary>
        <form method="post" action="<?= e(url('evento_pagos', ['id' => $ev['id'], 'accion' => 'gasto'])) ?>" class="rejilla-form">
            <?= campo_csrf() ?>
            <div class="campo ancho"><label for="g_concepto">¿En qué se gastó?</label><input type="text" id="g_concepto" name="concepto" maxlength="200" required placeholder="Ej.: Flores para la ceremonia"></div>
            <div class="campo"><label for="g_monto">Valor ($)</label><input type="text" inputmode="decimal" id="g_monto" name="monto" required></div>
            <div class="campo"><label for="g_categoria">Tipo de gasto</label><select id="g_categoria" name="categoria"><?php foreach (Cobranza::CATEGORIAS_GASTO as $k => $t): ?><option value="<?= e($k) ?>"><?= e($t) ?></option><?php endforeach; ?></select></div>
            <div class="campo"><label for="g_fecha">Fecha</label><input type="date" id="g_fecha" name="fecha" value="<?= e($hoy) ?>"></div>
            <div class="campo"><label for="g_proveedor">Proveedor <small>(opcional)</small></label>
                <select id="g_proveedor" name="proveedor_id"><option value="0">— Ninguno —</option><?php foreach ($proveedores as $pv): ?><option value="<?= (int) $pv['id'] ?>"><?= e($pv['nombre']) ?></option><?php endforeach; ?></select></div>
            <div class="ancho"><button class="boton boton-claro" type="submit">Guardar gasto</button></div>
        </form>
    </details>
    <?php endif; ?>
</section>

<?php if (!$sinValor): ?>
<section class="bloque" id="ganancia">
    <h2>Ganancia del evento</h2>
    <div class="tabla-scroll cb-sin-minimo">
    <table class="tabla cb-ganancia">
        <tbody>
            <tr><td>Valor del evento</td><td class="num"><?= dinero($ganancia['total']) ?></td></tr>
            <tr><td>Menos IVA</td><td class="num">−<?= dinero($ganancia['iva']) ?></td></tr>
            <tr class="fila-total"><td>Ingreso sin IVA</td><td class="num"><?= dinero($ganancia['ingreso']) ?></td></tr>
        </tbody>
    </table>
    </div>
    <div class="rejilla rejilla-2 cb-ganancias">
        <div class="cb-caja">
            <h3>Lo que calculaste</h3>
            <p class="suave">Con los costos del catálogo en la cotización.</p>
            <dl class="ficha-datos"><dt>Costo estimado</dt><dd>−<?= dinero($ganancia['costo_estimado']) ?></dd>
                <dt>Ganancia estimada</dt><dd class="<?= $ganancia['ganancia_estimada'] < 0 ? 'cb-rojo' : '' ?>"><?= dinero($ganancia['ganancia_estimada']) ?> <small>(<?= e(number_format($ganancia['margen_estimado'], 1, ',', '')) ?> %)</small></dd></dl>
        </div>
        <div class="cb-caja">
            <h3>Lo que gastaste de verdad</h3>
            <p class="suave">Gastos registrados + compras a proveedores + pagos al personal<?= $ganancia['personas'] ? ' (' . $ganancia['personas'] . ' personas)' : '' ?>.</p>
            <dl class="ficha-datos"><dt>Gastos</dt><dd>−<?= dinero($ganancia['gastos']) ?></dd>
                <?php if (($ganancia['compras'] ?? 0) > 0): ?><dt>Compras</dt><dd>−<?= dinero($ganancia['compras']) ?></dd><?php endif; ?>
                <dt>Personal</dt><dd>−<?= dinero($ganancia['personal']) ?></dd>
                <dt>Ganancia real</dt><dd class="<?= $ganancia['ganancia_real'] < 0 ? 'cb-rojo' : 'cb-verde' ?>"><?= dinero($ganancia['ganancia_real']) ?> <small>(<?= e(number_format($ganancia['margen_real'], 1, ',', '')) ?> %)</small></dd></dl>
        </div>
    </div>
    <?php if ($ganancia['costo_real'] == 0): ?><p class="suave cb-nota">Registra los gastos y el personal del evento para ver la ganancia real.</p><?php endif; ?>
</section>
<?php endif; ?>
