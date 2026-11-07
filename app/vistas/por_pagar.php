<?php
use TuRenta\{Compras, WhatsApp};

/** @var array $pp @var ?int $eventoId @var ?array $evento @var array $eventosCompras @var DateTimeImmutable $hoy */
$hoyTxt = $hoy->format('Y-m-d');
$maxTramo = max(1.0, ...array_values($pp['tramos']));
$cuando = function (?string $f) use ($hoy): string {
    if (!$f) return 'sin fecha';
    $d = (int) $hoy->diff(new DateTimeImmutable($f))->format('%r%a');
    return match (true) { $d === 0 => 'vence hoy', $d === 1 => 'vence mañana', $d < 0 => 'venció hace ' . -$d . (-$d === 1 ? ' día' : ' días'), default => "vence en $d días" };
};
?>
<div class="cabecera">
    <div>
        <h1>Por pagar</h1>
        <p>Lo que les debes a tus proveedores<?= $evento ? ' por ' . e($evento['nombre']) : '' ?>.</p>
    </div>
    <div class="acciones">
        <a class="boton boton-claro" href="<?= e(url('por_pagar', array_filter(['csv' => 1, 'evento' => $eventoId]))) ?>">Descargar para Excel</a>
        <a class="boton boton-claro" href="<?= e(url('compras')) ?>">Compras</a>
    </div>
</div>

<form method="get" class="pv-filtros pv-filtro-uno">
    <input type="hidden" name="r" value="por_pagar">
    <div class="campo"><label for="ppev">Ver solo un evento</label>
        <select id="ppev" name="evento" data-autoenviar><option value="0">Todos (también compras generales)</option>
            <?php foreach ($eventosCompras as $x): ?><option value="<?= (int) $x['id'] ?>" <?= $eventoId === (int) $x['id'] ? 'selected' : '' ?>><?= e(fecha_corta($x['fecha'])) ?> · <?= e($x['nombre']) ?></option><?php endforeach; ?>
        </select></div>
</form>

<div class="cifras">
    <div class="cifra-tarjeta"><small>Total que debes</small><strong><?= dinero($pp['total']) ?></strong></div>
    <div class="cifra-tarjeta <?= $pp['vencido'] > 0 ? 'alerta' : 'bien' ?>"><small>Vencido (<?= (int) $pp['vencidas'] ?>)</small><strong><?= dinero($pp['vencido']) ?></strong></div>
    <div class="cifra-tarjeta"><small>Vence esta semana (<?= (int) $pp['semana_cuantas'] ?>)</small><strong><?= dinero($pp['semana']) ?></strong></div>
    <div class="cifra-tarjeta"><small>Cheques por cobrar (<?= count($pp['cheques']) ?>)</small><strong><?= dinero($pp['cheques_total']) ?></strong></div>
</div>

<?php foreach ($pp['sobre_cupo'] as $s): ?>
    <p class="aviso aviso-error" role="status">Le debes <?= dinero($s['saldo_total']) ?> a <a href="<?= e(url('proveedor', ['id' => $s['proveedor_id']])) ?>"><?= e($s['proveedor']) ?></a>: pasaste su cupo de crédito de <?= dinero($s['cupo']) ?>.</p>
<?php endforeach; ?>

<?php if (!$pp['compras']): ?>
    <section class="bloque"><div class="vacio"><p><strong>No les debes nada a tus proveedores.</strong> 🎉</p>
        <p>Cuando registres una compra en <a href="<?= e(url('compras')) ?>">Compras</a>, aquí verás lo que falta pagar y cuándo vence.</p></div></section>
<?php else: ?>
<div class="rejilla rejilla-2">
    <section class="bloque">
        <h2>Próximos pagos</h2>
        <ul class="filas pv-filas">
            <?php foreach (array_slice($pp['compras'], 0, 40) as $c): ?>
            <li class="<?= $c['estado_pago'] === 'vencida' ? 'pv-fila-vencida' : '' ?>">
                <div><a href="<?= e(url('compra', ['id' => $c['id']])) ?>"><strong><?= e($c['proveedor']) ?></strong></a>
                    <br><small><?= e($c['codigo']) ?> · <?= $c['evento'] ? e($c['evento']) : 'Compra general' ?></small>
                    <div class="pv-chips"><span class="pv-chip <?= $c['estado_pago'] === 'vencida' ? 'pv-chip-rojo' : ($c['vence'] && $c['vence'] <= $hoy->modify('+7 days')->format('Y-m-d') ? 'pv-chip-amarillo' : 'pv-chip-suave') ?>"><?= e($cuando($c['vence'])) ?></span>
                        <?php if ($c['anticipo_pendiente'] > 0): ?><span class="pv-chip">Falta el anticipo de <?= dinero($c['anticipo_pendiente']) ?></span><?php endif; ?></div></div>
                <div class="derecha"><strong class="monto"><?= dinero($c['saldo']) ?></strong><br><small><?= $c['vence'] ? e(fecha_corta($c['vence'], false)) : '' ?></small></div>
            </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <div>
        <section class="bloque">
            <h2>Antigüedad de saldos</h2>
            <ul class="pv-tramos">
                <?php foreach (Compras::TRAMOS as $k => $t): $v = $pp['tramos'][$k]; ?>
                <li class="pv-tramo-<?= e($k) ?>"><span><?= e($k === 'al_dia' ? 'Al día (no vencido)' : 'Vencido ' . mb_strtolower($t)) ?></span>
                    <span class="barra" aria-hidden="true"><span style="width:<?= (int) round($v * 100 / $maxTramo) ?>%"></span></span>
                    <strong class="monto"><?= dinero($v) ?></strong></li>
                <?php endforeach; ?>
            </ul>
        </section>
        <section class="bloque">
            <h2>Por proveedor</h2>
            <ul class="filas">
                <?php foreach ($pp['por_proveedor'] as $p):
                    $wa = WhatsApp::enlace($p['telefono'], 'Hola, te escribe ' . $u['negocio'] . '. Te contamos que tenemos pendiente ' . dinero($p['saldo']) . ' contigo. Te confirmamos la fecha de pago. ¡Gracias!'); ?>
                <li><div><a href="<?= e(url('proveedor', ['id' => $p['proveedor_id'], 'ver' => 'cuenta'])) ?>"><strong><?= e($p['proveedor']) ?></strong></a>
                    <br><small><?= (int) $p['compras'] ?> <?= $p['compras'] === 1 ? 'compra' : 'compras' ?><?= $p['proximo'] ? ' · próximo pago ' . e(fecha_corta($p['proximo'], false)) : '' ?></small>
                    <div class="pv-chips"><?php if ($p['vencido'] > 0): ?><span class="pv-chip pv-chip-rojo">Vencido <?= dinero($p['vencido']) ?></span><?php endif; ?>
                        <?php if ($p['sobre_cupo']): ?><span class="pv-chip pv-chip-rojo">Pasa el cupo</span><?php endif; ?></div></div>
                    <div class="derecha"><strong class="monto"><?= dinero($p['saldo']) ?></strong>
                        <?php if ($wa): ?><br><a class="enlace-chico" href="<?= e($wa) ?>" target="_blank" rel="noopener">WhatsApp</a><?php endif; ?></div></li>
                <?php endforeach; ?>
            </ul>
        </section>
    </div>
</div>
<?php endif; ?>

<?php if ($pp['cheques']): ?>
<section class="bloque">
    <h2>Cheques posfechados por cobrar</h2>
    <p class="suave">Ten la plata en la cuenta para el día en que el proveedor puede cobrarlos.</p>
    <ul class="filas">
        <?php foreach ($pp['cheques'] as $x): ?>
        <li><div><strong><?= e(fecha_corta($x['cheque_fecha'])) ?></strong> · <?= e($x['proveedor']) ?><br><small><?= e($x['referencia']) ?> · <a href="<?= e(url('compra', ['id' => $x['compra_id']])) ?>"><?= e(Compras::codigo($x['numero'])) ?></a><?= $x['evento'] ? ' · ' . e($x['evento']) : '' ?></small></div>
            <div class="derecha monto"><?= dinero($x['monto']) ?></div></li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>
