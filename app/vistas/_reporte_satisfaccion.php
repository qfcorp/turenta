<?php
/**
 * Reportes → Satisfacción (migración 070): NPS, promedios por aspecto, por mes y comentarios de los clientes.
 * Se incluye desde app/vistas/reportes.php (usa $per y $u). Cuenta las encuestas de los eventos con fecha en el periodo.
 */
use TuRenta\{Encuestas, Reportes};

$sat = (new Encuestas(db()))->reporte((int) $u['negocio_id'], $per['desde'], $per['hasta']);
$npsClase = $sat['nps'] === null ? '' : ($sat['nps'] >= 50 ? 'pt-nps-bien' : ($sat['nps'] >= 0 ? 'pt-nps-medio' : 'pt-nps-mal'));
$estrellas = fn(?float $v) => $v === null ? '—' : number_format($v, 1, ',', '') . ' ★';
?>
<section class="bloque" id="satisfaccion">
    <h2>Satisfacción de los clientes</h2>
    <p class="g-nota">Encuesta que recibe el cliente después de su evento. NPS = % que nos recomendaría con 9 o 10 − % que puso 6 o menos (va de −100 a 100; más de 50 es excelente).</p>
    <?php if (!$sat['respuestas']): ?>
        <p class="vacio">Todavía no hay encuestas de eventos de este periodo. La encuesta sale en "Mensajes por WhatsApp" el día después de cada evento.</p>
    <?php else: ?>
    <div class="g-cifras pt-sat-cifras">
        <div class="g-cifra <?= $npsClase ?>"><strong><?= (int) $sat['nps'] ?></strong><span>NPS · <?= (int) $sat['respuestas'] ?> <?= $sat['respuestas'] === 1 ? 'encuesta' : 'encuestas' ?></span></div>
        <div class="g-cifra"><strong><?= (int) $sat['tasa'] ?> %</strong><span>Respondieron · de <?= (int) $sat['eventos'] ?> eventos</span></div>
        <?php foreach (Encuestas::ASPECTOS as $k => $txt): ?>
            <div class="g-cifra"><strong><?= e($estrellas($sat['promedios'][$k])) ?></strong><span><?= e($txt) ?></span></div>
        <?php endforeach; ?>
    </div>
    <div class="pt-sat-reparto" role="img" aria-label="<?= (int) $sat['pct_promotores'] ?> % promotores, <?= (int) $sat['pct_pasivos'] ?> % pasivos, <?= (int) $sat['pct_detractores'] ?> % detractores">
        <?php if ($sat['promotores']): ?><span class="pt-sat-prom" style="width:<?= (int) $sat['pct_promotores'] ?>%"></span><?php endif; ?>
        <?php if ($sat['pasivos']): ?><span class="pt-sat-pas" style="width:<?= (int) $sat['pct_pasivos'] ?>%"></span><?php endif; ?>
        <?php if ($sat['detractores']): ?><span class="pt-sat-det" style="width:<?= (int) $sat['pct_detractores'] ?>%"></span><?php endif; ?>
    </div>
    <p class="pt-sat-leyenda"><span><i class="pt-sat-prom"></i> Nos recomiendan (9-10): <?= (int) $sat['promotores'] ?></span>
        <span><i class="pt-sat-pas"></i> Conformes (7-8): <?= (int) $sat['pasivos'] ?></span>
        <span><i class="pt-sat-det"></i> No contentos (0-6): <?= (int) $sat['detractores'] ?></span></p>

    <?php if (count($sat['por_mes']) > 1): ?>
    <h3>Por mes del evento</h3>
    <div class="tabla-scroll"><table class="tabla" style="min-width:300px">
        <thead><tr><th>Mes</th><th class="num">Encuestas</th><th class="num">NPS</th></tr></thead>
        <tbody><?php foreach ($sat['por_mes'] as $mes => $m): ?>
            <tr><td><?= e(ucfirst(Reportes::nombreMes($mes))) ?></td><td class="num"><?= (int) $m['respuestas'] ?></td><td class="num"><?= (int) $m['nps'] ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>

    <?php if ($sat['comentarios']): ?>
    <h3>Lo que dicen los clientes</h3>
    <ul class="pt-sat-comentarios">
        <?php foreach ($sat['comentarios'] as $i => $c): $g = Encuestas::grupo((int) $c['nps']); ?>
        <li class="<?= $i >= 8 ? 'pt-sat-mas' : '' ?>" <?= $i >= 8 ? 'hidden' : '' ?>>
            <span class="pt-opinion-nps pt-opinion-<?= e($g) ?> pt-nps-chico"><strong><?= (int) $c['nps'] ?></strong></span>
            <div><p>«<?= nl2br(e($c['comentario'])) ?>»</p>
                <small class="suave"><?= e($c['cliente']) ?> · <a href="<?= e(url('evento_formulario', ['id' => $c['evento_id']])) ?>#opinion"><?= e($c['evento']) ?></a> · <?= e(fecha_corta($c['fecha'])) ?></small></div>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php if (count($sat['comentarios']) > 8): ?><button type="button" class="boton boton-claro boton-chico" data-pt-ver-mas=".pt-sat-mas">Ver los <?= count($sat['comentarios']) ?> comentarios</button><?php endif; ?>
    <?php endif; ?>
    <?php endif; ?>
</section>
