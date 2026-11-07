<?php
use TuRenta\Planos;

/**
 * Editor del plano (distribución de un espacio o plano de un evento). El dibujo lo arma mod_plano.js;
 * sin JavaScript se ve el plano fijo (SVG del servidor).
 * @var ?array $ev @var array $plano @var array $planoDatos @var bool $editable @var bool $puedeBorrar @var array $config
 */
$esEvento = $plano['tipo'] === 'evento';
$res = $planoDatos['resumen'];
$json = json_encode(['datos' => $planoDatos, 'config' => $config], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$iconoTipo = function (string $tipo): string {
    $c = Planos::COLORES[Planos::esMesa($tipo) ? 'mesa' : $tipo];
    $f = $c[0] === 'none' ? 'none' : $c[0];
    $b = $c[1] === 'none' ? '#4A1E2A' : $c[1];
    $forma = match ($tipo) {
        'redonda' => '<circle cx="14" cy="14" r="6.5" fill="' . $f . '" stroke="' . $b . '" stroke-width="1.6"/>'
                     . implode('', array_map(fn($a) => '<circle cx="' . round(14 + 10 * cos($a), 1) . '" cy="' . round(14 + 10 * sin($a), 1) . '" r="1.8" fill="#fff" stroke="#9A8E92"/>', [0, 1.05, 2.1, 3.14, 4.19, 5.24])),
        'rectangular' => '<rect x="5" y="10" width="18" height="8" rx="1.5" fill="' . $f . '" stroke="' . $b . '" stroke-width="1.6"/>'
                     . '<circle cx="9" cy="6.5" r="1.8" fill="#fff" stroke="#9A8E92"/><circle cx="19" cy="6.5" r="1.8" fill="#fff" stroke="#9A8E92"/><circle cx="9" cy="21.5" r="1.8" fill="#fff" stroke="#9A8E92"/><circle cx="19" cy="21.5" r="1.8" fill="#fff" stroke="#9A8E92"/>',
        'imperial' => '<rect x="2" y="11" width="24" height="6" rx="1.5" fill="' . $f . '" stroke="' . $b . '" stroke-width="1.6"/>'
                     . implode('', array_map(fn($x) => '<circle cx="' . $x . '" cy="7.5" r="1.6" fill="#fff" stroke="#9A8E92"/><circle cx="' . $x . '" cy="20.5" r="1.6" fill="#fff" stroke="#9A8E92"/>', [6, 11, 17, 22])),
        'pista' => '<rect x="4" y="4" width="20" height="20" fill="#EFE4CF" stroke="' . $b . '" stroke-width="1.4"/><path d="M4 4h5v5H4zM14 4h5v5h-5zM9 9h5v5H9zM19 9h5v5h-5zM4 14h5v5H4zM14 14h5v5h-5zM9 19h5v5H9zM19 19h5v5h-5z" fill="#DCC9A4"/>',
        'columna' => '<rect x="9" y="9" width="10" height="10" fill="' . $f . '" stroke="' . $b . '" stroke-width="1.4"/>',
        'entrada' => '<rect x="4" y="18" width="20" height="5" fill="' . $f . '" stroke="' . $b . '" stroke-width="1.4"/><path d="M14 5v10M10 11l4 4 4-4" fill="none" stroke="' . $b . '" stroke-width="1.8" stroke-linecap="round"/>',
        'texto' => '<text x="14" y="19" text-anchor="middle" font-family="DM Sans, Arial" font-weight="700" font-size="14" fill="#4A1E2A">Aa</text>',
        default => '<rect x="3" y="8" width="22" height="12" rx="2" fill="' . $f . '" stroke="' . $b . '" stroke-width="1.4"/>',
    };
    return '<svg viewBox="0 0 28 28" width="28" height="28" aria-hidden="true">' . $forma . '</svg>';
};
?>
<?php if ($esEvento && $ev): require RAIZ . '/app/vistas/_evento_tabs.php'; else: ?>
<p class="pl-migas"><a href="<?= e(url('plano', ['espacio' => $plano['espacio_id']])) ?>">Planos del salón</a> · <?= e($plano['espacio']) ?></p>
<?php endif; ?>

<div class="cabecera pl-cabecera">
    <div>
        <h1><?= $esEvento ? 'Plano del salón' : e($plano['nombre']) ?></h1>
        <p><?= $esEvento ? e($plano['nombre']) . ' · ' : '' ?><?= e(Planos::m($plano['ancho'])) ?> × <?= e(Planos::m($plano['largo'])) ?> m<?= $plano['espacio'] ? ' · ' . e($plano['espacio']) : '' ?></p>
    </div>
    <div class="acciones">
        <a class="boton boton-claro boton-chico" href="<?= e(url('pdf', ['tipo' => 'plano', 'id' => $plano['id']])) ?>" target="_blank" rel="noopener">PDF para imprimir</a>
        <?php if ($esEvento && $ev): ?>
            <a class="boton boton-claro boton-chico" href="<?= e(url('invitados', ['id' => $ev['id'], 'ver' => 'mesas'])) ?>">Sentar invitados</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($esEvento): ?>
<div class="pl-resumen" data-pl-resumen>
    <div><strong data-pl-r="puestos"><?= (int) $res['puestos'] ?></strong><span>puestos en <span data-pl-r="mesas"><?= (int) $res['mesas'] ?></span> mesas</span></div>
    <div><strong data-pl-r="confirmados"><?= (int) $res['confirmados'] ?></strong><span>confirmados</span></div>
    <div><strong data-pl-r="esperados"><?= (int) $res['esperados'] ?></strong><span>esperados<small> (confirmados + por responder)</small></span></div>
    <div class="<?= $res['sin_mesa'] ? 'pl-r-alerta' : '' ?>" data-pl-r-caja="sin_mesa"><strong data-pl-r="sin_mesa"><?= (int) $res['sin_mesa'] ?></strong><span>invitados sin mesa</span></div>
</div>
<p class="pl-balance suave" data-pl-balance></p>
<?php endif; ?>

<div class="pl-editor<?= $editable ? '' : ' pl-solo-mirar' ?>" data-pl-editor>
    <script type="application/json" data-pl-json><?= $json ?></script>

    <div class="pl-barra" role="toolbar" aria-label="Herramientas del plano">
        <?php if ($editable): ?>
        <button type="button" class="pl-btn" data-pl-accion="deshacer" disabled title="Deshacer (Ctrl+Z)" aria-label="Deshacer el último cambio">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 14L4 9l5-5"/><path d="M4 9h10a6 6 0 0 1 0 12h-3"/></svg><span>Deshacer</span></button>
        <?php endif; ?>
        <div class="pl-zoom" role="group" aria-label="Acercar o alejar">
            <button type="button" class="pl-btn pl-btn-icono" data-pl-accion="alejar" aria-label="Alejar"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14"/></svg></button>
            <button type="button" class="pl-btn" data-pl-accion="ajustar" aria-label="Ver el salón completo"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/></svg><span>Ajustar</span></button>
            <button type="button" class="pl-btn pl-btn-icono" data-pl-accion="acercar" aria-label="Acercar"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M12 5v14"/></svg></button>
        </div>
        <?php if ($editable): ?>
        <span class="pl-estado" data-pl-estado role="status" aria-live="polite">Guardado ✓</span>
        <button type="button" class="boton boton-chico pl-guardar" data-pl-accion="guardar">Guardar</button>
        <?php else: ?>
        <span class="pl-estado pl-estado-mirar">Solo para mirar</span>
        <?php endif; ?>
    </div>

    <?php if ($editable): ?>
    <div class="pl-agregar" role="group" aria-label="Agregar al plano">
        <span class="pl-agregar-titulo">Agregar</span>
        <?php foreach (Planos::TIPOS as $k => $t): ?>
            <button type="button" class="pl-tipo" data-pl-agregar="<?= e($k) ?>" title="<?= e($t['nombre']) ?>"><?= $iconoTipo($k) ?><span><?= e($t['corto']) ?></span></button>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="pl-cuerpo">
        <div class="pl-lienzo" data-pl-lienzo>
            <noscript><?= Planos::svg($planoDatos, ['id' => 'ns', 'ocupacion' => true, 'clase' => 'pl-svg-fijo']) ?></noscript>
            <div class="pl-flotante" data-pl-flotante hidden>
                <span class="pl-flotante-nombre" data-pl-flotante-nombre></span>
                <?php if ($editable): ?>
                <button type="button" class="pl-btn pl-btn-icono" data-pl-accion="girar-izq" aria-label="Girar a la izquierda"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4v6h6"/><path d="M3.5 10A8.5 8.5 0 1 1 6 17"/></svg></button>
                <button type="button" class="pl-btn pl-btn-icono" data-pl-accion="girar-der" aria-label="Girar a la derecha"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 4v6h-6"/><path d="M20.5 10A8.5 8.5 0 1 0 18 17"/></svg></button>
                <button type="button" class="pl-btn pl-btn-icono" data-pl-accion="duplicar" aria-label="Duplicar"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V5a1 1 0 0 0-1-1H5a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h3"/></svg></button>
                <button type="button" class="pl-btn pl-btn-icono pl-btn-peligro" data-pl-accion="borrar" aria-label="Borrar"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/></svg></button>
                <?php endif; ?>
                <button type="button" class="pl-btn pl-btn-icono" data-pl-accion="soltar" aria-label="Quitar la selección"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
            </div>
        </div>
        <aside class="pl-panel" data-pl-panel aria-live="polite"></aside>
    </div>
    <div class="pl-mensajes" data-pl-mensajes aria-live="assertive"></div>
</div>

<?php if ($editable || $puedeBorrar): ?>
<div class="rejilla rejilla-2 pl-ajustes">
    <?php if ($editable): ?>
    <details class="bloque pl-plegable">
        <summary><h2>Nombre y medidas del salón</h2></summary>
        <form method="post" action="<?= e(url('plano', $esEvento && $ev ? ['id' => $ev['id']] : ['p' => $plano['id']])) ?>">
            <?= campo_csrf() ?><input type="hidden" name="accion" value="datos">
            <div class="campo"><label for="pl-nombre">Nombre</label><input type="text" id="pl-nombre" name="nombre" value="<?= e($plano['nombre']) ?>" maxlength="80" required></div>
            <div class="fila-campos pl-fila-2">
                <div class="campo"><label for="pl-ancho">Ancho (m)</label><input type="text" inputmode="decimal" id="pl-ancho" name="ancho" value="<?= e(Planos::m($plano['ancho'])) ?>" required></div>
                <div class="campo"><label for="pl-largo">Largo (m)</label><input type="text" inputmode="decimal" id="pl-largo" name="largo" value="<?= e(Planos::m($plano['largo'])) ?>" required></div>
            </div>
            <p class="suave"><small>Si achicas el salón, lo que quede afuera se acerca a la pared.</small></p>
            <button class="boton boton-chico" type="submit">Guardar medidas</button>
        </form>
    </details>
    <?php endif; ?>
    <?php if ($puedeBorrar && puede_editar($u)): ?>
    <section class="bloque">
        <h2><?= $esEvento ? 'Empezar de nuevo' : 'Esta distribución' ?></h2>
        <?php if ($esEvento): ?>
            <p class="suave">Borra este plano para elegir otra distribución. Las mesas y los invitados sentados no se pierden.</p>
            <form method="post" action="<?= e(url('plano', ['id' => $ev['id']])) ?>"><?= campo_csrf() ?><input type="hidden" name="accion" value="evento_borrar">
                <button class="boton boton-peligro boton-chico" type="submit" data-confirmar="¿Borrar el plano de este evento? Las mesas y los invitados sentados se mantienen.">Borrar el plano</button></form>
        <?php else: ?>
            <div class="acciones">
                <form method="post" action="<?= e(url('plano', ['p' => $plano['id']])) ?>"><?= campo_csrf() ?><input type="hidden" name="accion" value="duplicar">
                    <button class="boton boton-claro boton-chico" type="submit">Hacer una copia</button></form>
                <form method="post" action="<?= e(url('plano', ['p' => $plano['id']])) ?>"><?= campo_csrf() ?><input type="hidden" name="accion" value="borrar">
                    <button class="boton boton-peligro boton-chico" type="submit" data-confirmar="¿Borrar la distribución «<?= e($plano['nombre']) ?>»? Los planos de eventos que ya la usaron no cambian.">Borrar</button></form>
            </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</div>
<?php endif; ?>
