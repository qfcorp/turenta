<?php
use TuRenta\{Adjuntos, Compras, Cotizaciones, Incidencias, ProveedoresFicha, WhatsApp};

/** Ficha del proveedor. @var array $p @var array $secciones @var string $ver @var bool $verDinero @var bool $comercial @var array $negocio
 *  @var ?array $puntaje @var array $documentos @var ?string $portal @var ?array $cuenta */
$editable = puede_editar($u);
$pid = (int) $p['id'];
$hoyTxt = date('Y-m-d');
$primer = trim(explode(' ', trim((string) ($p['contacto'] ?: $p['nombre'])))[0]);
$wa = WhatsApp::enlace($p['telefono'], "Hola $primer, te escribe {$negocio['nombre']}.");
$waPortal = $portal ? WhatsApp::enlace($p['telefono'], "Hola $primer, te escribe {$negocio['nombre']}. Este es tu enlace para ver tus eventos con nosotros, confirmar órdenes, subir tus facturas y ver tus pagos. Guárdalo, es solo tuyo:\n$portal") : null;
$docAlertas = array_filter($documentos, fn($d) => in_array($d['estado'], ['vencido', 'por_vencer'], true));
$etqDoc = ['vencido' => ['pv-chip-rojo', 'Vencido'], 'por_vencer' => ['pv-chip-amarillo', 'Vence pronto'], 'vigente' => ['pv-chip-verde', 'Vigente'], 'sin_fecha' => ['pv-chip-suave', 'Sin fecha']];
$estrellas = fn(?int $n) => $n ? '<span class="pv-estrellas" aria-label="' . $n . ' de 5">' . str_repeat('★', $n) . '<span class="pv-estrellas-vacias">' . str_repeat('★', 5 - $n) . '</span></span>' : '';
$conteo = ['documentos' => count($documentos), 'incidencias' => (int) ($puntaje['incidencias'] ?? 0), 'eventos' => (int) ($puntaje['eventos'] ?? 0) + (int) ($puntaje['proximos'] ?? 0),
           'cuenta' => $cuenta ? count(array_filter($cuenta['compras'], fn($c) => $c['estado'] !== 'cotizacion')) : 0];
?>
<div class="evento-cabeza">
    <div>
        <p class="evento-sub" style="margin:0"><a href="<?= e(url('proveedores')) ?>">Proveedores</a> · <?= e(Cotizaciones::CATEGORIAS[$p['servicio']] ?? $p['servicio']) ?></p>
        <h1><?= e($p['nombre']) ?><?= $p['preferido'] ? ' <span class="pv-estrella" title="Preferido">★</span>' : '' ?></h1>
        <p class="evento-sub"><?= e(implode(' · ', array_filter([$p['contacto'], $p['telefono'], $p['email']]))) ?: 'Sin datos de contacto' ?></p>
    </div>
    <?php if (!$p['activo']): ?><span class="etq etq-cancelada">Inactivo</span><?php endif; ?>
</div>
<div class="acciones pv-barra">
    <?php if ($wa): ?><a class="boton boton-wa boton-chico" href="<?= e($wa) ?>" target="_blank" rel="noopener">WhatsApp</a><?php endif; ?>
    <?php if ($comercial): ?><a class="boton boton-chico" href="<?= e(url('compras', ['nueva' => 1, 'proveedor' => $pid])) ?>#nueva">Nueva compra</a><?php endif; ?>
    <?php if ($verDinero): ?><a class="boton boton-claro boton-chico" href="<?= e(url('pdf', ['tipo' => 'estado_proveedor', 'id' => $pid])) ?>" target="_blank" rel="noopener">Estado de cuenta (PDF)</a><?php endif; ?>
    <?php if ($editable): ?><a class="boton boton-claro boton-chico" href="<?= e(url('proveedores', ['id' => $pid])) ?>#form-proveedor">Editar datos</a><?php endif; ?>
    <a class="boton boton-claro boton-chico" href="<?= e(url('incidencias', ['proveedor' => $pid])) ?>#nueva">Reportar problema</a>
</div>

<?php if ($docAlertas): ?>
    <p class="aviso pv-aviso-amarillo" role="status">📄 <?= count($docAlertas) === 1 ? 'Un documento' : count($docAlertas) . ' documentos' ?> vencido<?= count($docAlertas) > 1 ? 's' : '' ?> o por vencer:
        <?= e(implode(', ', array_map(fn($d) => ProveedoresFicha::TIPOS_DOC[$d['tipo']] . ' (' . ($d['estado'] === 'vencido' ? 'venció' : 'vence') . ' el ' . fecha_corta($d['vence'], false) . ')', $docAlertas))) ?>.
        <a href="<?= e(url('proveedor', ['id' => $pid, 'ver' => 'documentos'])) ?>">Ver documentos</a></p>
<?php endif; ?>
<?php if ($cuenta && $cuenta['sobre_cupo']): ?>
    <p class="aviso aviso-error" role="status">Le debes <?= dinero($cuenta['saldo']) ?>: pasaste su cupo de crédito de <?= dinero($cuenta['cupo']) ?>.</p>
<?php endif; ?>

<nav class="pestanas" aria-label="Partes de la ficha">
    <?php foreach ($secciones as $k => $t): ?>
        <a href="<?= e(url('proveedor', ['id' => $pid, 'ver' => $k])) ?>" class="<?= $ver === $k ? 'activa' : '' ?>" <?= $ver === $k ? 'aria-current="page"' : '' ?>>
            <?= e($t) ?><?php if (!empty($conteo[$k])): ?> <span class="dia-cuenta"><?= (int) $conteo[$k] ?></span><?php endif; ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($ver === 'resumen'): ?>
    <?php if ($verDinero && $cuenta): ?>
    <div class="cifras">
        <a class="cifra-tarjeta pv-cifra <?= $cuenta['saldo'] > 0 ? 'alerta' : 'bien' ?>" href="<?= e(url('proveedor', ['id' => $pid, 'ver' => 'cuenta'])) ?>"><small>Le debes</small><strong><?= dinero($cuenta['saldo']) ?></strong></a>
        <div class="cifra-tarjeta <?= $cuenta['vencido'] > 0 ? 'alerta' : '' ?>"><small>Vencido</small><strong><?= dinero($cuenta['vencido']) ?></strong></div>
        <div class="cifra-tarjeta"><small>Comprado este año</small><strong><?= dinero($puntaje['comprado_anio'] ?? 0) ?></strong></div>
        <div class="cifra-tarjeta"><small>Pagado (total)</small><strong><?= dinero($cuenta['pagado']) ?></strong></div>
    </div>
    <?php endif; ?>
    <div class="rejilla rejilla-2">
        <section class="bloque">
            <h2>Cómo cumple</h2>
            <?php if ($puntaje && $puntaje['puntaje'] !== null): ?>
                <div class="pv-puntaje-grande pv-nivel-<?= e($puntaje['nivel']) ?>"><strong><?= (int) $puntaje['puntaje'] ?></strong><span>de 100 · <?= e(ProveedoresFicha::NIVELES[$puntaje['nivel']]) ?></span></div>
            <?php elseif (!empty($puntaje['pocos_datos'])): ?>
                <p class="suave"><strong>Sin datos suficientes:</strong> el puntaje se muestra desde <?= (int) $puntaje['minimo_eventos'] ?> eventos trabajados y lleva <?= (int) $puntaje['eventos'] ?>.</p>
            <?php else: ?>
                <p class="suave">Todavía sin puntaje: califícalo en el día del evento → Proveedores, después de cada evento.</p>
            <?php endif; ?>
            <?php if (!empty($puntaje['detalle'])): ?>
                <p class="pv-previa-detalle"><small><?= e(implode(' · ', array_map(fn($k, $x) => \TuRenta\PuntajeProveedores::PESOS[$k] . ' ' . $x['valor'] . ' (pesa ' . str_replace('.', ',', (string) $x['peso']) . ' %)', array_keys($puntaje['detalle']), $puntaje['detalle']))) ?>
                    <?= !empty($puntaje['tope_aplicado']) ? ' · nivel limitado por una incidencia grave' : '' ?></small></p>
            <?php endif; ?>
            <dl class="ficha-datos">
                <dt>Eventos trabajados</dt><dd><?= (int) ($puntaje['eventos'] ?? 0) ?><?= !empty($puntaje['proximos']) ? ' · ' . (int) $puntaje['proximos'] . ' próximos' : '' ?></dd>
                <dt>Puntualidad</dt><dd><?= ($puntaje['puntualidad'] ?? null) !== null ? (int) $puntaje['puntualidad'] . ' %' : '—' ?>
                    <?php if (!empty($puntaje['medidas']) || !empty($puntaje['bloques'])): ?><small>(<?= (int) $puntaje['a_tiempo'] + (int) $puntaje['bloques_a_tiempo'] ?> de <?= (int) $puntaje['medidas'] + (int) $puntaje['bloques'] ?> a tiempo)</small><?php endif; ?></dd>
                <dt>Calificación</dt><dd><?= ($puntaje['calificacion'] ?? null) !== null ? $estrellas((int) round($puntaje['calificacion'])) . ' ' . e(number_format((float) $puntaje['calificacion'], 1, ',', '')) : '—' ?></dd>
                <dt>Cumplió</dt><dd><?= ($puntaje['cumplimiento'] ?? null) !== null ? (int) $puntaje['cumplimiento'] . ' %' : '—' ?></dd>
                <dt>Tolerancia</dt><dd><?= (int) $toleranciaProv ?> min <small><a href="<?= e(url('puntaje_proveedores')) ?>">Cómo se calcula el puntaje</a></small></dd>
                <dt>Incidencias</dt><dd><?= (int) ($puntaje['incidencias'] ?? 0) ?><?= !empty($puntaje['graves']) ? ' · <span class="cb-rojo">' . (int) $puntaje['graves'] . ((int) $puntaje['graves'] === 1 ? ' grave' : ' graves') . '</span>' : '' ?></dd>
            </dl>
        </section>
        <section class="bloque">
            <h2>Datos</h2>
            <dl class="ficha-datos">
                <?php if ($p['razon_social']): ?><dt>Razón social</dt><dd><?= e($p['razon_social']) ?></dd><?php endif; ?>
                <dt><?= strlen((string) $p['ruc']) === 10 ? 'Cédula' : 'RUC' ?></dt><dd><?= e($p['ruc'] ?: '—') ?></dd>
                <?php if ($p['direccion'] || $p['ciudad']): ?><dt>Dirección</dt><dd><?= e(trim(($p['direccion'] ?? '') . ($p['ciudad'] ? ', ' . $p['ciudad'] : ''), ', ')) ?></dd><?php endif; ?>
                <?php if ($verDinero): ?>
                <dt>Crédito</dt><dd><?= e(ProveedoresFicha::textoCredito((int) $p['credito_dias']) === 'contado' ? 'De contado' : ProveedoresFicha::textoCredito((int) $p['credito_dias'])) ?></dd>
                <dt>Cupo</dt><dd><?= $p['cupo_credito'] !== null ? dinero($p['cupo_credito']) : 'Sin límite' ?></dd>
                <dt>Anticipo</dt><dd><?= (float) $p['anticipo_pct'] > 0 ? e(rtrim(rtrim(number_format((float) $p['anticipo_pct'], 2, ',', ''), '0'), ',')) . ' %' : 'No pide' ?></dd>
                <?php endif; ?>
            </dl>
            <?php if ($verDinero && $p['numero_cuenta']): ?>
            <div class="pv-banco">
                <small>Para transferirle</small>
                <strong><?= e($p['banco'] ?: 'Banco') ?></strong>
                <span><?= e(ProveedoresFicha::TIPOS_CUENTA[$p['tipo_cuenta'] ?? ''] ?? 'Cuenta') ?> N.º <strong><?= e($p['numero_cuenta']) ?></strong></span>
                <span><?= e($p['titular_cuenta'] ?: ($p['razon_social'] ?: $p['nombre'])) ?><?= $p['ruc'] ? ' · ' . e($p['ruc']) : '' ?></span>
                <button type="button" class="boton boton-claro boton-chico" data-copiar="<?= e(trim(($p['banco'] ?? '') . ' ' . (ProveedoresFicha::TIPOS_CUENTA[$p['tipo_cuenta'] ?? ''] ?? '') . ' ' . $p['numero_cuenta'] . ' ' . ($p['titular_cuenta'] ?? '') . ' ' . ($p['ruc'] ?? ''))) ?>">Copiar datos</button>
            </div>
            <?php elseif ($verDinero && $comercial): ?>
                <p class="suave"><small>Sin cuenta de banco. <a href="<?= e(url('proveedores', ['id' => $pid])) ?>#form-proveedor">Agrégala</a> para pagarle por transferencia.</small></p>
            <?php endif; ?>
            <?php if ($p['notas']): ?><p class="suave"><?= nl2br(e($p['notas'])) ?></p><?php endif; ?>
        </section>
    </div>
    <?php if ($portal): ?>
    <section class="bloque">
        <h2>Portal del proveedor</h2>
        <p class="suave">Un enlace solo para <?= e($p['nombre']) ?>, sin contraseña: ve sus eventos con la dirección y la hora de llegada, confirma las órdenes de compra, sube sus facturas, ve sus pagos y marca "Ya llegué" el día del evento.</p>
        <div class="acciones">
            <a class="boton boton-claro boton-chico" href="<?= e($portal) ?>" target="_blank" rel="noopener">Abrir portal</a>
            <button type="button" class="boton boton-claro boton-chico" data-copiar="<?= e($portal) ?>">Copiar enlace</button>
            <?php if ($waPortal): ?><a class="boton boton-wa boton-chico" href="<?= e($waPortal) ?>" target="_blank" rel="noopener">Enviar por WhatsApp</a><?php endif; ?>
            <?php if ($comercial): ?>
            <form method="post" class="dia-en-linea"><?= campo_csrf() ?><input type="hidden" name="accion" value="nuevo_enlace">
                <button class="boton-enlace" type="submit" data-confirmar="El enlace actual dejará de servir. ¿Crear uno nuevo?">Cambiar el enlace</button></form>
            <?php endif; ?>
        </div>
    </section>
    <?php endif; ?>
    <?php $prox = $eventosProv['proximos'] ?? []; if ($prox): ?>
    <section class="bloque">
        <h2>Próximos eventos</h2>
        <ul class="filas">
            <?php foreach (array_slice($prox, 0, 5) as $x): ?>
            <li><div><a href="<?= e(url('evento_dia', ['id' => $x['evento_id'], 'ver' => 'proveedores'])) ?>"><strong><?= e($x['evento']) ?></strong></a>
                <br><small><?= e(fecha_corta($x['fecha'])) ?><?= $x['hora_llegada'] ? ' · llega ' . e(hora_corta($x['hora_llegada'])) : '' ?><?= $x['compra_numero'] ? ' · ' . e(Compras::codigo($x['compra_numero'])) : '' ?></small></div>
                <div class="derecha"><span class="pv-chip <?= $x['confirmado'] ? 'pv-chip-verde' : 'pv-chip-amarillo' ?>"><?= $x['confirmado'] ? 'Confirmó' : 'Sin confirmar' ?></span></div></li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

<?php elseif ($ver === 'cuenta'): ?>
    <div class="cifras">
        <div class="cifra-tarjeta"><small>Comprado</small><strong><?= dinero($cuenta['total']) ?></strong></div>
        <div class="cifra-tarjeta bien"><small>Pagado</small><strong><?= dinero($cuenta['pagado']) ?></strong></div>
        <div class="cifra-tarjeta"><small>Retenciones y descuentos</small><strong><?= dinero($cuenta['retenciones'] + $cuenta['descuentos']) ?></strong></div>
        <div class="cifra-tarjeta <?= $cuenta['saldo'] > 0 ? 'alerta' : '' ?>"><small>Saldo</small><strong><?= dinero($cuenta['saldo']) ?></strong></div>
    </div>
    <section class="bloque">
        <div class="dia-titulo-acciones"><h2>Compras</h2>
            <?php if ($comercial): ?><a class="boton boton-chico" href="<?= e(url('compras', ['nueva' => 1, 'proveedor' => $pid])) ?>#nueva">Nueva compra</a><?php endif; ?></div>
        <?php if (!$cuenta['compras']): ?>
            <p class="vacio">Todavía no le has comprado nada.</p>
        <?php else: ?>
        <?php $listaCompras = $cuenta['compras']; $ocultarProveedor = true; require RAIZ . '/app/vistas/_pv_compras.php'; ?>
        <?php endif; ?>
    </section>
    <section class="bloque">
        <h2>Pagos hechos</h2>
        <?php if (!$pagos): ?><p class="vacio">Sin pagos todavía.</p><?php else: ?>
        <ul class="filas">
            <?php foreach ($pagos as $x): ?>
            <li class="<?= $x['estado'] === 'anulado' ? 'pv-anulado' : '' ?>"><div><strong><?= dinero($x['monto']) ?></strong> · <?= e(Compras::METODOS[$x['metodo']] ?? $x['metodo']) ?><?= $x['referencia'] ? ' ' . e($x['referencia']) : '' ?>
                <br><small><?= e(fecha_corta($x['fecha'])) ?> · <a href="<?= e(url('compra', ['id' => $x['compra_id']])) ?>"><?= e(Compras::codigo($x['numero'])) ?></a><?= $x['evento'] ? ' · ' . e($x['evento']) : '' ?>
                <?= $x['cheque_fecha'] ? ' · cheque para el ' . e(fecha_corta($x['cheque_fecha'], false)) : '' ?><?= $x['estado'] === 'anulado' ? ' · <strong>ANULADO</strong>: ' . e($x['anulado_motivo']) : '' ?></small></div>
                <?php if ($x['comprobante']): ?><div class="derecha"><a class="enlace-chico" href="<?= e(url('proveedor_archivo', ['tipo' => 'comprobante', 'id' => $x['id']])) ?>" target="_blank" rel="noopener">Comprobante</a></div><?php endif; ?></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>
    <?php if ($gastosProv): ?>
    <section class="bloque">
        <h2>Gastos anotados con este proveedor</h2>
        <p class="suave">Los que se registraron como gasto del evento (aparte de las compras).</p>
        <ul class="filas">
            <?php foreach ($gastosProv as $g): ?>
            <li><div><?= e($g['concepto']) ?><br><small><?= e(fecha_corta($g['fecha'])) ?><?= $g['evento'] ? ' · ' . e($g['evento']) : ' · gasto general' ?></small></div><div class="derecha monto"><?= dinero($g['monto']) ?></div></li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

<?php elseif ($ver === 'eventos'): ?>
    <?php foreach (['proximos' => 'Próximos eventos', 'pasados' => 'Eventos donde trabajó'] as $k => $tit): ?>
    <section class="bloque">
        <h2><?= e($tit) ?></h2>
        <?php if (!$eventosProv[$k]): ?><p class="vacio"><?= $k === 'proximos' ? 'Sin eventos próximos.' : 'Todavía no ha trabajado en un evento contigo.' ?></p><?php else: ?>
        <ul class="filas pv-filas">
            <?php foreach ($eventosProv[$k] as $x): $pu = $x['puntualidad']; ?>
            <li><div>
                <a href="<?= e(url('evento_dia', ['id' => $x['evento_id'], 'ver' => 'proveedores'])) ?>"><strong><?= e($x['evento']) ?></strong></a>
                <br><small><?= e(fecha_corta($x['fecha'])) ?> · <?= e($x['codigo']) ?><?= $x['hora_llegada'] ? ' · llegada pactada ' . e(hora_corta($x['hora_llegada'])) : '' ?>
                    <?= $x['llegada_real'] ? ' · llegó ' . e(substr((string) $x['llegada_real'], 11, 5)) : '' ?><?= $x['compra_numero'] ? ' · ' . e(Compras::codigo($x['compra_numero'])) : '' ?></small>
                <div class="pv-chips">
                    <?php if ($pu): ?><span class="pv-chip <?= $pu['estado'] === 'a_tiempo' ? 'pv-chip-verde' : 'pv-chip-rojo' ?>"><?= e($pu['texto']) ?></span><?php endif; ?>
                    <?php if ($x['cumplimiento']): ?><span class="pv-chip <?= in_array($x['cumplimiento'], ['cumplio'], true) ? 'pv-chip-verde' : ($x['cumplimiento'] === 'observaciones' ? 'pv-chip-amarillo' : 'pv-chip-rojo') ?>"><?= e(ProveedoresFicha::CUMPLIMIENTO[$x['cumplimiento']]) ?></span><?php endif; ?>
                    <?php foreach ($x['bloques'] as $b): if ($b['atraso'] === null) continue; ?>
                        <span class="pv-chip <?= $b['a_tiempo'] ? 'pv-chip-verde' : 'pv-chip-rojo' ?>"><?= e($b['actividad']) ?>: <?= $b['a_tiempo'] ? 'a tiempo' : $b['atraso'] . ' min tarde' ?></span>
                    <?php endforeach; ?>
                    <?php if ($k === 'proximos'): ?><span class="pv-chip <?= $x['confirmado'] ? 'pv-chip-verde' : 'pv-chip-amarillo' ?>"><?= $x['confirmado'] ? 'Confirmó' : 'Sin confirmar' ?></span><?php endif; ?>
                </div>
                <?php if ($x['comentario']): ?><small class="suave">“<?= e($x['comentario']) ?>”</small><?php endif; ?>
            </div>
            <div class="derecha"><?= $estrellas($x['calificacion'] !== null ? (int) $x['calificacion'] : null) ?></div></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>
    <?php endforeach; ?>

<?php elseif ($ver === 'documentos'): ?>
    <section class="bloque">
        <h2>Documentos</h2>
        <?php if (!$documentos): ?>
            <p class="vacio">Sin documentos. Sube su RUC, permisos (sanitario/ARCSA si es de comida), póliza de seguro o contrato, con la fecha en que vencen: te avisamos 30 días antes.</p>
        <?php else: ?>
        <ul class="filas">
            <?php foreach ($documentos as $d): [$cls, $txt] = $etqDoc[$d['estado']]; ?>
            <li><div><strong><?= e(ProveedoresFicha::TIPOS_DOC[$d['tipo']]) ?></strong><?= $d['nombre'] ? ' · ' . e($d['nombre']) : '' ?>
                <br><small><?= $d['vence'] ? ($d['estado'] === 'vencido' ? 'Venció el ' : 'Vence el ') . e(fecha_corta($d['vence'])) : 'Sin fecha de vencimiento' ?> · subido el <?= e(fecha_corta(substr((string) $d['creado_en'], 0, 10), false)) ?></small>
                <div class="pv-chips"><span class="pv-chip <?= e($cls) ?>"><?= e($txt) ?></span></div></div>
                <div class="derecha acciones">
                    <?php if ($d['archivo']): ?><a class="boton boton-claro boton-chico" href="<?= e(url('proveedor_archivo', ['tipo' => 'documento', 'id' => $d['id']])) ?>" target="_blank" rel="noopener">Ver <?= Adjuntos::esPdf($d['archivo']) ? 'PDF' : 'foto' ?></a><?php endif; ?>
                    <?php if ($editable): ?>
                    <form method="post"><?= campo_csrf() ?><input type="hidden" name="accion" value="documento_borrar"><input type="hidden" name="documento_id" value="<?= (int) $d['id'] ?>">
                        <button class="boton boton-peligro boton-chico" type="submit" data-confirmar="¿Quitar este documento?">Quitar</button></form>
                    <?php endif; ?>
                </div></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>
    <?php if ($editable): ?>
    <form method="post" enctype="multipart/form-data" class="bloque"><?= campo_csrf() ?><input type="hidden" name="accion" value="documento">
        <h2>Subir documento</h2>
        <div class="fila-campos">
            <div class="campo"><label for="dtipo">¿Qué documento es?</label>
                <select id="dtipo" name="tipo"><?php foreach (ProveedoresFicha::TIPOS_DOC as $k => $t): ?><option value="<?= e($k) ?>"><?= e($t) ?></option><?php endforeach; ?></select></div>
            <div class="campo"><label for="dvence">Vence el <small>(opcional)</small></label><input type="date" id="dvence" name="vence"></div>
        </div>
        <div class="campo"><label for="dnom">Detalle <small>(opcional: aseguradora, número…)</small></label><input type="text" id="dnom" name="nombre_doc" maxlength="150"></div>
        <div class="campo"><label for="darch">Foto o PDF</label><input type="file" id="darch" name="archivo" accept="image/*,application/pdf"></div>
        <button class="boton" type="submit">Guardar documento</button>
    </form>
    <?php endif; ?>

<?php elseif ($ver === 'precios'): ?>
    <section class="bloque">
        <h2>Historial de precios</h2>
        <p class="suave">Todo lo que le compraste, con la fecha y el precio unitario. La flecha compara con la compra anterior del mismo ítem.</p>
        <?php if (!$precios): ?><p class="vacio">Todavía no le has comprado nada.</p><?php else: ?>
        <div class="tabla-scroll"><table class="tabla">
            <thead><tr><th>Fecha</th><th>Ítem</th><th class="num">Cantidad</th><th class="num">Precio unitario</th><th>Compra</th></tr></thead>
            <tbody>
            <?php foreach ($precios as $x): ?>
                <tr><td><?= e(fecha_corta($x['fecha'])) ?></td><td><?= e($x['descripcion']) ?><?= $x['evento'] ? '<br><small>' . e($x['evento']) . '</small>' : '' ?></td>
                    <td class="num"><?= e(rtrim(rtrim(number_format((float) $x['cantidad'], 2, ',', '.'), '0'), ',')) ?></td>
                    <td class="num"><?= dinero($x['precio_unitario']) ?>
                        <?php if ($x['cambio_pct'] !== null && abs($x['cambio_pct']) >= 0.1): ?><br><small class="<?= $x['cambio_pct'] > 0 ? 'cb-rojo' : 'cb-verde' ?>"><?= $x['cambio_pct'] > 0 ? '▲' : '▼' ?> <?= e(number_format(abs($x['cambio_pct']), 1, ',', '')) ?> %</small><?php endif; ?></td>
                    <td><a href="<?= e(url('compra', ['id' => $x['compra_id']])) ?>"><?= e(Compras::codigo($x['numero'])) ?></a><br><small><?= e(Compras::ESTADOS[$x['estado']]) ?></small></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </section>

<?php elseif ($ver === 'incidencias'): ?>
    <section class="bloque">
        <div class="dia-titulo-acciones"><h2>Incidencias</h2><a class="boton boton-chico" href="<?= e(url('incidencias', ['proveedor' => $pid])) ?>#nueva">Reportar problema</a></div>
        <?php if (!$incidenciasProv): ?><p class="vacio">Sin problemas registrados con este proveedor. 👍</p><?php else: ?>
        <ul class="filas pv-filas">
            <?php foreach ($incidenciasProv as $i): ?>
            <li><div><span class="pv-chip pv-grav-<?= e($i['gravedad']) ?>"><?= e(Incidencias::GRAVEDADES[$i['gravedad']]) ?></span> <strong><?= e(mb_strimwidth($i['descripcion'], 0, 120, '…')) ?></strong>
                <br><small><?= e(fecha_corta(substr((string) $i['creado_en'], 0, 10))) ?><?= $i['evento'] ? ' · ' . e($i['evento']) : '' ?> · <?= e(Incidencias::ESTADOS[$i['estado']]) ?>
                <?= $verDinero && (float) $i['descuento'] > 0 ? ' · descuento ' . dinero($i['descuento']) : '' ?></small></div>
                <div class="derecha"><a class="enlace-chico" href="<?= e(url('incidencias', ['estado' => 'todas', 'proveedor' => $pid])) ?>#i<?= (int) $i['id'] ?>">Ver</a></div></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>

<?php elseif ($ver === 'bitacora'): ?>
    <section class="bloque">
        <h2>Bitácora</h2>
        <p class="suave">Todo lo que pasó con <?= e($p['nombre']) ?>: compras, facturas, pagos, documentos, llegadas, calificaciones e incidencias.</p>
        <?php if (!$bitacora): ?><p class="vacio">Todavía no hay movimientos.</p><?php else: ?>
        <ol class="pv-linea">
            <?php foreach ($bitacora as $m): ?>
            <li class="pv-mov-<?= e($m['tipo']) ?>">
                <span class="pv-linea-punto" aria-hidden="true"></span>
                <div>
                    <p><?= e($m['accion']) ?></p>
                    <small><?= e(fecha_corta(substr((string) $m['creado_en'], 0, 10))) ?> <?= e(substr((string) $m['creado_en'], 11, 5)) ?> ·
                        <span class="pv-origen-<?= e($m['origen']) ?>"><?= e($m['quien'] ?: 'Sistema') ?></span>
                        <?php if ($m['compra_numero'] && $verDinero): ?> · <a href="<?= e(url('compra', ['id' => $m['compra_id']])) ?>"><?= e(Compras::codigo($m['compra_numero'])) ?></a><?php endif; ?>
                        <?php if ($m['evento']): ?> · <a href="<?= e(url('evento', ['id' => $m['evento_id']])) ?>"><?= e($m['evento']) ?></a><?php endif; ?></small>
                </div>
            </li>
            <?php endforeach; ?>
        </ol>
        <?php endif; ?>
    </section>
<?php endif; ?>
