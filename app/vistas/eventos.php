<?php
use TuRenta\{Eventos, Ventas, WhatsApp};

/** @var string $estado @var array $estados @var array $f @var string $vistaLista */
$base = ['estado' => $estado] + array_filter(['q' => $f['buscar'], 'tipo' => $f['tipo'], 'vendedor' => $f['vendedor_id'],
                      'desde' => $f['desde'], 'hasta' => $f['hasta']], fn($v) => $v !== '' && $v !== null);
$chips = ['activos' => 'Activos', 'consulta' => 'Consultas', 'cotizado' => 'Cotizados', 'separado' => 'Separados',
          'confirmado' => 'Confirmados', 'realizado' => 'Realizados', 'vendidos' => 'Vendidos', 'perdido' => 'Perdidos', 'cancelado' => 'Cancelados', '' => 'Todos'];
$mesesCortos = ['', 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
?>
<div class="cabecera">
    <div>
        <h1>Eventos</h1>
        <p><?= $vistaLista === 'tablero' ? 'Tus ventas en camino, de la consulta a la confirmación' : 'Busca, filtra y abre cualquier evento' ?></p>
    </div>
    <div class="acciones">
        <?php if ($puedeCrear && modulo_activo($u, 'asistente_ia')): ?><a class="boton boton-claro" href="<?= e(url('ia_cotizacion')) ?>">✨ Desde un mensaje</a><?php endif; ?>
        <?php if ($puedeCrear): ?><a class="boton" href="<?= e(url('evento_nuevo')) ?>">Nuevo evento</a><?php endif; ?>
    </div>
</div>

<nav class="cal-vistas" aria-label="Forma de ver los eventos">
    <a href="<?= e(url('eventos', ['vista' => 'lista'] + $base)) ?>" class="<?= $vistaLista === 'lista' ? 'activa' : '' ?>" <?= $vistaLista === 'lista' ? 'aria-current="page"' : '' ?>>Lista</a>
    <a href="<?= e(url('eventos', ['vista' => 'tablero'] + array_diff_key($base, ['estado' => 1]))) ?>" class="<?= $vistaLista === 'tablero' ? 'activa' : '' ?>" <?= $vistaLista === 'tablero' ? 'aria-current="page"' : '' ?>>Tablero de ventas</a>
</nav>

<form method="get" class="bloque ev-filtros" role="search">
    <input type="hidden" name="r" value="eventos">
    <input type="hidden" name="vista" value="<?= e($vistaLista) ?>">
    <?php if ($vistaLista === 'lista'): ?><input type="hidden" name="estado" value="<?= e($estado) ?>"><?php endif; ?>
    <div class="ev-filtros-fila">
        <div class="campo ev-buscar">
            <label for="q">Buscar</label>
            <input type="search" id="q" name="q" value="<?= e($f['buscar']) ?>" placeholder="Nombre, código, cliente o celular">
        </div>
        <div class="campo">
            <label for="tipo">Tipo</label>
            <select id="tipo" name="tipo">
                <option value="">Todos</option>
                <?php foreach (Eventos::TIPOS as $k => $t): ?><option value="<?= e($k) ?>" <?= $f['tipo'] === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
            </select>
        </div>
        <?php if (count($vendedores) > 1): ?>
        <div class="campo">
            <label for="vendedor">Vendedor</label>
            <select id="vendedor" name="vendedor">
                <option value="">Todos</option>
                <?php foreach ($vendedores as $v): ?><option value="<?= (int) $v['id'] ?>" <?= (int) $f['vendedor_id'] === (int) $v['id'] ? 'selected' : '' ?>><?= e($v['nombre']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div class="campo">
            <label for="desde">Desde</label>
            <input type="date" id="desde" name="desde" value="<?= e($f['desde']) ?>">
        </div>
        <div class="campo">
            <label for="hasta">Hasta</label>
            <input type="date" id="hasta" name="hasta" value="<?= e($f['hasta']) ?>">
        </div>
        <div class="ev-filtros-botones">
            <button class="boton" type="submit">Filtrar</button>
            <?php if ($hayFiltros): ?><a class="boton boton-claro" href="<?= e(url('eventos', ['vista' => $vistaLista] + ($vistaLista === 'lista' ? ['estado' => $estado] : []))) ?>">Limpiar</a><?php endif; ?>
        </div>
    </div>
</form>

<?php if ($vistaLista === 'tablero'): ?>
    <?php
    $total = array_sum(array_column($tablero, 'cantidad'));
    ?>
    <?php if ($total === 0): ?>
        <section class="bloque vacio">
            <p><strong>Todavía no hay ventas en camino<?= $hayFiltros ? ' con estos filtros' : '' ?>.</strong></p>
            <p>Cuando un cliente pregunte por una fecha, créale un evento: aparecerá aquí en "Consulta" y lo irás moviendo hasta "Confirmado".</p>
            <?php if ($puedeCrear): ?><a class="boton" href="<?= e(url('evento_nuevo')) ?>">Crear el primer evento</a><?php endif; ?>
        </section>
    <?php else: ?>
    <div class="tablero" aria-label="Tablero de ventas por estado">
        <?php foreach ($tablero as $est => $col): ?>
            <section class="columna-estado col-<?= e($est) ?>" aria-labelledby="col-<?= e($est) ?>">
                <h3 id="col-<?= e($est) ?>">
                    <span><span class="ev-estado ev-<?= e($est) ?>"><?= e(Eventos::ESTADOS[$est]) ?></span> <?= $col['cantidad'] ?></span>
                    <?php if ($verDinero): ?><small class="monto"><?= dinero($col['valor']) ?></small><?php endif; ?>
                </h3>
                <p class="col-ayuda"><?= e(Eventos::ESTADOS_AYUDA[$est]) ?></p>
                <?php if (!$col['eventos']): ?><p class="col-vacia">Nada por aquí.</p><?php endif; ?>
                <?php foreach ($col['eventos'] as $ev): ?>
                    <a class="ficha-ev ficha-<?= e($est) ?>" href="<?= e(url('evento', ['id' => $ev['id']])) ?>">
                        <strong><?= e($ev['nombre']) ?></strong>
                        <small><?= e(fecha_corta($ev['fecha'])) ?> · <?= (int) $ev['invitados'] ?> inv. · <?= e($ev['espacios'] ?? '') ?></small>
                        <small><?= e($ev['cliente']) ?><?= $ev['vendedor'] ? ' · ' . e($ev['vendedor']) : '' ?></small>
                        <?php if ($verDinero && $ev['valor'] > 0): ?>
                            <small class="ficha-valor"><?= dinero($ev['valor']) ?><?= $ev['fuente_valor'] === 'cotizacion' ? ' (cotizado)' : ($ev['fuente_valor'] === 'presupuesto' ? ' (presupuesto del cliente)' : '') ?></small>
                        <?php endif; ?>
                        <?php if ($est === 'cotizado' && $ev['retenida_hasta']): ?>
                            <small class="<?= $ev['retenida_hasta'] < date('Y-m-d') ? 'ocupado' : 'retenido' ?>">
                                <?= $ev['retenida_hasta'] < date('Y-m-d') ? 'Retención vencida' : 'Retenida hasta ' . e(fecha_corta($ev['retenida_hasta'], false)) ?></small>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </section>
        <?php endforeach; ?>
    </div>
    <?php if ($perdidos): ?>
        <p class="suave" style="margin-top:12px"><?= count($perdidos) ?> <?= count($perdidos) === 1 ? 'evento perdido' : 'eventos perdidos' ?>.
            <a href="<?= e(url('eventos', ['estado' => 'perdido'] + array_diff_key($base, ['estado' => 1]))) ?>">Ver por qué se perdieron</a></p>
    <?php endif; ?>
    <?php endif; ?>

<?php else: ?>
    <nav class="chips-estado ev-chips" aria-label="Filtrar por estado">
        <?php foreach ($chips as $k => $t): ?>
            <a class="chip-estado <?= $estado === $k ? 'chip-activo' : '' ?>" href="<?= e(url('eventos', ['estado' => $k] + array_diff_key($base, ['estado' => 1]))) ?>"
               <?= $estado === $k ? 'aria-current="page"' : '' ?>><?= e($t) ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ($lista): ?>
    <div class="cifras">
        <div class="cifra-tarjeta"><small>Eventos</small><strong><?= $totales['cantidad'] ?></strong></div>
        <?php if ($verDinero): ?>
        <div class="cifra-tarjeta"><small>Vendido</small><strong><?= dinero($totales['total']) ?></strong></div>
        <div class="cifra-tarjeta bien"><small>Cobrado</small><strong><?= dinero($totales['pagado']) ?></strong></div>
        <div class="cifra-tarjeta <?= $totales['saldo'] > 0 ? 'alerta' : '' ?>"><small>Por cobrar</small><strong><?= dinero($totales['saldo']) ?></strong></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <section class="bloque">
        <div class="ev-lista-cabeza">
            <p class="suave" style="margin:0">
                <?= $soloFuturos ? 'De hoy en adelante. ' : '' ?>
                <?php if ($soloFuturos): ?><a href="<?= e(url('eventos', $base + ['pasados' => 1])) ?>">Ver también los pasados</a><?php endif; ?>
            </p>
            <p style="margin:0"><small>Orden:</small>
                <?php if ($orden === 'fecha'): ?><strong>por fecha</strong> · <a href="<?= e(url('eventos', $base + ['orden' => 'recientes'])) ?>">recién creados</a>
                <?php else: ?><a href="<?= e(url('eventos', $base)) ?>">por fecha</a> · <strong>recién creados</strong><?php endif; ?>
            </p>
        </div>
        <?php if (!$lista): ?>
            <div class="vacio">
                <?php if ($hayFiltros || !in_array($estado, ['activos', ''], true)): ?>
                    <p>No hay eventos con estos filtros.</p>
                    <a class="boton boton-claro" href="<?= e(url('eventos')) ?>">Ver todos los activos</a>
                <?php else: ?>
                    <p><strong>Todavía no tienes eventos.</strong></p>
                    <p>Crea uno cada vez que alguien pregunte por una fecha. Así nunca se te olvida responder y ves el calendario siempre al día.</p>
                    <?php if ($puedeCrear): ?><a class="boton" href="<?= e(url('evento_nuevo')) ?>">Crear el primer evento</a><?php endif; ?>
                <?php endif; ?>
            </div>
        <?php else: ?>
        <ul class="lista ev-lista">
            <?php foreach ($lista as $ev):
                $wa = $verDinero ? WhatsApp::enlace($ev['cliente_telefono'], 'Hola ' . explode(' ', trim($ev['cliente']))[0] . ', te saludamos de ' . $u['negocio'] . '. ') : null; ?>
                <li>
                    <a class="ev-fila" href="<?= e(url('evento', ['id' => $ev['id']])) ?>">
                        <span class="ev-fecha" aria-hidden="true">
                            <b><?= (new DateTimeImmutable($ev['fecha']))->format('j') ?></b>
                            <small><?= e($mesesCortos[(int) substr($ev['fecha'], 5, 2)] . ' ' . substr($ev['fecha'], 0, 4)) ?></small>
                        </span>
                        <span class="ev-datos">
                            <span class="principal-linea"><?= e($ev['nombre']) ?></span>
                            <span class="linea-sub"><?= e(fecha_corta($ev['fecha'])) ?> · <?= e(hora_corta($ev['hora_inicio'])) ?> · <?= e($ev['espacios'] ?? 'Sin espacio') ?> · <?= (int) $ev['invitados'] ?> invitados</span>
                            <span class="linea-sub"><?= e($ev['codigo']) ?> · <?= e($ev['cliente']) ?><?= $ev['vendedor'] ? ' · ' . e($ev['vendedor']) : '' ?></span>
                        </span>
                    </a>
                    <span class="derecha ev-derecha">
                        <span class="ev-estado ev-<?= e($ev['estado']) ?>"><?= e(Eventos::ESTADOS[$ev['estado']]) ?></span>
                        <?php if ($verDinero && (float) $ev['total'] > 0): ?>
                            <small class="monto"><?= dinero($ev['total']) ?></small>
                            <?php if ((float) $ev['pagado'] < (float) $ev['total']): ?><small class="suave">debe <?= dinero((float) $ev['total'] - (float) $ev['pagado']) ?></small><?php endif; ?>
                        <?php endif; ?>
                        <?php if ($wa): ?><a class="boton boton-wa boton-chico" href="<?= e($wa) ?>" target="_blank" rel="noopener" aria-label="WhatsApp a <?= e($ev['cliente']) ?>">WhatsApp</a><?php endif; ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php if (count($lista) >= 500): ?><p class="suave">Se muestran los primeros 500. Usa los filtros para encontrar el que buscas.</p><?php endif; ?>
        <?php endif; ?>
    </section>
<?php endif; ?>
