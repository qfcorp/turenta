<?php
use TuRenta\{Eventos, Ventas, WhatsApp, Cotizaciones, Contratos};

/** Resumen del evento. @var array $ev @var array $cliente @var bool $puedeVender @var bool $verDinero */
require RAIZ . '/app/vistas/_evento_tabs.php';

$primerNombre = explode(' ', trim((string) $cliente['nombre']))[0];
$waCliente = WhatsApp::enlace($cliente['telefono'], "Hola $primerNombre, te saludamos de {$u['negocio']}. ");
$textoPaso = [
    'consulta>cotizado' => ['Marcar como cotizado', 'Ya le enviaste la cotización. La fecha queda retenida unos días.'],
    'consulta>separado' => ['Separar la fecha', 'Úsalo solo si ya pagó la reserva. Bloquea el espacio.'],
    'cotizado>separado' => ['Separar la fecha', 'Úsalo solo si ya pagó la reserva. Bloquea el espacio.'],
    'cotizado>consulta' => ['Volver a consulta', 'Quita la retención de la fecha.'],
    'separado>confirmado' => ['Confirmar evento', 'El contrato está firmado: el evento va.'],
    'separado>cotizado' => ['Volver a cotizado', 'Libera el bloqueo del espacio (por ejemplo, si se anuló el pago).'],
    'confirmado>realizado' => ['Marcar como realizado', 'El evento ya pasó.'],
    'confirmado>separado' => ['Volver a separado', 'Por ejemplo, si se anuló el contrato.'],
    'realizado>confirmado' => ['Volver a confirmado', 'Si lo marcaste como realizado por error.'],
    'perdido>consulta' => ['Reabrir como consulta', 'El cliente volvió a escribir.'],
    'perdido>cotizado' => ['Reabrir como cotizado', 'Retiene la fecha otra vez unos días.'],
    'cancelado>consulta' => ['Reabrir como consulta', 'El cliente quiere retomar.'],
    'cancelado>separado' => ['Volver a separar', 'Bloquea el espacio otra vez (revisamos que siga libre).'],
];
$pasos = Eventos::PASOS[$ev['estado']];
// "Realizado" solo desde el día del evento
if ($ev['fecha'] > date('Y-m-d')) $pasos = array_values(array_diff($pasos, ['realizado']));
$normales = array_values(array_filter($pasos, fn($p) => !in_array($p, ['perdido', 'cancelado'], true)));
$conMotivo = array_values(array_filter($pasos, fn($p) => in_array($p, ['perdido', 'cancelado'], true)));
$tareasPend = array_filter($tareas, fn($t) => !$t['hecha']);
$tareasHechas = array_filter($tareas, fn($t) => $t['hecha']);
$actPend = array_filter($actividades, fn($a) => !$a['hecha']);
$actHechas = array_filter($actividades, fn($a) => $a['hecha']);
$fmtCuando = fn(?string $ts) => $ts ? fecha_corta(substr($ts, 0, 10), false) . ' ' . substr($ts, 11, 5) : '';
?>

<div class="rejilla ev-resumen">
<div class="ev-col">

    <!-- Estado -->
    <section class="bloque ev-bloque-estado" aria-labelledby="t-estado">
        <h2 id="t-estado">Estado: <span class="ev-estado ev-<?= e($ev['estado']) ?>"><?= e(Eventos::ESTADOS[$ev['estado']]) ?></span></h2>
        <p class="suave"><?= e(Eventos::ESTADOS_AYUDA[$ev['estado']]) ?></p>
        <ol class="ev-camino" aria-label="Camino del evento">
            <?php $orden = ['consulta', 'cotizado', 'separado', 'confirmado', 'realizado'];
            $pos = array_search($ev['estado'], $orden, true);
            foreach ($orden as $i => $paso): ?>
                <li class="<?= $pos !== false && $i < $pos ? 'hecho' : '' ?> <?= $paso === $ev['estado'] ? 'actual' : '' ?>"><?= e(Eventos::ESTADOS[$paso]) ?></li>
            <?php endforeach; ?>
        </ol>
        <?php if (in_array($ev['estado'], ['perdido', 'cancelado'], true) && $ev['motivo_perdida']): ?>
            <p class="aviso aviso-error">Motivo: <?= e($ev['motivo_perdida']) ?></p>
        <?php endif; ?>

        <?php if ($ev['estado'] === 'cotizado'): ?>
            <?php $vencida = $ev['retenida_hasta'] && $ev['retenida_hasta'] < $hoyTxt; ?>
            <div class="ev-retencion <?= $vencida ? 'vencida' : '' ?>">
                <p><?= $ev['retenida_hasta']
                    ? ($vencida ? 'La retención venció el ' . e(fecha_corta($ev['retenida_hasta'])) . '. La fecha ya no está guardada para este cliente.'
                                : 'Fecha retenida hasta el <strong>' . e(fecha_corta($ev['retenida_hasta'])) . '</strong>. Si no paga la reserva antes, la fecha se libera.')
                    : 'La fecha no tiene retención.' ?></p>
                <?php if ($puedeVender): ?>
                <form method="post" action="<?= e(url('evento', ['id' => $ev['id'], 'accion' => 'retener'])) ?>" class="fila-en-linea">
                    <?= campo_csrf() ?>
                    <label for="dias-retener">Retener</label>
                    <select id="dias-retener" name="dias">
                        <?php foreach ([3, 5, 7, 10, 15, 30] as $n): ?><option value="<?= $n ?>" <?= $n === $diasRetencion ? 'selected' : '' ?>><?= $n ?> días más</option><?php endforeach; ?>
                    </select>
                    <button class="boton boton-claro boton-chico" type="submit">Retener</button>
                </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($puedeVender && $pasos): ?>
            <div class="ev-pasos">
                <?php foreach ($normales as $p):
                    [$txt, $ayuda] = $textoPaso[$ev['estado'] . '>' . $p] ?? ['Pasar a ' . Eventos::ESTADOS[$p], Eventos::ESTADOS_AYUDA[$p]];
                    $confirmar = match ($p) {
                        'separado' => in_array($ev['estado'], ['consulta', 'cotizado'], true) ? '¿Separar la fecha? Hazlo solo si ya pagó la reserva: el espacio queda bloqueado para este evento.' : '',
                        'cotizado' => $ev['estado'] === 'separado' ? '¿Volver a cotizado? El espacio deja de estar bloqueado y otra persona podría separarlo.' : '',
                        'realizado' => '',
                        default => '',
                    }; ?>
                    <form method="post" action="<?= e(url('evento', ['id' => $ev['id'], 'accion' => 'estado'])) ?>">
                        <?= campo_csrf() ?>
                        <input type="hidden" name="estado" value="<?= e($p) ?>">
                        <button class="boton <?= in_array($p, ['cotizado', 'separado', 'confirmado', 'realizado'], true) && $p !== 'cotizado' ? '' : 'boton-claro' ?> boton-chico" type="submit"
                            <?= $confirmar ? 'data-confirmar="' . e($confirmar) . '"' : '' ?>><?= e($txt) ?></button>
                        <small class="suave"><?= e($ayuda) ?></small>
                    </form>
                <?php endforeach; ?>
                <?php foreach ($conMotivo as $p): ?>
                    <details class="ev-motivo">
                        <summary class="boton boton-peligro boton-chico"><?= $p === 'perdido' ? 'Marcar como perdido' : 'Cancelar evento' ?></summary>
                        <form method="post" action="<?= e(url('evento', ['id' => $ev['id'], 'accion' => 'estado'])) ?>">
                            <?= campo_csrf() ?>
                            <input type="hidden" name="estado" value="<?= e($p) ?>">
                            <div class="campo">
                                <label for="motivo-<?= e($p) ?>">¿Por qué? <small>(obligatorio: sirve para saber por qué se pierden ventas)</small></label>
                                <input type="text" id="motivo-<?= e($p) ?>" name="motivo" required minlength="3" maxlength="200"
                                       list="motivos-<?= e($p) ?>" placeholder="<?= $p === 'perdido' ? 'Ej. Precio alto, eligió otro local' : 'Ej. Cambio de planes de los novios' ?>">
                                <datalist id="motivos-<?= e($p) ?>">
                                    <?php foreach (($p === 'perdido'
                                        ? ['Precio alto', 'Eligió otro local', 'Fecha ocupada', 'No respondió', 'Pospuso el evento', 'Muy pocos invitados']
                                        : ['Cambio de planes', 'Problemas de salud', 'Pospuso sin fecha', 'Problemas económicos']) as $m): ?>
                                        <option value="<?= e($m) ?>">
                                    <?php endforeach; ?>
                                </datalist>
                            </div>
                            <button class="boton boton-peligro boton-chico" type="submit"
                                    data-confirmar="<?= $p === 'perdido' ? '¿Marcar como perdido? La fecha queda libre para otros clientes.' : '¿Cancelar el evento? La fecha y el espacio quedan libres. Los pagos registrados no se borran.' ?>">
                                <?= $p === 'perdido' ? 'Confirmar: perdido' : 'Confirmar cancelación' ?></button>
                        </form>
                    </details>
                <?php endforeach; ?>
            </div>
        <?php elseif (!$puedeVender): ?>
            <p class="suave ayuda-chica">Tu usuario solo puede ver. El estado lo cambia el dueño o un vendedor.</p>
        <?php endif; ?>
    </section>

    <?php if ($choques['bloqueados'] || $choques['otros']): ?>
    <section class="bloque ev-choques" aria-labelledby="t-choques">
        <h2 id="t-choques">Otros eventos a la misma hora</h2>
        <ul class="filas">
            <?php foreach (array_merge($choques['bloqueados'], $choques['otros']) as $c): ?>
                <li>
                    <span><?= e(Ventas::textoChoque($c)) ?></span>
                    <?php if ($c['id']): ?><a class="boton boton-claro boton-chico derecha" href="<?= e(url('evento', ['id' => $c['id']])) ?>">Ver</a><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php if ($choques['bloqueados'] && !in_array($ev['estado'], Eventos::BLOQUEAN, true)): ?>
            <p class="aviso aviso-error">Mientras el otro evento tenga la fecha separada, este no se puede separar. Ofrécele otra fecha u otro espacio.</p>
        <?php else: ?>
            <p class="suave ayuda-chica">El primero que pague la reserva se queda con la fecha.</p>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <!-- Datos del evento -->
    <section class="bloque" aria-labelledby="t-datos">
        <div class="bloque-cabeza">
            <h2 id="t-datos">Datos del evento</h2>
            <?php if ($puedeVender): ?><a class="boton boton-claro boton-chico" href="<?= e(url('evento_editar', ['id' => $ev['id']])) ?>">Editar</a><?php endif; ?>
        </div>
        <dl class="ficha-datos">
            <dt>Tipo</dt><dd><?= e(Eventos::TIPOS[$ev['tipo']]) ?></dd>
            <dt>Fecha</dt><dd><?= e(ucfirst(fecha_larga($ev['fecha']))) ?></dd>
            <dt>Horario</dt><dd><?= e(hora_corta($ev['hora_inicio'])) ?> a <?= e(hora_corta($ev['hora_fin'])) ?><?= $ev['hora_fin'] <= $ev['hora_inicio'] ? ' (del día siguiente)' : '' ?></dd>
            <dt>Espacios</dt><dd><?= e($ev['espacios_texto'] ?: 'Sin espacio') ?></dd>
            <dt>Invitados</dt><dd><?= (int) $ev['invitados'] ?>
                <?php if ($capacidad !== null && (int) $ev['invitados'] > $capacidad): ?>
                    <span class="ocupado">· supera la capacidad (<?= $capacidad ?>)</span>
                <?php elseif ($capacidad !== null): ?><small class="suave">(capacidad <?= $capacidad ?>)</small><?php endif; ?></dd>
            <dt>Montaje</dt><dd><?= e(Eventos::MONTAJES[$ev['montaje']] ?? 'Por definir') ?></dd>
            <?php if ($verDinero && $ev['presupuesto_cliente'] !== null): ?><dt>Presupuesto</dt><dd><?= dinero($ev['presupuesto_cliente']) ?></dd><?php endif; ?>
            <dt>Vendedor</dt><dd><?= e($ev['vendedor'] ?? 'Sin asignar') ?></dd>
            <?php if ($ev['origen']): ?><dt>Nos conoció</dt><dd><?= e(Eventos::ORIGENES[$ev['origen']] ?? $ev['origen']) ?></dd><?php endif; ?>
        </dl>
        <?php if ($ev['notas']): ?><p class="ev-notas"><?= nl2br(e($ev['notas'])) ?></p><?php endif; ?>
    </section>

    <!-- Cliente -->
    <section class="bloque" aria-labelledby="t-cliente">
        <div class="bloque-cabeza">
            <h2 id="t-cliente">Cliente</h2>
            <?php if ($u['rol'] !== 'coordinador'): ?><a class="boton boton-claro boton-chico" href="<?= e(url('cliente', ['id' => $cliente['id']])) ?>">Ver ficha</a><?php endif; ?>
        </div>
        <p class="principal-linea" style="margin-bottom:4px"><?= e($cliente['nombre']) ?></p>
        <dl class="ficha-datos">
            <?php if ($cliente['telefono']): ?><dt>Celular</dt><dd><a href="tel:<?= e(preg_replace('/[^\d+]/', '', $cliente['telefono'])) ?>"><?= e($cliente['telefono']) ?></a></dd><?php endif; ?>
            <?php if ($cliente['email']): ?><dt>Correo</dt><dd><a href="mailto:<?= e($cliente['email']) ?>"><?= e($cliente['email']) ?></a></dd><?php endif; ?>
            <?php if ($cliente['identificacion']): ?><dt><?= strlen($cliente['identificacion']) === 13 ? 'RUC' : 'Cédula' ?></dt><dd><?= e($cliente['identificacion']) ?></dd><?php endif; ?>
        </dl>
        <?php if ($waCliente): ?>
            <a class="boton boton-wa" href="<?= e($waCliente) ?>" target="_blank" rel="noopener">Escribir por WhatsApp</a>
        <?php else: ?>
            <p class="suave ayuda-chica">Sin celular registrado. <?= $u['rol'] !== 'coordinador' ? 'Agrégalo en la ficha para escribirle por WhatsApp con un toque.' : '' ?></p>
        <?php endif; ?>
    </section>

    <!-- Detalles para el día -->
    <section class="bloque" id="detalles" aria-labelledby="t-detalles">
        <h2 id="t-detalles">Menú y detalles del cliente</h2>
        <?php if ($puedeVender): ?>
            <form method="post" action="<?= e(url('evento', ['id' => $ev['id'], 'accion' => 'detalles'])) ?>">
                <?= campo_csrf() ?>
                <div class="campo">
                    <label for="menu_elegido">Menú elegido</label>
                    <textarea id="menu_elegido" name="menu_elegido" rows="3" placeholder="Entrada, plato fuerte, postre, opciones vegetarianas…"><?= e($ev['menu_elegido']) ?></textarea>
                </div>
                <div class="campo">
                    <label for="detalles_cliente">Lo que pidió el cliente</label>
                    <textarea id="detalles_cliente" name="detalles_cliente" rows="3" placeholder="Colores, música, alergias, horarios especiales…"><?= e($ev['detalles_cliente']) ?></textarea>
                </div>
                <button class="boton boton-claro boton-chico" type="submit">Guardar detalles</button>
            </form>
        <?php else: ?>
            <h3>Menú elegido</h3>
            <p><?= $ev['menu_elegido'] ? nl2br(e($ev['menu_elegido'])) : '<span class="suave">Todavía no se define.</span>' ?></p>
            <h3>Lo que pidió el cliente</h3>
            <p><?= $ev['detalles_cliente'] ? nl2br(e($ev['detalles_cliente'])) : '<span class="suave">Sin detalles todavía.</span>' ?></p>
        <?php endif; ?>
    </section>

    <!-- Tareas -->
    <section class="bloque" id="tareas" aria-labelledby="t-tareas">
        <h2 id="t-tareas">Tareas <small class="suave"><?= count($tareasHechas) ?> de <?= count($tareas) ?> hechas</small></h2>
        <?php if ($tareas): ?>
            <div class="barra" aria-hidden="true"><span style="width:<?= count($tareas) ? (int) floor(count($tareasHechas) * 100 / count($tareas)) : 0 ?>%"></span></div>
        <?php endif; ?>
        <?php if (!$tareas): ?>
            <p class="vacio">Sin tareas. Agrega lo que hay que hacer antes del evento (degustación, música, invitados…).</p>
        <?php else: ?>
        <ul class="filas ev-tareas">
            <?php $todasTareas = array_merge($tareasPend, $tareasHechas);
            foreach ($todasTareas as $i => $t):
                if ($i === 6): ?>
        </ul>
        <details class="ev-mas-tareas">
            <summary class="boton boton-claro boton-chico">Ver <?= count($todasTareas) - 6 ?> tareas más</summary>
        <ul class="filas ev-tareas">
                <?php endif;
                $vencida = !$t['hecha'] && $t['vence'] && $t['vence'] < $hoyTxt; ?>
                <li class="<?= $t['hecha'] ? 'hecha' : '' ?>">
                    <span>
                        <span class="<?= $t['hecha'] ? 'tachado' : '' ?>"><?= e($t['titulo']) ?></span>
                        <?php if ($t['vence']): ?><small class="<?= $vencida ? 'ocupado' : 'suave' ?>" style="display:block"><?= $vencida ? 'Venció' : 'Hasta' ?> el <?= e(fecha_corta($t['vence'])) ?></small><?php endif; ?>
                    </span>
                    <?php if ($puedeVender): ?>
                    <form method="post" action="<?= e(url('evento', ['id' => $ev['id'], 'accion' => 'tarea_marcar'])) ?>" class="derecha">
                        <?= campo_csrf() ?>
                        <input type="hidden" name="tarea_id" value="<?= (int) $t['id'] ?>">
                        <input type="hidden" name="hecha" value="<?= $t['hecha'] ? '0' : '1' ?>">
                        <button class="boton <?= $t['hecha'] ? 'boton-claro' : '' ?> boton-chico" type="submit"
                                aria-label="<?= $t['hecha'] ? 'Volver a pendiente' : 'Marcar como hecha' ?>: <?= e($t['titulo']) ?>"><?= $t['hecha'] ? 'Deshacer' : 'Hecha' ?></button>
                    </form>
                    <?php elseif ($t['hecha']): ?><span class="derecha libre">✓</span><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php if (count($todasTareas) > 6): ?></details><?php endif; ?>
        <?php endif; ?>
        <?php if ($puedeVender): ?>
        <form method="post" action="<?= e(url('evento', ['id' => $ev['id'], 'accion' => 'tarea'])) ?>" class="form-en-linea">
            <?= campo_csrf() ?>
            <div class="campo">
                <label for="tarea-titulo">Nueva tarea</label>
                <input type="text" id="tarea-titulo" name="titulo" maxlength="150" required placeholder="Ej. Prueba de menú con los novios">
            </div>
            <div class="campo">
                <label for="tarea-vence">Hasta</label>
                <input type="date" id="tarea-vence" name="vence" max="<?= e($ev['fecha']) ?>">
            </div>
            <button class="boton boton-claro" type="submit">Agregar</button>
        </form>
        <?php endif; ?>
    </section>
</div>

<div class="ev-col">
    <?php if ($verDinero): ?>
    <!-- Dinero -->
    <section class="bloque" aria-labelledby="t-dinero">
        <div class="bloque-cabeza">
            <h2 id="t-dinero">Dinero</h2>
            <a class="boton boton-claro boton-chico" href="<?= e(url('evento_pagos', ['id' => $ev['id']])) ?>">Ver pagos</a>
        </div>
        <?php if ($dinero['total'] <= 0): ?>
            <p class="suave">El valor del evento se pone cuando el cliente acepta la cotización.</p>
            <?php if ($dinero['pagado'] > 0): ?><p>Pagado: <strong><?= dinero($dinero['pagado']) ?></strong></p><?php endif; ?>
        <?php else: ?>
            <div class="resumen-cobro">
                <span>Total</span><strong class="num"><?= dinero($dinero['total']) ?></strong>
                <span>Pagado</span><strong class="num libre"><?= dinero($dinero['pagado']) ?></strong>
                <span>Saldo</span><strong class="num grande <?= $dinero['saldo'] > 0 ? '' : 'libre' ?>"><?= dinero($dinero['saldo']) ?></strong>
            </div>
            <div class="barra" role="progressbar" aria-valuenow="<?= $dinero['pct'] ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Pagado <?= $dinero['pct'] ?> %">
                <span style="width:<?= $dinero['pct'] ?>%"></span></div>
            <p class="suave ayuda-chica"><?= $dinero['pct'] ?> % pagado</p>
            <?php if ($dinero['vencido'] > 0): ?><p class="aviso aviso-error">Tiene <?= dinero($dinero['vencido']) ?> vencido.</p><?php endif; ?>
            <?php if ($dinero['por_revisar'] > 0): ?><p class="aviso aviso-alerta"><?= dinero($dinero['por_revisar']) ?> en comprobantes por revisar.</p><?php endif; ?>
            <?php if ($dinero['proxima']): ?>
                <p>Próxima cuota: <strong><?= e($dinero['proxima']['concepto']) ?></strong> · <?= dinero($dinero['proxima']['saldo']) ?>
                    · vence el <?= e(fecha_corta($dinero['proxima']['vence'])) ?></p>
            <?php endif; ?>
            <?php if ($dinero['a_favor'] > 0): ?><p class="libre">Saldo a favor del cliente: <?= dinero($dinero['a_favor']) ?></p><?php endif; ?>
        <?php endif; ?>
    </section>

    <!-- Cotización y contrato -->
        <section class="bloque" aria-labelledby="t-docs">
        <h2 id="t-docs">Cotización y contrato</h2>
        <ul class="filas">
            <li>
                <span>
                    <strong>Cotización</strong>
                    <?php if ($cotizacion): ?>
                        <small class="suave" style="display:block">N.º <?= (int) $cotizacion['numero'] ?><?= $numCotizaciones > 1 ? ' · versión ' . (int) $cotizacion['version'] : '' ?>
                            · <?= dinero($cotizacion['total']) ?> · <?= e(Cotizaciones::ESTADOS[$cotizacion['estado']] ?? $cotizacion['estado']) ?></small>
                    <?php else: ?>
                        <small class="suave" style="display:block">Todavía no hay cotización.</small>
                    <?php endif; ?>
                </span>
                <a class="boton <?= $cotizacion ? 'boton-claro' : '' ?> boton-chico derecha" href="<?= e(url('cotizacion', ['id' => $ev['id']])) ?>"><?= $cotizacion ? 'Abrir' : 'Hacer cotización' ?></a>
            </li>
            <?php if (modulo_activo($u, 'contratos')): ?>
            <li>
                <span>
                    <strong>Contrato</strong>
                    <?php if ($contrato): ?>
                        <small class="suave" style="display:block">N.º <?= (int) $contrato['numero'] ?> · <?= e(Contratos::ESTADOS[$contrato['estado']] ?? $contrato['estado']) ?>
                            <?= $contrato['firmado_en'] ? ' el ' . e(fecha_corta(substr($contrato['firmado_en'], 0, 10))) : '' ?></small>
                    <?php else: ?>
                        <small class="suave" style="display:block">Todavía no hay contrato.</small>
                    <?php endif; ?>
                </span>
                <a class="boton boton-claro boton-chico derecha" href="<?= e(url('contrato', ['id' => $ev['id']])) ?>"><?= $contrato ? 'Abrir' : 'Preparar' ?></a>
            </li>
            <?php endif; ?>
        </ul>
    </section>

    <!-- Portal del cliente (módulo portal_cliente) -->
    <?php if (modulo_activo($u, 'portal_cliente')): ?>
    <section class="bloque" aria-labelledby="t-portal">
        <h2 id="t-portal">Portal del cliente</h2>
        <?php 
            $portal = url_completa('portal', ['t' => $ev['token_cliente']]);
            $waPortal = WhatsApp::enlace($cliente['telefono'], "Hola $primerNombre, este es el enlace de tu evento en {$u['negocio']}: ahí puedes ver tus pagos"
                . (modulo_activo($u, 'contratos') ? ', tu contrato' : '') . (modulo_activo($u, 'invitados') ? ' y la lista de invitados' : '') . ". Guárdalo: $portal"); ?>
            <p class="suave">El cliente ve sus pagos, su contrato y su lista de invitados, sin claves.</p>
            <div class="enlace-copiar">
                <input type="text" readonly value="<?= e($portal) ?>" aria-label="Enlace del portal del cliente" onclick="this.select()">
            </div>
            <div class="acciones">
                <button class="boton boton-claro boton-chico" type="button" data-copiar="<?= e($portal) ?>">Copiar enlace</button>
                <?php if ($waPortal): ?><a class="boton boton-wa boton-chico" href="<?= e($waPortal) ?>" target="_blank" rel="noopener">Enviar por WhatsApp</a><?php endif; ?>
            </div>
    </section>
    <?php endif; ?>
    <?php endif; ?>

    <!-- Coordinador -->
    <section class="bloque" aria-labelledby="t-coord">
        <h2 id="t-coord">Coordinador del día</h2>
        <?php if ($puedeVender): ?>
            <form method="post" action="<?= e(url('evento', ['id' => $ev['id'], 'accion' => 'coordinador'])) ?>" class="fila-en-linea">
                <?= campo_csrf() ?>
                <label class="sr-only" for="coordinador_id">Coordinador</label>
                <select id="coordinador_id" name="coordinador_id">
                    <option value="">— Sin asignar —</option>
                    <?php foreach ($equipo as $p): ?>
                        <option value="<?= (int) $p['id'] ?>" <?= (int) $ev['coordinador_id'] === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['nombre']) ?><?= $p['rol'] === 'coordinador' ? ' (coordinador)' : '' ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="boton boton-claro boton-chico" type="submit">Asignar</button>
            </form>
            <p class="suave ayuda-chica">Le llega un aviso. El coordinador ve el evento y el día del evento, pero no el dinero.</p>
        <?php else: ?>
            <p><?= $ev['coordinador'] ? e($ev['coordinador']) : '<span class="suave">Sin asignar.</span>' ?></p>
        <?php endif; ?>
    </section>

    <!-- Seguimiento -->
    <section class="bloque" id="seguimiento" aria-labelledby="t-seg">
        <h2 id="t-seg">Seguimiento</h2>
        <?php if ($puedeVender): ?>
        <form method="post" action="<?= e(url('evento', ['id' => $ev['id'], 'accion' => 'actividad'])) ?>" class="form-seguimiento">
            <?= campo_csrf() ?>
            <div class="fila-campos">
                <div class="campo">
                    <label for="act-tipo">Qué fue</label>
                    <select id="act-tipo" name="tipo">
                        <?php foreach (Ventas::TIPOS_SEGUIMIENTO as $k => $t): ?><option value="<?= e($k) ?>"><?= e($t) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="campo">
                    <label for="act-prog">Agendar para <small>(vacío = ya se hizo)</small></label>
                    <input type="datetime-local" id="act-prog" name="programada">
                </div>
            </div>
            <div class="campo">
                <label for="act-texto">Nota</label>
                <textarea id="act-texto" name="texto" rows="2" required placeholder="Ej. Llamar para confirmar la visita del sábado"></textarea>
            </div>
            <button class="boton boton-claro boton-chico" type="submit">Guardar</button>
        </form>
        <?php endif; ?>

        <?php if (!$actividades): ?>
            <p class="vacio">Sin seguimiento todavía. Anota cada llamada, mensaje o visita para no perder el hilo.</p>
        <?php else: ?>
        <ul class="filas ev-actividades">
            <?php foreach (array_merge($actPend, $actHechas) as $a):
                $atrasada = !$a['hecha'] && $a['programada_para'] && substr($a['programada_para'], 0, 10) < $hoyTxt; ?>
                <li class="<?= !$a['hecha'] ? 'pendiente' : '' ?>">
                    <span>
                        <span class="etq etq-act"><?= e(Eventos::TIPOS_ACTIVIDAD[$a['tipo']] ?? $a['tipo']) ?></span>
                        <?= nl2br(e($a['texto'])) ?>
                        <small class="<?= $atrasada ? 'ocupado' : 'suave' ?>" style="display:block">
                            <?= !$a['hecha'] ? ($atrasada ? 'Atrasado: ' : 'Agendado: ') . e($fmtCuando($a['programada_para'])) : e($fmtCuando($a['programada_para'] ?? $a['creado_en'])) ?>
                            <?= $a['usuario'] ? ' · ' . e($a['usuario']) : '' ?></small>
                    </span>
                    <?php if (!$a['hecha'] && $puedeVender): ?>
                    <form method="post" action="<?= e(url('evento', ['id' => $ev['id'], 'accion' => 'actividad_marcar'])) ?>" class="derecha">
                        <?= campo_csrf() ?>
                        <input type="hidden" name="actividad_id" value="<?= (int) $a['id'] ?>">
                        <button class="boton boton-chico" type="submit">Hecho</button>
                    </form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>

    <?php if ($puedeVender): ?>
    <section class="bloque" aria-labelledby="t-dup">
        <h2 id="t-dup">Copiar a otra fecha</h2>
        <p class="suave">Para el mismo cliente (por ejemplo, la fiesta de la empresa cada año). Se crea como consulta, con los mismos espacios y horario.</p>
        <form method="post" action="<?= e(url('evento', ['id' => $ev['id'], 'accion' => 'duplicar'])) ?>" class="fila-en-linea">
            <?= campo_csrf() ?>
            <label class="sr-only" for="dup-fecha">Nueva fecha</label>
            <input type="date" id="dup-fecha" name="fecha" min="<?= e($hoyTxt) ?>" required>
            <button class="boton boton-claro boton-chico" type="submit">Copiar</button>
        </form>
    </section>
    <?php endif; ?>

    <!-- Historial -->
    <section class="bloque" aria-labelledby="t-hist">
        <details>
            <summary><h2 id="t-hist" style="display:inline">Historial de cambios</h2> <small class="suave">(<?= count($historial) ?>)</small></summary>
            <?php if (!$historial): ?>
                <p class="suave">Sin cambios registrados.</p>
            <?php else: ?>
            <ul class="filas ev-historial">
                <?php foreach ($historial as $h): ?>
                    <li><span><?= e($h['accion']) ?><small class="suave" style="display:block"><?= e($h['quien'] ?? 'Sistema') ?> · <?= e($fmtCuando($h['creado_en'])) ?></small></span></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </details>
    </section>
</div>
</div>
