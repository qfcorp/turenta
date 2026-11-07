<?php
use TuRenta\{Pagos, WhatsApp};
/**
 * Tablero de cobranza.
 * @var array $t @var int $filtro @var array $porVencer @var bool $puede @var int $mensajesHoy @var array $u
 */
$cambio = $t['cobrado_anterior']['total'] > 0 ? (int) round(($t['cobrado_mes']['total'] - $t['cobrado_anterior']['total']) * 100 / $t['cobrado_anterior']['total']) : null;
$meses = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$mesActual = $meses[(int) date('n')];
$mesAnterior = $meses[(int) (new DateTimeImmutable('first day of last month'))->format('n')];
$pl = fn(int $n, string $uno, string $varios) => $n . ' ' . ($n === 1 ? $uno : $varios);
$metodosRapidos = ['transferencia', 'efectivo', 'deuna', 'tarjeta', 'cheque', 'otro'];

/** Fila de una cuota (vencida o por vencer) con WhatsApp, ir al evento y pago rápido. */
$filaCuota = function (array $c) use ($u, $puede, $metodosRapidos): void {
    $portal = url_completa('portal', ['t' => $c['token_cliente']]);
    $wa = WhatsApp::enlace($c['telefono'], Pagos::textoRecordatorio($c['cliente'], $u['negocio'], $c['concepto'], (float) $c['saldo'], $c['vence'], $portal));
    $cuando = $c['dias'] < 0 ? 'venció hace ' . -$c['dias'] . ($c['dias'] === -1 ? ' día' : ' días') : ($c['dias'] === 0 ? 'vence hoy' : 'vence en ' . $c['dias'] . ($c['dias'] === 1 ? ' día' : ' días'));
    $fid = 'rp' . (int) $c['id'];
    ?>
    <li class="cb-cobro <?= $c['vencida'] ? 'cb-cobro-vencida' : '' ?>">
        <div class="cb-cobro-datos">
            <a href="<?= e(url('evento_pagos', ['id' => $c['evento_id']])) ?>"><strong><?= e($c['cliente']) ?></strong></a>
            <small class="suave"><?= e($c['codigo']) ?> · <?= e($c['evento']) ?> · evento el <?= e(fecha_corta($c['evento_fecha'])) ?></small>
            <small><?= e($c['concepto']) ?> · <span class="<?= $c['vencida'] ? 'cb-rojo' : '' ?>"><?= e($cuando) ?> (<?= e(fecha_corta($c['vence'], false)) ?>)</span></small>
        </div>
        <div class="cb-cobro-lado">
            <span class="monto"><?= dinero($c['saldo']) ?></span>
            <div class="cb-cobro-botones">
                <?php if ($wa): ?><a class="boton boton-wa boton-chico" href="<?= e($wa) ?>" target="_blank" rel="noopener" title="Recordar por WhatsApp">WhatsApp</a><?php endif; ?>
                <a class="boton boton-claro boton-chico" href="<?= e(url('evento_pagos', ['id' => $c['evento_id']])) ?>">Ver evento</a>
                <?php if ($puede): ?><button type="button" class="boton boton-claro boton-chico" data-cb-abrir="<?= e($fid) ?>" aria-expanded="false" aria-controls="<?= e($fid) ?>">Registrar pago</button><?php endif; ?>
            </div>
        </div>
        <?php if ($puede): ?>
        <form method="post" action="<?= e(url('cobros', ['accion' => 'pago'])) ?>" class="cb-pago-rapido" id="<?= e($fid) ?>" hidden>
            <?= campo_csrf() ?><input type="hidden" name="evento_id" value="<?= (int) $c['evento_id'] ?>">
            <div class="campo"><label for="<?= e($fid) ?>m">Valor ($)</label><input type="text" inputmode="decimal" id="<?= e($fid) ?>m" name="monto" value="<?= e(number_format((float) $c['saldo'], 2, ',', '')) ?>" required></div>
            <div class="campo"><label for="<?= e($fid) ?>f">Forma de pago</label><select id="<?= e($fid) ?>f" name="metodo"><?php foreach ($metodosRapidos as $k): ?><option value="<?= e($k) ?>"><?= e(Pagos::METODOS[$k]) ?></option><?php endforeach; ?></select></div>
            <div class="campo"><label for="<?= e($fid) ?>r">Referencia <small>(opcional)</small></label><input type="text" id="<?= e($fid) ?>r" name="referencia" maxlength="120"></div>
            <button class="boton boton-chico" type="submit">Guardar pago</button>
        </form>
        <?php endif; ?>
    </li>
    <?php
};
?>
<div class="cabecera">
    <div><h1>Cobros</h1><p>Lo que te deben, lo que ya cobraste y a quién recordarle hoy.</p></div>
    <a class="boton boton-wa" href="<?= e(url('mensajes')) ?>">Mensajes de hoy<?= $mensajesHoy ? " ($mensajesHoy)" : '' ?></a>
</div>

<section class="cifras cb-cifras" aria-label="Resumen de cobros">
    <div class="cifra-tarjeta bien"><small>Cobrado en <?= e($mesActual) ?></small><strong><?= dinero($t['cobrado_mes']['total']) ?></strong>
        <small><?= e($pl((int) $t['cobrado_mes']['cuantos'], 'pago', 'pagos')) ?><?= $cambio !== null ? ' · ' . ($cambio >= 0 ? '+' : '') . $cambio . ' % vs. ' . e($mesAnterior) : '' ?></small></div>
    <div class="cifra-tarjeta"><small>Cobrado en <?= e($mesAnterior) ?></small><strong><?= dinero($t['cobrado_anterior']['total']) ?></strong><small><?= e($pl((int) $t['cobrado_anterior']['cuantos'], 'pago', 'pagos')) ?></small></div>
    <div class="cifra-tarjeta"><small>Total por cobrar</small><strong><?= dinero($t['por_cobrar']) ?></strong><small>de <?= e($pl((int) $t['eventos_por_cobrar'], 'evento separado o confirmado', 'eventos separados o confirmados')) ?></small></div>
    <div class="cifra-tarjeta <?= $t['total_vencido'] > 0 ? 'alerta' : '' ?>"><small>Vencido</small><strong><?= dinero($t['total_vencido']) ?></strong><small><?= e($pl(count($t['vencidas']), 'cuota atrasada', 'cuotas atrasadas')) ?></small></div>
</section>
<?php if ($t['por_cobrar_cotizados'] > 0): ?>
    <p class="suave cb-nota">Además hay <?= dinero($t['por_cobrar_cotizados']) ?> en eventos cotizados que todavía no pagan la reserva.</p>
<?php endif; ?>

<?php if ($t['por_revisar']): ?>
<section class="bloque cb-por-revisar">
    <h2>Comprobantes por revisar (<?= count($t['por_revisar']) ?>)</h2>
    <p class="suave">Revisa en tu banco que el dinero llegó antes de confirmar.</p>
    <ul class="filas">
        <?php foreach ($t['por_revisar'] as $p): ?>
        <li class="cb-revisar-fila">
            <div class="cb-revisar-izq">
                <?php if ($p['comprobante']): ?><a class="cb-mini" href="<?= e(url('archivo', ['tipo' => 'comprobante', 'id' => $p['id']])) ?>" target="_blank"><img src="<?= e(url('archivo', ['tipo' => 'comprobante', 'id' => $p['id']])) ?>" alt="Comprobante" loading="lazy"></a><?php endif; ?>
                <div><strong><?= e($p['cliente']) ?></strong><br><small class="suave"><?= e($p['codigo']) ?> · <?= e(Pagos::METODOS[$p['metodo']]) ?> · <?= e(fecha_corta($p['creado_en'])) ?><?= $p['referencia'] ? ' · Ref. ' . e($p['referencia']) : '' ?></small></div>
            </div>
            <div class="derecha"><span class="monto"><?= dinero($p['monto']) ?></span>
                <?php if ($puede): ?>
                <div class="cb-cobro-botones">
                    <form method="post" action="<?= e(url('cobros', ['accion' => 'confirmar'])) ?>"><?= campo_csrf() ?><input type="hidden" name="pago_id" value="<?= (int) $p['id'] ?>">
                        <button class="boton boton-chico" type="submit">Confirmar</button></form>
                    <a class="boton boton-claro boton-chico" href="<?= e(url('evento_pagos', ['id' => $p['evento_id']])) ?>#revisar">Revisar</a>
                </div>
                <?php endif; ?></div>
        </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<section class="bloque">
    <h2>Cuotas vencidas<?= $t['vencidas'] ? ' (' . count($t['vencidas']) . ')' : '' ?></h2>
    <?php if ($t['vencidas']): ?>
        <ul class="lista cb-cobros"><?php foreach ($t['vencidas'] as $c) $filaCuota($c); ?></ul>
    <?php else: ?>
        <p class="vacio">¡Nadie está atrasado! No hay cuotas vencidas.</p>
    <?php endif; ?>
</section>

<section class="bloque">
    <div class="cb-titulo-filtro">
        <h2>Por vencer</h2>
        <nav class="cb-chips" aria-label="Días">
            <?php foreach ([7, 15, 30] as $d): ?>
                <a href="<?= e(url('cobros', ['dias' => $d])) ?>" class="<?= $filtro === $d ? 'activo' : '' ?>" <?= $filtro === $d ? 'aria-current="true"' : '' ?>><?= $d ?> días <small><?= dinero($t['tramos'][$d]) ?></small></a>
            <?php endforeach; ?>
        </nav>
    </div>
    <?php if ($porVencer): ?>
        <ul class="lista cb-cobros"><?php foreach ($porVencer as $c) $filaCuota($c); ?></ul>
    <?php else: ?>
        <p class="vacio">No hay cuotas que venzan en los próximos <?= $filtro ?> días.</p>
    <?php endif; ?>
</section>

<section class="bloque">
    <h2>Garantías por devolver</h2>
    <?php if ($t['garantias']): ?>
    <p class="suave">Eventos que ya pasaron y cuya garantía sigue en manos del local. Devuélvela o registra lo retenido por daños.</p>
    <ul class="lista">
        <?php foreach ($t['garantias'] as $g): $wa = WhatsApp::enlace($g['telefono'], $g['texto']); ?>
        <li>
            <div><a href="<?= e(url('evento_pagos', ['id' => $g['id']])) ?>#garantia"><strong><?= e($g['cliente']) ?></strong></a><br>
                <small class="suave"><?= e($g['codigo']) ?> · <?= e($g['evento']) ?> · fue el <?= e(fecha_corta($g['fecha'])) ?><?= $g['dias'] > 0 ? ' (hace ' . $g['dias'] . ($g['dias'] === 1 ? ' día)' : ' días)') : '' ?></small></div>
            <div class="derecha"><span class="monto"><?= dinero($g['saldo']) ?></span>
                <div class="cb-cobro-botones">
                    <?php if ($wa): ?><a class="boton boton-wa boton-chico" href="<?= e($wa) ?>" target="_blank" rel="noopener">WhatsApp</a><?php endif; ?>
                    <a class="boton boton-claro boton-chico" href="<?= e(url('evento_pagos', ['id' => $g['id']])) ?>#garantia">Devolver</a>
                </div></div>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php else: ?>
        <p class="vacio">No tienes garantías pendientes de devolver.</p>
    <?php endif; ?>
</section>
