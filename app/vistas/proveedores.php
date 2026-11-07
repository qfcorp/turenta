<?php
use TuRenta\{Cotizaciones, ProveedoresFicha, WhatsApp};

/** @var array $lista @var ?array $proveedor @var ?array $previo @var array $puntajes @var array $alertas @var bool $verRanking @var array $ranking
 *  @var bool $comercial @var bool $verDinero @var array $cfgPuntaje (cómo se calcula el puntaje del negocio) */
$editable = puede_editar($u);
$p = $proveedor;
$v = fn(string $k, $def = '') => e($previo[$k] ?? $p[$k] ?? $def);
$porServicio = [];
foreach ($lista as $x) $porServicio[$x['servicio']][] = $x;
$num = fn($x) => $x === null || $x === '' ? '' : rtrim(rtrim(number_format((float) $x, 2, ',', '.'), '0'), ',');
?>
<div class="cabecera">
    <div>
        <h1>Proveedores</h1>
        <p>DJ, fotógrafos, florerías, catering y más: lo que les compras, lo que les debes, sus documentos y cómo cumplen.</p>
    </div>
    <div class="acciones">
        <?php if ($verDinero): ?>
            <a class="boton boton-claro" href="<?= e(url('compras')) ?>">Compras</a>
            <a class="boton boton-claro" href="<?= e(url('por_pagar')) ?>">Por pagar</a>
        <?php endif; ?>
        <a class="boton boton-claro" href="<?= e(url('incidencias')) ?>">Incidencias</a>
        <?php if ($p): ?><a class="boton boton-claro" href="<?= e(url('proveedores')) ?>">Ver todos</a><?php endif; ?>
    </div>
</div>

<nav class="pestanas" aria-label="Vista de proveedores">
    <a href="<?= e(url('proveedores')) ?>" class="<?= !$verRanking ? 'activa' : '' ?>" <?= !$verRanking ? 'aria-current="page"' : '' ?>>Lista</a>
    <a href="<?= e(url('proveedores', ['ver' => 'ranking'])) ?>" class="<?= $verRanking ? 'activa' : '' ?>" <?= $verRanking ? 'aria-current="page"' : '' ?>>Ranking</a>
</nav>

<?php if ($verRanking): ?>
<section class="bloque">
    <h2>Ranking de proveedores</h2>
    <p class="suave"><?= e(\TuRenta\PuntajeProveedores::explicar($cfgPuntaje)[0]) ?> Se califica en el día del evento → Proveedores, después del evento.
        <a href="<?= e(url('puntaje_proveedores')) ?>">Cómo se calcula el puntaje</a></p>
    <?php if (!$ranking): ?>
        <p class="vacio">Todavía no tienes proveedores.</p>
    <?php else: ?>
    <ol class="pv-ranking">
        <?php foreach ($ranking as $i => $r): ?>
        <li class="<?= $r['activo'] ? '' : 'dia-inactivo' ?>">
            <span class="pv-puesto"><?= $r['puntaje'] !== null ? $i + 1 : '–' ?></span>
            <div class="pv-ranking-texto">
                <a href="<?= e(url('proveedor', ['id' => $r['proveedor_id']])) ?>"><strong><?= e($r['nombre']) ?></strong></a><?= $r['preferido'] ? ' <span class="pv-estrella" title="Preferido">★</span>' : '' ?>
                <br><small><?= e(Cotizaciones::CATEGORIAS[$r['servicio']] ?? $r['servicio']) ?> · <?= (int) $r['eventos'] ?> <?= $r['eventos'] === 1 ? 'evento' : 'eventos' ?>
                    <?= $r['puntualidad'] !== null ? ' · ' . (int) $r['puntualidad'] . ' % puntual' : '' ?>
                    <?= $r['calificacion'] !== null ? ' · ★ ' . e(number_format((float) $r['calificacion'], 1, ',', '')) : '' ?>
                    <?= $r['incidencias'] ? ' · ' . (int) $r['incidencias'] . ($r['incidencias'] === 1 ? ' incidencia' : ' incidencias') : '' ?>
                    <?= $verDinero && $r['comprado_anio'] > 0 ? ' · comprado este año ' . dinero($r['comprado_anio']) : '' ?></small>
            </div>
            <?php if ($r['puntaje'] !== null): ?>
                <span class="pv-puntaje pv-nivel-<?= e($r['nivel']) ?>"><strong><?= (int) $r['puntaje'] ?></strong><small><?= e(ProveedoresFicha::NIVELES[$r['nivel']]) ?></small></span>
            <?php else: ?>
                <span class="pv-puntaje pv-sin-datos"><small><?= e(ProveedoresFicha::textoSinPuntaje($r)) ?></small></span>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ol>
    <?php endif; ?>
</section>
<?php else: ?>
<div class="rejilla rejilla-2">
    <section class="bloque">
        <h2>Tus proveedores <small class="suave">(<?= count(array_filter($lista, fn($x) => $x['activo'])) ?> activos)</small></h2>
        <?php if (!$lista): ?>
            <div class="vacio">
                <p><strong>Todavía no tienes proveedores.</strong></p>
                <p>Agrega a los que trabajan contigo seguido (DJ, fotógrafo, florería). Los que trae el cliente se anotan directo en cada evento.</p>
            </div>
        <?php else: ?>
            <?php foreach (Cotizaciones::CATEGORIAS as $cat => $nombreCat): if (empty($porServicio[$cat])) continue; ?>
            <h3 class="dia-subtitulo"><?= e($nombreCat) ?></h3>
            <ul class="filas pv-filas">
                <?php foreach ($porServicio[$cat] as $x):
                    $sc = $puntajes[(int) $x['id']] ?? null;
                    $al = $alertas[(int) $x['id']] ?? null;
                    $wa = WhatsApp::enlace($x['telefono'], 'Hola ' . trim(explode(' ', (string) ($x['contacto'] ?: $x['nombre']))[0]) . ', te escribe ' . $u['negocio'] . '.'); ?>
                <li class="<?= $x['activo'] ? '' : 'dia-inactivo' ?>">
                    <div>
                        <a class="pv-nombre" href="<?= e(url('proveedor', ['id' => $x['id']])) ?>"><strong><?= e($x['nombre']) ?></strong></a>
                        <?= $x['preferido'] ? '<span class="pv-estrella" title="Preferido">★</span>' : '' ?>
                        <?php if (!$x['activo']): ?><span class="etq etq-cancelada">Inactivo</span><?php endif; ?>
                        <br><small><?= e(implode(' · ', array_filter([$x['contacto'], $x['telefono'], $x['email']]))) ?: 'Sin datos de contacto' ?></small>
                        <div class="pv-chips">
                            <?php if ($sc && $sc['puntaje'] !== null): ?><span class="pv-chip pv-nivel-<?= e($sc['nivel']) ?>"><?= e(ProveedoresFicha::textoPuntaje($sc)) ?></span><?php endif; ?>
                            <?php if ($al && $al['vencidos']): ?><span class="pv-chip pv-chip-rojo"><?= (int) $al['vencidos'] ?> doc. vencido<?= $al['vencidos'] > 1 ? 's' : '' ?></span><?php endif; ?>
                            <?php if ($al && $al['por_vencer']): ?><span class="pv-chip pv-chip-amarillo"><?= (int) $al['por_vencer'] ?> doc. por vencer</span><?php endif; ?>
                            <?php if ($verDinero && $sc && $sc['saldo'] > 0): ?><span class="pv-chip">Le debes <?= dinero($sc['saldo']) ?></span><?php endif; ?>
                            <?php if ((int) $x['proximos']): ?><span class="pv-chip pv-chip-suave"><?= (int) $x['proximos'] ?> <?= (int) $x['proximos'] === 1 ? 'evento próximo' : 'eventos próximos' ?></span><?php endif; ?>
                        </div>
                    </div>
                    <div class="derecha acciones">
                        <?php if ($wa): ?><a class="boton boton-wa boton-chico" href="<?= e($wa) ?>" target="_blank" rel="noopener" aria-label="WhatsApp a <?= e($x['nombre']) ?>">WhatsApp</a><?php endif; ?>
                        <a class="boton boton-claro boton-chico" href="<?= e(url('proveedor', ['id' => $x['id']])) ?>">Ficha</a>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
    <?php if ($editable): ?>
    <form method="post" class="bloque" id="form-proveedor">
        <h2><?= $p ? 'Editar ' . e($p['nombre']) : 'Agregar proveedor' ?></h2>
        <?= campo_csrf() ?>
        <input type="hidden" name="accion" value="<?= $p ? 'editar' : 'crear' ?>">
        <?php if ($p): ?><input type="hidden" name="proveedor_id" value="<?= (int) $p['id'] ?>"><?php endif; ?>
        <div class="campo"><label for="pnom">Nombre o empresa</label>
            <input type="text" id="pnom" name="nombre" value="<?= $v('nombre') ?>" required maxlength="150" placeholder="DJ Fiesta Total"></div>
        <div class="fila-campos">
            <div class="campo"><label for="pser">Servicio</label>
                <select id="pser" name="servicio">
                    <?php foreach (Cotizaciones::CATEGORIAS as $k => $t): ?><option value="<?= e($k) ?>" <?= ($previo['servicio'] ?? $p['servicio'] ?? 'musica') === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
                </select></div>
            <div class="campo"><label for="pcon">Persona de contacto</label>
                <input type="text" id="pcon" name="contacto" value="<?= $v('contacto') ?>" maxlength="100"></div>
        </div>
        <div class="fila-campos">
            <div class="campo"><label for="ptel">Celular</label>
                <input type="tel" id="ptel" name="telefono" value="<?= $v('telefono') ?>" placeholder="0991234567"></div>
            <div class="campo"><label for="pmail">Correo <small>(opcional)</small></label>
                <input type="email" id="pmail" name="email" value="<?= $v('email') ?>"></div>
        </div>
        <?php if ($comercial): ?>
        <details class="pv-detalles" <?= $previo || ($p && ($p['ruc'] || $p['numero_cuenta'] || (int) $p['credito_dias'])) ? 'open' : '' ?>>
            <summary>RUC, cuenta del banco y crédito</summary>
            <div class="fila-campos">
                <div class="campo"><label for="pruc">RUC o cédula</label>
                    <input type="text" id="pruc" name="ruc" value="<?= $v('ruc') ?>" inputmode="numeric" maxlength="13" placeholder="1791234567001"></div>
                <div class="campo"><label for="prazon">Razón social <small>(como sale en la factura)</small></label>
                    <input type="text" id="prazon" name="razon_social" value="<?= $v('razon_social') ?>" maxlength="150"></div>
            </div>
            <div class="fila-campos">
                <div class="campo"><label for="pdir">Dirección</label><input type="text" id="pdir" name="direccion" value="<?= $v('direccion') ?>" maxlength="250"></div>
                <div class="campo"><label for="pciu">Ciudad</label><input type="text" id="pciu" name="ciudad" value="<?= $v('ciudad') ?>" maxlength="80"></div>
            </div>
            <div class="fila-campos">
                <div class="campo"><label for="pban">Banco</label>
                    <input type="text" id="pban" name="banco" value="<?= $v('banco') ?>" maxlength="80" list="pv-bancos" placeholder="Banco Pichincha">
                    <datalist id="pv-bancos"><?php foreach (ProveedoresFicha::BANCOS as $b): ?><option value="<?= e($b) ?>"><?php endforeach; ?></datalist></div>
                <div class="campo"><label for="ptcu">Tipo de cuenta</label>
                    <select id="ptcu" name="tipo_cuenta"><option value="">—</option>
                        <?php foreach (ProveedoresFicha::TIPOS_CUENTA as $k => $t): ?><option value="<?= e($k) ?>" <?= ($previo['tipo_cuenta'] ?? $p['tipo_cuenta'] ?? '') === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
                    </select></div>
            </div>
            <div class="fila-campos">
                <div class="campo"><label for="pncu">Número de cuenta</label><input type="text" id="pncu" name="numero_cuenta" value="<?= $v('numero_cuenta') ?>" inputmode="numeric" maxlength="30"></div>
                <div class="campo"><label for="ptit">Titular de la cuenta</label><input type="text" id="ptit" name="titular_cuenta" value="<?= $v('titular_cuenta') ?>" maxlength="150"></div>
            </div>
            <div class="pv-fila-3">
                <div class="campo"><label for="pcre">Días de crédito <small>(0 = contado)</small></label>
                    <input type="number" id="pcre" name="credito_dias" value="<?= $v('credito_dias', '0') ?>" min="0" max="365"></div>
                <div class="campo"><label for="pcup">Cupo de crédito <small>($, opcional)</small></label>
                    <input type="text" id="pcup" name="cupo_credito" inputmode="decimal" value="<?= e($previo['cupo_credito'] ?? $num($p['cupo_credito'] ?? null)) ?>" placeholder="Sin límite"></div>
                <div class="campo"><label for="pant">Anticipo habitual <small>(%)</small></label>
                    <input type="text" id="pant" name="anticipo_pct" inputmode="decimal" value="<?= e($previo['anticipo_pct'] ?? $num($p['anticipo_pct'] ?? 0)) ?>"></div>
            </div>
            <label class="opcion" style="margin-bottom:14px"><input type="checkbox" name="preferido" value="1" <?= !empty($previo['preferido'] ?? $p['preferido'] ?? false) ? 'checked' : '' ?>>
                <span><strong>Proveedor preferido</strong><small>Sale con ★ y primero cuando eliges a quién comprar.</small></span></label>
        </details>
        <?php endif; ?>
        <div class="campo"><label for="pnot">Notas <small>(precios, qué incluye…)</small></label>
            <textarea id="pnot" name="notas" rows="3" maxlength="500"><?= $v('notas') ?></textarea></div>
        <?php if ($p): ?>
            <label class="opcion" style="margin-bottom:14px"><input type="checkbox" name="activo" value="1" <?= $p['activo'] ? 'checked' : '' ?>>
                <span><strong>Activo</strong><small>Desmárcalo si ya no trabajas con este proveedor.</small></span></label>
        <?php endif; ?>
        <button class="boton boton-ancho" type="submit"><?= $p ? 'Guardar cambios' : 'Agregar proveedor' ?></button>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>
