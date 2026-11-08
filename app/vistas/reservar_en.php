<?php
/**
 * Reserva en línea (inglés).
 * @var array $neg @var array $paquetes @var string $elegido @var string $fechaElegida @var string $primerDia @var array $df @var string $slug
 * @var bool $usaMapa (201) las horas dependen de la dirección (viaje desde la base)
 */
$v = fn(string $k, string $def = '') => e($df[$k] ?? $def);
$pct = rtrim(rtrim(number_format((float) $neg['reserva_pct'], 2, '.', ''), '0'), '.');
$ocasiones = \TuRenta\Reservas::OCASIONES;
$condiciones = trim((string) $neg['reserva_condiciones']);
$duracionTxt = function (int $min): string {
    if ($min % 60 === 0) return ($min / 60) . ' hour' . ($min === 60 ? '' : 's');
    return $min < 60 ? "$min minutes" : intdiv($min, 60) . ' h ' . ($min % 60) . ' min';
};
?>
<section class="en-hero">
    <p class="en-eyebrow">Book online</p>
    <h1>Book your party</h1>
    <p class="en-lead">Pick your party, choose a date and time, and lock it with a <?= e($pct) ?>% deposit — or pay it all now. It takes about two minutes.</p>
</section>

<?php if (!$paquetes): ?>
    <p class="en-empty">Online booking will open soon. Please call us to book your party.</p>
<?php else: ?>
<form class="en-form" method="post" action="<?= e(url('reservar', ['s' => $slug])) ?>" data-reserva data-horas="<?= e(url('reservar', ['s' => $slug, 'json' => 'horas'])) ?>"<?= $usaMapa ? ' data-mapa="1"' : '' ?> novalidate>
    <?= campo_csrf() ?>
    <div class="en-trap" aria-hidden="true"><label for="sitio_web">Website</label><input id="sitio_web" name="sitio_web" tabindex="-1" autocomplete="off"></div>

    <fieldset class="en-step">
        <legend><span class="en-num">1</span> Choose your party</legend>
        <div class="en-pkgs">
            <?php foreach ($paquetes as $p): ?>
            <label class="en-pkg">
                <input type="radio" name="paquete" value="<?= e($p['slug']) ?>" <?= $p['slug'] === $elegido ? 'checked' : '' ?> required
                       data-precio="<?= e(number_format((float) $p['precio'], 2, '.', '')) ?>" data-nombre="<?= e($p['nombre']) ?>" data-max="<?= (int) $p['maximo_invitados'] ?>">
                <?php if ($p['foto_url']): ?><img src="<?= e($p['foto_url']) ?>" alt="" loading="lazy"><?php endif; ?>
                <span class="en-pkg-body">
                    <span class="en-pkg-name"><?= e($p['nombre']) ?></span>
                    <span class="en-pkg-meta"><?= e($duracionTxt((int) $p['duracion_min'])) ?><?= $p['maximo_invitados'] ? ' · up to ' . (int) $p['maximo_invitados'] . ' kids' : '' ?></span>
                    <span class="en-pkg-price"><?= e(dinero_en($p['precio'])) ?></span>
                </span>
            </label>
            <?php endforeach; ?>
        </div>
    </fieldset>

    <fieldset class="en-step">
        <legend><span class="en-num">2</span> Where is the party?</legend>
        <div class="en-grid">
            <div class="en-field en-span"><label for="direccion">Party address</label>
                <input id="direccion" name="direccion" maxlength="240" autocomplete="street-address" value="<?= $v('direccion') ?>" placeholder="1200 Main St, Weston, FL" required></div>
            <div class="en-field"><label for="zip">ZIP code</label>
                <input id="zip" name="zip" inputmode="numeric" maxlength="5" autocomplete="postal-code" value="<?= $v('zip') ?>" placeholder="33326" required></div>
        </div>
        <?php if ($usaMapa): ?><p class="en-small">We bring the party to you: open times include our drive to this address.</p><?php endif; ?>
    </fieldset>

    <fieldset class="en-step">
        <legend><span class="en-num">3</span> Date and time</legend>
        <div class="en-field en-field-date">
            <label for="fecha">Party date</label>
            <input type="date" id="fecha" name="fecha" min="<?= e($primerDia) ?>" value="<?= e($fechaElegida) ?>" required>
            <small>We need at least <?= (int) $neg['reserva_dias_min'] ?> days' notice. Sooner? Call us.</small>
        </div>
        <div class="en-times" data-horas-lista aria-live="polite">
            <p class="en-hint" data-horas-texto>Pick a date to see open times.</p>
        </div>
        <input type="hidden" name="hora" value="<?= $v('hora') ?>" data-hora>
        <noscript><p class="en-hint">Please turn on JavaScript to see open times, or call us to book.</p></noscript>
    </fieldset>

    <fieldset class="en-step">
        <legend><span class="en-num">4</span> About the party</legend>
        <div class="en-grid">
            <div class="en-field"><label for="ninos">How many kids?</label>
                <input type="number" id="ninos" name="ninos" min="1" max="500" inputmode="numeric" value="<?= $v('ninos') ?>" placeholder="25" required></div>
            <div class="en-field"><label for="ocasion">Occasion</label>
                <select id="ocasion" name="ocasion"><?php foreach ($ocasiones as $k => $t): ?><option value="<?= e($k) ?>" <?= ($df['ocasion'] ?? 'cumpleanos') === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
            <div class="en-field en-span"><label for="homenajeado">Name of the birthday child or guest of honor <span class="en-opt">(optional)</span></label>
                <input id="homenajeado" name="homenajeado" maxlength="80" value="<?= $v('homenajeado') ?>" placeholder="Sofia"></div>
            <div class="en-field en-span"><label for="notas">Anything we should know? <span class="en-opt">(optional)</span></label>
                <textarea id="notas" name="notas" maxlength="1000" placeholder="Gate code, parking, add-ons you'd like…"><?= $v('notas') ?></textarea></div>
        </div>
    </fieldset>

    <fieldset class="en-step">
        <legend><span class="en-num">5</span> Your contact</legend>
        <div class="en-grid">
            <div class="en-field en-span"><label for="nombre">Your name</label>
                <input id="nombre" name="nombre" maxlength="150" autocomplete="name" value="<?= $v('nombre') ?>" required></div>
            <div class="en-field"><label for="telefono">Mobile phone</label>
                <input id="telefono" name="telefono" type="tel" autocomplete="tel" value="<?= $v('telefono') ?>" placeholder="(954) 555-0123" required></div>
            <div class="en-field"><label for="email">Email</label>
                <input id="email" name="email" type="email" autocomplete="email" value="<?= $v('email') ?>" placeholder="you@example.com" required></div>
        </div>
    </fieldset>

    <div class="en-summary" data-resumen>
        <div>
            <p class="en-summary-name" data-res-nombre>—</p>
            <p class="en-summary-when" data-res-cuando>Pick a date and time</p>
        </div>
        <div class="en-summary-money">
            <p><span>Total</span><strong data-res-total>—</strong></p>
            <p><span>Deposit today (<?= e($pct) ?>%)</span><strong data-res-deposito data-pct="<?= e(number_format((float) $neg['reserva_pct'], 2, '.', '')) ?>">—</strong></p>
        </div>
    </div>

    <?php if ($condiciones !== ''): ?>
        <details class="en-terms"><summary>Booking terms</summary><div><?= nl2br(e($condiciones)) ?></div></details>
    <?php endif; ?>
    <label class="en-check"><input type="checkbox" name="acepto" value="1" <?= !empty($df['acepto']) ? 'checked' : '' ?> required>
        I agree to the booking terms<?= $condiciones === '' ? '' : ' above' ?>.</label>

    <button class="en-btn en-btn-big" type="submit">Continue to payment</button>
    <p class="en-small">Next you choose: pay the deposit or the full amount by card. Payments are processed securely by Stripe.</p>
</form>
<?php endif; ?>
