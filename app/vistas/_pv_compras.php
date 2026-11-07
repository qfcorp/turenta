<?php
use TuRenta\Compras;

/**
 * Lista de compras (tarjetas que se leen bien en el celular). Se incluye con:
 *   $listaCompras (Compras::lista/completar), opcional $ocultarProveedor, $ocultarEvento.
 */
$ocultarProveedor ??= false;
$ocultarEvento ??= false;
$clsPago = ['pagada' => 'pv-chip-verde', 'vencida' => 'pv-chip-rojo', 'parcial' => 'pv-chip-amarillo', 'pendiente' => '', 'sin_valor' => 'pv-chip-suave', 'no_aplica' => 'pv-chip-suave'];
?>
<ul class="pv-compras">
    <?php foreach ($listaCompras as $c): $activa = in_array($c['estado'], Compras::ACTIVAS, true); ?>
    <li class="pv-compra pv-est-<?= e($c['estado']) ?>">
        <a class="pv-compra-enlace" href="<?= e(url('compra', ['id' => $c['id']])) ?>">
            <span class="pv-compra-cab">
                <strong><?= e($c['codigo']) ?></strong>
                <span class="pv-estado pv-estado-<?= e($c['estado']) ?>"><?= e(Compras::ESTADOS[$c['estado']]) ?></span>
                <?php if ($activa && $c['estado_pago'] !== 'pendiente'): ?><span class="pv-chip <?= e($clsPago[$c['estado_pago']] ?? '') ?>"><?= e(Compras::ESTADOS_PAGO[$c['estado_pago']]) ?></span><?php endif; ?>
            </span>
            <span class="pv-compra-texto">
                <?php if (!$ocultarProveedor): ?><strong><?= e($c['proveedor']) ?></strong><br><?php endif; ?>
                <small><?= e(fecha_corta($c['fecha'])) ?><?= !$ocultarEvento ? ' · ' . ($c['evento'] ? e($c['evento']) : 'Compra general') : '' ?><?= $c['factura_numero'] ? ' · Fact. ' . e($c['factura_numero']) : '' ?></small>
            </span>
            <span class="pv-compra-montos">
                <span><small>Valor</small><?= dinero($c['base']) ?></span>
                <?php if ($activa): ?>
                <span><small>Saldo</small><strong class="<?= $c['saldo'] > 0 && $c['estado_pago'] === 'vencida' ? 'cb-rojo' : '' ?>"><?= dinero($c['saldo']) ?></strong></span>
                <span><small>Vence</small><?= $c['vence'] ? e(fecha_corta($c['vence'], false)) : '—' ?></span>
                <?php endif; ?>
            </span>
        </a>
    </li>
    <?php endforeach; ?>
</ul>
