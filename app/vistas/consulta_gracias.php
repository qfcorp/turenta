<section class="bloque confirmacion sitio-gracias" role="status">
    <p class="sitio-gracias-icono" aria-hidden="true">🎉</p>
    <h1>¡Gracias, <?= e($primer) ?>!</h1>
    <p>Recibimos tu consulta para <strong><?= e(mb_strtolower($tipoTxt)) ?></strong> el <strong><?= e(fecha_larga($ok['fecha'])) ?></strong>
        (<?= (int) $ok['invitados'] ?> invitados).</p>
    <p><?= e($neg['nombre']) ?> te escribirá pronto por WhatsApp con precios y disponibilidad.</p>
    <p class="suave">La fecha todavía no está separada: se asegura con el pago de la reserva.</p>
    <div class="acciones">
        <?php if ($wa): ?><a class="boton boton-wa" href="<?= e($wa) ?>" target="_blank" rel="noopener">Escribir ahora por WhatsApp</a><?php endif; ?>
        <a class="boton boton-claro" href="<?= e(url('sitio', ['s' => $neg['slug']])) ?>">Volver a la página</a>
    </div>
</section>
