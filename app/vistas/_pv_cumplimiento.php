<?php
use TuRenta\{Compras, ProveedoresFicha};

/**
 * Dentro de la tarjeta de un proveedor del evento (día del evento → Proveedores):
 * su compra (si se ve el dinero), la llegada real con la puntualidad y la evaluación después del evento.
 * Usa $p (fila de evento_proveedores), $ev, $editable, $accion, $comprasEv, $hoyTxt, $toleranciaProv (minutos del negocio).
 */
$compraP = $p['compra_id'] ? ($comprasEv[(int) $p['compra_id']] ?? null) : null;
$puP = ProveedoresFicha::puntualidad($p + ['fecha' => $ev['fecha'], 'hora_inicio' => $ev['hora_inicio']], $toleranciaProv ?? null);
$diaLlego = $ev['fecha'] <= $hoyTxt;
$nombresCal = [5 => 'Excelente', 4 => 'Muy bueno', 3 => 'Bueno', 2 => 'Regular', 1 => 'Malo'];
?>
<div class="pv-cumple">
    <?php if ($compraP): ?>
        <p class="pv-cumple-compra"><a href="<?= e(url('compra', ['id' => $compraP['id']])) ?>"><?= e($compraP['codigo']) ?></a> · <?= e(Compras::ESTADOS[$compraP['estado']]) ?> · <?= dinero($compraP['base']) ?>
            <?php if (in_array($compraP['estado'], Compras::ACTIVAS, true)): ?> · <?= $compraP['saldo'] > 0 ? 'saldo <strong>' . dinero($compraP['saldo']) . '</strong>' : '<span class="dia-ok-texto">pagada</span>' ?><?php endif; ?></p>
    <?php endif; ?>
    <?php if ($p['llegada_real']): ?>
        <p class="pv-cumple-llegada"><span class="pv-chip <?= $puP && $puP['estado'] === 'a_tiempo' ? 'pv-chip-verde' : ($puP ? 'pv-chip-rojo' : 'pv-chip-suave') ?>">Llegó <?= e(substr((string) $p['llegada_real'], 11, 5)) ?><?= $puP ? ' · ' . e(mb_strtolower($puP['texto'])) : '' ?></span>
            <?php if ($p['llegada_origen'] === 'proveedor'): ?><small class="suave">(lo marcó el proveedor)</small><?php endif; ?>
            <?php if ($editable): ?><form method="post" class="dia-en-linea"><?= campo_csrf() ?><?= $accion('prov_sin_llegada') ?><input type="hidden" name="prov_id" value="<?= (int) $p['id'] ?>">
                <button class="boton-enlace" type="submit" data-confirmar="¿Borrar la hora de llegada?">Borrar</button></form><?php endif; ?></p>
    <?php elseif ($diaLlego && $editable && $p['cumplimiento'] !== 'no_llego'): ?>
        <form method="post" class="pv-llegada-form"><?= campo_csrf() ?><?= $accion('prov_llegada') ?><input type="hidden" name="prov_id" value="<?= (int) $p['id'] ?>">
            <button class="boton boton-chico" type="submit" name="ahora" value="1">Llegó ahora</button>
            <label class="sr-only" for="hr<?= (int) $p['id'] ?>">Hora en que llegó</label>
            <input type="time" id="hr<?= (int) $p['id'] ?>" name="hora_real" class="pv-hora-real">
            <button class="boton boton-claro boton-chico" type="submit">Llegó a esa hora</button>
        </form>
    <?php endif; ?>
    <?php if ($diaLlego): ?>
        <?php if ($p['cumplimiento'] || $p['calificacion']): ?>
            <p class="pv-cumple-eval"><?php if ($p['cumplimiento']): ?><span class="pv-chip <?= $p['cumplimiento'] === 'cumplio' ? 'pv-chip-verde' : ($p['cumplimiento'] === 'observaciones' ? 'pv-chip-amarillo' : 'pv-chip-rojo') ?>"><?= e(ProveedoresFicha::CUMPLIMIENTO[$p['cumplimiento']]) ?></span><?php endif; ?>
                <?php if ($p['calificacion']): ?><span class="pv-estrellas" aria-label="<?= (int) $p['calificacion'] ?> de 5"><?= str_repeat('★', (int) $p['calificacion']) ?><span class="pv-estrellas-vacias"><?= str_repeat('★', 5 - (int) $p['calificacion']) ?></span></span><?php endif; ?>
                <?php if ($p['comentario']): ?><br><small class="suave">“<?= e($p['comentario']) ?>”</small><?php endif; ?></p>
        <?php endif; ?>
        <?php if ($editable): ?>
        <details class="dia-editar"><summary><?= $p['cumplimiento'] || $p['calificacion'] ? 'Cambiar evaluación' : '¿Cómo cumplió? Evaluar' ?></summary>
            <form method="post" class="dia-form-bloque"><?= campo_csrf() ?><?= $accion('prov_evaluar') ?><input type="hidden" name="prov_id" value="<?= (int) $p['id'] ?>">
                <div class="fila-campos">
                    <div class="campo"><label for="cu<?= (int) $p['id'] ?>">¿Cumplió?</label>
                        <select id="cu<?= (int) $p['id'] ?>" name="cumplimiento"><option value="">—</option>
                            <?php foreach (ProveedoresFicha::CUMPLIMIENTO as $k => $t): ?><option value="<?= e($k) ?>" <?= $p['cumplimiento'] === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
                    <div class="campo"><label for="ca<?= (int) $p['id'] ?>">Calificación</label>
                        <select id="ca<?= (int) $p['id'] ?>" name="calificacion"><option value="">—</option>
                            <?php foreach ($nombresCal as $k => $t): ?><option value="<?= $k ?>" <?= (int) $p['calificacion'] === $k ? 'selected' : '' ?>><?= str_repeat('★', $k) ?> <?= e($t) ?></option><?php endforeach; ?></select></div>
                </div>
                <div class="campo"><label for="cm<?= (int) $p['id'] ?>">Comentario <small>(opcional)</small></label><input type="text" id="cm<?= (int) $p['id'] ?>" name="comentario" value="<?= e($p['comentario']) ?>" maxlength="500" placeholder="Buena música, llegó un poco tarde"></div>
                <button class="boton boton-chico" type="submit">Guardar evaluación</button>
            </form>
        </details>
        <?php endif; ?>
    <?php endif; ?>
    <?php if ($editable): ?><a class="enlace-chico" href="<?= e(url('evento_dia', ['id' => $ev['id'], 'ver' => 'incidencias', 'proveedor' => (int) $p['proveedor_id']])) ?>#nueva">Reportar un problema</a><?php endif; ?>
</div>
