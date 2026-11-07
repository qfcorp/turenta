<?php
use TuRenta\Incidencias;

/**
 * Formulario para reportar una incidencia. Variables:
 *   $provsSel (lista de [id, nombre]), $conDinero, opcional: $eventoFijo (id del evento) o $eventosSel (lista para elegir),
 *   $comprasSel (compras con saldo, para el descuento), $volverA + $volverId (a dónde regresar), $pre (evento, proveedor preseleccionados).
 */
$eventoFijo ??= null;
$eventosSel ??= [];
$comprasSel ??= [];
$volverA ??= '';
$volverId ??= 0;
$pre ??= ['evento' => 0, 'proveedor' => 0];
?>
<form method="post" action="<?= e(url('incidencias')) ?>" enctype="multipart/form-data" class="bloque pv-form-incidencia" id="nueva"><?= campo_csrf() ?>
    <input type="hidden" name="accion" value="crear">
    <input type="hidden" name="volver" value="<?= e($volverA) ?>"><input type="hidden" name="volver_id" value="<?= (int) $volverId ?>">
    <h2>Reportar un problema</h2>
    <?php if ($eventoFijo): ?>
        <input type="hidden" name="evento_id" value="<?= (int) $eventoFijo ?>">
    <?php else: ?>
    <div class="campo"><label for="ievento">Evento <small>(opcional)</small></label>
        <select id="ievento" name="evento_id"><option value="0">— Sin evento —</option>
            <?php foreach ($eventosSel as $x): ?><option value="<?= (int) $x['id'] ?>" <?= (int) $pre['evento'] === (int) $x['id'] ? 'selected' : '' ?>><?= e(fecha_corta($x['fecha'])) ?> · <?= e($x['nombre']) ?></option><?php endforeach; ?>
        </select></div>
    <?php endif; ?>
    <div class="campo"><label for="idesc">¿Qué pasó?</label>
        <textarea id="idesc" name="descripcion" rows="3" required minlength="5" maxlength="3000" placeholder="El DJ llegó sin el micrófono inalámbrico"></textarea></div>
    <fieldset class="pv-gravedad"><legend>¿Qué tan grave?</legend>
        <?php foreach (Incidencias::GRAVEDADES as $k => $t): ?>
            <label class="pv-grav-op pv-grav-<?= e($k) ?>"><input type="radio" name="gravedad" value="<?= e($k) ?>" <?= $k === 'leve' ? 'checked' : '' ?>><span><?= e($t) ?></span></label>
        <?php endforeach; ?>
    </fieldset>
    <div class="fila-campos">
        <div class="campo"><label for="iarea">Área</label>
            <select id="iarea" name="area"><option value="">Según el proveedor</option>
                <?php foreach (Incidencias::AREAS as $k => $t): ?><option value="<?= e($k) ?>"><?= e($t) ?></option><?php endforeach; ?></select></div>
        <div class="campo"><label for="iprov">Proveedor <small>(si fue de un proveedor)</small></label>
            <select id="iprov" name="proveedor_id"><option value="0">— Ninguno —</option>
                <?php foreach ($provsSel as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) $pre['proveedor'] === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['nombre']) ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="fila-campos">
        <div class="campo"><label for="iresp">Quién lo resuelve <small>(opcional)</small></label><input type="text" id="iresp" name="responsable" maxlength="120" placeholder="Coordinación"></div>
        <div class="campo"><label for="ifoto">Foto <small>(opcional)</small></label><input type="file" id="ifoto" name="foto" accept="image/*" capture="environment"></div>
    </div>
    <?php if ($conDinero): ?>
    <details class="pv-detalles"><summary>Costo y descuento al proveedor</summary>
        <div class="fila-campos">
            <div class="campo"><label for="icosto">Costo del problema ($)</label><input type="text" id="icosto" name="costo" inputmode="decimal" placeholder="0"></div>
            <div class="campo"><label for="idesct">Descontar al proveedor ($)</label><input type="text" id="idesct" name="descuento" inputmode="decimal" placeholder="0"></div>
        </div>
        <div class="campo"><label for="icompra">De qué compra se descuenta</label>
            <select id="icompra" name="compra_id"><option value="0">— Ninguna —</option>
                <?php foreach ($comprasSel as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['codigo']) ?> · <?= e($c['proveedor']) ?> · saldo <?= e(dinero($c['saldo'])) ?></option><?php endforeach; ?></select></div>
    </details>
    <?php endif; ?>
    <button class="boton boton-ancho" type="submit">Guardar el problema</button>
</form>
