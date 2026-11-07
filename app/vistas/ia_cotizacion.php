<?php
use TuRenta\{Cotizaciones, Eventos, Ia};
/** Cotización a partir de un mensaje (Asistente IA). @var string $mensaje @var ?array $propuesta @var ?string $aviso @var ?string $error
 *  @var string $aMano @var array $catalogo */
$cat = $catalogo;
$enlazar = fn(string $t) => str_replace(e($aMano), '<a href="' . e($aMano) . '">' . e($aMano) . '</a>', e($t));
?>
<div class="cabecera">
    <div><h1>✨ Cotización desde un mensaje</h1>
        <p>Pegue el mensaje de WhatsApp del cliente y le proponemos el evento y la cotización con sus espacios, paquetes y productos. Usted revisa y guarda.</p></div>
    <a class="boton boton-claro" href="<?= e(url('evento_nuevo')) ?>">Hacerlo a mano</a>
</div>

<?php if ($error): ?><p class="aviso aviso-error" role="alert"><?= e($error) ?></p><?php endif; ?>
<?php if ($aviso): ?><p class="aviso aviso-alerta" role="status" id="ia-aviso"><?= $enlazar($aviso) ?></p><?php endif; ?>

<?php if (!$propuesta): ?>
<section class="bloque" aria-labelledby="ia-t-msj">
    <form method="post" action="<?= e(url('ia_cotizacion')) ?>" id="form-ia-leer">
        <?= campo_csrf() ?><input type="hidden" name="accion" value="leer">
        <div class="campo">
            <label for="mensaje" id="ia-t-msj"><strong>Mensaje del cliente</strong></label>
            <textarea id="mensaje" name="mensaje" rows="7" maxlength="<?= Ia::MAX_MENSAJE ?>" required
                      placeholder="Hola, quisiera cotizar una boda de 150 personas el 14 de febrero, con DJ y fotógrafo. Soy Ana Pérez, 0991234567."><?= e($mensaje) ?></textarea>
            <small>Copie el mensaje desde WhatsApp y péguelo aquí (hasta <?= Ia::MAX_MENSAJE ?> letras). No se envía nada al cliente.</small>
        </div>
        <button class="boton" type="submit">Proponer la cotización</button>
    </form>
</section>
<?php else:
    $p = $propuesta;
    $ev = $p['evento'];
    $usadas = [];
    foreach ($p['lineas'] as $l) $usadas[(int) $l['producto_id']] = $l;
    $subtotal = 0.0; ?>
<form method="post" action="<?= e(url('ia_cotizacion')) ?>" id="form-ia-borrador">
    <?= campo_csrf() ?><input type="hidden" name="accion" value="guardar">
    <input type="hidden" name="mensaje" value="<?= e($mensaje) ?>">
    <?php foreach ($p['dudas'] as $d): ?><input type="hidden" name="dudas[]" value="<?= e($d) ?>"><?php endforeach; ?>
    <p class="aviso aviso-alerta" role="status">Borrador: revise los datos antes de guardar. Nada se envía al cliente.</p>

    <?php if ($p['dudas']): ?>
        <section class="bloque"><h2>Falta preguntarle al cliente</h2>
            <ul><?php foreach ($p['dudas'] as $d): ?><li><?= e($d) ?></li><?php endforeach; ?></ul></section>
    <?php endif; ?>

    <section class="bloque" aria-labelledby="ia-t-cli">
        <h2 id="ia-t-cli">Cliente</h2>
        <div class="fila-campos">
            <div class="campo"><label for="ia-cli-nombre">Nombre</label>
                <input id="ia-cli-nombre" name="cliente_nombre" maxlength="120" required value="<?= e($p['cliente']['nombre']) ?>"></div>
            <div class="campo"><label for="ia-cli-tel">Celular</label>
                <input id="ia-cli-tel" name="cliente_telefono" maxlength="30" inputmode="tel" value="<?= e($p['cliente']['telefono']) ?>"></div>
            <div class="campo"><label for="ia-cli-email">Correo</label>
                <input id="ia-cli-email" name="cliente_email" type="email" maxlength="120" value="<?= e($p['cliente']['email']) ?>"></div>
        </div>
    </section>

    <section class="bloque" aria-labelledby="ia-t-ev">
        <h2 id="ia-t-ev">Evento</h2>
        <div class="fila-campos">
            <div class="campo"><label for="ia-tipo">Tipo</label>
                <select id="ia-tipo" name="tipo"><?php foreach (Eventos::TIPOS as $k => $t): ?><option value="<?= e($k) ?>" <?= $ev['tipo'] === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
            <div class="campo"><label for="ia-fecha">Fecha</label>
                <input id="ia-fecha" name="fecha" type="date" required value="<?= e($ev['fecha']) ?>">
                <?php if ($ev['fecha'] === ''): ?><small>El mensaje no dice la fecha: escríbala.</small><?php endif; ?></div>
            <div class="campo"><label for="ia-inv">Invitados</label>
                <input id="ia-inv" name="invitados" type="number" min="1" max="20000" required value="<?= $ev['invitados'] ?: '' ?>"></div>
        </div>
        <div class="fila-campos">
            <div class="campo"><label for="ia-hi">Desde</label><input id="ia-hi" name="hora_inicio" type="time" value="<?= e($ev['hora_inicio']) ?>"></div>
            <div class="campo"><label for="ia-hf">Hasta</label><input id="ia-hf" name="hora_fin" type="time" value="<?= e($ev['hora_fin']) ?>"></div>
            <div class="campo"><label for="ia-nombre">Nombre del evento <small>(opcional)</small></label>
                <input id="ia-nombre" name="nombre" maxlength="150" value="<?= e($ev['nombre'] ?? '') ?>" placeholder="Boda de Ana y Luis"></div>
        </div>
        <fieldset class="campo"><legend>Espacios</legend>
            <?php foreach ($cat['espacios'] as $es): ?>
                <label class="casilla"><input type="checkbox" name="espacios[]" value="<?= (int) $es['id'] ?>" <?= in_array((int) $es['id'], $ev['espacio_ids'], true) ? 'checked' : '' ?>>
                    <?= e($es['nombre']) ?> <small>(<?= (int) $es['capacidad_banquete'] ?> sentados · <?= dinero($es['precio_base']) ?>)</small></label>
            <?php endforeach; ?>
        </fieldset>
        <div class="campo"><label for="ia-notas">Notas (lo que pidió y no está en su catálogo)</label>
            <textarea id="ia-notas" name="notas" rows="2" maxlength="1000"><?= e($ev['notas']) ?></textarea></div>
    </section>

    <section class="bloque" aria-labelledby="ia-t-cot">
        <h2 id="ia-t-cot">Cotización</h2>
        <div class="campo"><label for="ia-paq">Paquete</label>
            <select id="ia-paq" name="paquete_id"><option value="">Sin paquete (alquiler de los espacios)</option>
                <?php foreach ($cat['paquetes'] as $pq): ?><option value="<?= (int) $pq['id'] ?>" <?= (int) ($p['paquete_id'] ?? 0) === (int) $pq['id'] ? 'selected' : '' ?>><?= e($pq['nombre']) ?></option><?php endforeach; ?>
            </select></div>
        <?php if ($usadas): ?>
            <div class="tabla-scroll"><table class="tabla" id="ia-lineas">
                <thead><tr><th scope="col">Usar</th><th scope="col">Producto</th><th scope="col" class="der">Cantidad</th><th scope="col" class="der">Precio</th><th scope="col" class="der">Total</th></tr></thead>
                <tbody>
                <?php foreach ($usadas as $pid => $l): $pr = $cat['productos'][$pid]; $tot = round((float) $l['cantidad'] * (float) $pr['precio'], 2);
                    $usar = $l['usar'] ?? true; if ($usar) $subtotal += $tot; ?>
                    <tr>
                        <td><input type="checkbox" name="linea[<?= $pid ?>][usar]" value="1" <?= $usar ? 'checked' : '' ?> aria-label="Usar <?= e($pr['nombre']) ?>"></td>
                        <td><?= e($pr['nombre']) ?><br><small><?= e(Cotizaciones::CATEGORIAS[$pr['categoria']] ?? $pr['categoria']) ?> · <?= e(Cotizaciones::UNIDADES[$pr['unidad']] ?? $pr['unidad']) ?></small></td>
                        <td class="der"><input name="linea[<?= $pid ?>][cantidad]" inputmode="decimal" size="6" value="<?= e(rtrim(rtrim(number_format((float) $l['cantidad'], 2, '.', ''), '0'), '.')) ?>" aria-label="Cantidad de <?= e($pr['nombre']) ?>"></td>
                        <td class="der num"><?= dinero($pr['precio']) ?></td>
                        <td class="der num"><?= dinero($tot) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <p class="suave">Productos sueltos: <?= dinero($subtotal) ?> antes de IVA<?= $p['paquete_id'] ? ', más el paquete' : '' ?>. El total exacto lo ve en la cotización al guardar.</p>
        <?php else: ?>
            <p class="suave">Sin productos sueltos: se arma con <?= $p['paquete_id'] ? 'el paquete' : 'el alquiler de los espacios' ?>. Puede agregar más en la cotización.</p>
        <?php endif; ?>
    </section>

    <?php if ($error && str_contains($error, 'no está libre')): ?>
        <label class="casilla"><input type="checkbox" name="aceptar_choque" value="1"> Guardarlo igual como consulta (lista de espera)</label>
    <?php endif; ?>
    <div class="acciones">
        <button class="boton" type="submit">Guardar evento y cotización</button>
        <a class="boton boton-claro" href="<?= e(url('ia_cotizacion')) ?>">Empezar de nuevo</a>
    </div>
    <details class="bloque"><summary>Mensaje del cliente</summary><p><?= nl2br(e($mensaje)) ?></p></details>
</form>
<?php endif; ?>
