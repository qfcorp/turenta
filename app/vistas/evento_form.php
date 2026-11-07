<?php
use TuRenta\{Eventos, Ventas};

/** Formulario de evento nuevo o editar. @var string $modo @var array $d @var array $espacios @var array $clientes @var ?array $ev */
$nuevo = $modo === 'nuevo';
$accion = $nuevo ? url('evento_nuevo') : url('evento_editar', ['id' => $ev['id']]);
?>
<?php if ($nuevo): ?>
<div class="cabecera">
    <div>
        <h1>Nuevo evento</h1>
        <p>Con nombre, celular y fecha basta para empezar. Lo demás se completa después.</p>
    </div>
    <div class="acciones">
        <?php if (modulo_activo($u, 'asistente_ia')): ?><a class="boton boton-claro" href="<?= e(url('ia_cotizacion')) ?>">✨ Desde un mensaje de WhatsApp</a><?php endif; ?>
        <a class="boton boton-claro" href="<?= e(url('eventos')) ?>">Cancelar</a>
    </div>
</div>
<?php else: ?>
<div class="cabecera">
    <div>
        <p class="evento-sub" style="margin:0"><a href="<?= e(url('evento', ['id' => $ev['id']])) ?>"><?= e($ev['codigo']) ?></a></p>
        <h1>Editar evento</h1>
        <?php if (in_array($ev['estado'], Eventos::BLOQUEAN, true)): ?>
            <p>Este evento ya está <?= e(strtolower(Eventos::ESTADOS[$ev['estado']])) ?>: si cambias la fecha, la hora o el espacio, revisamos que siga libre.</p>
        <?php endif; ?>
    </div>
    <a class="boton boton-claro" href="<?= e(url('evento', ['id' => $ev['id']])) ?>">Cancelar</a>
</div>
<?php endif; ?>

<?php if ($error): ?><p class="aviso aviso-error" role="alert"><?= e($error) ?></p><?php endif; ?>

<?php if (!$espacios): ?>
    <section class="bloque vacio">
        <p><strong>Primero crea al menos un espacio</strong> (salón, jardín, terraza…) para poder ubicar el evento.</p>
        <?php if (es_admin_total($u)): ?><a class="boton" href="<?= e(url('espacios')) ?>">Crear un espacio</a><?php endif; ?>
    </section>
<?php else: ?>
<form method="post" action="<?= e($accion) ?>" id="form-evento" class="form-evento" novalidate
      data-disponible="<?= e(url('disponible')) ?>" data-excluir="<?= $ev ? (int) $ev['id'] : '' ?>">
    <?= campo_csrf() ?>

    <fieldset class="bloque paso">
        <legend><span class="paso-num">1</span> Cliente</legend>
        <div class="modos" role="radiogroup" aria-label="¿Cliente nuevo o ya registrado?">
            <label class="modo"><input type="radio" name="modo_cliente" value="nuevo" <?= $d['modo_cliente'] !== 'existente' ? 'checked' : '' ?>>
                <span><strong>Cliente nuevo</strong><small>Primera vez que nos escribe</small></span></label>
            <label class="modo"><input type="radio" name="modo_cliente" value="existente" <?= $d['modo_cliente'] === 'existente' ? 'checked' : '' ?>>
                <span><strong>Ya está registrado</strong><small>Buscar en mis clientes</small></span></label>
        </div>

        <div class="caja-cliente caja-cliente-nuevo" data-si="nuevo" style="margin-top:14px">
            <div class="rejilla-form">
                <div class="campo">
                    <label for="cliente_nombre">Nombre del cliente</label>
                    <input type="text" id="cliente_nombre" name="cliente_nombre" value="<?= e($d['cliente_nombre']) ?>" placeholder="Ej. Ana Torres y Luis Pérez" autocomplete="off">
                </div>
                <div class="campo">
                    <label for="cliente_telefono">Celular <small>(para WhatsApp)</small></label>
                    <input type="tel" id="cliente_telefono" name="cliente_telefono" value="<?= e($d['cliente_telefono']) ?>" placeholder="0991234567" inputmode="tel">
                </div>
                <div class="campo">
                    <label for="cliente_identificacion">Cédula o RUC <small>(opcional, para el contrato)</small></label>
                    <input type="text" id="cliente_identificacion" name="cliente_identificacion" value="<?= e($d['cliente_identificacion']) ?>" inputmode="numeric" maxlength="13">
                </div>
                <div class="campo">
                    <label for="cliente_email">Correo <small>(opcional)</small></label>
                    <input type="email" id="cliente_email" name="cliente_email" value="<?= e($d['cliente_email']) ?>">
                </div>
            </div>
            <p class="suave ayuda-chica">Si el celular o la cédula ya están registrados, usamos ese mismo cliente (no se duplica).</p>
        </div>

        <div class="caja-cliente caja-cliente-existente" data-si="existente" style="margin-top:14px">
            <?php if (!$clientes): ?>
                <p class="suave">Todavía no tienes clientes registrados. Marca "Cliente nuevo".</p>
            <?php else: ?>
            <div class="campo">
                <label for="cliente_buscar">Buscar cliente</label>
                <input type="search" id="cliente_buscar" class="js-filtrar-clientes" data-select="cliente_id" placeholder="Escribe el nombre, celular o cédula" autocomplete="off">
            </div>
            <div class="campo">
                <label for="cliente_id">Cliente</label>
                <select id="cliente_id" name="cliente_id" size="5" class="select-clientes">
                    <?php foreach ($clientes as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" data-buscar="<?= e(mb_strtolower($c['nombre'] . ' ' . $c['telefono'] . ' ' . $c['identificacion'])) ?>"
                            <?= (int) $d['cliente_id'] === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['nombre']) ?><?= $c['telefono'] ? ' · ' . e($c['telefono']) : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
        </div>

        <div class="campo" style="margin-top:6px">
            <label for="origen">¿Cómo nos conoció?</label>
            <select id="origen" name="origen">
                <option value="">— Elegir —</option>
                <?php foreach (Eventos::ORIGENES as $k => $t): ?><option value="<?= e($k) ?>" <?= $d['origen'] === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
            </select>
        </div>
    </fieldset>

    <fieldset class="bloque paso">
        <legend><span class="paso-num">2</span> Evento</legend>
        <div class="rejilla-form">
            <div class="campo">
                <label for="tipo">¿Qué celebran?</label>
                <select id="tipo" name="tipo">
                    <?php foreach (Eventos::TIPOS as $k => $t): ?><option value="<?= e($k) ?>" <?= $d['tipo'] === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="campo">
                <label for="nombre">Nombre del evento <small>(opcional)</small></label>
                <input type="text" id="nombre" name="nombre" value="<?= e($d['nombre']) ?>" maxlength="150" placeholder="Ej. Boda de Ana y Luis (si lo dejas vacío, lo ponemos solo)">
            </div>
            <div class="campo">
                <label for="fecha">Fecha</label>
                <input type="date" id="fecha" name="fecha" value="<?= e($d['fecha']) ?>" required>
            </div>
            <div class="fila-campos">
                <div class="campo">
                    <label for="hora_inicio">Empieza</label>
                    <input type="time" id="hora_inicio" name="hora_inicio" value="<?= e($d['hora_inicio']) ?>" required>
                </div>
                <div class="campo">
                    <label for="hora_fin">Termina</label>
                    <input type="time" id="hora_fin" name="hora_fin" value="<?= e($d['hora_fin']) ?>" required>
                    <small>Si termina después de medianoche, pon la hora (ej. 02:00): entendemos que es el día siguiente.</small>
                </div>
            </div>
            <div class="campo">
                <label for="invitados">Invitados <small>(aproximado)</small></label>
                <input type="number" id="invitados" name="invitados" value="<?= e($d['invitados']) ?>" min="1" max="20000" inputmode="numeric" required>
            </div>
            <div class="campo">
                <label for="montaje">Montaje</label>
                <select id="montaje" name="montaje">
                    <option value="">— Por definir —</option>
                    <?php foreach (Eventos::MONTAJES as $k => $t): ?><option value="<?= e($k) ?>" <?= $d['montaje'] === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
                </select>
            </div>
        </div>

        <fieldset class="campo">
            <legend>Espacios</legend>
            <div class="casillas casillas-espacios">
                <?php foreach ($espacios as $es): ?>
                    <label>
                        <input type="checkbox" name="espacios[]" value="<?= (int) $es['id'] ?>"
                               data-banquete="<?= (int) $es['capacidad_banquete'] ?>" data-coctel="<?= (int) ($es['capacidad_coctel'] ?: $es['capacidad_banquete']) ?>"
                               <?= in_array((int) $es['id'], $d['espacios'], true) ? 'checked' : '' ?>>
                        <span class="punto-color" style="background:<?= e($es['color']) ?>" aria-hidden="true"></span>
                        <span><?= e($es['nombre']) ?>
                            <small class="suave"><?= $es['capacidad_banquete'] ? (int) $es['capacidad_banquete'] . ' sentados' : '' ?><?= $es['capacidad_coctel'] ? ' · ' . (int) $es['capacidad_coctel'] . ' de pie' : '' ?><?= !$es['activo'] ? ' · desactivado' : '' ?></small></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>

        <div id="aviso-capacidad" class="aviso aviso-error" role="status" hidden></div>
        <div id="choques-vivo" class="choques-vivo" aria-live="polite">
            <?php if (!empty($choquesForm['bloqueados']) || !empty($choquesForm['otros'])): ?>
                <div class="aviso <?= $choquesForm['bloqueados'] ? 'aviso-error' : 'aviso-alerta' ?>">
                    <?php foreach (array_merge($choquesForm['bloqueados'], $choquesForm['otros']) as $c): ?>
                        <div><?= e(Ventas::textoChoque($c)) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php if (!empty($choquesForm['bloqueados'])): ?>
            <label class="opcion opcion-choque">
                <input type="checkbox" name="aceptar_choque" value="1">
                <span><strong>Guardarlo igual como consulta</strong>
                    <small>Queda en lista de espera: no se podrá separar mientras el otro evento tenga la fecha.</small></span>
            </label>
        <?php endif; ?>
    </fieldset>

    <fieldset class="bloque paso">
        <legend><span class="paso-num">3</span> Venta</legend>
        <div class="rejilla-form">
            <div class="campo">
                <label for="presupuesto_cliente">Presupuesto del cliente <small>(lo que piensa gastar, opcional)</small></label>
                <input type="text" id="presupuesto_cliente" name="presupuesto_cliente" value="<?= e($d['presupuesto_cliente']) ?>" inputmode="decimal" placeholder="Ej. 8000">
            </div>
            <div class="campo">
                <label for="vendedor_id">Vendedor</label>
                <select id="vendedor_id" name="vendedor_id">
                    <?php foreach ($vendedores as $v): ?><option value="<?= (int) $v['id'] ?>" <?= (int) $d['vendedor_id'] === (int) $v['id'] ? 'selected' : '' ?>><?= e($v['nombre']) ?><?= ['dueno' => ' (dueño)', 'admin' => ' (administrador)'][$v['rol']] ?? '' ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="campo ancho">
                <label for="notas">Notas <small>(lo que conversaron, ideas, pedidos)</small></label>
                <textarea id="notas" name="notas" rows="3"><?= e($d['notas']) ?></textarea>
            </div>
        </div>
    </fieldset>

    <div class="barra-guardar">
        <button class="boton" type="submit"><?= $nuevo ? 'Crear evento' : 'Guardar cambios' ?></button>
        <a class="boton boton-claro" href="<?= e($nuevo ? url('eventos') : url('evento', ['id' => $ev['id']])) ?>">Cancelar</a>
    </div>
</form>
<?php endif; ?>
