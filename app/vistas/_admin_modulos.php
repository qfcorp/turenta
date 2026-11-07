<?php
/**
 * Panel Tukán → ficha del negocio → Módulos (Anexo B): interruptores por módulo, precio de cada uno, total al mes,
 * precio acordado y pedidos desde "Mi plan". Usa $n, $modulosEstado, $catalogo, $pedidosModulos y $accion de admin_negocio.php.
 */
use TuRenta\Modulos;
$me = $modulosEstado;
$porGrupo = [];
foreach ($catalogo as $m) $porGrupo[$m['grupo_nombre']][] = $m;
$pendientes = array_values(array_filter($pedidosModulos, fn($s) => $s['estado'] === 'pendiente'));
?>
<section class="bloque adm-mod" id="modulos">
    <div class="dia-titulo-acciones"><h2>Módulos</h2>
        <span class="adm-mod-total">Paga <strong><?= dinero($me['mensual']) ?></strong> al mes<?= $me['precio_acordado'] !== null ? ' <small>(precio acordado)</small>' : '' ?></span></div>
    <?php if ($me['demo']): ?>
        <p class="aviso aviso-alerta">Está en demo: <?= $me['demo_todos'] ? 'tiene <strong>todos</strong> los módulos encendidos para que vea todo' : 'tiene encendidos solo los módulos que elegiste' ?>.
            Lo que marques aquí es lo que tendrá cuando pague.</p>
    <?php endif; ?>
    <?php if ($pendientes): ?>
        <div class="adm-mod-pedidos">
            <?php foreach ($pendientes as $s): $ids = Modulos::parse($s['encender']); ?>
                <form method="post" action="<?= $accion('solicitud_modulos') ?>" class="adm-mod-pedido"><?= campo_csrf() ?>
                    <input type="hidden" name="solicitud" value="<?= (int) $s['id'] ?>">
                    <p><strong>Pidió desde Mi plan:</strong> <?= e(implode(', ', array_map([Modulos::class, 'nombre'], $ids))) ?>
                        <small class="suave">· <?= e(date('d/m/Y H:i', strtotime($s['creado']))) ?><?= $s['nota'] ? ' · «' . e($s['nota']) . '»' : '' ?></small></p>
                    <div class="acciones">
                        <button class="boton boton-chico" type="submit" name="resultado" value="aprobada" data-confirmar="¿Encender <?= e(implode(', ', array_map([Modulos::class, 'nombre'], $ids))) ?>? Se suma a lo que paga al mes.">Aprobar y encender</button>
                        <button class="boton boton-claro boton-chico" type="submit" name="resultado" value="rechazada">Rechazar</button>
                    </div>
                </form>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <form method="post" action="<?= $accion('modulos') ?>"><?= campo_csrf() ?>
        <div class="adm-mod-grupos">
        <?php foreach ($porGrupo as $grupo => $lista): ?>
            <fieldset class="adm-mod-grupo"><legend><?= e($grupo) ?></legend>
                <?php foreach ($lista as $m):
                    $id = $m['id'];
                    $encendido = in_array($id, $me['pagados'], true);
                    $delPlan = in_array($id, $me['del_plan'], true);
                    // Planes de $20 y $45 (migración 112): los elegidos del Básico van incluidos; los demás extra a lo que diga el plan
                    $elegido = in_array($id, $me['elegidos'] ?? [], true);
                    $precioExtra = $me['precio_por_extra'][$id] ?? $m['precio_mensual'];
                    $nota = $delPlan ? ($encendido ? 'Del plan' : 'Quitado · −' . dinero($m['precio_mensual']))
                        : ($encendido ? ($elegido ? 'Elegido · incluido en el plan' : 'Extra · +' . dinero($precioExtra)) : '+' . dinero($m['precio_mensual']));
                    if (Modulos::porCotizacion($m) && !$delPlan) $nota = $encendido ? 'Extra · por cotización (ponlo en el precio acordado)' : 'Por cotización';
                    $dep = $m['depende_de'] ? 'Necesita: ' . implode(', ', array_map([Modulos::class, 'nombre'], $m['depende_de'])) : ''; ?>
                    <label class="adm-mod-item <?= $encendido ? 'on' : '' ?>">
                        <input type="checkbox" class="interruptor" name="modulo[<?= e($id) ?>]" value="1" <?= $encendido ? 'checked' : '' ?>
                               data-depende="<?= e(implode(',', $m['depende_de'])) ?>" data-id="<?= e($id) ?>">
                        <span class="adm-mod-nombre"><span aria-hidden="true"><?= e($m['icono']) ?></span> <?= e($m['nombre']) ?>
                            <small><?= e($nota) ?><?= $dep !== '' ? ' · ' . e($dep) : '' ?></small></span>
                    </label>
                <?php endforeach; ?>
            </fieldset>
        <?php endforeach; ?>
        </div>
        <ul class="adm-mod-cuenta">
            <li><span>Plan <?= e($n['plan_nombre']) ?></span><span class="monto"><?= dinero($me['precio_plan']) ?></span></li>
            <?php if (($me['modulos_elegir'] ?? 0) > 0): ?><li><span>Módulos a elegir incluidos</span><span class="monto"><?= count($me['elegidos']) ?> de <?= (int) $me['modulos_elegir'] ?></span></li><?php endif; ?>
            <li><span>Módulos extra</span><span class="monto">+<?= dinero($me['precio_extra']) ?></span></li>
            <li><span>Módulos quitados</span><span class="monto">−<?= dinero($me['descuento']) ?></span></li>
            <li class="total"><span>Calculado al mes</span><span class="monto"><?= dinero($me['calculado']) ?></span></li>
        </ul>
        <div class="fila-campos">
            <div class="campo"><label for="precio_acordado">Precio acordado al mes ($) <small>(vacío = el calculado)</small></label>
                <input type="text" inputmode="decimal" id="precio_acordado" name="precio_acordado" value="<?= $me['precio_acordado'] !== null ? e(number_format($me['precio_acordado'], 2, '.', '')) : '' ?>" style="max-width:180px"></div>
        </div>
        <?php if ($me['demo']): ?>
            <div class="casillas" style="margin-bottom:12px"><label><input type="checkbox" name="demo_igual" value="1" <?= $me['demo_todos'] ? '' : 'checked' ?>>
                Que la demo tenga solo estos módulos (si no, la demo tiene todos)</label></div>
        <?php endif; ?>
        <p class="g-nota">Apagar un módulo no borra nada: solo se esconde. Si lo vuelves a encender, todo sigue como estaba.</p>
        <button class="boton" type="submit" data-confirmar="¿Guardar los módulos de <?= e($n['nombre']) ?>? Cambia lo que puede usar y lo que paga al mes.">Guardar módulos</button>
    </form>
</section>
