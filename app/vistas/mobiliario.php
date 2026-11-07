<?php
use TuRenta\{DiaEvento, Eventos};

/** @var array $lista @var ?array $mueble @var array $uso @var array $faltantes @var ?array $previo */
$editable = puede_editar($u);
$m = $mueble;
$v = fn(string $k, $def = '') => e($previo[$k] ?? $m[$k] ?? $def);
$porCat = [];
foreach ($lista as $x) $porCat[$x['categoria']][] = $x;
$diasConFalta = 0;
foreach ($lista as $x) $diasConFalta += count(array_filter($faltantes[(int) $x['id']] ?? [], fn($c) => $c > (int) $x['cantidad_total']));
$valorTotal = array_sum(array_map(fn($x) => $x['activo'] ? (int) $x['cantidad_total'] * (float) $x['costo_reposicion'] : 0, $lista));
?>
<div class="cabecera">
    <div>
        <h1>Mobiliario</h1>
        <p>Lo que tienes y cuánto se usa en los próximos eventos. Si dos eventos el mismo día piden más de lo que hay, te avisamos.</p>
    </div>
    <?php if ($m): ?><a class="boton boton-claro" href="<?= e(url('mobiliario')) ?>">Ver todo</a><?php endif; ?>
</div>
<div class="cifras">
    <div class="cifra-tarjeta"><small>Ítems</small><strong><?= count(array_filter($lista, fn($x) => $x['activo'])) ?></strong></div>
    <div class="cifra-tarjeta"><small>Valor de reposición</small><strong><?= dinero($valorTotal) ?></strong></div>
    <div class="cifra-tarjeta <?= $diasConFalta ? 'alerta' : 'bien' ?>"><small>Días con faltantes</small><strong><?= $diasConFalta ?></strong></div>
</div>
<div class="rejilla rejilla-2">
    <section class="bloque">
        <h2>Tu inventario</h2>
        <?php if (!$lista): ?>
            <div class="vacio"><p><strong>Todavía no registras mobiliario.</strong></p><p>Agrega tus sillas, mesas y mantelería con la cantidad que tienes y lo que cuesta reponer cada una.</p></div>
        <?php else: foreach (DiaEvento::CATEGORIAS_MOBILIARIO as $cat => $nomCat): if (empty($porCat[$cat])) continue; ?>
            <h3 class="dia-subtitulo"><?= e($nomCat) ?></h3>
            <ul class="filas">
                <?php foreach ($porCat[$cat] as $x):
                    $usos = $uso[(int) $x['id']] ?? [];
                    $diasFalta = array_filter($faltantes[(int) $x['id']] ?? [], fn($c) => $c > (int) $x['cantidad_total']); ?>
                <li class="<?= $x['activo'] ? '' : 'dia-inactivo' ?>">
                    <div>
                        <strong><?= e($x['nombre']) ?></strong> <?php if (!$x['activo']): ?><span class="etq etq-cancelada">Inactivo</span><?php endif; ?>
                        <br><small>Tienes <strong><?= (int) $x['cantidad_total'] ?></strong> · reponer cuesta <?= dinero($x['costo_reposicion']) ?> c/u<?= (int) $x['danados_historico'] ? ' · ' . (int) $x['danados_historico'] . ' dañados en total' : '' ?></small>
                        <?php if ($usos): ?>
                        <details class="dia-detalles"><summary><?= count($usos) ?> <?= count($usos) === 1 ? 'evento próximo' : 'eventos próximos' ?></summary>
                            <ul class="dia-mini-lista">
                                <?php foreach ($usos as $us): ?>
                                <li><a href="<?= e(url('evento_dia', ['id' => $us['evento_id'], 'ver' => 'mobiliario'])) ?>"><?= e(fecha_corta($us['fecha'], false)) ?> · <?= e($us['evento']) ?></a>
                                    — <?= (int) $us['cantidad'] ?> <span class="ev-estado ev-<?= e($us['estado']) ?>"><?= e(Eventos::ESTADOS[$us['estado']]) ?></span></li>
                                <?php endforeach; ?>
                            </ul>
                        </details>
                        <?php endif; ?>
                        <?php foreach ($diasFalta as $fecha => $cant): ?>
                            <br><small class="dia-alerta-texto">El <?= e(fecha_corta($fecha, false)) ?> se reservan <?= (int) $cant ?>: faltan <?= $cant - (int) $x['cantidad_total'] ?>.</small>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($editable): ?><div class="derecha"><a class="boton boton-claro boton-chico" href="<?= e(url('mobiliario', ['id' => $x['id']])) ?>">Editar</a></div><?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endforeach; endif; ?>
    </section>
    <?php if ($editable): ?>
    <form method="post" class="bloque">
        <h2><?= $m ? 'Editar ' . e($m['nombre']) : 'Agregar mobiliario' ?></h2>
        <?= campo_csrf() ?>
        <input type="hidden" name="accion" value="<?= $m ? 'editar' : 'crear' ?>">
        <?php if ($m): ?><input type="hidden" name="mobiliario_id" value="<?= (int) $m['id'] ?>"><?php endif; ?>
        <div class="campo"><label for="mnom">Nombre</label>
            <input type="text" id="mnom" name="nombre" value="<?= $v('nombre') ?>" required maxlength="120" placeholder="Silla Tiffany dorada"></div>
        <div class="campo"><label for="mcat">Categoría</label>
            <select id="mcat" name="categoria">
                <?php foreach (DiaEvento::CATEGORIAS_MOBILIARIO as $k => $t): ?><option value="<?= e($k) ?>" <?= ($previo['categoria'] ?? $m['categoria'] ?? 'mobiliario') === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
            </select></div>
        <div class="fila-campos">
            <div class="campo"><label for="mcan">¿Cuántos tienes?</label>
                <input type="number" id="mcan" name="cantidad_total" value="<?= $v('cantidad_total', '0') ?>" min="0" step="1" required></div>
            <div class="campo"><label for="mcos">Costo de reposición <small>(por unidad)</small></label>
                <input type="text" inputmode="decimal" id="mcos" name="costo_reposicion" value="<?= $v('costo_reposicion') ?>" placeholder="45,00"></div>
        </div>
        <p class="suave" style="font-size:.9rem">El costo de reposición es lo que cobras si se daña o se pierde. Se calcula solo después de cada evento.</p>
        <?php if ($m): ?>
            <label class="opcion" style="margin-bottom:14px"><input type="checkbox" name="activo" value="1" <?= $m['activo'] ? 'checked' : '' ?>>
                <span><strong>Activo</strong><small>Desmárcalo si ya no lo tienes.</small></span></label>
        <?php endif; ?>
        <button class="boton boton-ancho" type="submit"><?= $m ? 'Guardar cambios' : 'Agregar' ?></button>
    </form>
    <?php endif; ?>
</div>
