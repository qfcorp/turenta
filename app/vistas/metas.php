<?php
use TuRenta\Reportes;

/** Metas de ventas (dueño). @var int $anio @var array $todas @var array $rep @var array $vendedores @var DateTimeImmutable $hoy */
$monto = fn(?array $f) => $f && $f['monto'] > 0 ? rtrim(rtrim(number_format($f['monto'], 2, ',', ''), '0'), ',') : '';
$evs = fn(?array $f) => $f && $f['eventos'] ? (string) $f['eventos'] : '';
$general = $todas['local'][''] ?? null;
$mesActual = $hoy->format('Y-m');
$vendedorMes = [];
foreach ($todas['vendedores'] as $vid => $porMes) foreach ($porMes as $mes => $f) if ($mes !== '') $vendedorMes[] = ['vendedor_id' => $vid, 'mes' => $mes] + $f;
usort($vendedorMes, fn($a, $b) => strcmp($b['mes'], $a['mes']));
$nombreDe = array_column($vendedores, 'nombre', 'id');
?>
<div class="cabecera">
    <div>
        <h1>Metas de ventas</h1>
        <p>Cuánto quieres vender cada mes. Se compara con lo vendido (igual que en Reportes: eventos que se separaron o confirmaron ese mes) y con el mismo mes del año anterior.</p>
    </div>
    <a class="boton boton-claro" href="<?= e(url('reportes', ['p' => 'anio'])) ?>#metas">Ver cómo vamos</a>
</div>

<nav class="mt-anios" aria-label="Año">
    <a href="<?= e(url('metas', ['anio' => $anio - 1])) ?>" aria-label="Año <?= $anio - 1 ?>">← <?= $anio - 1 ?></a>
    <strong><?= $anio ?></strong>
    <a href="<?= e(url('metas', ['anio' => $anio + 1])) ?>" aria-label="Año <?= $anio + 1 ?>"><?= $anio + 1 ?> →</a>
</nav>

<form method="post" class="bloque" action="<?= e(url('metas', ['anio' => $anio])) ?>">
    <?= campo_csrf() ?><input type="hidden" name="accion" value="local"><input type="hidden" name="anio" value="<?= $anio ?>">
    <h2>Meta del local</h2>
    <div class="mt-general">
        <div class="campo"><label for="mt-monto">Meta de todos los meses <small>(vendido, en dólares con IVA)</small></label>
            <input type="text" inputmode="decimal" id="mt-monto" name="monto" value="<?= e($monto($general)) ?>" placeholder="Ej. 15000"></div>
        <div class="campo"><label for="mt-eventos">Eventos por mes <small>(opcional)</small></label>
            <input type="number" min="0" max="10000" id="mt-eventos" name="eventos" value="<?= e($evs($general)) ?>" placeholder="Ej. 6"></div>
    </div>
    <p class="suave" style="font-size:.92rem">¿Un mes vendes más (diciembre, temporada de bodas)? Ponle una meta distinta abajo. Vacío = usa la meta de todos los meses.</p>
    <div class="tabla-scroll">
        <table class="tabla mt-tabla">
            <thead><tr><th>Mes</th><th>Meta del mes</th><th>Eventos</th><th class="num">Vendido</th><th class="num">Año anterior</th></tr></thead>
            <tbody>
            <?php for ($m = 1; $m <= 12; $m++): $mes = sprintf('%04d-%02d', $anio, $m); $f = $todas['local'][$mes] ?? null; $r = $rep['meses'][$mes]; ?>
                <tr class="<?= $mes === $mesActual ? 'mt-actual' : '' ?>">
                    <td><label for="mm-<?= $mes ?>"><?= e(ucfirst(Reportes::MESES[$m])) ?></label></td>
                    <td><input type="text" inputmode="decimal" id="mm-<?= $mes ?>" name="mes_monto[<?= $mes ?>]" value="<?= e($monto($f)) ?>"
                               placeholder="<?= e($general ? $monto($general) : '—') ?>" aria-label="Meta de <?= e(Reportes::MESES[$m]) ?>"></td>
                    <td><input type="number" min="0" max="10000" name="mes_eventos[<?= $mes ?>]" value="<?= e($evs($f)) ?>" placeholder="<?= e($general ? $evs($general) : '') ?>"
                               aria-label="Eventos de <?= e(Reportes::MESES[$m]) ?>" class="mt-chico"></td>
                    <td class="num"><?= $r['vendido'] > 0 ? dinero($r['vendido']) : '<span class="suave">—</span>' ?></td>
                    <td class="num suave"><?= $r['anterior'] > 0 ? dinero($r['anterior']) : '—' ?></td>
                </tr>
            <?php endfor; ?>
            </tbody>
        </table>
    </div>
    <button class="boton" type="submit">Guardar metas del local</button>
</form>

<?php if ($vendedores): ?>
<form method="post" class="bloque" action="<?= e(url('metas', ['anio' => $anio])) ?>">
    <?= campo_csrf() ?><input type="hidden" name="accion" value="vendedores"><input type="hidden" name="anio" value="<?= $anio ?>">
    <h2>Meta de cada vendedor <small class="suave">(opcional)</small></h2>
    <p class="suave" style="font-size:.92rem">Cada vendedor ve su meta en el Inicio. Cuenta lo que vende como "vendedor" del evento.</p>
    <ul class="filas mt-vendedores">
        <?php foreach ($vendedores as $v): $f = $todas['vendedores'][(int) $v['id']][''] ?? null; ?>
        <li>
            <div><strong><?= e($v['nombre']) ?></strong><br><small><?= ['dueno' => 'Dueño', 'admin' => 'Administrador'][$v['rol']] ?? 'Vendedor' ?><?= !$v['activo'] ? ' · desactivado' : '' ?></small></div>
            <div class="mt-v-campos">
                <input type="text" inputmode="decimal" name="v_monto[<?= (int) $v['id'] ?>]" value="<?= e($monto($f)) ?>" placeholder="Meta al mes" aria-label="Meta de <?= e($v['nombre']) ?> cada mes">
                <input type="number" min="0" max="10000" name="v_eventos[<?= (int) $v['id'] ?>]" value="<?= e($evs($f)) ?>" placeholder="Eventos" class="mt-chico" aria-label="Eventos de <?= e($v['nombre']) ?> cada mes">
            </div>
        </li>
        <?php endforeach; ?>
    </ul>
    <button class="boton" type="submit">Guardar metas de los vendedores</button>
</form>

<section class="bloque">
    <h2>Un mes distinto para un vendedor</h2>
    <form method="post" class="mt-vmes" action="<?= e(url('metas', ['anio' => $anio])) ?>">
        <?= campo_csrf() ?><input type="hidden" name="accion" value="vendedor_mes"><input type="hidden" name="anio" value="<?= $anio ?>">
        <div class="campo"><label for="vm-v">Vendedor</label><select id="vm-v" name="vendedor_id" required>
            <?php foreach ($vendedores as $v): ?><option value="<?= (int) $v['id'] ?>"><?= e($v['nombre']) ?></option><?php endforeach; ?></select></div>
        <div class="campo"><label for="vm-mes">Mes</label><input type="month" id="vm-mes" name="mes" required value="<?= e($anio === (int) $hoy->format('Y') ? $mesActual : $anio . '-01') ?>"></div>
        <div class="campo"><label for="vm-monto">Meta</label><input type="text" inputmode="decimal" id="vm-monto" name="monto" placeholder="Ej. 8000"></div>
        <div class="campo"><label for="vm-ev">Eventos</label><input type="number" min="0" max="10000" id="vm-ev" name="eventos" class="mt-chico"></div>
        <button class="boton boton-claro" type="submit">Guardar</button>
    </form>
    <?php if ($vendedorMes): ?>
    <ul class="filas">
        <?php foreach ($vendedorMes as $f): ?>
        <li><span><strong><?= e($nombreDe[$f['vendedor_id']] ?? '—') ?></strong> · <?= e(ucfirst(Reportes::nombreMes($f['mes']))) ?>:
                <?= $f['monto'] > 0 ? dinero($f['monto']) : '' ?><?= $f['eventos'] ? ' · ' . (int) $f['eventos'] . ' eventos' : '' ?></span>
            <form method="post" class="derecha" action="<?= e(url('metas', ['anio' => $anio])) ?>"><?= campo_csrf() ?><input type="hidden" name="accion" value="quitar">
                <input type="hidden" name="vendedor_id" value="<?= (int) $f['vendedor_id'] ?>"><input type="hidden" name="mes" value="<?= e($f['mes']) ?>"><input type="hidden" name="anio" value="<?= $anio ?>">
                <button class="boton boton-peligro boton-chico" type="submit">Quitar</button></form></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</section>
<?php endif; ?>
