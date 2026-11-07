<?php
use TuRenta\DiaEvento;

/** @var array $lista @var ?array $persona @var array $proximos @var ?array $pagos @var ?string $enlace @var ?string $wa @var ?array $previo */
$editable = puede_editar($u);
$form = function (?array $p) use ($previo) {
    $v = fn(string $k, $def = '') => e($previo[$k] ?? $p[$k] ?? $def);
    $s = $p ? 'e' : 'n';
    ob_start(); ?>
    <?= campo_csrf() ?>
    <input type="hidden" name="accion" value="<?= $p ? 'editar' : 'crear' ?>">
    <?php if ($p): ?><input type="hidden" name="personal_id" value="<?= (int) $p['id'] ?>"><?php endif; ?>
    <div class="campo"><label for="nom<?= $s ?>">Nombre completo</label>
        <input type="text" id="nom<?= $s ?>" name="nombre" value="<?= $v('nombre') ?>" required maxlength="120" placeholder="Carlos Andrade"></div>
    <div class="fila-campos">
        <div class="campo"><label for="rol<?= $s ?>">Qué hace</label>
            <select id="rol<?= $s ?>" name="rol">
                <?php foreach (DiaEvento::ROLES as $k => $t): ?><option value="<?= e($k) ?>" <?= ($previo['rol'] ?? $p['rol'] ?? 'mesero') === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
            </select></div>
        <div class="campo"><label for="pago<?= $s ?>">Le pagas por evento</label>
            <input type="text" inputmode="decimal" id="pago<?= $s ?>" name="pago_evento" value="<?= $v('pago_evento') ?>" placeholder="35,00"></div>
    </div>
    <div class="fila-campos">
        <div class="campo"><label for="tel<?= $s ?>">Celular <small>(para WhatsApp)</small></label>
            <input type="tel" id="tel<?= $s ?>" name="telefono" value="<?= $v('telefono') ?>" placeholder="0991234567"></div>
        <div class="campo"><label for="ced<?= $s ?>">Cédula <small>(opcional)</small></label>
            <input type="text" inputmode="numeric" id="ced<?= $s ?>" name="identificacion" value="<?= $v('identificacion') ?>" maxlength="10"></div>
    </div>
    <div class="campo"><label for="not<?= $s ?>">Notas <small>(talla de uniforme, si tiene carro…)</small></label>
        <input type="text" id="not<?= $s ?>" name="notas" value="<?= $v('notas') ?>" maxlength="300"></div>
    <?php if ($p): ?>
        <label class="opcion" style="margin-bottom:14px"><input type="checkbox" name="activo" value="1" <?= $p['activo'] ? 'checked' : '' ?>>
            <span><strong>Activo</strong><small>Desmárcalo si ya no trabaja contigo (se guarda su historial).</small></span></label>
    <?php endif; ?>
    <button class="boton boton-ancho" type="submit"><?= $p ? 'Guardar cambios' : 'Agregar al personal' ?></button>
    <?php return ob_get_clean();
};
?>
<div class="cabecera">
    <div>
        <h1>Personal</h1>
        <p>Meseros, cocina, seguridad y más. Cada persona tiene su enlace para ver sus eventos y confirmar.</p>
    </div>
    <?php if ($persona): ?><a class="boton boton-claro" href="<?= e(url('personal')) ?>">Ver todos</a><?php endif; ?>
</div>

<?php if (!$persona): ?>
<div class="rejilla rejilla-2">
    <section class="bloque">
        <h2>Tu equipo <small class="suave">(<?= count(array_filter($lista, fn($p) => $p['activo'])) ?> activos)</small></h2>
        <?php if (!$lista): ?>
            <div class="vacio">
                <p><strong>Todavía no tienes personal.</strong></p>
                <p>Agrega a tus meseros, cocineros y guardias con su celular. Después los asignas a cada evento desde “Día del evento” y les mandas el aviso por WhatsApp con un toque.</p>
            </div>
        <?php else: ?>
        <ul class="filas dia-lista-personas">
            <?php foreach ($lista as $p): ?>
            <li class="<?= $p['activo'] ? '' : 'dia-inactivo' ?>">
                <div>
                    <a href="<?= e(url('personal', ['id' => $p['id']])) ?>"><strong><?= e($p['nombre']) ?></strong></a>
                    <?php if (!$p['activo']): ?><span class="etq etq-cancelada">Inactivo</span><?php endif; ?>
                    <br><small><?= e(DiaEvento::ROLES[$p['rol']] ?? $p['rol']) ?> · <?= dinero($p['pago_evento']) ?> por evento<?= $p['telefono'] ? ' · ' . e($p['telefono']) : '' ?></small>
                    <?php if ((int) $p['proximos'] || (float) $p['por_pagar'] > 0): ?>
                        <br><small><?= (int) $p['proximos'] ? (int) $p['proximos'] . ' ' . ((int) $p['proximos'] === 1 ? 'evento próximo' : 'eventos próximos') : '' ?>
                        <?= (float) $p['por_pagar'] > 0 ? '<span class="dia-alerta-texto">· Le debes ' . dinero($p['por_pagar']) . '</span>' : '' ?></small>
                    <?php endif; ?>
                </div>
                <div class="derecha"><a class="boton boton-claro boton-chico" href="<?= e(url('personal', ['id' => $p['id']])) ?>">Abrir</a></div>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>
    <?php if ($editable): ?>
    <form method="post" class="bloque">
        <h2>Agregar persona</h2>
        <?= $form(null) ?>
    </form>
    <?php endif; ?>
</div>

<?php else: $p = $persona; ?>
<div class="rejilla rejilla-2">
    <div>
        <section class="bloque">
            <h2><?= e($p['nombre']) ?> <?php if (!$p['activo']): ?><span class="etq etq-cancelada">Inactivo</span><?php endif; ?></h2>
            <p class="suave"><?= e(DiaEvento::ROLES[$p['rol']] ?? $p['rol']) ?> · <?= dinero($p['pago_evento']) ?> por evento</p>
            <h3>Su enlace “Mis eventos”</h3>
            <p class="suave" style="font-size:.92rem">Ahí ve sus próximos eventos con horario, dirección y mapa, confirma si va y revisa sus pagos. No necesita usuario ni contraseña.</p>
            <div class="acciones">
                <?php if ($wa): ?><a class="boton boton-wa" href="<?= e($wa) ?>" target="_blank" rel="noopener">Mandar por WhatsApp</a><?php endif; ?>
                <button type="button" class="boton boton-claro" data-copiar="<?= e($enlace) ?>">Copiar enlace</button>
                <a class="boton boton-claro" href="<?= e($enlace) ?>" target="_blank" rel="noopener">Ver como lo ve</a>
            </div>
            <?php if (!$p['telefono']): ?><p class="suave" style="margin-top:8px"><small>Agrega su celular para mandarle el enlace por WhatsApp.</small></p><?php endif; ?>
        </section>
        <section class="bloque">
            <h2>Próximos eventos</h2>
            <?php if (!$proximos): ?>
                <p class="vacio">No está asignado a ningún evento próximo. Asígnalo desde la pestaña “Día del evento” de cada evento.</p>
            <?php else: ?>
            <ul class="filas">
                <?php foreach ($proximos as $x): ?>
                <li><div><a href="<?= e(url('evento_dia', ['id' => $x['evento_id'], 'ver' => 'personal'])) ?>"><strong><?= e($x['evento']) ?></strong></a>
                    <br><small><?= e(fecha_corta($x['fecha'])) ?> · <?= e(DiaEvento::ROLES[$x['rol']] ?? $x['rol']) ?> · <?= e(hora_corta($x['hora_entrada'])) ?> a <?= e(hora_corta($x['hora_salida'])) ?></small></div>
                    <div class="derecha"><?= $x['confirmado'] ? '<span class="etq etq-confirmada">Confirmó</span>' : ($x['respuesta'] === 'no' ? '<span class="etq etq-cancelada">No puede</span>' : '<span class="etq etq-pendiente">Sin confirmar</span>') ?></div></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </section>
        <section class="bloque">
            <h2>Pagos</h2>
            <div class="cifras">
                <div class="cifra-tarjeta <?= $pagos['total_pendiente'] > 0 ? 'alerta' : '' ?>"><small>Le debes</small><strong><?= dinero($pagos['total_pendiente']) ?></strong></div>
                <div class="cifra-tarjeta bien"><small>Ya le pagaste</small><strong><?= dinero($pagos['total_recibido']) ?></strong></div>
            </div>
            <?php if ($pagos['pendientes']): ?>
            <ul class="filas">
                <?php foreach ($pagos['pendientes'] as $x): ?>
                <li><div><a href="<?= e(url('evento_dia', ['id' => $x['evento_id'], 'ver' => 'personal'])) ?>"><?= e($x['evento']) ?></a><br><small><?= e(fecha_corta($x['fecha'])) ?></small></div>
                    <div class="derecha monto"><?= dinero($x['pago']) ?></div></li>
                <?php endforeach; ?>
            </ul>
            <p class="suave"><small>Para marcar un pago, abre el evento → Día del evento → Personal.</small></p>
            <?php endif; ?>
        </section>
    </div>
    <?php if ($editable): ?>
    <form method="post" class="bloque">
        <h2>Datos</h2>
        <?= $form($p) ?>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>
