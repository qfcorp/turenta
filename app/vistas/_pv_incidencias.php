<?php
use TuRenta\{Compras, Incidencias};

/**
 * Lista de incidencias con su formulario para actualizar. Variables: $listaInc, $editable, $conDinero, $comprasSel,
 * opcional $volverA + $volverId, $ocultarEvento.
 */
$volverA ??= '';
$volverId ??= 0;
$ocultarEvento ??= false;
$comprasSel ??= [];
?>
<ul class="pv-incidencias">
    <?php foreach ($listaInc as $i): ?>
    <li class="pv-incidencia pv-inc-<?= e($i['gravedad']) ?> <?= $i['estado'] === 'resuelta' ? 'pv-inc-resuelta' : '' ?>" id="i<?= (int) $i['id'] ?>">
        <div class="pv-inc-cab">
            <span class="pv-chip pv-grav-<?= e($i['gravedad']) ?>"><?= e(Incidencias::GRAVEDADES[$i['gravedad']]) ?></span>
            <span class="pv-chip pv-chip-suave"><?= e(Incidencias::AREAS[$i['area']]) ?></span>
            <span class="pv-chip <?= $i['estado'] === 'resuelta' ? 'pv-chip-verde' : ($i['estado'] === 'en_proceso' ? 'pv-chip-amarillo' : 'pv-chip-rojo') ?>"><?= e(Incidencias::ESTADOS[$i['estado']]) ?></span>
        </div>
        <p class="pv-inc-texto"><?= nl2br(e($i['descripcion'])) ?></p>
        <small class="suave"><?= e(fecha_corta(substr((string) $i['creado_en'], 0, 10))) ?> <?= e(substr((string) $i['creado_en'], 11, 5)) ?> · reportó <?= e($i['reportado'] ?? $i['reportado_nombre'] ?? '—') ?>
            <?php if (!$ocultarEvento && $i['evento']): ?> · <a href="<?= e(url('evento', ['id' => $i['evento_id']])) ?>"><?= e($i['evento']) ?></a><?php endif; ?>
            <?php if ($i['proveedor']): ?> · <a href="<?= e(url('proveedor', ['id' => $i['proveedor_id']])) ?>"><?= e($i['proveedor']) ?></a><?php endif; ?>
            <?= $i['responsable'] ? ' · lo resuelve ' . e($i['responsable']) : '' ?>
            <?php if ($conDinero && (float) $i['costo'] > 0): ?> · costo <?= dinero($i['costo']) ?><?php endif; ?>
            <?php if ($conDinero && (float) $i['descuento'] > 0): ?> · descuento <?= dinero($i['descuento']) ?> en <a href="<?= e(url('compra', ['id' => $i['compra_id']])) ?>"><?= e(Compras::codigo($i['compra_numero'])) ?></a><?php endif; ?></small>
        <?php if ($i['resolucion']): ?><p class="pv-inc-resol">✓ <?= nl2br(e($i['resolucion'])) ?></p><?php endif; ?>
        <?php if ($i['foto']): ?><a class="enlace-chico" href="<?= e(url('proveedor_archivo', ['tipo' => 'incidencia', 'id' => $i['id']])) ?>" target="_blank" rel="noopener">Ver foto</a><?php endif; ?>
        <?php if ($editable): ?>
        <details class="pv-detalles"><summary><?= $i['estado'] === 'resuelta' ? 'Cambiar' : 'Actualizar o resolver' ?></summary>
            <form method="post" action="<?= e(url('incidencias')) ?>" class="dia-form-bloque"><?= campo_csrf() ?>
                <input type="hidden" name="accion" value="actualizar"><input type="hidden" name="incidencia_id" value="<?= (int) $i['id'] ?>">
                <input type="hidden" name="volver" value="<?= e($volverA) ?>"><input type="hidden" name="volver_id" value="<?= (int) $volverId ?>">
                <div class="fila-campos">
                    <div class="campo"><label for="ie<?= (int) $i['id'] ?>">Estado</label><select id="ie<?= (int) $i['id'] ?>" name="estado">
                        <?php foreach (Incidencias::ESTADOS as $k => $t): ?><option value="<?= e($k) ?>" <?= $i['estado'] === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
                    <div class="campo"><label for="ig<?= (int) $i['id'] ?>">Gravedad</label><select id="ig<?= (int) $i['id'] ?>" name="gravedad">
                        <?php foreach (Incidencias::GRAVEDADES as $k => $t): ?><option value="<?= e($k) ?>" <?= $i['gravedad'] === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
                </div>
                <div class="campo"><label for="ir<?= (int) $i['id'] ?>">Cómo se resolvió</label><textarea id="ir<?= (int) $i['id'] ?>" name="resolucion" rows="2" maxlength="3000"><?= e($i['resolucion']) ?></textarea></div>
                <div class="campo"><label for="ip<?= (int) $i['id'] ?>">Quién lo resuelve</label><input type="text" id="ip<?= (int) $i['id'] ?>" name="responsable" value="<?= e($i['responsable']) ?>" maxlength="120"></div>
                <?php if ($conDinero): ?>
                <div class="fila-campos">
                    <div class="campo"><label for="ic<?= (int) $i['id'] ?>">Costo ($)</label><input type="text" id="ic<?= (int) $i['id'] ?>" name="costo" inputmode="decimal" value="<?= (float) $i['costo'] > 0 ? e(number_format((float) $i['costo'], 2, ',', '')) : '' ?>"></div>
                    <div class="campo"><label for="id<?= (int) $i['id'] ?>">Descuento al proveedor ($)</label><input type="text" id="id<?= (int) $i['id'] ?>" name="descuento" inputmode="decimal" value="<?= (float) $i['descuento'] > 0 ? e(number_format((float) $i['descuento'], 2, ',', '')) : '' ?>"></div>
                </div>
                <div class="campo"><label for="ico<?= (int) $i['id'] ?>">Compra</label><select id="ico<?= (int) $i['id'] ?>" name="compra_id"><option value="0">— Ninguna —</option>
                    <?php $ya = false; foreach ($comprasSel as $c): if ($i['proveedor_id'] && (int) $c['proveedor_id'] !== (int) $i['proveedor_id']) continue; $ya = $ya || (int) $c['id'] === (int) $i['compra_id']; ?>
                        <option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === (int) $i['compra_id'] ? 'selected' : '' ?>><?= e($c['codigo']) ?> · <?= e($c['proveedor']) ?> · saldo <?= e(dinero($c['saldo'])) ?></option><?php endforeach; ?>
                    <?php if ($i['compra_id'] && !$ya): ?><option value="<?= (int) $i['compra_id'] ?>" selected><?= e(Compras::codigo($i['compra_numero'])) ?></option><?php endif; ?></select></div>
                <?php endif; ?>
                <button class="boton boton-chico" type="submit">Guardar</button>
            </form>
        </details>
        <?php endif; ?>
    </li>
    <?php endforeach; ?>
</ul>
