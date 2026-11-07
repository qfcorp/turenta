<?php
use TuRenta\{Eventos, WhatsApp};
/** @var array $pend @var array $sinAtender @var array $sinRespuesta @var array $retenciones @var array $visitas */
$fmt = fn(?string $ts) => $ts ? fecha_corta(substr($ts, 0, 10), false) . ' · ' . substr($ts, 11, 5) : '';
$saludo = fn(?string $nombre) => 'Hola ' . explode(' ', trim((string) $nombre))[0] . ', te saludamos de ' . $u['negocio'] . '. ';
$totalPend = count($pend['vencidas']) + count($pend['hoy']) + count($pend['proximas']);
$grupos = ['vencidas' => ['Atrasadas', 'Ya pasó la fecha: hazlas primero.'], 'hoy' => ['Para hoy', ''], 'proximas' => ['Próximos 7 días', '']];
$filtro = $mias ? ['mias' => 1] : [];
?>
<div class="cabecera">
    <div>
        <h1>Seguimiento</h1>
        <p>A quién llamar, escribir o recibir. Nada se queda sin respuesta.</p>
    </div>
    <nav class="cal-vistas" aria-label="De quién">
        <a href="<?= e(url('seguimiento')) ?>" class="<?= !$mias ? 'activa' : '' ?>">Todo el equipo</a>
        <a href="<?= e(url('seguimiento', ['mias' => 1])) ?>" class="<?= $mias ? 'activa' : '' ?>">Solo lo mío</a>
    </nav>
</div>

<div class="cifras">
    <a class="cifra-tarjeta <?= $pend['vencidas'] ? 'alerta' : '' ?>" href="#pendientes"><small>Atrasadas</small><strong><?= count($pend['vencidas']) ?></strong></a>
    <a class="cifra-tarjeta" href="#pendientes"><small>Para hoy</small><strong><?= count($pend['hoy']) ?></strong></a>
    <a class="cifra-tarjeta <?= $sinAtender ? 'alerta' : '' ?>" href="#sin-atender"><small>Consultas sin atender</small><strong><?= count($sinAtender) ?></strong></a>
    <a class="cifra-tarjeta" href="#sin-respuesta"><small>Cotizaciones sin respuesta</small><strong><?= count($sinRespuesta) ?></strong></a>
</div>

<div class="rejilla rejilla-2">
<div>
    <section class="bloque" id="pendientes" aria-labelledby="t-pend">
        <h2 id="t-pend">Pendientes</h2>
        <?php if ($totalPend === 0): ?>
            <p class="vacio">¡Todo al día! No hay llamadas ni visitas pendientes para esta semana.<br>
                <small>Agéndalas desde cada evento, en la parte de Seguimiento.</small></p>
        <?php endif; ?>
        <?php foreach ($grupos as $g => [$tit, $ayuda]): if (!$pend[$g]) continue; ?>
            <h3 class="seg-grupo seg-<?= e($g) ?>"><?= e($tit) ?> <small>(<?= count($pend[$g]) ?>)</small></h3>
            <?php if ($ayuda): ?><p class="suave ayuda-chica"><?= e($ayuda) ?></p><?php endif; ?>
            <ul class="filas seg-lista">
                <?php foreach ($pend[$g] as $a):
                    $wa = WhatsApp::enlace($a['telefono'], $saludo($a['cliente'])); ?>
                    <li>
                        <div class="seg-item">
                            <span class="etq etq-act"><?= e(Eventos::TIPOS_ACTIVIDAD[$a['tipo']] ?? $a['tipo']) ?></span>
                            <strong class="<?= $g === 'vencidas' ? 'ocupado' : '' ?>"><?= e($fmt($a['programada_para'])) ?></strong>
                            <p style="margin:4px 0"><?= nl2br(e($a['texto'])) ?></p>
                            <small class="suave">
                                <?= e($a['cliente'] ?? '') ?>
                                <?php if ($a['evento_id']): ?> · <a href="<?= e(url('evento', ['id' => $a['evento_id']])) ?>#seguimiento"><?= e($a['evento']) ?></a><?php endif; ?>
                                <?= $a['usuario'] ? ' · ' . e($a['usuario']) : '' ?>
                            </small>
                            <?php if ($puedeEditar): ?>
                            <div class="acciones seg-acciones">
                                <form method="post" action="<?= e(url('seguimiento', ['accion' => 'hecha'] + $filtro)) ?>">
                                    <?= campo_csrf() ?><input type="hidden" name="actividad_id" value="<?= (int) $a['id'] ?>">
                                    <button class="boton boton-chico" type="submit">Hecho</button>
                                </form>
                                <?php if ($wa): ?><a class="boton boton-wa boton-chico" href="<?= e($wa) ?>" target="_blank" rel="noopener">WhatsApp</a><?php endif; ?>
                                <?php if ($a['telefono']): ?><a class="boton boton-claro boton-chico" href="tel:<?= e(preg_replace('/[^\d+]/', '', $a['telefono'])) ?>">Llamar</a><?php endif; ?>
                                <details class="seg-reagendar">
                                    <summary class="boton boton-claro boton-chico">Reagendar</summary>
                                    <form method="post" action="<?= e(url('seguimiento', ['accion' => 'reagendar'] + $filtro)) ?>" class="fila-en-linea">
                                        <?= campo_csrf() ?><input type="hidden" name="actividad_id" value="<?= (int) $a['id'] ?>">
                                        <label class="sr-only" for="cuando-<?= (int) $a['id'] ?>">Nueva fecha y hora</label>
                                        <input type="datetime-local" id="cuando-<?= (int) $a['id'] ?>" name="cuando" required
                                               value="<?= e((new DateTimeImmutable('tomorrow 10:00'))->format('Y-m-d\TH:i')) ?>">
                                        <button class="boton boton-chico" type="submit">Guardar</button>
                                    </form>
                                </details>
                            </div>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endforeach; ?>
    </section>

    <section class="bloque" id="visitas" aria-labelledby="t-vis">
        <h2 id="t-vis">Visitas y degustaciones de la semana</h2>
        <?php if (!$visitas): ?>
            <p class="vacio">No hay visitas al local, degustaciones ni reuniones agendadas para estos 7 días.</p>
        <?php else: ?>
        <ul class="filas">
            <?php foreach ($visitas as $a): ?>
                <li class="<?= $a['hecha'] ? 'hecha' : '' ?>">
                    <span>
                        <strong><?= e($fmt($a['programada_para'])) ?></strong> · <?= e(Eventos::TIPOS_ACTIVIDAD[$a['tipo']]) ?>
                        <small class="suave" style="display:block"><?= e($a['cliente'] ?? '') ?><?= $a['evento'] ? ' · ' . e($a['evento']) : '' ?> — <?= e(mb_strimwidth($a['texto'], 0, 90, '…')) ?></small>
                    </span>
                    <span class="derecha"><?= $a['hecha'] ? '<span class="libre">Hecha</span>' : '<span class="retenido">Pendiente</span>' ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>
</div>

<div>
    <section class="bloque" id="sin-atender" aria-labelledby="t-sin">
        <h2 id="t-sin">Consultas sin atender</h2>
        <p class="suave ayuda-chica">Preguntaron por una fecha y nadie les escribió en los últimos 3 días (ni hay algo agendado).</p>
        <?php if (!$sinAtender): ?>
            <p class="vacio">Ninguna. ¡Bien!</p>
        <?php else: ?>
        <ul class="filas">
            <?php foreach ($sinAtender as $c):
                $wa = WhatsApp::enlace($c['telefono'], $saludo($c['cliente']) . 'Te escribimos por tu consulta para el ' . fecha_corta($c['fecha']) . '. ¿Seguimos con tu evento?'); ?>
                <li>
                    <a href="<?= e(url('evento', ['id' => $c['id']])) ?>" class="fila-enlace">
                        <strong><?= e($c['nombre']) ?></strong>
                        <small class="suave" style="display:block"><?= e(fecha_corta($c['fecha'])) ?> · <?= (int) $c['invitados'] ?> inv.
                            · <?= $c['ultimo_contacto'] ? 'último contacto ' . e(fecha_corta(substr($c['ultimo_contacto'], 0, 10), false)) : 'llegó el ' . e(fecha_corta(substr($c['creado_en'], 0, 10), false)) ?>
                            <?= $c['origen'] === 'pagina' ? ' · desde tu página' : '' ?></small>
                    </a>
                    <?php if ($wa): ?><a class="boton boton-wa boton-chico derecha" href="<?= e($wa) ?>" target="_blank" rel="noopener">WhatsApp</a><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>

    <section class="bloque" id="sin-respuesta" aria-labelledby="t-resp">
        <h2 id="t-resp">Cotizaciones sin respuesta</h2>
        <?php if (!$sinRespuesta): ?>
            <p class="vacio">No hay cotizaciones esperando respuesta.</p>
        <?php else: ?>
        <ul class="filas">
            <?php foreach ($sinRespuesta as $q):
                $vence = $q['valida_hasta'] < $hoyTxt;
                $wa = WhatsApp::enlace($q['telefono'], $saludo($q['cliente']) . '¿Pudiste revisar la cotización para tu evento del ' . fecha_corta($q['evento_fecha']) . '? Cualquier duda, aquí estamos.'); ?>
                <li>
                    <a href="<?= e(url('cotizacion', ['id' => $q['evento_id']])) ?>" class="fila-enlace">
                        <strong><?= e($q['nombre']) ?></strong>
                        <small class="suave" style="display:block">N.º <?= (int) $q['numero'] ?> · <?= dinero($q['total']) ?> · enviada el <?= e(fecha_corta($q['fecha'], false)) ?>
                            · <span class="<?= $vence ? 'ocupado' : '' ?>"><?= $vence ? 'venció' : 'vale hasta' ?> el <?= e(fecha_corta($q['valida_hasta'], false)) ?></span></small>
                    </a>
                    <?php if ($wa): ?><a class="boton boton-wa boton-chico derecha" href="<?= e($wa) ?>" target="_blank" rel="noopener">Preguntar</a><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>

    <section class="bloque" id="retenidas" aria-labelledby="t-ret">
        <h2 id="t-ret">Fechas retenidas que vencen</h2>
        <p class="suave ayuda-chica">Si no pagan la reserva, la fecha se libera. Recuérdales o extiende la retención desde el evento.</p>
        <?php if (!$retenciones): ?>
            <p class="vacio">Ninguna vence en los próximos 3 días.</p>
        <?php else: ?>
        <ul class="filas">
            <?php foreach ($retenciones as $r):
                $vencida = $r['retenida_hasta'] < $hoyTxt;
                $wa = WhatsApp::enlace($r['telefono'], $saludo($r['cliente']) . 'Te guardamos el ' . fecha_corta($r['fecha']) . ' hasta el ' . fecha_corta($r['retenida_hasta'], false) . '. Para asegurarla, solo falta el pago de la reserva.'); ?>
                <li>
                    <a href="<?= e(url('evento', ['id' => $r['id']])) ?>" class="fila-enlace">
                        <strong><?= e($r['nombre']) ?></strong>
                        <small class="<?= $vencida ? 'ocupado' : 'retenido' ?>" style="display:block">
                            <?= $vencida ? 'Venció el ' : 'Vence el ' ?><?= e(fecha_corta($r['retenida_hasta'], false)) ?> · evento <?= e(fecha_corta($r['fecha'])) ?> · <?= e($r['espacios'] ?? '') ?></small>
                    </a>
                    <?php if ($wa): ?><a class="boton boton-wa boton-chico derecha" href="<?= e($wa) ?>" target="_blank" rel="noopener">Recordar</a><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>
</div>
</div>
