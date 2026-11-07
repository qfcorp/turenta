<?php
use TuRenta\Contratos;

/** @var array $plantillas @var ?array $editar @var ?array $previa @var ?string $textoPrevia @var array $ejemplo
 *  @var array $anexo @var ?string $editarAnexo @var array $ejemploAnexo @var ?string $textoPreviaAnexo (anexo por extras, migración 090) */
?>
<div class="cabecera">
    <div>
        <h1>Plantillas de contrato</h1>
        <p>El texto base de tus contratos. Al generar un contrato, las variables como <code>{{cliente}}</code> se llenan solas con los datos del evento.</p>
    </div>
    <?php if ($editar === null): ?>
    <div class="acciones"><a class="boton" href="<?= e(url('contrato', ['plantilla' => 'nueva'])) ?>">+ Nueva plantilla</a></div>
    <?php endif; ?>
</div>

<?php if ($editar !== null): ?>
<div class="doc-plantilla-editor">
    <form method="post" action="<?= e(url('contrato', ['accion' => 'guardar_plantilla'])) ?>" class="bloque">
        <?= campo_csrf() ?>
        <?php if (!empty($editar['id'])): ?><input type="hidden" name="plantilla_id" value="<?= (int) $editar['id'] ?>"><?php endif; ?>
        <h2><?= !empty($editar['id']) ? 'Editar plantilla' : 'Nueva plantilla' ?></h2>
        <div class="campo"><label for="tnom">Nombre</label>
            <input type="text" id="tnom" name="nombre" required maxlength="100" value="<?= e($editar['nombre']) ?>" placeholder="Ej.: Contrato de bodas"></div>
        <div class="campo"><label for="ttexto">Texto del contrato</label>
            <textarea id="ttexto" name="texto" rows="26" required class="doc-texto-contrato-edit" data-doc-plantilla-texto><?= e($editar['texto']) ?></textarea>
            <small>Deja una línea en blanco entre cláusulas. Las líneas que empiezan con • salen como lista.</small></div>
        <label class="opcion" style="margin-bottom:14px"><input type="checkbox" name="predeterminada" value="1" <?= !empty($editar['predeterminada']) ? 'checked' : '' ?>>
            <span><strong>Usar esta plantilla por defecto</strong><small>Es la que se elige sola al generar un contrato.</small></span></label>
        <div class="acciones">
            <button class="boton" type="submit">Guardar plantilla</button>
            <a class="boton boton-claro" href="<?= e(url('contrato')) ?>">Cancelar</a>
        </div>
    </form>
    <aside class="bloque doc-variables">
        <h2>Variables</h2>
        <p class="suave">Toca una para ponerla donde está el cursor.</p>
        <ul>
            <?php foreach (Contratos::VARIABLES as $k => $desc): ?>
            <li><button type="button" class="doc-variable" data-doc-variable="{{<?= e($k) ?>}}" title="Ejemplo: <?= e(mb_strimwidth((string) ($ejemplo[$k] ?? ''), 0, 80, '…')) ?>">
                <code>{{<?= e($k) ?>}}</code><small><?= e($desc) ?></small></button></li>
            <?php endforeach; ?>
        </ul>
    </aside>
</div>
<?php endif; ?>

<?php if ($previa): ?>
<section class="bloque" id="previa">
    <div class="doc-version-cabeza">
        <h2>Vista previa: <?= e($previa['nombre']) ?> <small>(con datos de ejemplo)</small></h2>
        <a class="boton boton-claro boton-chico" href="<?= e(url('contrato')) ?>">Cerrar</a>
    </div>
    <div class="texto-contrato"><?= e($textoPrevia) ?></div>
</section>
<?php endif; ?>

<section class="bloque">
    <h2>Tus plantillas</h2>
    <?php if (!$plantillas): ?>
        <div class="vacio">
            <p><strong>Todavía no tienes plantillas.</strong><br>Mientras tanto, los contratos salen con el texto de fábrica de TuRenta (pensado para Ecuador).</p>
            <a class="boton" href="<?= e(url('contrato', ['plantilla' => 'nueva'])) ?>">Crear una desde el texto de fábrica</a>
        </div>
    <?php else: ?>
    <ul class="filas">
        <?php foreach ($plantillas as $p): ?>
        <li>
            <div><strong><?= e($p['nombre']) ?></strong>
                <?php if ($p['predeterminada']): ?><span class="doc-estado doc-estado-aceptada">Predeterminada</span><?php endif; ?>
                <br><small><?= number_format(mb_strlen($p['texto']), 0, ',', '.') ?> letras · <?= substr_count($p['texto'], '{{') ?> variables</small></div>
            <div class="acciones derecha">
                <a class="boton boton-claro boton-chico" href="<?= e(url('contrato', ['previa' => $p['id']])) ?>#previa">Vista previa</a>
                <a class="boton boton-claro boton-chico" href="<?= e(url('contrato', ['plantilla' => $p['id']])) ?>">Editar</a>
                <?php if (!$p['predeterminada']): ?>
                <form method="post" action="<?= e(url('contrato', ['accion' => 'predeterminada'])) ?>">
                    <?= campo_csrf() ?><input type="hidden" name="plantilla_id" value="<?= (int) $p['id'] ?>">
                    <button class="boton boton-claro boton-chico" type="submit">Usar por defecto</button>
                </form>
                <?php endif; ?>
                <form method="post" action="<?= e(url('contrato', ['accion' => 'restaurar'])) ?>">
                    <?= campo_csrf() ?><input type="hidden" name="plantilla_id" value="<?= (int) $p['id'] ?>">
                    <button class="boton boton-claro boton-chico" type="submit" data-confirmar="¿Reemplazar el texto de «<?= e($p['nombre']) ?>» por el de fábrica? Se pierden tus cambios en esta plantilla.">Restaurar la de fábrica</button>
                </form>
                <?php if (!$p['predeterminada']): ?>
                <form method="post" action="<?= e(url('contrato', ['accion' => 'borrar_plantilla'])) ?>">
                    <?= campo_csrf() ?><input type="hidden" name="plantilla_id" value="<?= (int) $p['id'] ?>">
                    <button class="boton boton-peligro boton-chico" type="submit" data-confirmar="¿Borrar la plantilla «<?= e($p['nombre']) ?>»? Los contratos ya hechos no cambian.">Borrar</button>
                </form>
                <?php endif; ?>
            </div>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</section>
<?php require RAIZ . '/app/vistas/_anexo_plantilla.php'; // anexo por extras aprobados (migración 090) ?>

<p class="suave">Los contratos ya generados no cambian cuando editas una plantilla. Para usar el texto nuevo en un evento, anula su contrato y genera uno nuevo.</p>
