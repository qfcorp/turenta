<?php
use TuRenta\{Cotizaciones, Eventos};

/**
 * @var array $ev @var string $tab @var array $versiones @var ?array $actual @var bool $editable @var bool $vencida
 * @var array $paquetes @var array $catalogo @var ?string $enlace @var ?string $wa @var bool $puede @var bool $dueno
 * @var bool $comparar @var array $todas
 */
require RAIZ . '/app/vistas/_evento_tabs.php';
$cats = Cotizaciones::CATEGORIAS;
$unis = Cotizaciones::UNIDADES;
$estados = Cotizaciones::ESTADOS;
$num = fn($v) => rtrim(rtrim(number_format((float) $v, 2, ',', ''), '0'), ',');
$dec = fn($v) => number_format((float) $v, 2, ',', '');
$formCrear = function (string $titulo, bool $hayAnterior) use ($paquetes, $ev): string {
    ob_start(); ?>
    <form method="post" action="<?= e(url('cotizacion', ['id' => $ev['id'], 'accion' => 'crear'])) ?>" class="doc-crear">
        <?= campo_csrf() ?>
        <h3><?= e($titulo) ?></h3>
        <div class="opciones">
            <?php if ($paquetes): ?>
            <label class="opcion"><input type="radio" name="modo" value="paquete" <?= $hayAnterior ? '' : 'checked' ?> data-mostrar="doc-crear-paq">
                <span><strong>Desde un paquete</strong><small>Carga todo el paquete con los invitados del evento (<?= (int) $ev['invitados'] ?>).</small></span></label>
            <?php endif; ?>
            <?php if ($hayAnterior): ?>
            <label class="opcion"><input type="radio" name="modo" value="copia" checked data-mostrar="doc-crear-paq">
                <span><strong>Copiar la versión anterior</strong><small>Para corregir precios, invitados o lo que pidió el cliente.</small></span></label>
            <?php endif; ?>
            <label class="opcion"><input type="radio" name="modo" value="vacia" <?= !$paquetes && !$hayAnterior ? 'checked' : '' ?> data-mostrar="doc-crear-paq">
                <span><strong>En blanco</strong><small>Solo con el alquiler del espacio; tú agregas lo demás.</small></span></label>
        </div>
        <?php if ($paquetes): ?>
        <div class="campo doc-crear-paq" data-si="paquete" style="margin-top:12px">
            <label for="crear-paq-<?= $hayAnterior ? 'v' : 'p' ?>">Paquete</label>
            <select id="crear-paq-<?= $hayAnterior ? 'v' : 'p' ?>" name="paquete_id">
                <?php foreach ($paquetes as $p): ?>
                    <option value="<?= (int) $p['id'] ?>"><?= e($p['nombre']) ?><?= $p['tipo_evento'] === $ev['tipo'] ? ' ★' : '' ?><?= (int) $p['minimo_invitados'] > 0 ? ' · desde ' . (int) $p['minimo_invitados'] . ' invitados' : '' ?></option>
                <?php endforeach; ?>
            </select>
            <small>★ = paquete para <?= e(mb_strtolower(Eventos::TIPOS[$ev['tipo']] ?? 'este tipo de evento')) ?>.</small>
        </div>
        <?php endif; ?>
        <button class="boton" type="submit" style="margin-top:12px"><?= $hayAnterior ? 'Crear nueva versión' : 'Crear cotización' ?></button>
    </form>
    <?php return ob_get_clean();
};
$lineaHtml = function (string $k, array $l) use ($cats, $unis, $dueno, $editable, $num, $dec): string {
    ob_start(); ?>
    <div class="doc-linea" data-doc-linea data-clave="<?= e($k) ?>">
        <input type="hidden" name="lineas[<?= e($k) ?>][id]" value="<?= (int) ($l['id'] ?? 0) ?>">
        <input type="hidden" name="lineas[<?= e($k) ?>][producto_id]" value="<?= (int) ($l['producto_id'] ?? 0) ?: '' ?>">
        <div class="doc-linea-desc">
            <select name="lineas[<?= e($k) ?>][categoria]" aria-label="Categoría" class="doc-linea-cat">
                <?php foreach ($cats as $ck => $cv): ?><option value="<?= e($ck) ?>" <?= ($l['categoria'] ?? 'otros') === $ck ? 'selected' : '' ?>><?= e($cv) ?></option><?php endforeach; ?>
            </select>
            <input type="text" name="lineas[<?= e($k) ?>][descripcion]" value="<?= e($l['descripcion'] ?? '') ?>" maxlength="300" aria-label="Descripción"
                   placeholder="<?= empty($l['descripcion']) ? 'Línea libre: escribe lo que quieras cobrar' : '' ?>" data-doc-desc>
        </div>
        <div class="doc-linea-nums">
            <label><span>Cantidad</span><input type="text" inputmode="decimal" name="lineas[<?= e($k) ?>][cantidad]" value="<?= e($num($l['cantidad'] ?? 1)) ?>" data-doc-cant></label>
            <label><span>Cobro</span><select name="lineas[<?= e($k) ?>][unidad]" data-doc-unidad>
                <?php foreach ($unis as $uk => $uv): ?><option value="<?= e($uk) ?>" <?= ($l['unidad'] ?? 'evento') === $uk ? 'selected' : '' ?>><?= e($uv) ?></option><?php endforeach; ?>
            </select></label>
            <label><span>Precio</span><input type="text" inputmode="decimal" name="lineas[<?= e($k) ?>][precio_unitario]" value="<?= e($dec($l['precio_unitario'] ?? 0)) ?>" data-doc-precio-l></label>
            <?php if ($dueno): ?>
            <label class="doc-solo-dueno"><span>Costo</span><input type="text" inputmode="decimal" name="lineas[<?= e($k) ?>][costo_unitario]" value="<?= e($dec($l['costo_unitario'] ?? 0)) ?>" data-doc-costo-l></label>
            <?php endif; ?>
            <label class="doc-linea-iva"><input type="checkbox" name="lineas[<?= e($k) ?>][con_iva]" value="1" <?= ($l['con_iva'] ?? true) ? 'checked' : '' ?> data-doc-iva> IVA</label>
            <span class="doc-linea-total monto" data-doc-total-l><?= dinero($l['total'] ?? 0) ?></span>
        </div>
        <div class="doc-linea-botones">
            <button type="submit" name="mover" value="<?= e($k) ?>:arriba" class="boton boton-claro boton-chico" aria-label="Subir" title="Subir" data-doc-subir formnovalidate>↑</button>
            <button type="submit" name="mover" value="<?= e($k) ?>:abajo" class="boton boton-claro boton-chico" aria-label="Bajar" title="Bajar" data-doc-bajar formnovalidate>↓</button>
            <button type="submit" name="quitar" value="<?= e($k) ?>" class="boton boton-peligro boton-chico" aria-label="Quitar" title="Quitar" data-doc-quitar formnovalidate>✕</button>
        </div>
    </div>
    <?php return ob_get_clean();
};
$pasoActual = !$actual ? 1 : (in_array($actual['estado'], ['borrador'], true) ? 1 : ($actual['estado'] === 'enviada' ? 2 : ($actual['estado'] === 'aceptada' ? 4 : 2)));
?>

<ol class="doc-pasos" aria-label="Cómo va la cotización">
    <li class="<?= $pasoActual > 1 ? 'doc-hecho' : 'doc-ahora' ?>"><span>1</span> Armar</li>
    <li class="<?= $pasoActual > 2 ? 'doc-hecho' : ($pasoActual === 2 ? 'doc-ahora' : '') ?>"><span>2</span> Enviar al cliente</li>
    <li class="<?= $pasoActual >= 4 ? 'doc-hecho' : '' ?>"><span>3</span> El cliente acepta</li>
    <li class="<?= $pasoActual >= 4 ? 'doc-ahora' : '' ?>"><span>4</span> <a href="<?= e(url('contrato', ['id' => $ev['id']])) ?>">Contrato</a></li>
</ol>

<?php if (!$actual): ?>
<section class="bloque">
    <h2>Este evento todavía no tiene cotización</h2>
    <p class="suave">Arma la cotización en un minuto con un paquete y ajústala a lo que pidió el cliente. Después se la mandas por WhatsApp con un enlace para que la vea y la acepte desde su celular.</p>
    <?php if ($puede): ?>
        <?php if (in_array($ev['estado'], ['perdido', 'cancelado', 'realizado'], true)): ?>
            <p class="aviso aviso-error">Este evento está <?= e(mb_strtolower(Eventos::ESTADOS[$ev['estado']])) ?>. Cámbialo de estado en el <a href="<?= e(url('evento', ['id' => $ev['id']])) ?>">resumen</a> para cotizar.</p>
        <?php else: ?>
            <?= $formCrear('¿Cómo quieres empezar?', false) ?>
        <?php endif; ?>
    <?php else: ?>
        <p class="vacio">Tu usuario solo puede mirar. Pide al dueño o a un vendedor que prepare la cotización.</p>
    <?php endif; ?>
</section>
<?php return; endif; ?>

<?php
$ver = (int) $actual['version'];
$estadoTxt = $estados[$actual['estado']] ?? $actual['estado'];
?>
<div class="doc-version-cabeza">
    <div>
        <h2>Cotización N.º <?= (int) $actual['numero'] ?> <small>· versión <?= $ver ?></small>
            <span class="doc-estado doc-estado-<?= e($actual['estado']) ?>"><?= e($estadoTxt) ?></span></h2>
        <p class="suave">Hecha el <?= e(fecha_corta($actual['fecha'])) ?> · válida hasta el <?= e(fecha_corta($actual['valida_hasta'])) ?>
            <?php if ($actual['estado'] === 'aceptada'): ?> · aceptada por <strong><?= e($actual['aceptada_por']) ?></strong> el <?= e(date('d/m/Y H:i', strtotime((string) $actual['aceptada_en']))) ?><?php endif; ?></p>
    </div>
    <div class="acciones">
        <a class="boton boton-claro boton-chico" href="<?= e(url('pdf', ['tipo' => 'cotizacion', 'id' => $actual['id']])) ?>" target="_blank" rel="noopener">Ver PDF</a>
        <?php if (count($versiones) > 1): ?>
            <a class="boton boton-claro boton-chico" href="<?= e(url('cotizacion', ['id' => $ev['id'], 'c' => $actual['id'], 'comparar' => 1])) ?>#comparar">Comparar versiones</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($vencida): ?>
    <p class="aviso aviso-error">La validez de esta cotización ya pasó (<?= e(fecha_corta($actual['valida_hasta'])) ?>). El cliente no la puede aceptar en línea: cambia la fecha de validez abajo o crea una nueva versión con precios al día.</p>
<?php endif; ?>
<?php if ($actual['estado'] === 'aceptada'): ?>
    <p class="aviso aviso-ok">El cliente aceptó esta cotización. El evento ya tiene su valor y su plan de pagos.
        <a href="<?= e(url('evento_pagos', ['id' => $ev['id']])) ?>">Ver pagos</a> · <a href="<?= e(url('contrato', ['id' => $ev['id']])) ?>">Preparar el contrato</a></p>
<?php elseif ($actual['estado'] === 'reemplazada'): ?>
    <p class="aviso aviso-error">Esta versión fue reemplazada por una más nueva. <a href="<?= e(url('cotizacion', ['id' => $ev['id']])) ?>">Ver la última</a></p>
<?php elseif ($actual['estado'] === 'rechazada'): ?>
    <p class="aviso aviso-error">El cliente no aceptó esta cotización. Si pide cambios, crea una nueva versión.</p>
<?php endif; ?>

<?php if ($editable): ?>
<form method="post" action="<?= e(url('cotizacion', ['id' => $ev['id'], 'accion' => 'guardar'])) ?>" class="doc-editor" id="editor"
      data-doc-editor data-iva="<?= e($actual['iva_pct']) ?>" data-invitados="<?= (int) $actual['invitados'] ?>" data-dueno="<?= $dueno ? 1 : 0 ?>">
    <?= campo_csrf() ?>
    <input type="hidden" name="cotizacion_id" value="<?= (int) $actual['id'] ?>">

    <section class="bloque">
        <div class="fila-campos doc-cabecera-cot">
            <div class="campo"><label for="cinv">Invitados</label>
                <input type="number" id="cinv" name="invitados" min="1" max="5000" value="<?= (int) $actual['invitados'] ?>" required data-doc-invitados>
                <small>Las líneas "por persona" se recalculan solas.</small></div>
            <div class="campo"><label for="cval">Válida hasta</label>
                <input type="date" id="cval" name="valida_hasta" value="<?= e($actual['valida_hasta']) ?>" required></div>
        </div>
    </section>

    <section class="bloque">
        <h2>Qué incluye</h2>
        <div class="doc-lineas" data-doc-lineas>
            <?php foreach ($actual['items'] as $i) echo $lineaHtml('i' . (int) $i['id'], $i); ?>
            <?= $lineaHtml('n0', ['categoria' => 'otros', 'unidad' => 'evento', 'cantidad' => 1, 'precio_unitario' => 0, 'con_iva' => true, 'total' => 0, 'costo_unitario' => 0]) ?>
        </div>
        <template data-doc-plantilla-linea><?= $lineaHtml('__K__', ['categoria' => 'otros', 'unidad' => 'evento', 'cantidad' => 1, 'precio_unitario' => 0, 'con_iva' => true, 'total' => 0, 'costo_unitario' => 0]) ?></template>
        <p class="suave doc-ayuda-linea">La última fila es una <strong>línea libre</strong>: escribe ahí cualquier cosa que quieras cobrar. Las filas sin descripción no se guardan.</p>

        <div class="doc-agregar">
            <h3>Agregar del catálogo</h3>
            <div class="doc-buscador" data-doc-buscador data-catalogo='<?= e(json_encode($catalogo, JSON_UNESCAPED_UNICODE)) ?>' hidden>
                <div class="doc-filtros-fila">
                    <input type="search" placeholder="Buscar: menú, DJ, sillas…" aria-label="Buscar en el catálogo" data-doc-buscar>
                    <select aria-label="Categoría" data-doc-buscar-cat>
                        <option value="">Todas</option>
                        <?php foreach ($cats as $ck => $cv): ?><option value="<?= e($ck) ?>"><?= e($cv) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <ul class="doc-resultados" data-doc-resultados aria-live="polite"></ul>
            </div>
            <div class="doc-sin-js" data-doc-sin-js>
                <div class="doc-filtros-fila">
                    <select name="producto_agregar" aria-label="Producto del catálogo">
                        <option value="">— Elegir producto —</option>
                        <?php $porCat = []; foreach ($catalogo as $p) $porCat[$p['categoria']][] = $p;
                        foreach ($porCat as $ck => $lista): ?>
                            <optgroup label="<?= e($cats[$ck] ?? $ck) ?>">
                                <?php foreach ($lista as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['nombre']) ?> · <?= e(dinero($p['precio'])) ?></option><?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                    <button class="boton boton-claro" type="submit" name="despues" value="producto">Agregar</button>
                </div>
            </div>
            <?php if ($paquetes): ?>
            <details class="doc-agregar-paq">
                <summary>Agregar un paquete completo</summary>
                <div class="doc-filtros-fila" style="margin-top:8px">
                    <select name="paquete_agregar" aria-label="Paquete">
                        <?php foreach ($paquetes as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['nombre']) ?></option><?php endforeach; ?>
                    </select>
                    <button class="boton boton-claro" type="submit" name="despues" value="paquete">Agregar paquete</button>
                </div>
            </details>
            <?php endif; ?>
        </div>
    </section>

    <div class="rejilla rejilla-2 doc-editor-pie">
        <section class="bloque">
            <div class="campo"><label for="cdes">Descuento <small>($, antes del IVA)</small></label>
                <input type="text" inputmode="decimal" id="cdes" name="descuento" value="<?= e($dec($actual['descuento'])) ?>" data-doc-descuento></div>
            <div class="campo"><label for="cnot">Notas para el cliente</label>
                <textarea id="cnot" name="notas" rows="3" maxlength="2000" placeholder="Ej.: Incluye degustación para 4 personas."><?= e($actual['notas']) ?></textarea></div>
            <div class="campo"><label for="ccon">Condiciones</label>
                <textarea id="ccon" name="condiciones" rows="5" maxlength="4000"><?= e($actual['condiciones']) ?></textarea>
                <small>Las condiciones de siempre se cambian en Configuración.</small></div>
        </section>
        <section class="bloque doc-totales-vivo" aria-live="polite">
            <h2>Totales</h2>
            <dl class="doc-totales">
                <dt>Subtotal</dt><dd data-doc-t="subtotal"><?= dinero($actual['subtotal']) ?></dd>
                <dt>Descuento</dt><dd data-doc-t="descuento">−<?= dinero($actual['descuento']) ?></dd>
                <dt>Base IVA <?= e($num($actual['iva_pct'])) ?> %</dt><dd data-doc-t="base_iva"><?= dinero($actual['base_iva']) ?></dd>
                <dt>Base 0 %</dt><dd data-doc-t="base_cero"><?= dinero($actual['base_cero']) ?></dd>
                <dt>IVA <?= e($num($actual['iva_pct'])) ?> %</dt><dd data-doc-t="iva"><?= dinero($actual['iva']) ?></dd>
                <dt class="doc-total">Total</dt><dd class="doc-total" data-doc-t="total"><?= dinero($actual['total']) ?></dd>
                <dt class="doc-por-persona">Por persona</dt><dd class="doc-por-persona" data-doc-t="por_persona"><?= dinero($actual['por_persona']) ?></dd>
                <?php if ($dueno):
                    $neto = (float) $actual['subtotal'] - (float) $actual['descuento']; ?>
                <dt class="doc-ganancia">Ganancia <small>(solo tú)</small></dt><dd class="doc-ganancia" data-doc-t="ganancia"><?= dinero($actual['ganancia']) ?></dd>
                <dt class="doc-ganancia">Margen</dt><dd class="doc-ganancia" data-doc-t="margen"><?= $neto > 0 ? e(number_format($actual['ganancia'] * 100 / $neto, 1, ',', '.')) . ' %' : '—' ?></dd>
                <?php endif; ?>
            </dl>
            <p class="doc-sin-guardar" data-doc-sin-guardar hidden>Tienes cambios sin guardar.</p>
            <div class="doc-botones-editor">
                <button class="boton boton-ancho" type="submit" name="despues" value="">Guardar</button>
                <?php if ($actual['estado'] === 'borrador'): ?>
                    <button class="boton boton-wa boton-ancho" type="submit" name="despues" value="whatsapp">Guardar y enviar por WhatsApp</button>
                    <button class="boton boton-claro boton-ancho" type="submit" name="despues" value="enviar">Guardar y marcar como enviada</button>
                <?php endif; ?>
            </div>
        </section>
    </div>
</form>
<?php else: ?>
<section class="bloque">
    <h2>Qué incluye</h2>
    <?php $c = $actual; $verCostos = $dueno; require RAIZ . '/app/vistas/cotizacion_detalle.php'; ?>
    <?php if (trim((string) $actual['notas']) !== ''): ?><h3>Notas</h3><p class="doc-texto"><?= nl2br(e($actual['notas'])) ?></p><?php endif; ?>
    <?php if (trim((string) $actual['condiciones']) !== ''): ?><h3>Condiciones</h3><p class="doc-texto"><?= nl2br(e($actual['condiciones'])) ?></p><?php endif; ?>
</section>
<?php endif; ?>

<?php if ($puede && in_array($actual['estado'], ['borrador', 'enviada'], true)): ?>
<section class="bloque doc-enlace-cliente">
    <h2>Enlace para el cliente</h2>
    <p class="suave">El cliente abre este enlace en su celular: ve la cotización con tu logo, la descarga en PDF y la acepta con su nombre.
        <?= $actual['estado'] === 'borrador' ? '<strong>Al mandarlo, márcala como enviada.</strong>' : '' ?></p>
    <div class="doc-enlace">
        <input type="text" readonly value="<?= e($enlace) ?>" aria-label="Enlace de la cotización" onclick="this.select()">
        <button type="button" class="boton boton-claro" data-copiar="<?= e($enlace) ?>">Copiar enlace</button>
        <?php if ($wa): ?>
            <a class="boton boton-wa" href="<?= e($wa) ?>" target="_blank" rel="noopener">Enviar por WhatsApp</a>
        <?php else: ?>
            <small>El cliente no tiene celular guardado. <a href="<?= e(url('evento_editar', ['id' => $ev['id']])) ?>">Agregarlo</a></small>
        <?php endif; ?>
        <a class="boton boton-claro" href="<?= e($enlace) ?>" target="_blank" rel="noopener">Ver como el cliente</a>
    </div>
</section>

<div class="rejilla rejilla-2">
    <details class="bloque doc-desplegar">
        <summary><strong>El cliente aceptó</strong> <small>— por teléfono, en persona o por WhatsApp</small></summary>
        <form method="post" action="<?= e(url('cotizacion', ['id' => $ev['id'], 'accion' => 'aceptar'])) ?>">
            <?= campo_csrf() ?><input type="hidden" name="cotizacion_id" value="<?= (int) $actual['id'] ?>">
            <p class="suave">Se guarda como aceptada, el valor pasa al evento (<?= dinero($actual['total']) ?>) y se arma el plan de pagos. Las otras versiones quedan reemplazadas.</p>
            <div class="campo"><label for="anom">Nombre de quien acepta</label>
                <input type="text" id="anom" name="nombre" required maxlength="150" value="<?= e($ev['cliente']) ?>"></div>
            <label class="opcion" style="margin-bottom:12px"><input type="checkbox" name="confirmo" value="1" required>
                <span><strong>Confirmo que el cliente aceptó esta cotización</strong></span></label>
            <button class="boton" type="submit">Aceptar en nombre del cliente</button>
        </form>
    </details>
    <details class="bloque doc-desplegar">
        <summary><strong>El cliente no la aceptó</strong></summary>
        <form method="post" action="<?= e(url('cotizacion', ['id' => $ev['id'], 'accion' => 'rechazar'])) ?>">
            <?= campo_csrf() ?><input type="hidden" name="cotizacion_id" value="<?= (int) $actual['id'] ?>">
            <div class="campo"><label for="rmot">¿Por qué? <small>(opcional, te sirve para vender mejor)</small></label>
                <input type="text" id="rmot" name="motivo" maxlength="200" placeholder="Ej.: Le pareció caro, eligió otro local"></div>
            <button class="boton boton-peligro" type="submit" data-confirmar="¿Marcar esta cotización como rechazada? El cliente ya no podrá aceptarla desde su enlace.">Marcar como rechazada</button>
        </form>
    </details>
</div>
<?php endif; ?>

<?php if ($puede && !in_array($ev['estado'], ['perdido', 'cancelado', 'realizado'], true) && $actual['estado'] !== 'aceptada'): ?>
<details class="bloque doc-desplegar" <?= in_array($actual['estado'], ['rechazada', 'vencida', 'reemplazada'], true) ? 'open' : '' ?>>
    <summary><strong>Nueva versión</strong> <small>— si el cliente pide cambios. La actual queda reemplazada y su enlace se lo avisa.</small></summary>
    <?= $formCrear('Nueva versión de la cotización', true) ?>
</details>
<?php elseif ($puede && $actual['estado'] === 'aceptada'): ?>
<details class="bloque doc-desplegar">
    <summary><strong>¿El cliente pidió cambios después de aceptar?</strong></summary>
    <p class="suave">Crea una nueva versión, envíala y, cuando la acepte, el valor del evento y el plan de pagos se actualizan solos. Si ya hay contrato, prepara uno nuevo.</p>
    <?= $formCrear('Nueva versión de la cotización', true) ?>
</details>
<?php endif; ?>

<?php if ($comparar && $todas): ?>
<section class="bloque" id="comparar">
    <h2>Comparar versiones</h2>
    <div class="tabla-scroll">
        <table class="tabla doc-comparar">
            <thead><tr><th></th><?php foreach ($todas as $v): ?><th class="num<?= (int) $v['id'] === (int) $actual['id'] ? ' doc-col-actual' : '' ?>">Versión <?= (int) $v['version'] ?><br><small><?= e(fecha_corta($v['fecha'], false)) ?> · <?= e($estados[$v['estado']] ?? '') ?></small></th><?php endforeach; ?></tr></thead>
            <tbody>
            <?php foreach (['invitados' => 'Invitados', 'lineas' => 'Productos', 'subtotal' => 'Subtotal', 'descuento' => 'Descuento', 'iva' => 'IVA', 'total' => 'Total', 'por_persona' => 'Por persona'] + ($dueno ? ['ganancia' => 'Ganancia'] : []) as $k => $t): ?>
                <tr class="<?= $k === 'total' ? 'fila-total' : '' ?>"><th scope="row"><?= e($t) ?></th>
                <?php $prev = null; foreach ($todas as $v):
                    $val = $k === 'lineas' ? count($v['items']) : ($k === 'invitados' ? (int) $v['invitados'] : (float) $v[$k]);
                    $dif = $prev !== null && $val != $prev ? ($val > $prev ? 'doc-sube' : 'doc-baja') : ''; ?>
                    <td class="num <?= $dif ?><?= (int) $v['id'] === (int) $actual['id'] ? ' doc-col-actual' : '' ?>"><?= in_array($k, ['invitados', 'lineas'], true) ? (int) $val : dinero($val) ?></td>
                <?php $prev = $val; endforeach; ?></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="suave"><span class="doc-sube">▲</span> subió · <span class="doc-baja">▼</span> bajó respecto a la versión anterior.</p>
</section>
<?php endif; ?>

<section class="bloque">
    <h2>Versiones</h2>
    <ul class="filas">
        <?php foreach ($versiones as $v): ?>
        <li class="<?= (int) $v['id'] === (int) $actual['id'] ? 'doc-fila-actual' : '' ?>">
            <div>
                <a href="<?= e(url('cotizacion', ['id' => $ev['id'], 'c' => $v['id']])) ?>"><strong>Versión <?= (int) $v['version'] ?></strong></a>
                <span class="doc-estado doc-estado-<?= e($v['estado']) ?>"><?= e($estados[$v['estado']] ?? $v['estado']) ?></span>
                <br><small>N.º <?= (int) $v['numero'] ?> · <?= e(fecha_corta($v['fecha'])) ?> · <?= (int) $v['invitados'] ?> invitados</small>
            </div>
            <div class="derecha"><span class="monto"><?= dinero($v['total']) ?></span><br>
                <a class="enlace-chico" href="<?= e(url('pdf', ['tipo' => 'cotizacion', 'id' => $v['id']])) ?>" target="_blank" rel="noopener">PDF</a></div>
        </li>
        <?php endforeach; ?>
    </ul>
</section>
