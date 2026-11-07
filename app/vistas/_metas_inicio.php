<?php
/**
 * Avance de la meta de ventas del mes en el Inicio (módulo 080). Se incluye desde inicio.php.
 * Dueño y consulta: la meta del local. Vendedor: la suya (si el dueño se la puso). Coordinador: nada.
 * Todo con prefijo $mt para no pisar variables de la vista.
 */
use TuRenta\{Metas, Reportes};

$mtHoy = isset($hoy) && $hoy instanceof DateTimeImmutable ? $hoy : new DateTimeImmutable('today');
$mtRol = $u['rol'] ?? '';
$mtProgreso = null;
if (in_array($mtRol, ['dueno', 'admin', 'consulta', 'vendedor'], true)) {
    $mtProgreso = (new Metas(db()))->progreso((int) $u['negocio_id'], $mtRol === 'vendedor' ? (int) $u['id'] : null, $mtHoy);
}
$mtNombreMes = Reportes::MESES[(int) $mtHoy->format('n')];
if ($mtProgreso):
    $mtP = $mtProgreso;
    $mtPct = (int) $mtP['pct'];
    $mtCumplida = $mtP['vendido'] >= $mtP['meta'] && $mtP['meta'] > 0;
    $mtAdelantado = $mtPct >= $mtP['pct_mes'];
    $mtDias = $mtP['dias_faltan'] === 0 ? 'hoy es el último día del mes' : ($mtP['dias_faltan'] === 1 ? 'queda 1 día' : 'quedan ' . $mtP['dias_faltan'] . ' días');
?>
<section class="bloque mt-inicio<?= $mtCumplida ? ' mt-cumplida' : '' ?>" aria-labelledby="mt-inicio-t">
    <div class="g-bloque-cab">
        <h2 id="mt-inicio-t"><?= $mtRol === 'vendedor' ? 'Tu meta de ' : 'Meta de ' ?><?= e($mtNombreMes) ?></h2>
        <a href="<?= e(url('reportes', ['p' => 'mes'])) ?>#metas">Ver detalle</a>
    </div>
    <p class="mt-cifra"><strong><?= dinero($mtP['vendido']) ?></strong> <span>de <?= dinero($mtP['meta']) ?></span> <b class="mt-pct"><?= $mtPct ?> %</b></p>
    <div class="mt-barra" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= min(100, $mtPct) ?>" aria-label="Vendido contra la meta">
        <span style="width:<?= min(100, $mtPct) ?>%"></span>
        <?php if (!$mtCumplida): ?><i class="mt-ritmo" style="left:<?= (int) $mtP['pct_mes'] ?>%" title="Dónde deberías ir hoy (<?= (int) $mtP['pct_mes'] ?> % del mes)"></i><?php endif; ?>
    </div>
    <p class="mt-detalle">
        <?php if ($mtCumplida): ?>
            <strong>¡Meta cumplida!</strong><?= $mtP['vendido'] > $mtP['meta'] ? ' Vas ' . dinero($mtP['vendido'] - $mtP['meta']) . ' por encima.' : '' ?>
        <?php else: ?>
            Faltan <strong><?= dinero($mtP['falta']) ?></strong> · <?= e($mtDias) ?>
        <?php endif; ?>
        <?php if ($mtP['meta_eventos']): ?> · <?= (int) $mtP['cantidad'] ?> de <?= (int) $mtP['meta_eventos'] ?> eventos<?php endif; ?>
    </p>
    <p class="mt-nota suave">
        <?php if (!$mtCumplida): ?><?= $mtAdelantado ? 'Vas al día o adelantado para este mes.' : 'Vas atrasado: a esta altura del mes deberías ir en ' . (int) $mtP['pct_mes'] . ' %.' ?><?php endif; ?>
        <?php if ($mtP['anio_pasado'] > 0): ?>
            <?= e(ucfirst($mtNombreMes)) ?> del año pasado: <?= dinero($mtP['anio_pasado']) ?><?php if ($mtP['variacion'] !== null): ?>
            (<span class="<?= $mtP['variacion'] >= 0 ? 'mt-sube' : 'mt-baja' ?>"><?= $mtP['variacion'] >= 0 ? '+' : '' ?><?= (int) $mtP['variacion'] ?> %</span>)<?php endif; ?>.
        <?php endif; ?>
    </p>
</section>
<?php elseif (es_admin_total($u)): ?>
<a class="g-franja mt-invitar" href="<?= e(url('metas')) ?>">🎯 Ponle una meta de ventas a <?= e($mtNombreMes) ?> y mira aquí cuánto te falta cada día.</a>
<?php endif; ?>
