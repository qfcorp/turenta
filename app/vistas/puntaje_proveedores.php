<?php
use TuRenta\PuntajeProveedores;

/** Cómo se calcula el puntaje. @var array $cfg (guardada) @var ?array $propuesta (lo que se está probando) @var ?array $previa @var bool $dueno
 *  @var array $historial @var bool $recomendada */
$val = $propuesta ?? $cfg;
$v = fn(string $k) => e((string) (is_bool($val[$k] ?? null) ? (int) $val[$k] : ($val[$k] ?? '')));
$suma = array_sum(array_map(fn($k) => (int) ($val[$k] ?? 0), array_keys(PuntajeProveedores::PESOS)));
$ayudaPeso = [
    'peso_calificacion' => 'Las estrellas que le pones después del evento.',
    'peso_puntualidad' => 'Si llega a la hora pactada y empieza a tiempo lo que le toca en el cronograma.',
    'peso_cumplimiento' => 'Si cumplió con lo que se le pidió (con observaciones cuenta la mitad).',
    'peso_incidencias' => 'Empieza en 100 y cada problema reportado le resta.',
];
?>
<div class="cabecera">
    <div>
        <p class="evento-sub" style="margin:0"><a href="<?= e(url('proveedores')) ?>">Proveedores</a> · <a href="<?= e(url('proveedores', ['ver' => 'ranking'])) ?>">Ranking</a></p>
        <h1>Cómo se calcula el puntaje</h1>
        <p>Cada local mide a sus proveedores a su manera. <?= $dueno ? 'Aquí decides qué pesa más.' : 'Esto lo decide el dueño del local (o un administrador).' ?></p>
    </div>
    <?php if ($recomendada): ?><span class="pv-chip pv-chip-verde">Valores recomendados</span><?php else: ?><span class="pv-chip pv-chip-amarillo">Configuración propia</span><?php endif; ?>
</div>

<section class="bloque pv-explica">
    <h2>Así se calcula hoy</h2>
    <ul>
        <?php foreach (PuntajeProveedores::explicar($cfg) as $linea): ?><li><?= e($linea) ?></li><?php endforeach; ?>
    </ul>
    <?php if (!$dueno): ?><p class="suave"><small>Si crees que algo debería pesar distinto, coméntalo con el dueño: él lo cambia aquí mismo.</small></p><?php endif; ?>
</section>

<?php if ($dueno): ?>
<div class="rejilla rejilla-2 pv-config">
<form method="post" action="<?= e(url('puntaje_proveedores')) ?>" class="bloque" data-pv-puntaje id="config"><?= campo_csrf() ?>
    <h2>Cambiar el cálculo</h2>
    <fieldset class="pv-config-grupo">
        <legend>¿Cuánto pesa cada cosa?</legend>
        <p class="suave"><small>Los pesos deben sumar <strong>100 %</strong>. Pon 0 en lo que no quieras tomar en cuenta.
            <span class="solo-celular">La vista previa se actualiza sola, <a href="#vista-previa">abajo, junto a Guardar</a>.</span></small></p>
        <?php foreach (PuntajeProveedores::PESOS as $k => $t): ?>
        <div class="pv-peso">
            <label for="<?= e($k) ?>"><strong><?= e($t) ?></strong><small><?= e($ayudaPeso[$k]) ?></small></label>
            <span class="pv-peso-campo"><input type="number" id="<?= e($k) ?>" name="<?= e($k) ?>" value="<?= $v($k) ?>" min="0" max="100" step="1" inputmode="numeric" required> %</span>
        </div>
        <?php endforeach; ?>
        <p class="pv-suma <?= $suma === 100 ? 'pv-suma-ok' : 'pv-suma-mal' ?>" data-pv-suma aria-live="polite"><?= $suma === 100 ? '✓ Suman 100 %' : "Suman $suma %: deben sumar 100 %" ?></p>
    </fieldset>

    <fieldset class="pv-config-grupo">
        <legend>Puntualidad</legend>
        <div class="campo"><label for="tolerancia_min">Minutos de tolerancia <small>(hasta cuántos minutos tarde todavía cuenta "a tiempo")</small></label>
            <input type="number" id="tolerancia_min" name="tolerancia_min" value="<?= $v('tolerancia_min') ?>" min="0" max="120" inputmode="numeric" required></div>
    </fieldset>

    <fieldset class="pv-config-grupo">
        <legend>Incidencias</legend>
        <p class="suave"><small>La parte de incidencias empieza en 100 y cada problema le resta estos puntos (nunca baja de 0).</small></p>
        <div class="pv-fila-3">
            <div class="campo"><label for="resta_leve">Leve resta</label><input type="number" id="resta_leve" name="resta_leve" value="<?= $v('resta_leve') ?>" min="0" max="100" inputmode="numeric" required></div>
            <div class="campo"><label for="resta_media">Media resta</label><input type="number" id="resta_media" name="resta_media" value="<?= $v('resta_media') ?>" min="0" max="100" inputmode="numeric" required></div>
            <div class="campo"><label for="resta_grave">Grave resta</label><input type="number" id="resta_grave" name="resta_grave" value="<?= $v('resta_grave') ?>" min="0" max="100" inputmode="numeric" required></div>
        </div>
        <label class="opcion" style="margin-bottom:10px"><input type="checkbox" name="tope_grave" value="1" <?= !empty($val['tope_grave']) ? 'checked' : '' ?>>
            <span><strong>Con una incidencia grave, el nivel tiene un tope</strong><small>Aunque sume muchos puntos, no sale como el mejor.</small></span></label>
        <div class="campo"><label for="tope_nivel">El nivel no pasa de</label>
            <select id="tope_nivel" name="tope_nivel"><?php foreach (['bueno', 'regular', 'malo'] as $n): ?><option value="<?= e($n) ?>" <?= ($val['tope_nivel'] ?? '') === $n ? 'selected' : '' ?>><?= e(PuntajeProveedores::NIVELES[$n]) ?></option><?php endforeach; ?></select></div>
    </fieldset>

    <fieldset class="pv-config-grupo">
        <legend>Niveles</legend>
        <div class="pv-fila-3">
            <div class="campo"><label for="umbral_excelente">Excelente desde</label><input type="number" id="umbral_excelente" name="umbral_excelente" value="<?= $v('umbral_excelente') ?>" min="1" max="100" inputmode="numeric" required></div>
            <div class="campo"><label for="umbral_bueno">Bueno desde</label><input type="number" id="umbral_bueno" name="umbral_bueno" value="<?= $v('umbral_bueno') ?>" min="1" max="100" inputmode="numeric" required></div>
            <div class="campo"><label for="umbral_regular">Regular desde</label><input type="number" id="umbral_regular" name="umbral_regular" value="<?= $v('umbral_regular') ?>" min="1" max="100" inputmode="numeric" required></div>
        </div>
        <p class="suave"><small>Menos que "Regular" sale como <strong>Malo</strong>.</small></p>
    </fieldset>

    <fieldset class="pv-config-grupo">
        <legend>Qué se evalúa</legend>
        <div class="fila-campos">
            <div class="campo"><label for="periodo_meses">Periodo</label>
                <select id="periodo_meses" name="periodo_meses"><?php foreach (PuntajeProveedores::PERIODOS as $k => $t): ?><option value="<?= (int) $k ?>" <?= (int) ($val['periodo_meses'] ?? 0) === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
            <div class="campo"><label for="minimo_eventos">Mostrar el puntaje desde <small>(eventos trabajados)</small></label>
                <input type="number" id="minimo_eventos" name="minimo_eventos" value="<?= $v('minimo_eventos') ?>" min="0" max="50" inputmode="numeric" required></div>
        </div>
        <p class="suave"><small>Con menos eventos que ese mínimo sale "Sin datos suficientes". 0 = siempre que haya algo medido.</small></p>
    </fieldset>

    <div class="acciones">
        <button class="boton" type="submit" name="accion" value="guardar">Guardar</button>
        <button class="boton boton-claro" type="submit" name="accion" value="vista_previa" formnovalidate>Ver cómo quedaría</button>
    </div>
</form>

<div>
    <section class="bloque pv-vista" id="vista-previa">
        <h2>Vista previa</h2>
        <p class="suave"><small><?= $propuesta ? 'Así quedarían tus proveedores con lo que estás probando (todavía no se guardó).' : 'Cambia los valores y mira aquí, antes de guardar, cómo quedarían 3 de tus proveedores.' ?></small></p>
        <p class="aviso aviso-error" data-pv-error hidden role="alert"></p>
        <div data-pv-vista><?php if ($previa) require RAIZ . '/app/vistas/_pv_vista_previa.php'; ?></div>
    </section>
    <form method="post" action="<?= e(url('puntaje_proveedores')) ?>" class="bloque"><?= campo_csrf() ?><input type="hidden" name="accion" value="recomendados">
        <h2>Valores recomendados</h2>
        <p class="suave"><small><?= e(PuntajeProveedores::resumen(PuntajeProveedores::RECOMENDADO)) ?></small></p>
        <button class="boton boton-claro" type="submit" <?= $recomendada ? 'disabled' : '' ?> data-confirmar="¿Volver a los valores recomendados? Se reemplaza tu configuración.">Volver a los valores recomendados</button>
    </form>
</div>
</div>
<?php endif; ?>

<?php if ($historial): ?>
<section class="bloque">
    <h2>Cambios anteriores</h2>
    <ol class="pv-linea">
        <?php foreach ($historial as $h): ?>
        <li class="pv-mov-condiciones"><span class="pv-linea-punto" aria-hidden="true"></span>
            <div><p><?= e(preg_replace('/^Puntaje de proveedores: /', '', $h['accion'])) ?></p>
                <small><?= e(fecha_corta(substr((string) $h['creado_en'], 0, 10))) ?> <?= e(substr((string) $h['creado_en'], 11, 5)) ?> · <?= e($h['quien']) ?></small></div></li>
        <?php endforeach; ?>
    </ol>
</section>
<?php endif; ?>
