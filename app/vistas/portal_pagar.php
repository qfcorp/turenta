<?php
/**
 * Cajita oficial de Payphone para pagar con tarjeta.
 * @var array $ev @var string $token @var array $payphone @var array $marca
 */
?>
<header class="publico-cabeza">
    <span class="marca-sello" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($marca['nombre'], 0, 1))) ?></span>
    <div><h1><?= e($marca['nombre']) ?></h1><p><?= e($ev['nombre']) ?></p></div>
</header>
<section class="bloque cb-cajita" id="pagar">
    <h2>Pago con tarjeta: <?= dinero($payphone['amount'] / 100) ?></h2>
    <p class="suave">Escribe los datos de tu tarjeta en el recuadro. El pago lo procesa Payphone de forma segura: el local no ve los datos de tu tarjeta.</p>
    <link rel="stylesheet" href="https://cdn.payphonetodoesposible.com/box/v1.1/payphone-payment-box.css">
    <script type="module" src="https://cdn.payphonetodoesposible.com/box/v1.1/payphone-payment-box.js"></script>
    <div id="pp-button" class="cb-pp">Cargando la cajita de pago…</div>
    <script>
        window.addEventListener('load', function () {
            if (typeof PPaymentButtonBox === 'undefined') { document.getElementById('pp-button').textContent = 'No se pudo cargar Payphone. Recarga la página o paga por transferencia.'; return; }
            document.getElementById('pp-button').textContent = '';
            new PPaymentButtonBox(<?= json_encode($payphone, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>).render('pp-button');
        });
    </script>
    <p class="suave">Al terminar vuelves a tu portal y el pago se suma solo.</p>
    <a class="boton boton-claro boton-ancho" href="<?= e(url('portal', ['t' => $token])) ?>#pagar">Volver · prefiero transferencia o DeUna</a>
</section>
