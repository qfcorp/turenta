<?php
use TuRenta\{ProveedoresFicha, PuntajeProveedores};

/** Vista previa del puntaje con la configuración propuesta. @var array $previa (filas, cambia, con_puntaje) */
$celda = function (array $r): string {
    if ($r['puntaje'] === null) return '<span class="pv-puntaje pv-sin-datos"><small>' . e(ProveedoresFicha::textoSinPuntaje($r)) . '</small></span>';
    return '<span class="pv-puntaje pv-nivel-' . e($r['nivel']) . '"><strong>' . (int) $r['puntaje'] . '</strong><small>' . e(PuntajeProveedores::NIVELES[$r['nivel']]) . '</small></span>';
};
?>
<?php if (!$previa['filas']): ?>
    <p class="vacio">Todavía ningún proveedor tiene datos (llegadas, calificaciones o incidencias). Cuando los tengan, aquí verás cómo cambia su puntaje.</p>
<?php else: ?>
<ul class="pv-previa">
    <?php foreach ($previa['filas'] as $f): $d = $f['despues']; ?>
    <li>
        <div class="pv-previa-nombre"><strong><?= e($f['nombre']) ?></strong>
            <small><?= (int) $d['eventos'] ?> <?= $d['eventos'] === 1 ? 'evento' : 'eventos' ?>
                <?= $d['calificacion'] !== null ? ' · ★ ' . e(number_format((float) $d['calificacion'], 1, ',', '')) : '' ?>
                <?= $d['puntualidad'] !== null ? ' · ' . (int) $d['puntualidad'] . ' % puntual' : '' ?>
                <?= $d['incidencias'] ? ' · ' . (int) $d['incidencias'] . ($d['incidencias'] === 1 ? ' incidencia' : ' incidencias') : '' ?></small></div>
        <div class="pv-previa-cifras">
            <span class="pv-previa-col"><small>Ahora</small><?= $celda($f['antes']) ?><?= $f['puesto_antes'] ? '<small>puesto ' . (int) $f['puesto_antes'] . '</small>' : '' ?></span>
            <span class="pv-previa-flecha" aria-hidden="true">→</span>
            <span class="pv-previa-col"><small>Con los cambios</small><?= $celda($d) ?><?= $f['puesto_despues'] ? '<small>puesto ' . (int) $f['puesto_despues'] . '</small>' : '' ?></span>
        </div>
        <?php if ($d['detalle']): ?>
        <p class="pv-previa-detalle"><small><?= e(implode(' · ', array_map(fn($k, $x) => PuntajeProveedores::PESOS[$k] . ' ' . $x['valor'] . ' (pesa ' . str_replace('.', ',', (string) $x['peso']) . ' %)', array_keys($d['detalle']), $d['detalle']))) ?>
            <?= $d['tope_aplicado'] ? ' · tiene una incidencia grave: nivel limitado' : '' ?></small></p>
        <?php endif; ?>
    </li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>
