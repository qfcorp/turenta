<?php
use TuRenta\{Compras, ProveedoresFicha, WhatsApp};

/** Portal del proveedor. @var array $p @var array $negocio @var ?array $dueno @var string $token @var array $eventosP @var array $ordenes @var array $pagos
 *  @var float $saldo @var ?string $mapa */
$lugar = trim(implode(', ', array_filter([$negocio['direccion'] ?? '', $negocio['ciudad'] ?? ''])));
$contactoGeneral = $negocio['whatsapp'] ?: ($negocio['telefono'] ?: ($dueno['telefono'] ?? null));
$hoy = date('Y-m-d');
$ahora = new DateTimeImmutable();
$primer = trim(explode(' ', trim((string) ($p['contacto'] ?: $p['nombre'])))[0]);
$pendientes = array_filter($ordenes, fn($c) => $c['estado'] === 'pedida');
$sinFactura = array_filter($ordenes, fn($c) => in_array($c['estado'], Compras::ACTIVAS, true) && !$c['factura_numero'] && !$c['factura_archivo']);
?>
<header class="publico-cabeza">
    <span class="marca-sello dia-sello" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($p['nombre'], 0, 1))) ?></span>
    <div>
        <h1>Hola, <?= e($primer) ?></h1>
        <p><?= e($p['nombre']) ?> · tus trabajos con <?= e($negocio['nombre']) ?></p>
    </div>
</header>

<?php if ($pendientes): ?><p class="aviso pv-aviso-amarillo">Tienes <?= count($pendientes) ?> <?= count($pendientes) === 1 ? 'orden' : 'órdenes' ?> de compra por confirmar.</p><?php endif; ?>

<section class="bloque dia-staff-lugar">
    <h2>Dónde</h2>
    <p><strong><?= e($negocio['nombre']) ?></strong><?= $lugar ? '<br>' . e($lugar) : '' ?></p>
    <div class="acciones">
        <?php if ($mapa): ?><a class="boton boton-claro" href="<?= e($mapa) ?>" target="_blank" rel="noopener">Ver en el mapa</a><?php endif; ?>
        <?php if ($wa = WhatsApp::enlace($contactoGeneral, 'Hola, soy ' . $p['nombre'] . ' (proveedor).')): ?>
            <a class="boton boton-wa" href="<?= e($wa) ?>" target="_blank" rel="noopener">Escribir al local</a><?php endif; ?>
    </div>
</section>

<h2 class="dia-staff-titulo">Tus próximos eventos</h2>
<?php if (!$eventosP): ?>
    <section class="bloque"><p class="vacio">No tienes eventos con nosotros por ahora. Cuando te contraten, aparecerán aquí.</p></section>
<?php endif; ?>
<?php foreach ($eventosP as $x):
    $coord = WhatsApp::enlace($x['coordinador_telefono'], 'Hola ' . trim(explode(' ', (string) $x['coordinador'])[0]) . ', soy ' . $p['nombre'] . ' (proveedor) para ' . $x['evento'] . '.');
    $puedeLlegar = ProveedoresFicha::puedeMarcarLlegada($x, $ahora); ?>
<section class="bloque dia-staff-evento <?= $x['confirmado'] ? 'dia-staff-ok' : '' ?>">
    <p class="dia-staff-fecha"><?= e(ucfirst(fecha_larga($x['fecha']))) ?><?= $x['fecha'] === $hoy ? ' · <strong>¡HOY!</strong>' : '' ?></p>
    <h3><?= e($x['evento']) ?></h3>
    <dl class="dia-staff-datos">
        <div><dt>Llegas</dt><dd><?= e(hora_corta($x['hora_llegada']) ?: 'Por confirmar') ?></dd></div>
        <div><dt>El evento</dt><dd><?= e(hora_corta($x['hora_inicio'])) ?> a <?= e(hora_corta($x['hora_fin'])) ?></dd></div>
    </dl>
    <?php if ($x['notas']): ?><p class="suave"><small><?= e($x['notas']) ?></small></p><?php endif; ?>
    <?php if ($x['bloques']): ?>
        <p class="pv-subtitulo">Lo que te toca en el cronograma</p>
        <ul class="pv-bloques-portal">
            <?php foreach ($x['bloques'] as $b): ?><li><strong><?= e(hora_corta($b['hora'])) ?></strong> <?= e($b['actividad']) ?> <small>(<?= (int) $b['duracion_min'] ?> min)</small><?= $b['notas'] ? '<br><small class="suave">' . e($b['notas']) . '</small>' : '' ?></li><?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if ($x['coordinador']): ?><p class="suave"><small>Coordina: <?= e($x['coordinador']) ?></small></p><?php endif; ?>
    <div class="acciones">
        <?php if ($coord): ?><a class="boton boton-wa boton-chico" href="<?= e($coord) ?>" target="_blank" rel="noopener">Escribir al coordinador</a><?php endif; ?>
    </div>
    <?php if ($x['llegada_real']): ?>
        <p class="dia-staff-estado dia-ok-texto">✓ Llegaste a las <?= e(substr((string) $x['llegada_real'], 11, 5)) ?><?= $x['puntualidad'] ? ' · ' . e(mb_strtolower($x['puntualidad']['texto'])) : '' ?>.</p>
    <?php elseif ($puedeLlegar): ?>
        <form method="post" class="dia-staff-botones"><?= campo_csrf() ?><input type="hidden" name="accion" value="llegue"><input type="hidden" name="ep_id" value="<?= (int) $x['id'] ?>">
            <button class="boton dia-boton-grande pv-llegue" type="submit">Ya llegué</button></form>
    <?php endif; ?>
    <?php if (!$x['confirmado'] && !$x['llegada_real'] && $x['fecha'] >= $hoy): ?>
        <p class="dia-staff-estado"><strong>¿Nos confirmas que vas?</strong></p>
        <form method="post" class="dia-staff-botones"><?= campo_csrf() ?><input type="hidden" name="accion" value="confirmar_evento"><input type="hidden" name="ep_id" value="<?= (int) $x['id'] ?>">
            <button class="boton dia-boton-grande" type="submit">Sí, confirmo</button></form>
    <?php elseif ($x['confirmado'] && !$x['llegada_real']): ?>
        <p class="dia-staff-estado dia-ok-texto">✓ Confirmaste que vas.</p>
    <?php endif; ?>
</section>
<?php endforeach; ?>

<h2 class="dia-staff-titulo">Tus órdenes de compra</h2>
<?php if (!$ordenes): ?>
    <section class="bloque"><p class="vacio">Todavía no tienes órdenes de compra con nosotros.</p></section>
<?php endif; ?>
<?php foreach ($ordenes as $c): ?>
<section class="bloque pv-orden-portal pv-est-<?= e($c['estado']) ?>">
    <div class="dia-tarjeta-cab">
        <div><h3><?= e($c['codigo']) ?> · <?= dinero($c['base']) ?></h3>
            <small><?= e(fecha_corta($c['fecha'])) ?><?= $c['evento'] ? ' · ' . e($c['evento']) . ' (' . e(fecha_corta($c['evento_fecha'], false)) . ')' : '' ?></small></div>
        <span class="pv-estado pv-estado-<?= e($c['estado']) ?>"><?= e(Compras::ESTADOS[$c['estado']]) ?></span>
    </div>
    <ul class="pv-oferta-items">
        <?php foreach ($c['items'] as $it): ?><li><?= e(rtrim(rtrim(number_format((float) $it['cantidad'], 2, ',', '.'), '0'), ',')) ?> × <?= e($it['descripcion']) ?> <span><?= dinero($it['subtotal']) ?></span></li><?php endforeach; ?>
    </ul>
    <?php if (in_array($c['estado'], Compras::ACTIVAS, true)): ?>
    <p><small>Pagado <?= dinero($c['pagado']) ?> · <strong>saldo <?= dinero($c['saldo']) ?></strong><?= $c['vence'] && $c['saldo'] > 0 ? ' · pago hasta el ' . e(fecha_corta($c['vence'], false)) : '' ?>
        <?= $c['factura_numero'] ? ' · factura ' . e($c['factura_numero']) : '' ?></small></p>
    <?php elseif ($c['estado'] === 'cancelada'): ?>
    <p><small class="cb-rojo"><?= e($c['motivo_cancelacion']) ?></small></p>
    <?php endif; ?>
    <div class="acciones">
        <a class="boton boton-claro boton-chico" href="<?= e(url('portal_proveedor', ['t' => $token, 'oc' => $c['id']])) ?>" target="_blank" rel="noopener">Ver PDF</a>
    </div>
    <?php if ($c['estado'] === 'pedida'): ?>
    <form method="post" class="pv-responder"><?= campo_csrf() ?><input type="hidden" name="compra_id" value="<?= (int) $c['id'] ?>">
        <div class="campo"><label for="com<?= (int) $c['id'] ?>">Comentario <small>(obligatorio si no puedes)</small></label>
            <input type="text" id="com<?= (int) $c['id'] ?>" name="comentario" maxlength="300" placeholder="Ej.: confirmado, llego a las 15:00"></div>
        <div class="dia-staff-botones">
            <button class="boton dia-boton-grande" type="submit" name="accion" value="confirmar_oc">Confirmo la orden</button>
            <button class="boton boton-claro dia-boton-grande" type="submit" name="accion" value="rechazar_oc">No puedo</button>
        </div>
    </form>
    <?php endif; ?>
    <?php if (in_array($c['estado'], Compras::ACTIVAS, true) && !$c['factura_numero'] && !$c['factura_archivo']): ?>
    <details class="pv-detalles" <?= count($sinFactura) === 1 ? 'open' : '' ?>><summary>Subir mi factura</summary>
        <form method="post" enctype="multipart/form-data" class="dia-form-bloque"><?= campo_csrf() ?><input type="hidden" name="accion" value="factura"><input type="hidden" name="compra_id" value="<?= (int) $c['id'] ?>">
            <div class="campo"><label for="fa<?= (int) $c['id'] ?>">XML de la factura electrónica, PDF o foto</label>
                <input type="file" id="fa<?= (int) $c['id'] ?>" name="factura" accept=".xml,text/xml,application/xml,application/pdf,image/*" required></div>
            <div class="campo"><label for="fn<?= (int) $c['id'] ?>">Número de factura <small>(si subes PDF o foto)</small></label>
                <input type="text" id="fn<?= (int) $c['id'] ?>" name="numero" maxlength="17" placeholder="001-001-000000123"></div>
            <button class="boton boton-chico" type="submit">Enviar factura</button>
        </form>
    </details>
    <?php endif; ?>
</section>
<?php endforeach; ?>

<section class="bloque">
    <h2>Tus pagos</h2>
    <div class="cifras">
        <div class="cifra-tarjeta <?= $saldo > 0 ? 'alerta' : '' ?>"><small>Te debemos</small><strong><?= dinero($saldo) ?></strong></div>
        <div class="cifra-tarjeta bien"><small>Ya te pagamos</small><strong><?= dinero(array_sum(array_map(fn($x) => (float) $x['monto'], $pagos))) ?></strong></div>
    </div>
    <?php if (!$pagos): ?>
        <p class="suave">Aquí verás cada pago que te hagamos.</p>
    <?php else: ?>
    <ul class="filas">
        <?php foreach (array_slice($pagos, 0, 30) as $x): ?>
            <li><div><?= e(Compras::codigo($x['numero'])) ?><?= $x['evento'] ? ' · ' . e($x['evento']) : '' ?><br><small><?= e(fecha_corta($x['fecha'])) ?> · <?= e(Compras::METODOS[$x['metodo']] ?? $x['metodo']) ?>
                <?= $x['cheque_fecha'] ? ' (para el ' . e(fecha_corta($x['cheque_fecha'], false)) . ')' : '' ?><?= $x['referencia'] ? ' · ' . e($x['referencia']) : '' ?></small></div>
                <div class="derecha monto"><?= dinero($x['monto']) ?></div></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</section>
<p class="suave" style="text-align:center"><small>Guarda este enlace: es solo tuyo.</small></p>
