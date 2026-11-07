<?php
use TuRenta\{DiaEvento, WhatsApp};

/** Mis eventos (personal). @var array $p @var array $negocio @var ?array $dueno @var array $proximos @var array $pagos @var ?string $mapa */
$lugar = trim(implode(', ', array_filter([$negocio['direccion'] ?? '', $negocio['ciudad'] ?? ''])));
$contactoGeneral = $negocio['whatsapp'] ?: ($negocio['telefono'] ?: ($dueno['telefono'] ?? null));
$hoy = date('Y-m-d');
?>
<header class="publico-cabeza">
    <span class="marca-sello dia-sello" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($p['nombre'], 0, 1))) ?></span>
    <div>
        <h1>Hola, <?= e(trim(explode(' ', $p['nombre'])[0])) ?></h1>
        <p>Tus eventos en <?= e($negocio['nombre']) ?></p>
    </div>
</header>

<section class="bloque dia-staff-lugar">
    <h2>Dónde</h2>
    <p><strong><?= e($negocio['nombre']) ?></strong><?= $lugar ? '<br>' . e($lugar) : '' ?></p>
    <div class="acciones">
        <?php if ($mapa): ?><a class="boton boton-claro" href="<?= e($mapa) ?>" target="_blank" rel="noopener">Ver en el mapa</a><?php endif; ?>
        <?php if ($wa = WhatsApp::enlace($contactoGeneral, 'Hola, soy ' . $p['nombre'] . ' del personal.')): ?>
            <a class="boton boton-wa" href="<?= e($wa) ?>" target="_blank" rel="noopener">Escribir al local</a><?php endif; ?>
    </div>
</section>

<h2 class="dia-staff-titulo">Próximos eventos</h2>
<?php if (!$proximos): ?>
    <section class="bloque"><p class="vacio">No tienes eventos asignados por ahora. Cuando te asignen uno, aparecerá aquí.</p></section>
<?php endif; ?>
<?php foreach ($proximos as $x):
    $coord = WhatsApp::enlace($x['coordinador_telefono'], 'Hola ' . trim(explode(' ', (string) $x['coordinador'])[0]) . ', soy ' . $p['nombre'] . ' (' . mb_strtolower(DiaEvento::ROLES[$x['rol']] ?? '') . ') para ' . $x['evento'] . '.'); ?>
<section class="bloque dia-staff-evento <?= $x['confirmado'] ? 'dia-staff-ok' : ($x['respuesta'] === 'no' ? 'dia-staff-no' : '') ?>">
    <p class="dia-staff-fecha"><?= e(ucfirst(fecha_larga($x['fecha']))) ?><?= $x['fecha'] === $hoy ? ' · <strong>¡HOY!</strong>' : '' ?></p>
    <h3><?= e($x['evento']) ?></h3>
    <dl class="dia-staff-datos">
        <div><dt>Llegas</dt><dd><?= e(hora_corta($x['hora_entrada']) ?: 'Por confirmar') ?></dd></div>
        <div><dt>Sales</dt><dd><?= e(hora_corta($x['hora_salida']) ?: '—') ?></dd></div>
        <div><dt>Haces</dt><dd><?= e(DiaEvento::ROLES[$x['rol']] ?? $x['rol']) ?></dd></div>
        <div><dt>Pago</dt><dd><?= dinero($x['pago']) ?></dd></div>
    </dl>
    <p class="suave"><small>El evento es de <?= e(hora_corta($x['hora_inicio'])) ?> a <?= e(hora_corta($x['hora_fin'])) ?>.</small>
        <?php if ($x['coordinador']): ?><br><small>Coordina: <?= e($x['coordinador']) ?></small><?php endif; ?></p>
    <?php if ($coord): ?><p><a class="boton boton-wa boton-chico" href="<?= e($coord) ?>" target="_blank" rel="noopener">Escribir al coordinador</a></p><?php endif; ?>
    <?php if ($x['confirmado']): ?>
        <p class="dia-staff-estado dia-ok-texto">✓ Confirmaste que vas.</p>
        <form method="post" class="dia-en-linea"><?= campo_csrf() ?><input type="hidden" name="asig_id" value="<?= (int) $x['id'] ?>">
            <button class="boton-enlace" type="submit" name="respuesta" value="no" data-confirmar="¿Avisar que ya NO puedes ir? Le llega un aviso al local.">Ya no puedo ir</button></form>
    <?php else: ?>
        <?php if ($x['respuesta'] === 'no'): ?><p class="dia-staff-estado dia-alerta-texto">Avisaste que no puedes ir.</p><?php else: ?><p class="dia-staff-estado"><strong>¿Puedes ir?</strong></p><?php endif; ?>
        <form method="post" class="dia-staff-botones"><?= campo_csrf() ?><input type="hidden" name="asig_id" value="<?= (int) $x['id'] ?>">
            <button class="boton dia-boton-grande" type="submit" name="respuesta" value="si">Sí, voy</button>
            <?php if ($x['respuesta'] !== 'no'): ?><button class="boton boton-claro dia-boton-grande" type="submit" name="respuesta" value="no">No puedo</button><?php endif; ?>
        </form>
    <?php endif; ?>
</section>
<?php endforeach; ?>

<section class="bloque">
    <h2>Mis pagos</h2>
    <div class="cifras">
        <div class="cifra-tarjeta <?= $pagos['total_pendiente'] > 0 ? 'alerta' : '' ?>"><small>Por cobrar</small><strong><?= dinero($pagos['total_pendiente']) ?></strong></div>
        <div class="cifra-tarjeta bien"><small>Ya cobrado</small><strong><?= dinero($pagos['total_recibido']) ?></strong></div>
    </div>
    <?php if (!$pagos['pendientes'] && !$pagos['recibidos']): ?>
        <p class="suave">Aquí verás tus pagos después de cada evento.</p>
    <?php else: ?>
    <ul class="filas">
        <?php foreach ($pagos['pendientes'] as $x): ?>
            <li><div><?= e($x['evento']) ?><br><small><?= e(fecha_corta($x['fecha'])) ?> · <span class="dia-alerta-texto">pendiente</span></small></div><div class="derecha monto"><?= dinero($x['pago']) ?></div></li>
        <?php endforeach; ?>
        <?php foreach (array_slice($pagos['recibidos'], 0, 12) as $x): ?>
            <li><div><?= e($x['evento']) ?><br><small><?= e(fecha_corta($x['fecha'])) ?> · <span class="dia-ok-texto">pagado<?= $x['pagado_en'] ? ' el ' . e(fecha_corta($x['pagado_en'], false)) : '' ?></span></small></div><div class="derecha monto"><?= dinero($x['pago']) ?></div></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</section>
<p class="suave" style="text-align:center"><small>Guarda este enlace: es solo tuyo.</small></p>
