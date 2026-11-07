<?php
/** Tarjeta del inicio: pagos a proveedores vencidos y de esta semana (solo para quien ve el dinero). Usa $u y $hoy. */
$pvInicio = (new \TuRenta\Compras(db()))->resumenInicio((int) $u['negocio_id'], $hoy ?? null);
if ($pvInicio['total'] > 0): ?>
<a class="g-cifra <?= $pvInicio['vencidas'] ? 'alerta' : ($pvInicio['semana'] ? 'atencion' : '') ?>" href="<?= e(url('por_pagar')) ?>">
    <strong><?= dinero($pvInicio['vencidas'] ? $pvInicio['vencido'] : $pvInicio['semana_valor']) ?></strong>
    <span>Pagos a proveedores: <?= (int) $pvInicio['vencidas'] ?> <?= $pvInicio['vencidas'] === 1 ? 'vencido' : 'vencidos' ?>, <?= (int) $pvInicio['semana'] ?> esta semana</span></a>
<?php endif;
