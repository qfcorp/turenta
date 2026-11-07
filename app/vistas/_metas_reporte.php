<?php
/**
 * Reportes → Metas (módulo 080): cada mes del periodo con meta, vendido, % de cumplimiento, el mismo mes del año anterior
 * y el cambio; y por vendedor. "Vendido" = lo mismo que la sección Ventas. El vendedor ve solo su fila de vendedor.
 * Se incluye desde reportes.php (usa $per y $u). Variables con prefijo $mt.
 */
use TuRenta\{Metas, Reportes};

$mtSolo = ($u['rol'] ?? '') === 'vendedor' ? (int) $u['id'] : null;
$mtRep = (new Metas(db()))->reporte((int) $u['negocio_id'], $per['desde'], $per['hasta'], $mtSolo);
$mtCambio = function (?int $v): string {
    if ($v === null) return '<span class="suave">—</span>';
    return '<span class="' . ($v >= 0 ? 'mt-sube' : 'mt-baja') . '">' . ($v > 0 ? '▲ +' : ($v < 0 ? '▼ ' : '')) . $v . ' %</span>';
};
$mtAvance = function (?int $pct): string {
    if ($pct === null) return '<span class="suave">Sin meta</span>';
    $clase = $pct >= 100 ? 'mt-ok' : ($pct >= 70 ? 'mt-cerca' : 'mt-lejos');
    return '<span class="mt-avance ' . $clase . '"><span class="mt-mini"><span style="width:' . min(100, $pct) . '%"></span></span><b>' . $pct . ' %</b></span>';
};
$mtT = $mtRep['total'];
$mtMeses = $mtRep['meses'];
?>
<section class="bloque" id="metas">
    <div class="g-bloque-cab">
        <h2>Metas y comparación con el año anterior</h2>
        <?php if (es_admin_total($u)): ?><a href="<?= e(url('metas', ['anio' => substr($per['desde'], 0, 4)])) ?>"><?= $mtRep['hay_metas'] ? 'Cambiar metas' : 'Poner metas' ?></a><?php endif; ?>
    </div>
    <p class="g-nota">Vendido igual que en Ventas (por el mes en que se separó o confirmó el evento, con IVA). Se cuentan los meses completos
        <?= count($mtMeses) === 1 ? 'de ' . e(Reportes::nombreMes(array_key_first($mtMeses))) : 'del periodo' ?>.</p>

    <?php if (!$mtRep['hay_metas']): ?>
        <p class="aviso aviso-ok mt-sin-metas">Todavía no hay metas de ventas.<?= es_admin_total($u) ? ' Ponlas en <a href="' . e(url('metas')) . '">Metas de ventas</a> para ver cuánto te falta cada mes.' : ' El dueño o un administrador las pone.' ?>
            Abajo igual puedes comparar con el año anterior.</p>
    <?php endif; ?>

    <?php if ($mtT['meses_con_meta']): ?>
    <div class="g-cifras mt-cifras-rep">
        <div class="g-cifra"><strong><?= dinero($mtT['vendido']) ?></strong><span>Vendido · meta <?= dinero($mtT['meta']) ?></span></div>
        <div class="g-cifra <?= ($mtT['pct'] ?? 0) >= 100 ? '' : 'atencion' ?>"><strong><?= (int) $mtT['pct'] ?> %</strong><span>Cumplimiento de la meta</span></div>
        <div class="g-cifra"><strong><?= $mtT['variacion'] === null ? '—' : (($mtT['variacion'] > 0 ? '+' : '') . $mtT['variacion'] . ' %') ?></strong><span>Contra el año anterior (<?= dinero($mtT['anterior']) ?>)</span></div>
    </div>
    <?php endif; ?>

    <div class="tabla-scroll">
        <table class="tabla mt-tabla-rep">
            <caption class="sr-only">Metas por mes</caption>
            <thead><tr><th>Mes</th><th class="num">Meta</th><th class="num">Vendido</th><th>Cumplimiento</th><th class="num">Año anterior</th><th class="num">Cambio</th></tr></thead>
            <tbody>
            <?php foreach ($mtMeses as $f): ?>
                <tr class="<?= $f['futuro'] ? 'mt-futuro' : '' ?>"><td><?= e(ucfirst(Reportes::nombreMes($f['mes']))) ?></td>
                    <td class="num"><?= $f['meta'] !== null ? dinero($f['meta']) : '<span class="suave">—</span>' ?><?= $f['meta_eventos'] ? '<br><small>' . (int) $f['meta_eventos'] . ' eventos</small>' : '' ?></td>
                    <td class="num"><?= dinero($f['vendido']) ?><br><small><?= (int) $f['cantidad'] ?> <?= (int) $f['cantidad'] === 1 ? 'evento' : 'eventos' ?></small></td>
                    <td><?= $f['futuro'] ? '<span class="suave">Todavía no llega</span>' : $mtAvance($f['pct']) ?></td>
                    <td class="num"><?= dinero($f['anterior']) ?><br><small><?= (int) $f['anterior_cantidad'] ?> ev.</small></td>
                    <td class="num"><?= $mtCambio($f['variacion']) ?></td></tr>
            <?php endforeach; ?>
            <?php if (count($mtMeses) > 1): ?>
                <tr class="fila-total"><td>Total<?= $mtT['meses_futuros'] ? '<br><small>hasta este mes</small>' : '' ?></td><td class="num"><?= $mtT['meses_con_meta'] ? dinero($mtT['meta']) : '—' ?></td><td class="num"><?= dinero($mtT['vendido']) ?></td>
                    <td><?= $mtAvance($mtT['pct']) ?></td><td class="num"><?= dinero($mtT['anterior']) ?></td><td class="num"><?= $mtCambio($mtT['variacion']) ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($mtRep['vendedores']): ?>
    <h3 style="margin-top:16px"><?= $mtSolo ? 'Tu avance' : 'Por vendedor' ?></h3>
    <div class="tabla-scroll">
        <table class="tabla mt-tabla-rep">
            <caption class="sr-only">Metas por vendedor</caption>
            <thead><tr><th>Vendedor</th><th class="num">Meta</th><th class="num">Vendido</th><th>Cumplimiento</th><th class="num">Año anterior</th><th class="num">Cambio</th></tr></thead>
            <tbody>
            <?php foreach ($mtRep['vendedores'] as $v): ?>
                <tr><td><?= e($v['nombre']) ?></td>
                    <td class="num"><?= $v['con_meta'] ? dinero($v['meta']) : '<span class="suave">—</span>' ?></td>
                    <td class="num"><?= dinero($v['vendido']) ?><br><small><?= (int) $v['cantidad'] ?> ev.</small></td>
                    <td><?= $mtAvance($v['pct']) ?></td>
                    <td class="num"><?= dinero($v['anterior']) ?></td>
                    <td class="num"><?= $mtCambio($v['variacion']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    <div class="g-pie-tabla"><a class="g-descargar" href="<?= e(url('metas', ['csv' => 1, 'desde' => $per['desde'], 'hasta' => $per['hasta']])) ?>">Descargar metas para Excel</a></div>
</section>
