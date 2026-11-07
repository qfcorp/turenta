<?php
use TuRenta\Planos;

/** El evento todavía no tiene plano. @var array $ev @var array $plantillas @var array $vistas @var int $mesasHay @var bool $puedeArmar @var ?array $previo */
$delEvento = array_values(array_filter($plantillas, fn($p) => $p['del_evento']));
$otras = array_values(array_filter($plantillas, fn($p) => !$p['del_evento']));
$sugerido = $delEvento[0] ?? $plantillas[0] ?? null;
$tarjeta = function (array $p) use ($vistas, $puedeArmar, $ev): string {
    $h = '<article class="pl-tarjeta">' . '<div class="pl-mini">' . $vistas[(int) $p['id']] . '</div>'
       . '<div class="pl-tarjeta-cuerpo"><h3>' . e($p['nombre']) . '</h3><p class="suave">' . e($p['espacio']) . ' · ' . e(Planos::m($p['ancho'])) . ' × ' . e(Planos::m($p['largo'])) . ' m<br>'
       . (int) $p['mesas'] . ' mesas · ' . (int) $p['puestos'] . ' puestos</p>';
    if ($puedeArmar) {
        $h .= '<form method="post" action="' . e(url('plano', ['id' => $ev['id']])) . '">' . campo_csrf()
            . '<input type="hidden" name="accion" value="evento_crear"><input type="hidden" name="plantilla_id" value="' . (int) $p['id'] . '">'
            . '<button class="boton boton-chico boton-ancho" type="submit">Usar esta</button></form>';
    }
    return $h . '</div></article>';
};
?>
<?php require RAIZ . '/app/vistas/_evento_tabs.php'; ?>

<div class="cabecera">
    <div><h1>Plano del salón</h1>
        <p>Dibuja cómo va a quedar el salón para este evento: mesas, pista, tarima, barra… Las mesas del plano son las mesas de los invitados.</p></div>
</div>

<?php if (!$puedeArmar): ?>
    <p class="bloque vacio">Este evento todavía no tiene plano.</p>
<?php else: ?>
    <?php if ($mesasHay): ?>
        <p class="aviso aviso-ok">Este evento ya tiene <?= $mesasHay ?> <?= $mesasHay === 1 ? 'mesa' : 'mesas' ?> en invitados: al usar una distribución, se ubican en sus mesas en orden (con sus invitados sentados).</p>
    <?php endif; ?>

    <?php if ($plantillas): ?>
    <section class="bloque">
        <h2>Usar una distribución de tus espacios</h2>
        <p class="suave">Se copia para este evento: lo que cambies aquí no cambia la distribución original.</p>
        <div class="pl-tarjetas">
            <?php foreach ($delEvento as $p) echo $tarjeta($p); ?>
            <?php foreach ($otras as $p) echo $tarjeta($p); ?>
        </div>
    </section>
    <?php endif; ?>

    <form method="post" class="bloque" action="<?= e(url('plano', ['id' => $ev['id']])) ?>">
        <?= campo_csrf() ?><input type="hidden" name="accion" value="evento_crear">
        <h2><?= $plantillas ? 'O empezar desde cero' : 'Empezar el plano' ?></h2>
        <p class="suave">Escribe el tamaño del salón en metros. Después agregas y mueves todo con el dedo o el mouse.</p>
        <div class="fila-campos pl-fila-2">
            <div class="campo"><label for="pe-ancho">Ancho (m)</label><input type="text" inputmode="decimal" id="pe-ancho" name="ancho" required
                value="<?= e($previo['ancho'] ?? ($sugerido ? Planos::m($sugerido['ancho']) : '20')) ?>"></div>
            <div class="campo"><label for="pe-largo">Largo (m)</label><input type="text" inputmode="decimal" id="pe-largo" name="largo" required
                value="<?= e($previo['largo'] ?? ($sugerido ? Planos::m($sugerido['largo']) : '30')) ?>"></div>
        </div>
        <button class="boton" type="submit">Empezar plano vacío</button>
        <?php if (!$plantillas && es_admin_total($u)): ?>
            <p class="suave" style="margin-top:10px"><small>Consejo: arma una vez la distribución de cada espacio en <a href="<?= e(url('plano')) ?>">Planos del salón</a> y úsala en cada evento.</small></p>
        <?php endif; ?>
    </form>
<?php endif; ?>
