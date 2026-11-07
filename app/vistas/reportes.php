<?php
use TuRenta\{Eventos, Pagos, Reportes};
/** @var array $per @var array $ventas @var array $cobrado @var array $porCobrar @var array $ocupacion @var array $conversion
 *  @var array $rentabilidad @var array $comisiones @var callable $enlaceExportar @var DateTimeImmutable $hoy */
$pct = fn(float $v, float $max) => $max > 0 ? max(1, (int) round($v * 100 / $max)) : 0;
$mesActual = $hoy->format('Y-m');
$corto = fn(float $v) => $v >= 10000 ? '$' . number_format($v / 1000, 1, ',', '.') . 'k' : '$' . number_format($v, 0, ',', '.');
// Anexo B: metas, embudo, ganancia y comisiones son del módulo "Metas y reportes avanzados"; las descargas, de "Exportar"
$avanzado = modulo_activo($u, 'metas');
$exportar = modulo_activo($u, 'exportar');
$satisfaccion = modulo_activo($u, 'cuestionarios');
$descargar = fn(string $tipo, string $txt = 'Descargar para Excel', ?string $d = null, ?string $h = null) => !$exportar ? '' :
    '<a class="g-descargar" href="' . e($enlaceExportar($tipo, $d, $h)) . '">' . e($txt) . '</a>';
/** Gráfico de columnas por mes */
$columnas = function (array $porMes, string $titulo) use ($pct, $mesActual, $corto): string {
    $max = max(array_merge([0.0], array_map('floatval', $porMes)));
    $h = '<div role="img" aria-label="' . e($titulo) . '"><div class="g-columnas">';
    foreach ($porMes as $mes => $v) {
        $h .= '<div class="g-col' . ($mes === $mesActual ? ' actual' : '') . '"><span class="g-col-valor">' . ($v > 0 ? e($corto((float) $v)) : '') . '</span>'
            . '<span class="g-col-barra" style="height:' . $pct((float) $v, $max) . '%"></span></div>';
    }
    $h .= '</div><div class="g-col-etq">';
    foreach (array_keys($porMes) as $mes) $h .= '<span>' . e(Reportes::mesCorto($mes)) . '</span>';
    return $h . '</div></div>';
};
/** Barras horizontales */
$barras = function (array $filas, string $clase = '') use ($pct): string {
    $max = max(array_merge([0.0], array_map(fn($f) => (float) $f[1], $filas)));
    $h = '<div class="g-barras">';
    foreach ($filas as [$nombre, $valor, $texto]) {
        $h .= '<div class="g-barra"><span class="g-barra-nombre" title="' . e($nombre) . '">' . e($nombre) . '</span>'
            . '<span class="g-barra-pista"><span class="' . $clase . '" style="width:' . $pct((float) $valor, $max) . '%"></span></span>'
            . '<span class="g-barra-valor">' . e($texto) . '</span></div>';
    }
    return $h . '</div>';
};
$tablaConversion = function (array $grupo, string $titulo): string {
    if (!$grupo) return '<p class="vacio">Sin datos en este periodo.</p>';
    $h = '<div class="tabla-scroll"><table class="tabla" style="min-width:420px"><caption class="sr-only">' . e($titulo) . '</caption>'
       . '<thead><tr><th>' . e($titulo) . '</th><th class="num">Consultas</th><th class="num">Vendidos</th><th class="num">Perdidos</th><th class="num">Conversión</th></tr></thead><tbody>';
    foreach ($grupo as $nombre => $g) {
        $h .= '<tr><td>' . e($nombre) . '</td><td class="num">' . $g['consultas'] . '</td><td class="num">' . $g['vendidos'] . '</td><td class="num">' . $g['perdidos']
            . '</td><td class="num"><span class="' . ($g['pct'] >= 30 ? 'g-pct-alto' : ($g['consultas'] >= 3 && $g['pct'] < 10 ? 'g-pct-bajo' : '')) . '">' . $g['pct'] . ' %</span></td></tr>';
    }
    return $h . '</tbody></table></div>';
};
$t = $conversion['total'];
?>
<div class="cabecera">
    <div><h1>Reportes</h1><p><?= e($per['nombre']) ?> · del <?= e((new DateTimeImmutable($per['desde']))->format('d/m/Y')) ?> al <?= e((new DateTimeImmutable($per['hasta']))->format('d/m/Y')) ?></p></div>
</div>

<nav class="g-periodos" aria-label="Periodo del reporte">
    <?php foreach (Reportes::PERIODOS as $k => $txt): ?>
        <a href="<?= e(url('reportes', ['p' => $k])) ?>" class="<?= $per['clave'] === $k ? 'activo' : '' ?>" <?= $k === 'rango' ? 'data-g-rango' : '' ?>
           <?= $per['clave'] === $k ? 'aria-current="true"' : '' ?>><?= e($txt) ?></a>
    <?php endforeach; ?>
</nav>
<form method="get" class="bloque g-rango" id="g-rango" <?= $per['clave'] === 'rango' ? '' : 'hidden' ?>>
    <input type="hidden" name="r" value="reportes"><input type="hidden" name="p" value="rango">
    <div class="campo"><label for="desde">Desde</label><input type="date" id="desde" name="desde" value="<?= e($per['desde']) ?>" required></div>
    <div class="campo"><label for="hasta">Hasta</label><input type="date" id="hasta" name="hasta" value="<?= e($per['hasta']) ?>" required></div>
    <button class="boton" type="submit">Ver</button>
</form>

<nav class="g-indice" aria-label="Ir a">
    <a href="#ventas">Ventas</a><a href="#cobros">Cobrado</a><a href="#por-cobrar">Por cobrar</a><a href="#ocupacion">Ocupación</a>
    <?php if ($avanzado): ?><a href="#conversion">Conversión</a><a href="#rentabilidad">Ganancia</a><a href="#comisiones">Comisiones</a><?php endif; ?>
    <?php if ($exportar): ?><a href="#descargas">Descargas</a><?php endif; ?>
    <?php if ($satisfaccion): ?><a href="#satisfaccion">Satisfacción</a><?php endif; ?>
    <?php if ($avanzado): ?><a href="#metas">Metas</a><?php endif; ?>
</nav>

<div class="g-cifras">
    <div class="g-cifra"><strong><?= dinero($ventas['valor']) ?></strong><span>Vendido · <?= $ventas['cantidad'] ?> <?= $ventas['cantidad'] === 1 ? 'evento' : 'eventos' ?></span></div>
    <div class="g-cifra"><strong><?= dinero($cobrado['total']) ?></strong><span>Cobrado en el periodo</span></div>
    <div class="g-cifra <?= $porCobrar['vencido'] > 0 ? 'alerta' : '' ?>"><strong><?= dinero($porCobrar['saldo']) ?></strong><span>Por cobrar · <?= dinero($porCobrar['vencido']) ?> vencido</span></div>
    <?php if ($avanzado): ?><div class="g-cifra"><strong><?= $t['pct'] ?> %</strong><span>Conversión · <?= $t['vendidos'] ?> de <?= $t['consultas'] ?> consultas</span></div><?php endif; ?>
    <div class="g-cifra"><strong><?= dinero($ventas['ticket']) ?></strong><span>Ticket promedio por evento</span></div>
    <div class="g-cifra"><strong><?= dinero($ventas['por_invitado']) ?></strong><span>Precio promedio por invitado</span></div>
    <div class="g-cifra"><strong><?= $ocupacion['pct_sabados'] ?> %</strong><span>Sábados vendidos (próximos 12 meses)</span></div>
</div>

<section class="bloque" id="ventas">
    <h2>Ventas</h2>
    <p class="g-nota">Un evento se cuenta como vendido el día que se separó (pagó la reserva) o se confirmó.</p>
    <?= $columnas(array_map(fn($m) => $m['valor'], $ventas['por_mes']), 'Ventas por mes') ?>
    <div class="rejilla rejilla-2" style="margin-top:14px;margin-bottom:0">
        <div class="ficha-datos" style="margin:0">
            <dt>Vendido (con IVA)</dt><dd><?= dinero($ventas['valor']) ?></dd>
            <dt>Sin IVA</dt><dd><?= dinero($ventas['sin_iva']) ?></dd>
            <dt>Eventos vendidos</dt><dd><?= $ventas['cantidad'] ?> · <?= $ventas['invitados'] ?> invitados</dd>
        </div>
        <div class="ficha-datos" style="margin:0">
            <dt>Eventos que se hacen en el periodo</dt><dd><?= $ventas['por_evento']['cantidad'] ?></dd>
            <dt>Valen</dt><dd><?= dinero($ventas['por_evento']['valor']) ?></dd>
        </div>
    </div>
    <?php if ($ventas['lista']): ?>
    <details style="margin-top:12px"><summary>Ver los <?= $ventas['cantidad'] ?> eventos vendidos</summary>
        <div class="tabla-scroll"><table class="tabla">
            <thead><tr><th>Vendido</th><th>Evento</th><th>Fecha del evento</th><th>Vendedor</th><th class="num">Valor</th></tr></thead>
            <tbody><?php foreach ($ventas['lista'] as $v): ?>
                <tr><td><?= e((new DateTimeImmutable($v['fecha_venta']))->format('d/m/Y')) ?></td>
                    <td><a href="<?= e(url('evento', ['id' => $v['id']])) ?>"><?= e($v['nombre']) ?></a><br><small><?= e($v['codigo']) ?></small></td>
                    <td><?= e(fecha_corta($v['fecha'])) ?></td><td><?= e($v['vendedor'] ?? '—') ?></td><td class="num"><?= dinero($v['total']) ?></td></tr>
            <?php endforeach; ?></tbody>
        </table></div>
    </details>
    <?php endif; ?>
    <div class="g-pie-tabla"><?= $descargar('eventos', 'Descargar eventos para Excel') ?></div>
</section>
<?php if ($avanzado) require RAIZ . '/app/vistas/_metas_reporte.php'; /* metas y año anterior (módulo metas) */ ?>

<div class="rejilla rejilla-2">
    <section class="bloque" id="cobros">
        <h2>Cobrado por mes</h2>
        <p class="g-nota">Pagos de eventos confirmados (sin contar garantías).</p>
        <?= $columnas($cobrado['por_mes'], 'Cobrado por mes') ?>
        <?php if ($cobrado['por_metodo']): ?>
            <h3 style="margin-top:14px">Cómo te pagaron (<?= $cobrado['pagos'] ?> pagos)</h3>
            <?= $barras(array_map(fn($m) => [Pagos::METODOS[$m['metodo']] ?? $m['metodo'], (float) $m['total'], dinero($m['total'])], $cobrado['por_metodo']), 'oro') ?>
        <?php else: ?><p class="vacio">No hubo cobros en este periodo.</p><?php endif; ?>
        <div class="g-pie-tabla"><?= $descargar('pagos', 'Descargar pagos para Excel') ?></div>
    </section>

    <section class="bloque" id="por-cobrar">
        <h2>Por cobrar</h2>
        <p class="g-nota">Lo que falta pagar de los eventos vendidos (a hoy).</p>
        <?php if (!$porCobrar['lista']): ?><p class="vacio">Nadie te debe. 👏</p><?php else: ?>
            <div class="ficha-datos"><dt>Total por cobrar</dt><dd><?= dinero($porCobrar['saldo']) ?></dd>
                <dt>Ya vencido</dt><dd class="<?= $porCobrar['vencido'] > 0 ? 'g-vencida' : '' ?>"><?= dinero($porCobrar['vencido']) ?></dd></div>
            <ul class="filas">
                <?php foreach (array_slice($porCobrar['lista'], 0, 12) as $r): ?>
                    <li><span><a href="<?= e(url('evento_pagos', ['id' => $r['id']])) ?>"><?= e($r['nombre']) ?></a><br><small><?= e(fecha_corta($r['fecha'])) ?><?= $r['vencido'] > 0 ? ' · <span class="g-vencida">' . dinero($r['vencido']) . ' vencido</span>' : '' ?></small></span>
                        <span class="derecha monto"><?= dinero($r['saldo']) ?></span></li>
                <?php endforeach; ?>
            </ul>
            <?php if (count($porCobrar['lista']) > 12): ?><p class="g-nota">Y <?= count($porCobrar['lista']) - 12 ?> más en Cobros.</p><?php endif; ?>
        <?php endif; ?>
        <div class="g-pie-tabla"><?= $descargar('cuotas', 'Descargar cuotas para Excel', $hoy->modify('-2 years')->format('Y-m-d'), $hoy->modify('+2 years')->format('Y-m-d')) ?>
            <a href="<?= e(url('cobros')) ?>">Ir a Cobros</a></div>
    </section>
</div>

<section class="bloque" id="ocupacion">
    <h2>Ocupación de los próximos 12 meses</h2>
    <p class="g-nota">Fechas vendidas (separadas o confirmadas) desde hoy hasta el <?= e((new DateTimeImmutable($ocupacion['hasta']))->format('d/m/Y')) ?>.
        Los sábados son los días que más se venden.</p>
    <div class="rejilla rejilla-2" style="margin-bottom:0">
        <div>
            <h3>Sábados vendidos por mes</h3>
            <?= $barras(array_map(fn($m, $k) => [ucfirst(Reportes::mesCorto($k)), $m['pct'], $m['vendidos'] . ' de ' . $m['sabados']], $ocupacion['por_mes'], array_keys($ocupacion['por_mes']))) ?>
        </div>
        <div>
            <h3>Por espacio</h3>
            <?php if (!$ocupacion['espacios']): ?><p class="vacio">No tienes espacios activos.</p><?php else: ?>
                <div class="tabla-scroll"><table class="tabla" style="min-width:300px">
                    <thead><tr><th>Espacio</th><th class="num">Fechas</th><th class="num">Sábados</th><th class="num">% sábados</th></tr></thead>
                    <tbody><?php foreach ($ocupacion['espacios'] as $es): ?>
                        <tr><td><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:<?= e($es['color']) ?>;margin-right:6px" aria-hidden="true"></span><?= e($es['nombre']) ?></td>
                            <td class="num"><?= $es['fechas'] ?></td><td class="num"><?= $es['sabados'] ?></td><td class="num"><?= $es['pct_sabados'] ?> %</td></tr>
                    <?php endforeach; ?>
                    <tr class="fila-total"><td>Todo el local</td><td class="num"><?= $ocupacion['fechas_vendidas'] ?></td><td class="num"><?= $ocupacion['sabados_vendidos'] ?> de <?= $ocupacion['sabados'] ?></td><td class="num"><?= $ocupacion['pct_sabados'] ?> %</td></tr>
                    </tbody>
                </table></div>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php if ($avanzado): ?>
<section class="bloque" id="conversion">
    <h2>Embudo de ventas y conversión</h2>
    <p class="g-nota">De las consultas que llegaron en el periodo, cuántas se vendieron. Sirve para saber qué red social y qué tipo de evento te traen clientes de verdad.</p>
    <?php if (!$t['consultas']): ?><p class="vacio">No llegaron consultas en este periodo.</p><?php else: ?>
        <div class="g-embudo" aria-label="Embudo">
            <?php $maxE = max(1, max($conversion['llegaron'])); foreach ($conversion['llegaron'] as $paso => $cant): ?>
                <div style="width:<?= max(30, (int) round($cant * 100 / $maxE)) ?>%"><?= e($paso) ?>: <?= (int) $cant ?></div>
            <?php endforeach; ?>
        </div>
        <p style="text-align:center"><strong><?= $t['pct'] ?> %</strong> de conversión · <?= $t['vendidos'] ?> vendidos, <?= $t['perdidos'] ?> perdidos, <?= $t['abiertos'] ?> todavía abiertos
            <?= $t['valor'] > 0 ? '· ' . dinero($t['valor']) . ' vendidos' : '' ?></p>
        <div class="rejilla rejilla-2" style="margin-bottom:0">
            <div><h3>Por cómo llegaron</h3><?= $tablaConversion($conversion['por_origen'], 'Origen') ?></div>
            <div><h3>Por tipo de evento</h3><?= $tablaConversion($conversion['por_tipo'], 'Tipo') ?></div>
            <div><h3>Por vendedor</h3><?= $tablaConversion($conversion['por_vendedor'], 'Vendedor') ?></div>
            <div><h3>Por qué se pierden</h3>
                <?php if (!$conversion['motivos']): ?><p class="vacio">No se perdió ninguna consulta. 🎉</p>
                <?php else: ?><?= $barras(array_map(fn($m) => [$m['motivo'], $m['cantidad'], (string) $m['cantidad']], array_slice($conversion['motivos'], 0, 8)), 'gris') ?><?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
    <div class="g-pie-tabla"><?= $descargar('clientes', 'Descargar clientes para Excel') ?></div>
</section>

<section class="bloque" id="rentabilidad">
    <h2>Ganancia por evento</h2>
    <p class="g-nota">De los eventos que se hacen en el periodo: lo cobrado sin IVA, menos los gastos y las compras a proveedores del evento, y lo pagado al personal.
        Si todavía no registraste gastos ni compras, usamos el costo estimado de la cotización (marcado con *).</p>
    <?php if (!$rentabilidad['lista']): ?><p class="vacio">No hay eventos vendidos en este periodo.</p><?php else: $tr = $rentabilidad['total']; ?>
        <div class="tabla-scroll"><table class="tabla">
            <thead><tr><th>Evento</th><th class="num">Sin IVA</th><th class="num">Gastos y compras</th><th class="num">Personal</th><th class="num">Ganancia</th><th class="num">Margen</th></tr></thead>
            <tbody>
            <?php foreach ($rentabilidad['lista'] as $r): ?>
                <tr><td><a href="<?= e(url('evento', ['id' => $r['id']])) ?>"><?= e($r['nombre']) ?></a><br><small><?= e(fecha_corta($r['fecha'])) ?> · <?= (int) $r['invitados'] ?> inv. · <?= e(Eventos::ESTADOS[$r['estado']]) ?></small></td>
                    <td class="num"><?= dinero($r['sin_iva']) ?></td>
                    <td class="num"><?= dinero($r['costo']) ?><?= $r['usa_estimado'] ? ' <span title="Costo estimado de la cotización">*</span>' : '' ?></td>
                    <td class="num"><?= dinero($r['personal']) ?></td>
                    <td class="num <?= $r['ganancia'] < 0 ? 'monto-neg' : '' ?>"><strong><?= dinero($r['ganancia']) ?></strong></td>
                    <td class="num"><?= $r['margen'] ?> %</td></tr>
            <?php endforeach; ?>
            <tr class="fila-total"><td>Total</td><td class="num"><?= dinero($tr['sin_iva']) ?></td><td class="num"><?= dinero($tr['costo']) ?></td>
                <td class="num"><?= dinero($tr['personal']) ?></td><td class="num"><?= dinero($tr['ganancia']) ?></td><td class="num"><?= $tr['margen'] ?> %</td></tr>
            </tbody>
        </table></div>
        <?php if ($rentabilidad['gastos_generales'] > 0): ?>
            <p style="margin-top:10px">Gastos generales del negocio en el periodo (luz, agua, arriendo…): <strong><?= dinero($rentabilidad['gastos_generales']) ?></strong>
                · Ganancia después de gastos generales: <strong class="<?= $rentabilidad['ganancia_neta'] < 0 ? 'monto-neg' : '' ?>"><?= dinero($rentabilidad['ganancia_neta']) ?></strong></p>
        <?php endif; ?>
    <?php endif; ?>
    <div class="g-pie-tabla"><?= $descargar('gastos', 'Descargar gastos para Excel') ?></div>
</section>

<section class="bloque" id="comisiones">
    <h2>Comisión de vendedores</h2>
    <?php if ($comisiones['pct'] <= 0): ?>
        <p class="vacio">No tienes comisión configurada.<?php if (es_admin_total($u)): ?> <a href="<?= e(url('configuracion', ['tab' => 'reglas'])) ?>">Ponerla en Configuración → Reglas</a><?php endif; ?></p>
    <?php else: ?>
        <p class="g-nota"><?= e(rtrim(rtrim(number_format($comisiones['pct'], 2, ',', ''), '0'), ',')) ?> % sobre lo vendido sin IVA en el periodo (por fecha de venta). El dueño no lleva comisión.</p>
        <?php if (!$comisiones['lista']): ?><p class="vacio">No hubo ventas en este periodo.</p><?php else: ?>
            <div class="tabla-scroll"><table class="tabla" style="min-width:420px">
                <thead><tr><th>Vendedor</th><th class="num">Eventos</th><th class="num">Vendido sin IVA</th><th class="num">Comisión</th></tr></thead>
                <tbody><?php foreach ($comisiones['lista'] as $c): ?>
                    <tr><td><?= e($c['vendedor']) ?></td><td class="num"><?= $c['eventos'] ?></td><td class="num"><?= dinero($c['sin_iva']) ?></td>
                        <td class="num"><strong><?= $c['comision'] > 0 ? dinero($c['comision']) : '—' ?></strong></td></tr>
                <?php endforeach; ?>
                <tr class="fila-total"><td colspan="3">Total a pagar en comisiones</td><td class="num"><?= dinero($comisiones['total']) ?></td></tr></tbody>
            </table></div>
        <?php endif; ?>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($satisfaccion) require RAIZ . '/app/vistas/_reporte_satisfaccion.php'; // encuesta de satisfacción (070, módulo cuestionarios) ?>

<?php if ($exportar): ?>
<section class="bloque" id="descargas">
    <h2>Descargar para Excel</h2>
    <p class="g-nota">Archivos del periodo elegido. Se abren directo en Excel o Google Sheets, con tildes y columnas en su lugar.</p>
    <div class="lista-mas">
        <?php foreach (Reportes::EXPORTES as $k => $txt): ?>
            <a href="<?= e($k === 'cuotas' ? $enlaceExportar('cuotas', $hoy->modify('-2 years')->format('Y-m-d'), $hoy->modify('+2 years')->format('Y-m-d')) : $enlaceExportar($k)) ?>">
                ⬇ <?= e($txt) ?><small><?= $k === 'cuotas' ? 'Todas las pendientes y pagadas' : e($per['nombre']) ?></small></a>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>
