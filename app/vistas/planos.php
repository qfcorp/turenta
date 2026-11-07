<?php
use TuRenta\Planos;

/** Distribuciones (plantillas) de cada espacio. @var array $espacios @var array $lista @var array $vistas @var bool $editaPlantillas @var int $espacioElegido @var ?array $previo */
$porEspacio = [];
foreach ($lista as $p) $porEspacio[(int) $p['espacio_id']][] = $p;
$pv = fn(string $k, string $def = '') => e($previo[$k] ?? $def);
?>
<div class="cabecera">
    <div>
        <h1>Planos del salón</h1>
        <p>Arma una vez cómo se acomoda cada espacio (boda con mesas redondas, corporativo en escuela…) y úsalo en cada evento.</p>
    </div>
</div>

<?php if (!$espacios): ?>
    <section class="bloque vacio"><p>Primero crea tus espacios en <a href="<?= e(url('espacios')) ?>">Espacios</a>.</p></section>
<?php else: ?>

<?php if ($editaPlantillas): ?>
<details class="bloque pl-plegable" id="nueva" <?= $espacioElegido || !$lista || $previo ? 'open' : '' ?>>
    <summary><h2>Nueva distribución</h2></summary>
    <form method="post" action="<?= e(url('plano')) ?>">
        <?= campo_csrf() ?><input type="hidden" name="accion" value="crear_plantilla">
        <div class="rejilla-form">
            <div class="campo"><label for="pn-espacio">Espacio</label>
                <select id="pn-espacio" name="espacio_id" required>
                    <?php foreach ($espacios as $es): ?><option value="<?= (int) $es['id'] ?>" <?= $espacioElegido === (int) $es['id'] ? 'selected' : '' ?>><?= e($es['nombre']) ?></option><?php endforeach; ?>
                </select></div>
            <div class="campo"><label for="pn-nombre">Nombre</label>
                <input type="text" id="pn-nombre" name="nombre" maxlength="80" required value="<?= $pv('nombre') ?>" placeholder="Boda 150 en mesas redondas"></div>
            <div class="campo"><label for="pn-ancho">Ancho del salón (m)</label><input type="text" inputmode="decimal" id="pn-ancho" name="ancho" required value="<?= $pv('ancho', '20') ?>"></div>
            <div class="campo"><label for="pn-largo">Largo del salón (m)</label><input type="text" inputmode="decimal" id="pn-largo" name="largo" required value="<?= $pv('largo', '30') ?>"></div>
        </div>
        <fieldset class="campo pl-ejemplos">
            <legend class="etiqueta">¿Cómo empiezas?</legend>
            <div class="opciones">
                <?php foreach (Planos::EJEMPLOS as $k => $txt): ?>
                    <label class="opcion"><input type="radio" name="ejemplo" value="<?= e($k) ?>" <?= ($previo['ejemplo'] ?? 'boda') === $k ? 'checked' : '' ?>><span><?= e($txt) ?></span></label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <button class="boton" type="submit">Crear distribución</button>
    </form>
</details>
<?php endif; ?>

<?php foreach ($espacios as $es): $suyas = $porEspacio[(int) $es['id']] ?? []; ?>
<section class="bloque" id="esp-<?= (int) $es['id'] ?>">
    <h2><span class="pl-punto" style="background:<?= e($es['color']) ?>" aria-hidden="true"></span><?= e($es['nombre']) ?></h2>
    <?php if (!$suyas): ?>
        <p class="vacio">Todavía no tiene distribuciones.<?php if ($editaPlantillas): ?> <a href="<?= e(url('plano', ['espacio' => $es['id']])) ?>#nueva">Crear la primera</a><?php endif; ?></p>
    <?php else: ?>
    <div class="pl-tarjetas">
        <?php foreach ($suyas as $p): ?>
        <a class="pl-tarjeta pl-tarjeta-enlace" href="<?= e(url('plano', ['p' => $p['id']])) ?>">
            <div class="pl-mini"><?= $vistas[(int) $p['id']] ?></div>
            <div class="pl-tarjeta-cuerpo">
                <h3><?= e($p['nombre']) ?></h3>
                <p class="suave"><?= e(Planos::m($p['ancho'])) ?> × <?= e(Planos::m($p['largo'])) ?> m · <?= (int) $p['mesas'] ?> mesas · <?= (int) $p['puestos'] ?> puestos</p>
                <span class="pl-abrir"><?= $editaPlantillas ? 'Abrir y cambiar' : 'Ver' ?> →</span>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>
<?php endforeach; ?>
<?php endif; ?>
