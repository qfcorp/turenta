<?php
/** Portal de invitados de los novios. @var array $ev @var array $negocio @var string $token @var bool $editable @var ?array $marca */
?>
<header class="publico-cabeza">
    <?php if (!empty($negocio['logo'])): ?>
        <img class="dia-logo" src="<?= e(url('imagen_publica', ['tipo' => 'logo', 's' => $negocio['slug']])) ?>" alt="">
    <?php else: ?>
        <span class="marca-sello dia-sello" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($negocio['nombre'], 0, 1))) ?></span>
    <?php endif; ?>
    <div>
        <h1><?= e($ev['nombre']) ?></h1>
        <p><?= e(fecha_larga($ev['fecha'])) ?> · <?= e($negocio['nombre']) ?></p>
    </div>
</header>
<p><a href="<?= e(url('portal', ['t' => $token])) ?>">← Volver a mi evento</a></p>
<?php if (!$editable): ?>
    <p class="aviso dia-aviso-amarillo">El evento ya pasó. Puedes ver tu lista, pero ya no cambiarla.</p>
<?php else: ?>
    <p class="suave">Arma tu lista, las mesas y manda las invitaciones por WhatsApp. Cada invitado confirma desde su celular y recibe su código QR para la entrada.</p>
<?php endif; ?>
<div class="dia-portal">
<?php require RAIZ . '/app/vistas/invitados_panel.php'; ?>
</div>
