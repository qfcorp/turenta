<?php
use TuRenta\Cotizaciones;

/**
 * Detalle de una cotización por categoría + totales (solo lectura). Lo usan "cotizacion" y la página pública "cot".
 * Variables: $c (Cotizaciones::obtener), $verCostos (bool: ganancia y margen, solo el dueño)
 */
$verCostos = $verCostos ?? false;
$grupos = [];
foreach ($c['items'] as $i) $grupos[$i['categoria']][] = $i;
$ordenCat = array_keys(Cotizaciones::CATEGORIAS);
uksort($grupos, fn($a, $b) => array_search($a, $ordenCat, true) <=> array_search($b, $ordenCat, true));
$ivaTxt = rtrim(rtrim(number_format((float) $c['iva_pct'], 2, ',', ''), '0'), ',');
$cantTxt = fn($n) => rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',');
?>
<div class="doc-detalle">
    <?php if (!$grupos): ?><p class="vacio">Esta cotización todavía no tiene productos.</p><?php endif; ?>
    <?php foreach ($grupos as $cat => $lineas): ?>
    <div class="doc-detalle-grupo">
        <h3><span><?= e(Cotizaciones::CATEGORIAS[$cat] ?? 'Otros') ?></span>
            <span class="monto"><?= dinero(array_sum(array_map(fn($l) => (float) $l['total'], $lineas))) ?></span></h3>
        <ul>
            <?php foreach ($lineas as $l): ?>
            <li>
                <span class="doc-detalle-desc"><?= e($l['descripcion']) ?><?= $l['con_iva'] ? '' : ' <small>(0 % IVA)</small>' ?>
                    <small><?= e(trim($cantTxt($l['cantidad']) . ' ' . ($l['unidad'] === 'persona' ? 'personas' : ($l['unidad'] === 'hora' ? 'horas' : ($l['unidad'] === 'unidad' ? 'unidades' : ''))))) ?> × <?= dinero($l['precio_unitario']) ?></small></span>
                <span class="monto"><?= dinero($l['total']) ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endforeach; ?>
    <dl class="doc-totales">
        <dt>Subtotal</dt><dd><?= dinero($c['subtotal']) ?></dd>
        <?php if ((float) $c['descuento'] > 0): ?><dt>Descuento</dt><dd>−<?= dinero($c['descuento']) ?></dd><?php endif; ?>
        <dt>Base IVA <?= e($ivaTxt) ?> %</dt><dd><?= dinero($c['base_iva']) ?></dd>
        <?php if ((float) $c['base_cero'] > 0): ?><dt>Base 0 %</dt><dd><?= dinero($c['base_cero']) ?></dd><?php endif; ?>
        <dt>IVA <?= e($ivaTxt) ?> %</dt><dd><?= dinero($c['iva']) ?></dd>
        <dt class="doc-total">Total</dt><dd class="doc-total"><?= dinero($c['total']) ?></dd>
        <dt class="doc-por-persona">Por persona <small>(<?= (int) $c['invitados'] ?> invitados, IVA incluido)</small></dt><dd class="doc-por-persona"><?= dinero($c['por_persona']) ?></dd>
        <?php if ($verCostos):
            $neto = (float) $c['subtotal'] - (float) $c['descuento'];
            $margen = $neto > 0 ? round($c['ganancia'] * 100 / $neto, 1) : 0; ?>
            <dt class="doc-ganancia">Ganancia estimada <small>(solo la ves tú)</small></dt>
            <dd class="doc-ganancia"><?= dinero($c['ganancia']) ?> · <?= e(number_format($margen, 1, ',', '.')) ?> %</dd>
        <?php endif; ?>
    </dl>
</div>
