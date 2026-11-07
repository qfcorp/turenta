<?php
use TuRenta\{Eventos, Pagos, WhatsApp};
/** @var array $negocio @var array $cifras @var array $noLeidos @var array $proximos @var array $seguimientos @var array $tareas
 *  @var array $cuotas @var array $retenidas @var array $consultas @var array $pasos @var bool $comercial @var bool $editar
 *  @var DateTimeImmutable $hoy @var bool $claveTemporal */
$dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
$meses = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$mesesCortos = ['', 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
$nombre = explode(' ', trim($u['nombre']))[0];
$hora = (int) date('G');
$saludo = $hora < 12 ? 'Buenos días' : ($hora < 19 ? 'Buenas tardes' : 'Buenas noches');
$hoyTxt = $hoy->format('Y-m-d');
$cuando = function (string $fecha) use ($hoy): string {
    $d = (int) $hoy->diff(new DateTimeImmutable($fecha))->format('%r%a');
    return match (true) { $d === 0 => 'hoy', $d === 1 => 'mañana', $d === -1 => 'ayer', $d < 0 => 'hace ' . -$d . ' días', default => "en $d días" };
};
$icono = fn(string $d) => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="' . $d . '"/></svg>';
$hechos = count(array_filter($pasos, fn($p) => $p['hecho']));
?>
<div class="cabecera g-saludo">
    <div>
        <h1><?= e($saludo) ?>, <?= e($nombre) ?></h1>
        <p><?= $dias[(int) $hoy->format('w')] ?> <?= (int) $hoy->format('j') ?> de <?= $meses[(int) $hoy->format('n')] ?> · <?= e($negocio['nombre']) ?></p>
    </div>
</div>

<?php if ($claveTemporal): ?>
    <a class="g-franja" href="<?= e(url('mi_cuenta')) ?>#clave">🔑 Entraste con una contraseña temporal. Cámbiala por una tuya en Mi cuenta.</a>
<?php endif; ?>

<nav class="g-accesos" aria-label="Accesos rápidos">
    <?php if (es_comercial($u)): ?>
        <a class="g-acceso principal-acc" href="<?= e(url('evento_nuevo')) ?>"><?= $icono('M12 5v14M5 12h14') ?>Nuevo evento</a>
    <?php endif; ?>
    <a class="g-acceso" href="<?= e(url('calendario')) ?>"><?= $icono('M7 3v3M17 3v3M4 8h16M5 5h14a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1z') ?>Calendario</a>
    <?php if ($comercial): ?>
        <a class="g-acceso" href="<?= e(url('cobros')) ?>"><?= $icono('M3 7h18v12H3zM3 11h18M7 15h3') ?>Cobros</a>
    <?php else: ?>
        <a class="g-acceso" href="<?= e(url('eventos')) ?>"><?= $icono('M12 3l2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.4 6.8 19.1l1-5.8L3.5 9.2l5.9-.9z') ?>Eventos</a>
    <?php endif; ?>
</nav>

<div class="instalar-app" data-instalar-app hidden>
    <img src="/assets/icono-192.png" alt="" width="48" height="48">
    <div style="flex:1"><p><strong>Instala TuRenta en tu celular</strong></p>
        <p class="suave" style="font-size:.9rem">Ábrelo como una app, con su ícono en la pantalla de inicio.</p></div>
    <button class="boton boton-chico" type="button" data-instalar hidden>Instalar</button>
</div>

<?php if ($pasos): ?>
<section class="bloque g-pasos" aria-labelledby="t-pasos">
    <div class="g-bloque-cab">
        <h2 id="t-pasos">Primeros pasos</h2>
        <span class="g-avance"><?= $hechos ?> de <?= count($pasos) ?> listos</span>
    </div>
    <div class="barra" style="margin-bottom:8px" role="progressbar" aria-valuemin="0" aria-valuemax="<?= count($pasos) ?>" aria-valuenow="<?= $hechos ?>">
        <span style="width:<?= (int) round($hechos * 100 / count($pasos)) ?>%"></span></div>
    <ol>
        <?php foreach ($pasos as $i => $p): ?>
            <li class="<?= $p['hecho'] ? 'hecho' : '' ?>">
                <span class="g-paso-marca" aria-hidden="true"><?= $p['hecho'] ? '✓' : $i + 1 ?></span>
                <div style="flex:1;min-width:0">
                    <div class="g-paso-texto"><?= e($p['texto']) ?><?= $p['hecho'] ? ' <span class="sr-only">(listo)</span>' : '' ?></div>
                    <div class="g-fila-sub"><?= e($p['ayuda']) ?></div>
                </div>
                <?php if (!$p['hecho']): ?>
                    <div class="acciones" style="flex:none">
                        <a class="boton boton-claro boton-chico" href="<?= e($p['enlace']) ?>">Hacerlo</a>
                        <?php if ($p['clave'] === 'precios'): ?>
                            <form method="post" action="<?= e(url('inicio', ['accion' => 'precios_revisados'])) ?>"><?= campo_csrf() ?>
                                <button class="boton boton-claro boton-chico" type="submit">Ya lo hice</button></form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
    <form method="post" action="<?= e(url('inicio', ['accion' => 'ocultar_pasos'])) ?>" style="margin-top:8px">
        <?= campo_csrf() ?><button class="boton boton-claro boton-chico" type="submit">Ya sé usarlo, ocultar</button>
    </form>
</section>
<?php endif; ?>

<?php if ($cifras['comprobantes'] > 0 && $comercial): ?>
    <a class="g-franja" href="<?= e(url('cobros')) ?>">💳 <?= $cifras['comprobantes'] ?> <?= $cifras['comprobantes'] === 1 ? 'comprobante de pago subido por un cliente espera' : 'comprobantes de pago subidos por clientes esperan' ?> tu revisión. Ver ahora</a>
<?php endif; ?>

<div class="g-cifras">
    <a class="g-cifra" href="<?= e(url('calendario')) ?>"><strong><?= (int) $cifras['eventos_mes'] ?></strong><span>Eventos este mes</span></a>
    <a class="g-cifra" href="<?= e(url('eventos', ['estado' => 'vendidos'])) ?>"><strong><?= (int) $cifras['proximos_30'] ?></strong><span>Eventos en los próximos 30 días</span></a>
    <?php if ($comercial): ?>
        <a class="g-cifra <?= $cifras['vencido'] > 0 ? 'alerta' : '' ?>" href="<?= e(url('cobros')) ?>"><strong><?= dinero($cifras['vencido']) ?></strong><span>Vencido por cobrar</span></a>
        <a class="g-cifra <?= $cifras['comprobantes'] > 0 ? 'atencion' : '' ?>" href="<?= e(url('cobros')) ?>"><strong><?= (int) $cifras['comprobantes'] ?></strong><span>Comprobantes por revisar</span></a>
        <?php if (modulo_activo($u, 'proveedores')) require RAIZ . '/app/vistas/_pv_inicio.php'; /* pagos a proveedores (060, módulo proveedores) */ ?>
    <?php endif; ?>
    <?php if ($u['rol'] !== 'coordinador'): ?>
        <a class="g-cifra <?= $cifras['consultas_nuevas'] > 0 ? 'atencion' : '' ?>" href="<?= e(url('eventos', ['estado' => 'consulta'])) ?>"><strong><?= (int) $cifras['consultas_nuevas'] ?></strong><span>Consultas nuevas sin atender</span></a>
    <?php endif; ?>
</div>
<?php if (modulo_activo($u, 'metas')) require RAIZ . '/app/vistas/_metas_inicio.php'; /* meta de ventas del mes (módulo metas) */ ?>

<div class="rejilla rejilla-2">
    <section class="bloque" aria-labelledby="t-proximos">
        <div class="g-bloque-cab"><h2 id="t-proximos">Próximos 7 días</h2><a href="<?= e(url('calendario')) ?>">Calendario</a></div>
        <?php if (!$proximos): ?>
            <p class="vacio">No hay eventos esta semana.<?php if (es_comercial($u)): ?><br><a href="<?= e(url('evento_nuevo')) ?>">Anotar una consulta</a><?php endif; ?></p>
        <?php else: foreach ($proximos as $e):
            $f = new DateTimeImmutable($e['fecha']);
            $saldo = max(0, (float) $e['total'] - (float) $e['pagado']); ?>
            <a class="g-fila" href="<?= e(url('evento', ['id' => $e['id']])) ?>">
                <span class="g-fecha <?= $e['fecha'] === $hoyTxt ? 'hoy' : '' ?>"><b><?= $f->format('j') ?></b><small><?= $mesesCortos[(int) $f->format('n')] ?></small></span>
                <span class="g-fila-cuerpo">
                    <span class="g-fila-titulo" style="display:block"><?= e($e['nombre']) ?></span>
                    <span class="g-fila-sub" style="display:block"><?= e(ucfirst($cuando($e['fecha']))) ?> · <?= e(hora_corta($e['hora_inicio'])) ?> · <?= (int) $e['invitados'] ?> inv. · <?= e($e['espacios'] ?? '') ?></span>
                </span>
                <span class="g-fila-der">
                    <span class="ev-estado ev-<?= e($e['estado']) ?>"><?= e($e['estado'] === 'cotizado' ? 'Retenido' : Eventos::ESTADOS[$e['estado']]) ?></span>
                    <?php if ($comercial && (float) $e['total'] > 0): ?>
                        <small style="display:block;margin-top:4px"><?= $saldo > 0 ? 'Debe ' . dinero($saldo) : 'Pagado' ?></small>
                    <?php endif; ?>
                </span>
            </a>
        <?php endforeach; endif; ?>
    </section>

    <section class="bloque" aria-labelledby="t-avisos">
        <div class="g-bloque-cab"><h2 id="t-avisos">Avisos sin leer</h2><a href="<?= e(url('notificaciones')) ?>">Ver todos</a></div>
        <?php if (!$noLeidos): ?>
            <p class="vacio">Estás al día. Aquí verás pagos, firmas y consultas nuevas.</p>
        <?php else: foreach ($noLeidos as $n): ?>
            <?php $enlace = $n['evento_id'] ? url('evento', ['id' => $n['evento_id']]) : url('notificaciones'); ?>
            <a class="g-fila" href="<?= e($enlace) ?>">
                <span class="g-punto" aria-hidden="true"></span>
                <span class="g-fila-cuerpo"><span class="g-fila-sub" style="display:block;color:var(--tinta)"><?= e(mb_strimwidth($n['texto'], 0, 160, '…')) ?></span>
                    <span class="g-fila-sub" style="display:block"><?= e(fecha_corta($n['creada_en'], false)) ?> · <?= e(substr((string) $n['creada_en'], 11, 5)) ?></span></span>
            </a>
        <?php endforeach; endif; ?>
    </section>

    <?php if ($u['rol'] !== 'coordinador'): ?>
    <section class="bloque" aria-labelledby="t-seg">
        <div class="g-bloque-cab"><h2 id="t-seg">Seguimientos de hoy</h2><a href="<?= e(url('seguimiento')) ?>">Todos</a></div>
        <?php if (!$seguimientos): ?>
            <p class="vacio">No tienes llamadas ni visitas pendientes para hoy.</p>
        <?php else: foreach (array_slice($seguimientos, 0, 8) as $a):
            $prog = new DateTimeImmutable($a['programada_para']);
            $atrasado = $prog->format('Y-m-d') < $hoyTxt;
            $wa = $a['telefono'] ? WhatsApp::enlace($a['telefono'], 'Hola ' . explode(' ', trim((string) $a['cliente']))[0] . ', te saludamos de ' . $negocio['nombre'] . '.') : null; ?>
            <div class="g-fila">
                <span class="hora <?= $atrasado ? 'g-vencida' : '' ?>"><?= $atrasado ? e($prog->format('d/m')) : e($prog->format('H:i')) ?></span>
                <div class="g-fila-cuerpo">
                    <div class="g-fila-titulo"><?= e(Eventos::TIPOS_ACTIVIDAD[$a['tipo']] ?? 'Nota') ?>: <?= e(mb_strimwidth($a['texto'], 0, 90, '…')) ?></div>
                    <div class="g-fila-sub"><?php if ($a['evento_id']): ?><a href="<?= e(url('evento', ['id' => $a['evento_id']])) ?>"><?= e($a['evento']) ?></a><?php else: ?><?= e($a['cliente'] ?? '') ?><?php endif; ?>
                        <?= $atrasado ? ' · <span class="g-vencida">atrasado</span>' : '' ?><?= $u['rol'] !== 'vendedor' && $a['usuario'] ? ' · ' . e($a['usuario']) : '' ?></div>
                </div>
                <div class="acciones">
                    <?php if ($wa && es_comercial($u)): ?><a class="boton boton-wa boton-chico" href="<?= e($wa) ?>" target="_blank" rel="noopener" aria-label="WhatsApp a <?= e($a['cliente']) ?>">WhatsApp</a><?php endif; ?>
                    <?php if ($editar && es_comercial($u)): ?>
                        <form method="post" action="<?= e(url('inicio', ['accion' => 'seguimiento'])) ?>"><?= campo_csrf() ?><input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                            <button class="boton boton-claro boton-chico" type="submit">Hecho</button></form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </section>
    <?php endif; ?>

    <section class="bloque" aria-labelledby="t-tareas">
        <div class="g-bloque-cab"><h2 id="t-tareas">Tareas por vencer</h2><small class="suave">próximos 7 días</small></div>
        <?php if (!$tareas): ?>
            <p class="vacio">No hay tareas pendientes esta semana.</p>
        <?php else: foreach (array_slice($tareas, 0, 10) as $t):
            $vencida = $t['vence'] < $hoyTxt; ?>
            <div class="g-fila">
                <div class="g-fila-cuerpo">
                    <div class="g-fila-titulo"><?= e($t['titulo']) ?></div>
                    <div class="g-fila-sub"><a href="<?= e(url('evento', ['id' => $t['evento_id']])) ?>"><?= e($t['evento']) ?></a> ·
                        <span class="<?= $vencida ? 'g-vencida' : '' ?>"><?= $vencida ? 'venció ' : 'vence ' ?><?= e($cuando($t['vence'])) ?></span></div>
                </div>
                <?php if ($editar): ?>
                    <form method="post" action="<?= e(url('inicio', ['accion' => 'tarea'])) ?>"><?= campo_csrf() ?><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                        <button class="boton boton-claro boton-chico" type="submit" aria-label="Marcar como hecha: <?= e($t['titulo']) ?>">Hecha</button></form>
                <?php endif; ?>
            </div>
        <?php endforeach; if (count($tareas) > 10): ?><p class="g-nota">Y <?= count($tareas) - 10 ?> más en cada evento.</p><?php endif; endif; ?>
    </section>

    <?php if ($comercial): ?>
    <section class="bloque" aria-labelledby="t-cuotas">
        <div class="g-bloque-cab"><h2 id="t-cuotas">Cuotas vencidas o por vencer</h2><a href="<?= e(url('mensajes')) ?>">Recordar por WhatsApp</a></div>
        <?php if (!$cuotas): ?>
            <p class="vacio">No hay cuotas que venzan esta semana. 👏</p>
        <?php else: foreach (array_slice($cuotas, 0, 8) as $c):
            $wa = es_comercial($u) && $c['telefono'] ? WhatsApp::enlace($c['telefono'], Pagos::textoRecordatorio((string) $c['cliente'], $negocio['nombre'], $c['concepto'], (float) $c['saldo'], $c['vence'], url_completa('portal', ['t' => $c['token_cliente']]))) : null; ?>
            <div class="g-fila">
                <div class="g-fila-cuerpo">
                    <div class="g-fila-titulo"><a href="<?= e(url('evento_pagos', ['id' => $c['evento_id']])) ?>" style="color:inherit;text-decoration:none"><?= e($c['evento']) ?></a></div>
                    <div class="g-fila-sub"><?= e($c['concepto']) ?> · <span class="<?= $c['vencida'] ? 'g-vencida' : '' ?>"><?= $c['vencida'] ? 'venció ' : 'vence ' ?><?= e($cuando($c['vence'])) ?></span></div>
                </div>
                <span class="monto <?= $c['vencida'] ? 'monto-neg' : '' ?>"><?= dinero($c['saldo']) ?></span>
                <?php if ($wa): ?><a class="boton boton-wa boton-chico" href="<?= e($wa) ?>" target="_blank" rel="noopener" aria-label="Recordar a <?= e($c['cliente']) ?>">WhatsApp</a><?php endif; ?>
            </div>
        <?php endforeach; endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($u['rol'] !== 'coordinador' && ($retenidas || $consultas)): ?>
    <section class="bloque" aria-labelledby="t-atender">
        <h2 id="t-atender">Para atender</h2>
        <?php foreach ($retenidas as $r):
            $wa = es_comercial($u) && $r['telefono'] ? WhatsApp::enlace($r['telefono'], 'Hola ' . explode(' ', trim($r['cliente']))[0] . ', te saludamos de ' . $negocio['nombre']
                . '. Te tenemos guardada la fecha ' . fecha_corta($r['fecha']) . ' hasta el ' . (new DateTimeImmutable($r['retenida_hasta']))->format('d/m')
                . '. Con el pago de la reserva queda separada para ti. ¿Te ayudamos?') : null; ?>
            <div class="g-fila">
                <span aria-hidden="true">⏳</span>
                <div class="g-fila-cuerpo">
                    <div class="g-fila-titulo"><a href="<?= e(url('evento', ['id' => $r['id']])) ?>" style="color:inherit;text-decoration:none"><?= e($r['nombre']) ?></a></div>
                    <div class="g-fila-sub">La fecha retenida (<?= e(fecha_corta($r['fecha'])) ?>) se libera <?= e($cuando($r['retenida_hasta'])) ?> si no pagan la reserva</div>
                </div>
                <?php if ($wa): ?><a class="boton boton-wa boton-chico" href="<?= e($wa) ?>" target="_blank" rel="noopener">WhatsApp</a><?php endif; ?>
            </div>
        <?php endforeach; ?>
        <?php foreach ($consultas as $c): ?>
            <a class="g-fila" href="<?= e(url('evento', ['id' => $c['id']])) ?>">
                <span aria-hidden="true">✉️</span>
                <span class="g-fila-cuerpo"><span class="g-fila-titulo" style="display:block">Consulta nueva: <?= e($c['nombre']) ?></span>
                    <span class="g-fila-sub" style="display:block"><?= e(fecha_corta($c['fecha'])) ?> · <?= (int) $c['invitados'] ?> invitados<?= $c['origen'] ? ' · ' . e(Eventos::ORIGENES[$c['origen']] ?? '') : '' ?> · llegó <?= e($cuando(substr((string) $c['creado_en'], 0, 10))) ?></span></span>
                <span class="boton boton-claro boton-chico">Atender</span>
            </a>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>
</div>
