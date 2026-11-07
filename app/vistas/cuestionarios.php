<?php
use TuRenta\{Cuestionarios, Eventos};
/**
 * Formularios del evento (plantillas).
 * @var array $plantillas @var string $tipo @var array $plantilla @var array $ajustes @var bool $editar @var ?array $borrador
 */
$secciones = array_values(array_unique(array_filter(array_column($plantilla['preguntas'], 'seccion'))));
$url = url('cuestionarios', ['tipo' => $tipo]);
$n = count($plantilla['preguntas']);
?>
<div class="cabecera">
    <div>
        <h1>Formularios del evento</h1>
        <p>Las preguntas que el cliente responde desde su portal: música, decoración, menú, horarios… Un formulario por tipo de evento.</p>
    </div>
</div>

<section class="bloque" id="ajustes">
    <h2>Fechas del formulario</h2>
    <form method="post" action="<?= e($url) ?>" class="fila-campos pt-ajustes">
        <?= campo_csrf() ?><input type="hidden" name="accion" value="ajustes">
        <div class="campo"><label for="dias_cierre">El cliente lo llena hasta</label>
            <div class="pt-dias"><input type="number" id="dias_cierre" name="dias_cierre" min="0" max="90" value="<?= (int) $ajustes['cierre'] ?>" <?= $editar ? '' : 'disabled' ?>> <span>días antes del evento</span></div>
            <small>Después, solo tu equipo puede cambiar las respuestas.</small></div>
        <div class="campo"><label for="dias_aviso">Recordarle por WhatsApp desde</label>
            <div class="pt-dias"><input type="number" id="dias_aviso" name="dias_aviso" min="1" max="365" value="<?= (int) $ajustes['aviso'] ?>" <?= $editar ? '' : 'disabled' ?>> <span>días antes del evento</span></div>
            <small>Sale en "Mensajes por WhatsApp" si no lo ha llenado.</small></div>
        <?php if ($editar): ?><div class="campo pt-ajustes-boton"><button class="boton boton-claro" type="submit">Guardar</button></div><?php endif; ?>
    </form>
</section>

<nav class="cb-chips pt-tipos" aria-label="Tipo de evento">
    <?php foreach ($plantillas as $p): ?>
        <a href="<?= e(url('cuestionarios', ['tipo' => $p['tipo_evento']])) ?>" class="<?= $p['tipo_evento'] === $tipo ? 'activo' : '' ?>" <?= $p['tipo_evento'] === $tipo ? 'aria-current="page"' : '' ?>>
            <?= e(Eventos::TIPOS[$p['tipo_evento']] ?? $p['tipo_evento']) ?><small><?= (int) $p['preguntas'] ?></small><?= $p['activo'] ? '' : ' · apagado' ?></a>
    <?php endforeach; ?>
</nav>

<section class="bloque" id="plantilla">
    <?php if ($editar): ?>
    <form method="post" action="<?= e($url) ?>" class="pt-plantilla-cabeza">
        <?= campo_csrf() ?><input type="hidden" name="accion" value="plantilla">
        <div class="campo"><label for="pl-nombre">Nombre del formulario (lo ve el cliente)</label>
            <input type="text" id="pl-nombre" name="nombre" maxlength="100" required value="<?= e($plantilla['nombre']) ?>"></div>
        <label class="opcion"><input type="checkbox" name="activo" value="1" <?= $plantilla['activo'] ? 'checked' : '' ?>>
            <span><strong>Usar este formulario</strong><small>Si lo apagas, los eventos de este tipo usan el formulario de "Otro".</small></span></label>
        <button class="boton boton-claro boton-chico" type="submit">Guardar</button>
    </form>
    <?php else: ?>
        <h2><?= e($plantilla['nombre']) ?></h2>
    <?php endif; ?>
    <p class="suave ayuda-chica">Cada evento recibe una copia de este formulario. Si cambias algo aquí, vale para los eventos que todavía no lo tienen; los formularios ya copiados no cambian.</p>
</section>

<section class="bloque" id="preguntas">
    <h2>Preguntas <small class="suave">(<?= $n ?>)</small></h2>
    <?php if (!$n): ?><p class="vacio">Este formulario no tiene preguntas.</p><?php endif; ?>
    <ol class="pt-plantilla-lista">
        <?php $seccionAnt = null; foreach ($plantilla['preguntas'] as $i => $q):
            if (($q['seccion'] ?: '') !== $seccionAnt): $seccionAnt = $q['seccion'] ?: ''; ?>
                <li class="pt-plantilla-seccion" aria-hidden="true"><?= e($seccionAnt ?: 'Sin sección') ?></li>
            <?php endif; ?>
        <li class="pt-plantilla-pregunta" id="q<?= (int) $q['id'] ?>">
            <div class="pt-plantilla-fila">
                <span class="pt-plantilla-texto"><strong><?= e($q['texto']) ?></strong><?= $q['obligatoria'] ? ' <span class="pt-obl">*</span>' : '' ?>
                    <small class="suave"><?= e(Cuestionarios::TIPOS[$q['tipo']]) ?><?= $q['opciones'] ? ': ' . e(implode(', ', Cuestionarios::opciones($q['opciones']))) : '' ?></small></span>
                <?php if ($editar): ?>
                <span class="pt-plantilla-botones">
                    <?php foreach (['subir' => ['↑', $i === 0], 'bajar' => ['↓', $i === $n - 1]] as $acc => [$flecha, $off]): ?>
                    <form method="post" action="<?= e($url) ?>"><?= campo_csrf() ?><input type="hidden" name="accion" value="<?= $acc ?>"><input type="hidden" name="pregunta_id" value="<?= (int) $q['id'] ?>">
                        <button class="boton boton-claro boton-chico" type="submit" <?= $off ? 'disabled' : '' ?> aria-label="<?= $acc === 'subir' ? 'Subir' : 'Bajar' ?>: <?= e($q['texto']) ?>"><?= $flecha ?></button></form>
                    <?php endforeach; ?>
                    <form method="post" action="<?= e($url) ?>"><?= campo_csrf() ?><input type="hidden" name="accion" value="pregunta_borrar"><input type="hidden" name="pregunta_id" value="<?= (int) $q['id'] ?>">
                        <button class="boton boton-peligro boton-chico" type="submit" data-confirmar="¿Borrar «<?= e($q['texto']) ?>»?" aria-label="Borrar: <?= e($q['texto']) ?>">Borrar</button></form>
                </span>
                <?php endif; ?>
            </div>
            <?php if ($editar): ?>
            <details class="pt-editar">
                <summary>Editar</summary>
                <?php $f = $q; $ptAccionUrl = $url; $ptOcultos = ['accion' => 'pregunta', 'pregunta_id' => (int) $q['id']]; $ptSecciones = $secciones; $ptBoton = 'Guardar pregunta';
                require RAIZ . '/app/vistas/_pt_pregunta_form.php'; ?>
            </details>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ol>
</section>

<?php if ($editar): ?>
<section class="bloque" id="nueva">
    <h2>Agregar una pregunta</h2>
    <?php $f = $borrador ?? []; $ptAccionUrl = $url; $ptOcultos = ['accion' => 'pregunta']; $ptSecciones = $secciones; $ptBoton = 'Agregar pregunta';
    require RAIZ . '/app/vistas/_pt_pregunta_form.php'; ?>
</section>

<form method="post" action="<?= e($url) ?>" class="pt-restaurar">
    <?= campo_csrf() ?><input type="hidden" name="accion" value="restaurar">
    <button class="enlace-chico cb-boton-enlace" type="submit" data-confirmar="¿Volver a las preguntas de ejemplo? Se borran las preguntas que tiene este formulario (los eventos que ya lo tienen no cambian).">Volver a las preguntas de ejemplo</button>
</form>
<?php endif; ?>
