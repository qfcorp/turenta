<?php
use TuRenta\{Cotizaciones, Eventos};

/** @var array $paquetes @var array $productos @var ?array $editar @var ?array $sim @var array $resumen @var bool $puede @var bool $dueno */
$cats = Cotizaciones::CATEGORIAS;
$unis = Cotizaciones::UNIDADES;
$porCat = [];
foreach ($productos as $p) $porCat[$p['categoria']][] = $p;
$opcionesProductos = function (?int $elegido) use ($porCat, $cats): string {
    $h = '<option value="">— Elegir producto —</option>';
    foreach ($porCat as $c => $lista) {
        $h .= '<optgroup label="' . e($cats[$c] ?? $c) . '">';
        foreach ($lista as $p) {
            $h .= '<option value="' . (int) $p['id'] . '" data-precio="' . e($p['precio']) . '" data-unidad="' . e($p['unidad']) . '" data-iva="' . ($p['con_iva'] ? 1 : 0) . '"'
                . ($elegido === (int) $p['id'] ? ' selected' : '') . '>' . e($p['nombre']) . ' · ' . e(dinero($p['precio'])) . ' ' . e(Cotizaciones::UNIDADES[$p['unidad']]) . '</option>';
        }
        $h .= '</optgroup>';
    }
    return $h;
};
$filaItem = function (int $i, ?array $it) use ($opcionesProductos): string {
    ob_start(); ?>
    <tr class="doc-fila-item" data-doc-paq-fila>
        <td><select name="items[<?= $i ?>][producto_id]" aria-label="Producto" data-doc-paq-prod><?= $opcionesProductos(isset($it['producto_id']) ? (int) $it['producto_id'] : null) ?></select></td>
        <td><input type="text" inputmode="decimal" name="items[<?= $i ?>][cantidad]" value="<?= e(isset($it['cantidad']) ? rtrim(rtrim(number_format((float) str_replace(',', '.', (string) $it['cantidad']), 2, ',', ''), '0'), ',') : '1') ?>" aria-label="Cantidad" data-doc-paq-cant></td>
        <td><input type="text" inputmode="decimal" name="items[<?= $i ?>][precio]" value="<?= e(isset($it['precio_paquete']) && $it['precio_paquete'] !== null && $it['precio_paquete'] !== '' ? number_format((float) str_replace(',', '.', (string) $it['precio_paquete']), 2, ',', '') : '') ?>" placeholder="Normal" aria-label="Precio especial" data-doc-paq-precio></td>
        <td class="num" data-doc-paq-total>—</td>
        <td><button type="button" class="boton boton-peligro boton-chico" data-doc-quitar-fila aria-label="Quitar producto">✕</button></td>
    </tr>
    <?php return ob_get_clean();
};
?>
<div class="cabecera">
    <div>
        <h1>Paquetes</h1>
        <p>Junta productos del catálogo en paquetes listos (Boda Oro, Quince años…). Al cotizar, se cargan con un toque.</p>
    </div>
    <?php if ($puede && $editar === null): ?>
    <div class="acciones">
        <a class="boton" href="<?= e(url('paquetes', ['nuevo' => 1])) ?>#form-paquete">+ Crear paquete</a>
        <a class="boton boton-claro" href="<?= e(url('catalogo')) ?>">Catálogo</a>
    </div>
    <?php endif; ?>
</div>

<?php if ($editar !== null): ?>
<form method="post" action="<?= e(url('paquetes', ['accion' => 'guardar'])) ?>" class="bloque" id="form-paquete"
      data-doc-paquete data-iva="<?= e($ivaPct) ?>">
    <?= campo_csrf() ?>
    <?php if (!empty($editar['id'])): ?><input type="hidden" name="paquete_id" value="<?= (int) $editar['id'] ?>"><?php endif; ?>
    <h2><?= !empty($editar['id']) ? 'Editar paquete' : 'Nuevo paquete' ?></h2>
    <?php if (!$productos): ?>
        <p class="vacio">Primero agrega productos en el <a href="<?= e(url('catalogo')) ?>">catálogo</a>.</p>
    <?php else: ?>
    <div class="rejilla-form">
        <div class="campo ancho"><label for="qnom">Nombre del paquete</label>
            <input type="text" id="qnom" name="nombre" maxlength="120" required value="<?= e($editar['nombre']) ?>" placeholder="Ej.: Paquete Boda Oro"></div>
        <div class="campo"><label for="qtipo">Para qué tipo de evento</label>
            <select id="qtipo" name="tipo_evento">
                <option value="">Cualquier evento</option>
                <?php foreach (Eventos::TIPOS as $k => $v): ?><option value="<?= e($k) ?>" <?= ($editar['tipo_evento'] ?? '') === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
            </select></div>
        <div class="campo"><label for="qmin">Mínimo de invitados</label>
            <input type="number" id="qmin" name="minimo_invitados" min="0" max="5000" value="<?= (int) $editar['minimo_invitados'] ?>" data-doc-paq-min>
            <small>Si el evento tiene menos, se cobra como si fueran este número. 0 = sin mínimo.</small></div>
        <div class="campo ancho"><label for="qdes">Qué incluye <small>(sale en la cotización)</small></label>
            <textarea id="qdes" name="descripcion" rows="2" maxlength="800" placeholder="Ej.: Salón, menú tres tiempos, barra libre, DJ y decoración."><?= e($editar['descripcion'] ?? '') ?></textarea></div>
    </div>

    <h3>Productos del paquete</h3>
    <p class="suave" style="font-size:.9rem">Lo que es <strong>por persona</strong> se multiplica por los invitados. Deja el precio especial vacío para usar el precio normal del catálogo.</p>
    <div class="tabla-scroll">
        <table class="tabla doc-tabla-items">
            <thead><tr><th>Producto</th><th style="width:90px">Cantidad</th><th style="width:120px">Precio especial</th><th class="num" style="width:110px">Total*</th><th style="width:48px"></th></tr></thead>
            <tbody data-doc-paq-filas>
                <?php $i = 0; foreach ($editar['items'] as $it) echo $filaItem($i++, $it); ?>
                <?php for ($k = 0; $k < (count($editar['items']) ? 1 : 3); $k++) echo $filaItem($i++, null); ?>
            </tbody>
        </table>
    </div>
    <template data-doc-paq-plantilla><?= $filaItem(999, null) ?></template>
    <div class="acciones" style="margin:10px 0 14px">
        <button type="button" class="boton boton-claro boton-chico" data-doc-agregar-fila>+ Agregar otro producto</button>
    </div>
    <div class="doc-sim-vivo" aria-live="polite">
        <label for="qsimn">Calcular para</label>
        <input type="number" id="qsimn" min="1" max="5000" value="<?= max(100, (int) $editar['minimo_invitados']) ?>" data-doc-paq-n> invitados:
        <strong data-doc-paq-resumen>—</strong>
    </div>
    <small class="suave">* Total para el número de invitados de arriba, sin IVA.</small>
    <div class="acciones" style="margin-top:14px">
        <button class="boton" type="submit"><?= !empty($editar['id']) ? 'Guardar paquete' : 'Crear paquete' ?></button>
        <a class="boton boton-claro" href="<?= e(url('paquetes')) ?>">Cancelar</a>
    </div>
    <?php endif; ?>
</form>
<?php endif; ?>

<?php if ($sim): $sp = $sim['paquete']; ?>
<section class="bloque doc-simulador" id="simulador">
    <div class="doc-sim-cabeza">
        <div>
            <h2>¿Cuánto sale el <?= e($sp['nombre']) ?>?</h2>
            <p class="suave">Respuesta rápida para el cliente que pregunta por WhatsApp.</p>
        </div>
        <form method="get" class="doc-sim-form">
            <input type="hidden" name="r" value="paquetes"><input type="hidden" name="sim" value="<?= (int) $sp['id'] ?>">
            <label for="simn">Invitados</label>
            <input type="number" id="simn" name="n" min="1" max="5000" value="<?= (int) $sim['invitados'] ?>">
            <button class="boton boton-chico" type="submit">Calcular</button>
        </form>
    </div>
    <?php if ($sim['bajo_minimo']): ?>
        <p class="aviso aviso-error">Este paquete es desde <?= (int) $sp['minimo_invitados'] ?> invitados: se calcula como si fueran <?= (int) $sim['invitados_calculo'] ?>.</p>
    <?php endif; ?>
    <div class="cifras">
        <div class="cifra-tarjeta"><small>Total con IVA</small><strong><?= dinero($sim['total']) ?></strong></div>
        <div class="cifra-tarjeta"><small>Por persona</small><strong><?= dinero($sim['por_persona']) ?></strong></div>
        <div class="cifra-tarjeta"><small>Subtotal</small><strong><?= dinero($sim['subtotal']) ?></strong></div>
        <div class="cifra-tarjeta"><small>IVA <?= e(rtrim(rtrim(number_format($sim['iva_pct'], 2, ',', ''), '0'), ',')) ?> %</small><strong><?= dinero($sim['iva']) ?></strong></div>
        <?php if ($dueno): ?><div class="cifra-tarjeta bien"><small>Ganancia estimada</small><strong><?= dinero($sim['ganancia']) ?></strong></div><?php endif; ?>
    </div>
    <details>
        <summary>Ver el detalle (<?= count($sim['lineas']) ?> productos)</summary>
        <div class="tabla-scroll"><table class="tabla">
            <thead><tr><th>Producto</th><th class="num">Cantidad</th><th class="num">Precio</th><th class="num">Total</th></tr></thead>
            <tbody>
            <?php foreach ($sim['lineas'] as $l): ?>
                <tr><td><?= e($l['nombre']) ?><?= $l['con_iva'] ? '' : ' <small>(0 % IVA)</small>' ?></td>
                    <td class="num"><?= e(rtrim(rtrim(number_format($l['cantidad'], 2, ',', '.'), '0'), ',')) ?></td>
                    <td class="num"><?= dinero($l['precio']) ?></td><td class="num"><?= dinero($l['total']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </details>
    <?php
    $msg = $sp['nombre'] . ' para ' . (int) $sim['invitados_calculo'] . ' invitados: ' . dinero($sim['total']) . ' con IVA (' . dinero($sim['por_persona']) . ' por persona).'
         . ($sp['descripcion'] ? ' Incluye: ' . $sp['descripcion'] : '');
    ?>
    <div class="acciones" style="margin-top:12px">
        <button type="button" class="boton boton-claro boton-chico" data-copiar="<?= e($msg) ?>">Copiar respuesta para el cliente</button>
        <a class="boton boton-claro boton-chico" href="<?= e(url('paquetes')) ?>">Cerrar</a>
    </div>
</section>
<?php endif; ?>

<?php if (!$paquetes): ?>
<section class="bloque vacio">
    <p><strong>Todavía no tienes paquetes.</strong><br>Un paquete junta varios productos con precio especial: el vendedor cotiza en un toque y el cliente entiende rápido qué incluye.</p>
    <?php if ($puede): ?><a class="boton" href="<?= e(url('paquetes', ['nuevo' => 1])) ?>#form-paquete">Crear el primero</a><?php endif; ?>
</section>
<?php else: ?>
<div class="doc-paquetes">
    <?php foreach ($paquetes as $p): $r = $resumen[(int) $p['id']] ?? null; ?>
    <article class="bloque doc-paquete<?= $p['activo'] ? '' : ' doc-inactivo' ?>">
        <div class="doc-paquete-cabeza">
            <h2><?= e($p['nombre']) ?></h2>
            <?php if (!$p['activo']): ?><span class="ev-estado ev-perdido">Desactivado</span><?php endif; ?>
        </div>
        <p class="suave doc-paquete-sub">
            <?= $p['tipo_evento'] ? e(Eventos::TIPOS[$p['tipo_evento']] ?? '') : 'Cualquier evento' ?>
            · <?= (int) $p['productos'] ?> <?= (int) $p['productos'] === 1 ? 'producto' : 'productos' ?>
            <?= (int) $p['minimo_invitados'] > 0 ? '· desde ' . (int) $p['minimo_invitados'] . ' invitados' : '' ?>
        </p>
        <?php if ($p['descripcion']): ?><p class="doc-paquete-desc"><?= e($p['descripcion']) ?></p><?php endif; ?>
        <?php if ($r): ?>
            <p class="doc-paquete-precio"><strong><?= dinero($r['por_persona']) ?></strong> por persona
                <small>· <?= dinero($r['total']) ?> para <?= (int) $r['invitados_calculo'] ?> invitados, con IVA</small></p>
        <?php endif; ?>
        <div class="acciones">
            <a class="boton boton-chico" href="<?= e(url('paquetes', ['sim' => $p['id']])) ?>#simulador">¿Cuánto sale?</a>
            <?php if ($puede): ?>
            <a class="boton boton-claro boton-chico" href="<?= e(url('paquetes', ['editar' => $p['id']])) ?>#form-paquete">Editar</a>
            <form method="post" action="<?= e(url('paquetes', ['accion' => 'duplicar'])) ?>">
                <?= campo_csrf() ?><input type="hidden" name="paquete_id" value="<?= (int) $p['id'] ?>">
                <button class="boton boton-claro boton-chico" type="submit">Duplicar</button>
            </form>
            <form method="post" action="<?= e(url('paquetes', ['accion' => $p['activo'] ? 'desactivar' : 'activar'])) ?>">
                <?= campo_csrf() ?><input type="hidden" name="paquete_id" value="<?= (int) $p['id'] ?>">
                <?php if ($p['activo']): ?>
                    <button class="boton boton-peligro boton-chico" type="submit" data-confirmar="¿Desactivar «<?= e($p['nombre']) ?>»? Ya no saldrá al cotizar (las cotizaciones hechas no cambian).">Desactivar</button>
                <?php else: ?>
                    <button class="boton boton-claro boton-chico" type="submit">Activar</button>
                <?php endif; ?>
            </form>
            <?php endif; ?>
        </div>
    </article>
    <?php endforeach; ?>
</div>
<?php endif; ?>
