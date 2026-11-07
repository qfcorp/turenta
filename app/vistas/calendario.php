<?php
use TuRenta\Eventos;

/** @var string $vistaCal @var int $anio @var int $mes @var array $espacios @var int $espacioId */
$mesesLargos = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$dispTexto = ['libre' => 'Libre', 'consulta' => 'Hay consultas', 'retenido' => 'Retenido', 'ocupado' => 'Ocupado', 'cerrado' => 'Cerrado'];
$dispAyuda = [
    'libre'    => 'Nadie ha preguntado por esta fecha.',
    'consulta' => 'Alguien preguntó, pero no tiene cotización vigente. Se puede vender.',
    'retenido' => 'Hay una cotización vigente que guarda la fecha unos días (no está bloqueada).',
    'ocupado'  => 'Fecha separada con pago: no se puede vender.',
    'cerrado'  => 'Marcaste este día como cerrado.',
];
$filtroEsp = $espacioId ? ['espacio' => $espacioId] : [];
$urlMes = fn(DateTimeImmutable $d, array $extra = []) => url('calendario', ['a' => $d->format('Y'), 'm' => $d->format('n')] + $filtroEsp + $extra);
?>
<div class="cabecera">
    <div>
        <h1>Calendario</h1>
        <p><?= $vistaCal === 'sabados' ? 'Los próximos 12 sábados, espacio por espacio' : 'Toca un día para ver qué espacios están libres' ?></p>
    </div>
    <div class="acciones">
        <?php if ($puedeCrear): ?><a class="boton" href="<?= e(url('evento_nuevo', $dia ? ['fecha' => $dia] : [])) ?>">Nuevo evento</a><?php endif; ?>
    </div>
</div>

<nav class="cal-vistas" aria-label="Forma de ver el calendario">
    <a href="<?= e(url('calendario', ['a' => $anio, 'm' => $mes] + $filtroEsp)) ?>" class="<?= $vistaCal === 'mes' ? 'activa' : '' ?>" <?= $vistaCal === 'mes' ? 'aria-current="page"' : '' ?>>Mes</a>
    <a href="<?= e(url('calendario', ['vista' => 'sabados'])) ?>" class="<?= $vistaCal === 'sabados' ? 'activa' : '' ?>" <?= $vistaCal === 'sabados' ? 'aria-current="page"' : '' ?>>Próximos sábados</a>
</nav>

<?php if ($vistaCal === 'sabados'): ?>
    <?php if (!$espacios): ?>
        <section class="bloque vacio">
            <p>Todavía no tienes espacios activos.</p>
            <a class="boton" href="<?= e(url('espacios')) ?>">Crear un espacio</a>
        </section>
    <?php else: ?>
    <p class="suave cal-ayuda">Los sábados son los días que más se venden. Úsalo cuando un cliente pregunte "¿qué sábados tienen libres?".</p>
    <ul class="sabados" aria-label="Próximos sábados">
        <?php foreach ($sabados as $s): ?>
            <li class="sabado <?= $s['vendibles'] === 0 ? 'sabado-lleno' : '' ?>">
                <a class="sabado-fecha" href="<?= e(url('calendario', ['dia' => $s['fecha']])) ?>#dia">
                    <strong><?= e(fecha_corta($s['fecha'])) ?></strong>
                    <small><?= $s['vendibles'] === 0 ? 'Nada para vender'
                        : ($s['libres'] === count($s['espacios']) ? 'Todo libre'
                        : $s['vendibles'] . ' de ' . count($s['espacios']) . ($s['vendibles'] === 1 ? ' se puede vender' : ' se pueden vender')) ?></small>
                </a>
                <div class="sabado-espacios">
                    <?php foreach ($s['espacios'] as $d): ?>
                        <?php if (in_array($d['estado'], ['libre', 'consulta'], true) && $puedeCrear): ?>
                            <a class="disp disp-<?= e($d['estado']) ?>" href="<?= e(url('evento_nuevo', ['fecha' => $s['fecha'], 'espacio' => $d['espacio_id']])) ?>"
                               title="<?= e($dispAyuda[$d['estado']]) ?> Toca para crear un evento.">
                                <span><?= e($d['espacio']) ?></span><b><?= $d['estado'] === 'libre' ? 'Libre · vender' : 'Hay consultas · vender' ?></b></a>
                        <?php else: ?>
                            <span class="disp disp-<?= e($d['estado']) ?>" title="<?= e($dispAyuda[$d['estado']]) ?>">
                                <span><?= e($d['espacio']) ?></span><b><?= e($dispTexto[$d['estado']]) ?></b></span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
<?php else: ?>

<div class="cal-barra">
    <div class="cal-nav">
        <a class="boton boton-claro boton-chico" href="<?= e($urlMes($anterior)) ?>" aria-label="Mes anterior">‹ <span class="solo-ancho"><?= e(ucfirst($mesesLargos[(int) $anterior->format('n')])) ?></span></a>
        <h2 class="cal-titulo"><?= e(ucfirst($mesesLargos[$mes])) ?> <?= $anio ?></h2>
        <a class="boton boton-claro boton-chico" href="<?= e($urlMes($siguiente)) ?>" aria-label="Mes siguiente"><span class="solo-ancho"><?= e(ucfirst($mesesLargos[(int) $siguiente->format('n')])) ?></span> ›</a>
    </div>
    <form method="get" class="cal-filtro">
        <input type="hidden" name="r" value="calendario">
        <input type="hidden" name="a" value="<?= $anio ?>">
        <input type="hidden" name="m" value="<?= $mes ?>">
        <label class="sr-only" for="filtro-espacio">Espacio</label>
        <select id="filtro-espacio" name="espacio" data-autoenviar>
            <option value="">Todos los espacios</option>
            <?php foreach ($espacios as $es): ?>
                <option value="<?= (int) $es['id'] ?>" <?= $espacioId === (int) $es['id'] ? 'selected' : '' ?>><?= e($es['nombre']) ?></option>
            <?php endforeach; ?>
        </select>
        <noscript><button class="boton boton-chico" type="submit">Ver</button></noscript>
        <?php if ((int) date('Y') !== $anio || (int) date('n') !== $mes): ?>
            <a class="boton boton-claro boton-chico" href="<?= e(url('calendario', $filtroEsp)) ?>">Hoy</a>
        <?php endif; ?>
    </form>
</div>

<div class="cal-contenedor <?= $dia ? 'con-dia' : '' ?>">
    <div>
        <div class="cal" role="grid" aria-label="Eventos de <?= e($mesesLargos[$mes]) ?> <?= $anio ?>">
            <?php foreach (['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'] as $i => $n): ?>
                <div class="cal-cab" role="columnheader"><?= $n ?></div>
            <?php endforeach; ?>
            <?php foreach ($cal['semanas'] as $semana): ?>
                <?php foreach ($semana as $f):
                    $evs = $cal['eventos'][$f] ?? [];
                    $cer = $cal['cerrados'][$f] ?? [];
                    $d = new DateTimeImmutable($f);
                    $clases = ['cal-dia'];
                    if ((int) $d->format('n') !== $mes) $clases[] = 'otro-mes';
                    if ($f === $hoyTxt) $clases[] = 'hoy';
                    if ((int) $d->format('N') >= 6) $clases[] = 'finde';
                    if ($cer) $clases[] = 'cerrado';
                    if ($f === $dia) $clases[] = 'elegido';
                    if ($f < $hoyTxt) $clases[] = 'pasado';
                    $resumen = count($evs) ? count($evs) . (count($evs) === 1 ? ' evento' : ' eventos') : 'sin eventos';
                    if ($cer) $resumen .= ', cerrado';
                ?>
                <div class="<?= implode(' ', $clases) ?>" role="gridcell">
                    <a class="cal-num cal-abrir" href="<?= e(url('calendario', ['a' => $anio, 'm' => $mes, 'dia' => $f] + $filtroEsp)) ?>#dia"
                       aria-label="<?= e(fecha_larga($f)) ?>: <?= e($resumen) ?>"><?= (int) $d->format('j') ?></a>
                    <?php foreach ($cer as $c): ?>
                        <span class="cal-ev ev-cerrado" title="Cerrado<?= $c['espacio'] ? ': ' . e($c['espacio']) : '' ?><?= $c['motivo'] ? ' — ' . e($c['motivo']) : '' ?>">Cerrado<?= $c['espacio'] ? ' · ' . e($c['espacio']) : '' ?></span>
                    <?php endforeach; ?>
                    <?php foreach (array_slice($evs, 0, 3) as $ev): ?>
                        <a class="cal-ev ev-<?= e($ev['estado']) ?>" href="<?= e(url('evento', ['id' => $ev['id']])) ?>"
                           title="<?= e($ev['nombre'] . ' · ' . hora_corta($ev['hora_inicio']) . ' · ' . ($ev['espacios'] ?? '') . ' · ' . Eventos::ESTADOS[$ev['estado']]) ?>">
                            <span class="cal-ev-hora"><?= e(hora_corta($ev['hora_inicio'])) ?></span> <?= e($ev['nombre']) ?></a>
                    <?php endforeach; ?>
                    <?php if (count($evs) > 3): ?><span class="cal-mas">+<?= count($evs) - 3 ?> más</span><?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </div>

        <ul class="cal-leyenda" aria-label="Qué significa cada color">
            <?php foreach (['consulta', 'cotizado', 'separado', 'confirmado', 'realizado'] as $est): ?>
                <li><span class="ev-estado ev-<?= $est ?>"><?= e(Eventos::ESTADOS[$est]) ?></span> <small><?= e(Eventos::ESTADOS_AYUDA[$est]) ?></small></li>
            <?php endforeach; ?>
            <li><span class="ev-estado ev-cerrado">Cerrado</span> <small>Día que no se alquila.</small></li>
            <li><span class="cal-muestra-hoy" aria-hidden="true"></span> <span>Hoy</span></li>
        </ul>
    </div>

    <?php if ($dia): ?>
    <aside class="bloque cal-panel" id="dia" aria-labelledby="titulo-dia">
        <div class="cal-panel-cabeza">
            <h2 id="titulo-dia"><?= e(ucfirst(fecha_larga($dia))) ?></h2>
            <a class="boton boton-claro boton-chico" href="<?= e(url('calendario', ['a' => $anio, 'm' => $mes] + $filtroEsp)) ?>" aria-label="Cerrar el detalle del día">✕</a>
        </div>
        <?php if (!empty($cierresDia)): ?>
            <?php foreach ($cierresDia as $c): ?>
                <p class="aviso aviso-error">Cerrado<?= $c['espacio'] ? ' (' . e($c['espacio']) . ')' : ' (todo el local)' ?><?= $c['motivo'] ? ': ' . e($c['motivo']) : '' ?></p>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php if ($dia < $hoyTxt): ?><p class="suave">Este día ya pasó.</p><?php endif; ?>
        <?php if (!$disponibilidad): ?>
            <p class="vacio">No tienes espacios activos. <a href="<?= e(url('espacios')) ?>">Crea uno</a>.</p>
        <?php else: ?>
        <h3>Espacios</h3>
        <ul class="filas disp-lista">
            <?php foreach ($disponibilidad as $d): ?>
                <li>
                    <div>
                        <strong><span class="punto-color" style="background:<?= e($d['color']) ?>" aria-hidden="true"></span><?= e($d['espacio']) ?></strong>
                        <small class="suave" style="display:block"><?= e($dispAyuda[$d['estado']]) ?>
                            <?= $d['capacidad_banquete'] ? ' · ' . (int) $d['capacidad_banquete'] . ' sentados' : '' ?></small>
                        <?php foreach ($d['eventos'] as $ev): ?>
                            <a class="disp-evento" href="<?= e(url('evento', ['id' => $ev['id']])) ?>">
                                <span class="ev-estado ev-<?= e($ev['estado']) ?>"><?= e(Eventos::ESTADOS[$ev['estado']]) ?></span>
                                <?= e(hora_corta($ev['hora_inicio'])) ?>–<?= e(hora_corta($ev['hora_fin'])) ?> · <?= e($ev['nombre']) ?></a>
                        <?php endforeach; ?>
                    </div>
                    <span class="derecha disp disp-<?= e($d['estado']) ?>"><b><?= e($dispTexto[$d['estado']]) ?></b></span>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
        <?php if ($puedeCrear && $dia >= $hoyTxt): ?>
            <a class="boton boton-ancho" href="<?= e(url('evento_nuevo', ['fecha' => $dia])) ?>">Nuevo evento este día</a>
        <?php endif; ?>
    </aside>
    <?php endif; ?>
</div>
<?php endif; ?>
