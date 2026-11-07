<?php
/**
 * Portal del cliente en inglés ("My Party"). Migración 200.
 * @var array $ev @var array $negocio @var string $token @var bool $activo @var array $res @var array $historial
 * @var bool $stripeListo @var bool $stripePrueba @var array $montos @var ?string $paqueteNombre @var string $primerNombre
 */
$hoy = new DateTimeImmutable('today');
$faltan = (int) $hoy->diff(new DateTimeImmutable($ev['fecha']))->format('%r%a');
$estados = [
    'consulta' => ['Request received', ''], 'cotizado' => ['Waiting for payment', ''], 'separado' => ['Date locked', 'ok'],
    'confirmado' => ['Confirmed', 'ok'], 'realizado' => ['Party done — thank you!', 'ok'], 'perdido' => ['Not booked', 'off'],
    'cancelado' => ['Canceled', 'off'],
];
[$estadoTxt, $estadoClase] = $estados[$ev['estado']] ?? [$ev['estado'], ''];
$conceptos = ['Reserva' => 'Deposit', 'Saldo' => 'Balance', 'Pago total' => 'Full payment'];
$concepto = function (string $c) use ($conceptos): string {
    if (isset($conceptos[$c])) return $conceptos[$c];
    if (preg_match('/^Cuota (\d+)$/', $c, $m)) return 'Payment ' . $m[1];
    if (str_starts_with($c, 'Extras')) return 'Add-ons' . substr($c, 6);
    return $c;
};
$estadosCuota = ['pagada' => ['Paid', 'ok'], 'parcial' => ['Partly paid', ''], 'pendiente' => ['Due', ''], 'vencida' => ['Past due', 'err']];
$separada = in_array($ev['estado'], ['separado', 'confirmado', 'realizado'], true);
$retencionVencida = $ev['estado'] === 'cotizado' && $ev['retenida_hasta'] && $ev['retenida_hasta'] < $hoy->format('Y-m-d');
$telNeg = (string) ($negocio['telefono'] ?: $negocio['whatsapp']);
$telDig = preg_replace('/\D/', '', $telNeg);
$saldoCuota = null;
foreach ($res['cuotas'] as $c) {
    if ($c['concepto'] === 'Saldo') $saldoCuota = $c;
}
$puedePagar = $activo && $res['saldo'] > 0 && $stripeListo;
$pasos = [
    ['Booked online', 'We received your party details.', true],
    ['Deposit paid', $separada ? 'Your date and time are locked.' : 'Pay the deposit to lock your date.', $separada],
    ['Balance paid', $res['saldo'] <= 0 ? 'Everything is paid.' : ($saldoCuota ? 'Due by ' . fecha_en($saldoCuota['vence'], false) . '.' : 'Pay any time before the party.'), $res['saldo'] <= 0 && $res['total'] > 0],
    ['Party day', $faltan > 0 ? "In $faltan day" . ($faltan === 1 ? '' : 's') . '.' : ($faltan === 0 ? 'Today!' : 'Done.'), $ev['estado'] === 'realizado'],
];
$siguiente = null;
foreach ($pasos as $i => $p) {
    if (!$p[2]) { $siguiente = $i; break; }
}
?>
<section class="en-portal-hero">
    <p class="en-hi">Hi, <?= e($primerNombre) ?>! This is your party page.</p>
    <h1><?= e($ev['nombre']) ?></h1>
    <p class="en-when"><?= e(fecha_en($ev['fecha'])) ?> · <?= e(hora_en($ev['hora_inicio'])) ?> – <?= e(hora_en($ev['hora_fin'])) ?></p>
    <span class="en-status <?= $estadoClase ? 'en-status-' . $estadoClase : '' ?>"><?= e($estadoTxt) ?></span>
    <?php if ($activo && $faltan >= 0): ?>
    <div class="en-cd" data-cuenta="<?= e($ev['fecha'] . 'T' . substr((string) $ev['hora_inicio'], 0, 5) . ':00') ?>" aria-label="Countdown to your party">
        <div><b data-d><?= max(0, $faltan) ?></b><small>days</small></div><div><b data-h>–</b><small>hours</small></div>
        <div><b data-m>–</b><small>min</small></div><div><b data-s>–</b><small>sec</small></div>
    </div>
    <?php endif; ?>
</section>

<?php if (!$activo): ?>
    <p class="en-alert en-alert-err" role="alert">This booking is <?= $ev['estado'] === 'cancelado' ? 'canceled' : 'not active' ?>. If you think this is a mistake, please call <?= e($negocio['nombre']) ?>.</p>
<?php endif; ?>

<?php if ($activo && $res['saldo'] > 0): ?>
<section class="en-card en-card-pay" id="pay">
    <?php if (!$separada): ?>
        <h2>Lock your date</h2>
        <p><?= $retencionVencida
            ? 'Your hold ended. Pay now and we lock your time if it is still open — if not, we call you to pick another one.'
            : 'Your time is held until the end of today. Pay the deposit or the full amount to lock it.' ?></p>
    <?php else: ?>
        <h2>Pay your balance</h2>
        <p>You still owe <strong><?= e(dinero_en($res['saldo'])) ?></strong><?= $saldoCuota ? ', due by <strong>' . e(fecha_en($saldoCuota['vence'], false)) . '</strong>' : '' ?>.</p>
    <?php endif; ?>
    <?php if ($puedePagar): ?>
        <div class="en-pay-options">
            <?php if (!$separada && $montos['deposito'] > 0 && $montos['deposito'] < $montos['total']): ?>
            <form method="post" action="<?= e(url('portal_pagar', ['t' => $token, 'accion' => 'stripe'])) ?>">
                <?= campo_csrf() ?><input type="hidden" name="opcion" value="deposito">
                <button class="en-btn" type="submit">Pay deposit <?= e(dinero_en($montos['deposito'])) ?><small>Balance later</small></button>
            </form>
            <?php endif; ?>
            <form method="post" action="<?= e(url('portal_pagar', ['t' => $token, 'accion' => 'stripe'])) ?>">
                <?= campo_csrf() ?><input type="hidden" name="opcion" value="total">
                <button class="en-btn <?= !$separada && $montos['deposito'] > 0 && $montos['deposito'] < $montos['total'] ? 'en-btn-ghost' : '' ?>" type="submit">Pay <?= $separada ? 'balance' : 'in full' ?> <?= e(dinero_en($montos['total'])) ?><small>Nothing left to pay</small></button>
            </form>
        </div>
        <p class="en-small">Secure card payment by Stripe. You get a receipt by email.</p>
        <?php if ($stripePrueba): ?>
            <p class="en-test"><strong>Test mode:</strong> no real charge. Use card 4242 4242 4242 4242, any future date and any 3-digit code.</p>
        <?php endif; ?>
    <?php else: ?>
        <p class="en-hint">Card payments are not available online yet. We will text you how to pay. Questions? Call <?= e(telefono_en($telNeg)) ?>.</p>
    <?php endif; ?>
</section>
<?php endif; ?>

<section class="en-card">
    <h2>Your booking</h2>
    <ol class="en-steps">
        <?php foreach ($pasos as $i => $p): ?>
        <li class="<?= $p[2] ? 'en-done' : ($i === $siguiente ? 'en-now' : '') ?>"><span class="en-dot" aria-hidden="true"><?= $p[2] ? '✓' : $i + 1 ?></span>
            <div><strong><?= e($p[0]) ?></strong><?= $p[2] ? ' <span class="en-tag en-tag-ok">Done</span>' : '' ?><small><?= e($p[1]) ?></small></div></li>
        <?php endforeach; ?>
    </ol>
</section>

<section class="en-card">
    <h2>Party details</h2>
    <dl class="en-kv">
        <?php if ($paqueteNombre): ?><dt>Party</dt><dd><?= e($paqueteNombre) ?></dd><?php endif; ?>
        <dt>Date</dt><dd><?= e(fecha_en($ev['fecha'])) ?></dd>
        <dt>Time</dt><dd><?= e(hora_en($ev['hora_inicio'])) ?> – <?= e(hora_en($ev['hora_fin'])) ?></dd>
        <dt>Kids</dt><dd>Up to <?= (int) $ev['invitados'] ?></dd>
        <?php if ($ev['direccion']): ?><dt>Address</dt><dd><?= e($ev['direccion']) ?><?= $ev['codigo_postal'] ? ' · ' . e($ev['codigo_postal']) : '' ?></dd><?php endif; ?>
        <?php if ($ev['detalles_cliente']): ?><dt>Your notes</dt><dd><?= nl2br(e($ev['detalles_cliente'])) ?></dd><?php endif; ?>
        <dt>Booking #</dt><dd><?= e($ev['codigo']) ?></dd>
    </dl>
</section>

<section class="en-card" id="payments">
    <h2>Payments</h2>
    <div class="en-money">
        <div><span>Total</span><b><?= e(dinero_en($res['total'])) ?></b></div>
        <div><span>Paid</span><b><?= e(dinero_en($res['pagado'])) ?></b></div>
        <div><span>Balance</span><b><?= e(dinero_en($res['saldo'])) ?></b></div>
    </div>
    <div class="en-bar" role="progressbar" aria-valuenow="<?= (int) $res['pct'] ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Paid"><i style="width: <?= (int) $res['pct'] ?>%"></i></div>
    <?php if ($res['cuotas']): ?>
    <ul class="en-list">
        <?php foreach ($res['cuotas'] as $c): [$t, $cl] = $estadosCuota[$c['estado']] ?? [$c['estado'], '']; ?>
        <li><div><strong><?= e($concepto((string) $c['concepto'])) ?></strong><small>Due <?= e(fecha_en($c['vence'], false)) ?> · <?= e(dinero_en($c['monto'])) ?></small></div>
            <span class="en-tag <?= $cl ? 'en-tag-' . $cl : '' ?>"><?= e($t) ?></span></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <?php if ($historial): ?>
    <h2 style="margin-top:18px">Payments received</h2>
    <ul class="en-list">
        <?php foreach ($historial as $p): ?>
        <li><div><strong><?= e(dinero_en($p['monto'])) ?></strong><small><?= e(fecha_en($p['fecha'], false)) ?> · <?= $p['metodo'] === 'stripe' ? 'Card (Stripe)' : e(ucfirst((string) $p['metodo'])) ?></small></div>
            <span class="en-tag <?= $p['estado'] === 'confirmado' ? 'en-tag-ok' : ($p['estado'] === 'rechazado' ? 'en-tag-err' : '') ?>"><?= $p['estado'] === 'confirmado' ? 'Received' : ($p['estado'] === 'rechazado' ? 'Declined' : 'Checking') ?></span></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</section>

<section class="en-card">
    <h2>Questions?</h2>
    <p>Call or text <?= e($negocio['nombre']) ?> and mention booking <strong><?= e($ev['codigo']) ?></strong>.</p>
    <div class="en-contact">
        <?php if ($telDig !== ''): ?>
        <a class="en-btn" href="tel:+<?= e($telDig) ?>">Call <?= e(telefono_en($telNeg)) ?></a>
        <a class="en-btn en-btn-ghost" href="sms:+<?= e($telDig) ?>">Text us</a>
        <?php endif; ?>
        <?php if ($negocio['sitio_externo']): ?><a class="en-btn en-btn-ghost" href="<?= e($negocio['sitio_externo']) ?>">Back to website</a><?php endif; ?>
    </div>
    <p class="en-small">Keep this link: it is your private party page. Anyone with the link can see it.</p>
</section>
