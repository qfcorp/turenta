<?php /** @var array $lista @var array $a @var array $tabla @var array $registro @var array $catalogo @var array $planesModulos */ ?>
<div class="cabecera">
    <div><h1>Planes y precios</h1>
        <p>Cada plan es un precio base + los módulos que trae. Lo que cambies aquí se ve al instante en Mi plan de cada negocio y en los próximos pagos.</p></div>
</div>

<div class="rejilla rejilla-2">
    <?php foreach ($lista as $p): $c = e($p['codigo']); ?>
        <form method="post" class="bloque">
            <?= campo_csrf() ?><input type="hidden" name="accion" value="plan"><input type="hidden" name="codigo" value="<?= $c ?>">
            <h2>Plan <?= e($p['nombre']) ?></h2>
            <div class="fila-campos">
                <div class="campo"><label for="n-<?= $c ?>">Nombre</label>
                    <input type="text" id="n-<?= $c ?>" name="nombre" value="<?= e($p['nombre']) ?>" required></div>
                <div class="campo"><label for="p-<?= $c ?>">Precio al mes ($)</label>
                    <input type="number" id="p-<?= $c ?>" name="precio_mensual" value="<?= e($p['precio_mensual']) ?>" step="0.01" min="0" required></div>
            </div>
            <div class="fila-campos">
                <div class="campo"><label for="e-<?= $c ?>">Máximo de espacios <small>(vacío = sin límite)</small></label>
                    <input type="number" id="e-<?= $c ?>" name="max_espacios" value="<?= e($p['max_espacios'] ?? '') ?>" min="1"></div>
                <div class="campo"><label for="u-<?= $c ?>">Máximo de usuarios <small>(vacío = sin límite)</small></label>
                    <input type="number" id="u-<?= $c ?>" name="max_usuarios" value="<?= e($p['max_usuarios'] ?? '') ?>" min="1"></div>
            </div>
            <div class="fila-campos">
                <div class="campo"><label for="me-<?= $c ?>">Módulos que elige el cliente <small>(incluidos en el precio)</small></label>
                    <input type="number" id="me-<?= $c ?>" name="modulos_elegir" value="<?= (int) ($p['modulos_elegir'] ?? 0) ?>" min="0" max="50"></div>
                <div class="campo"><label for="mx-<?= $c ?>">Cada módulo más ($) <small>(vacío = precio del catálogo)</small></label>
                    <input type="number" id="mx-<?= $c ?>" name="precio_modulo_extra" value="<?= e($p['precio_modulo_extra'] ?? '') ?>" step="0.01" min="0"></div>
            </div>
            <div class="campo"><label for="d-<?= $c ?>">Descripción corta <small>(la ve el cliente en Mi plan: sin precios)</small></label>
                <input type="text" id="d-<?= $c ?>" name="descripcion" value="<?= e($p['descripcion'] ?? '') ?>" maxlength="300"></div>
            <table class="tabla">
                <thead><tr><th>Periodo</th><th class="der">Cobras</th><th class="der">Sale al mes</th></tr></thead>
                <tbody><?php foreach ($tabla[$p['codigo']] as $per => $q): ?>
                    <tr><td><?= e(planes()->textoPeriodo($per)) ?></td><td class="der"><strong><?= dinero($q['monto']) ?></strong></td><td class="der"><?= dinero($q['precio_por_mes']) ?></td></tr>
                <?php endforeach; ?></tbody>
            </table>
            <button class="boton boton-ancho" type="submit" style="margin-top:12px">Guardar plan <?= e($p['nombre']) ?></button>
        </form>
    <?php endforeach; ?>
</div>

<form method="post" class="bloque" id="catalogo">
    <?= campo_csrf() ?><input type="hidden" name="accion" value="catalogo">
    <h2>Catálogo de módulos</h2>
    <p class="g-nota">En el plan Básico el cliente elige sus módulos: los primeros van incluidos y cada uno más cuesta lo que diga el plan (arriba). El Asistente IA no cuenta entre los que elige: siempre se cobra con el precio de aquí.
        Precio al mes de cada módulo (lo que paga un cliente que lo agrega a su plan, o lo que se le descuenta si se lo quitas), la descripción que ve en Mi plan y el orden.</p>
    <div class="desliza"><table class="tabla adm-cat-tabla" style="min-width:640px">
        <thead><tr><th>Módulo</th><th style="width:110px">Precio al mes ($)</th><th>Descripción</th><th style="width:80px">Orden</th></tr></thead>
        <tbody><?php foreach ($catalogo as $m): $mid = e($m['id']); ?>
            <tr><td><strong><?= e($m['icono']) ?> <?= e($m['nombre']) ?></strong><br><small class="suave"><?= e($m['grupo_nombre']) ?><?= $m['depende_de'] ? ' · necesita ' . e(implode(', ', array_map([TuRenta\Modulos::class, 'nombre'], $m['depende_de']))) : '' ?></small></td>
                <td><input type="text" inputmode="decimal" name="precio[<?= $mid ?>]" value="<?= e(number_format($m['precio_mensual'], 2, '.', '')) ?>" aria-label="Precio de <?= e($m['nombre']) ?>" required></td>
                <td><input type="text" name="descripcion[<?= $mid ?>]" value="<?= e($m['descripcion']) ?>" maxlength="400" aria-label="Descripción de <?= e($m['nombre']) ?>"></td>
                <td><input type="number" name="orden[<?= $mid ?>]" value="<?= (int) $m['orden'] ?>" min="0" max="10000" aria-label="Orden de <?= e($m['nombre']) ?>"></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <h2 style="margin-top:18px">Qué módulos trae cada plan</h2>
    <div class="adm-cat-planes">
        <?php foreach ($planesModulos as $pm): $pc = e($pm['codigo']); ?>
            <fieldset class="adm-mod-grupo"><legend>Plan <?= e($pm['nombre']) ?> · <?= dinero($pm['precio_mensual']) ?></legend>
                <label class="adm-mod-item"><input type="checkbox" name="plan_todos[<?= $pc ?>]" value="1" <?= $pm['todos'] ? 'checked' : '' ?>>
                    <span class="adm-mod-nombre"><strong>Todos los módulos</strong><small>También los que se agreguen después</small></span></label>
                <?php foreach ($catalogo as $m): ?>
                    <label class="adm-mod-item"><input type="checkbox" name="plan_mod[<?= $pc ?>][<?= e($m['id']) ?>]" value="1" <?= in_array($m['id'], $pm['modulos'], true) ? 'checked' : '' ?>>
                        <span class="adm-mod-nombre"><?= e($m['icono']) ?> <?= e($m['nombre']) ?></span></label>
                <?php endforeach; ?>
            </fieldset>
        <?php endforeach; ?>
    </div>
    <button class="boton" type="submit" style="margin-top:12px" data-confirmar="¿Guardar el catálogo? Cambia lo que tienen y lo que pagan los clientes de cada plan.">Guardar catálogo y planes</button>
</form>

<form method="post" class="bloque">
    <?= campo_csrf() ?><input type="hidden" name="accion" value="ajustes">
    <h2>Demo, promociones y cobro</h2>
    <div class="rejilla rejilla-3" style="gap:12px">
        <div class="campo"><label for="dias_prueba">Días de demo</label>
            <input type="number" id="dias_prueba" name="dias_prueba" value="<?= e($a['dias_prueba']) ?>" min="0" max="90" required></div>
        <div class="campo"><label for="dias_gracia">Días de gracia al vencer</label>
            <input type="number" id="dias_gracia" name="dias_gracia" value="<?= e($a['dias_gracia']) ?>" min="0" max="30" required>
            <small>Después del vencimiento, sigue entrando estos días con un aviso.</small></div>
        <div class="campo"><label for="whatsapp_ventas">WhatsApp de ventas</label>
            <input type="tel" id="whatsapp_ventas" name="whatsapp_ventas" value="<?= e($a['whatsapp_ventas']) ?>" required>
            <small>Con código del país: 593…</small></div>
    </div>
    <div class="rejilla rejilla-2" style="gap:12px">
        <fieldset class="campo"><legend>Semestral</legend>
            <div class="fila-campos">
                <div class="campo"><label for="semestral_paga">Paga (meses)</label><input type="number" id="semestral_paga" name="semestral_paga" value="<?= e($a['semestral_paga']) ?>" min="1" max="6" required></div>
                <div class="campo"><label for="semestral_recibe">Recibe (meses)</label><input type="number" id="semestral_recibe" name="semestral_recibe" value="<?= e($a['semestral_recibe']) ?>" min="6" max="12" required></div>
            </div></fieldset>
        <fieldset class="campo"><legend>Anual (incluye dominio propio)</legend>
            <div class="fila-campos">
                <div class="campo"><label for="anual_paga">Paga (meses)</label><input type="number" id="anual_paga" name="anual_paga" value="<?= e($a['anual_paga']) ?>" min="1" max="12" required></div>
                <div class="campo"><label for="anual_recibe">Recibe (meses)</label><input type="number" id="anual_recibe" name="anual_recibe" value="<?= e($a['anual_recibe']) ?>" min="12" max="24" required></div>
            </div></fieldset>
    </div>
    <button class="boton" type="submit">Guardar ajustes</button>
</form>

<section class="bloque">
    <h2>Registro de acciones del panel</h2>
    <?php if (!$registro): ?><p class="vacio">Sin movimientos.</p><?php else: ?>
        <div class="desliza"><table class="tabla">
            <thead><tr><th>Cuándo</th><th>Quién</th><th>Acción</th><th>Negocio</th></tr></thead>
            <tbody><?php foreach ($registro as $m): ?>
                <tr><td style="white-space:nowrap"><?= date('d/m/Y H:i', strtotime($m['creado_en'])) ?></td><td><?= e($m['admin'] ?? '—') ?></td>
                    <td><?= e($m['accion']) ?></td><td><?= $m['negocio_id'] ? '<a href="' . e(url('admin_negocio', ['id' => $m['negocio_id']])) . '">' . e($m['negocio']) . '</a>' : '' ?></td></tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
</section>
