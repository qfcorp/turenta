<?php
/**
 * Plantillas de contrato → "Anexo por extras" (migración 090): ver, editar con sus variables, vista previa y restaurar.
 * @var array $anexo @var ?string $editarAnexo @var array $ejemploAnexo @var ?string $textoPreviaAnexo
 */
use TuRenta\Anexos;
?>
<section class="bloque" id="anexo">
    <div class="doc-version-cabeza">
        <h2>Anexo por extras <span class="doc-estado doc-estado-enviada">Tipo anexo</span></h2>
        <?php if ($editarAnexo === null): ?>
        <div class="acciones">
            <a class="boton boton-claro boton-chico" href="<?= e(url('contrato', ['previa' => 'anexo'])) ?>#anexo">Vista previa</a>
            <a class="boton boton-claro boton-chico" href="<?= e(url('contrato', ['plantilla' => 'anexo'])) ?>#anexo">Editar</a>
        </div>
        <?php endif; ?>
    </div>
    <p class="suave">Cuando apruebas extras que el cliente pidió desde su portal y el evento ya tiene el contrato firmado, se prepara solo un anexo con este texto para que el cliente lo firme desde su celular. Lleva los extras, su valor, el nuevo total y la cuota nueva.</p>

    <?php if ($editarAnexo !== null): ?>
    <div class="doc-plantilla-editor">
        <form method="post" action="<?= e(url('contrato', ['accion' => 'guardar_anexo'])) ?>">
            <?= campo_csrf() ?>
            <div class="campo"><label for="atexto">Texto del anexo</label>
                <textarea id="atexto" name="texto" rows="24" required class="doc-texto-contrato-edit" data-doc-plantilla-texto><?= e($editarAnexo) ?></textarea>
                <small>Deja una línea en blanco entre cláusulas. Las líneas que empiezan con • salen como lista.</small></div>
            <div class="acciones">
                <button class="boton" type="submit">Guardar anexo</button>
                <a class="boton boton-claro" href="<?= e(url('contrato')) ?>#anexo">Cancelar</a>
            </div>
        </form>
        <aside class="doc-variables">
            <h3>Variables del anexo</h3>
            <p class="suave">Toca una para ponerla donde está el cursor.</p>
            <ul>
                <?php foreach (Anexos::VARIABLES as $k => $desc): ?>
                <li><button type="button" class="doc-variable" data-doc-variable="{{<?= e($k) ?>}}" title="Ejemplo: <?= e(mb_strimwidth((string) ($ejemploAnexo[$k] ?? ''), 0, 80, '…')) ?>">
                    <code>{{<?= e($k) ?>}}</code><small><?= e($desc) ?></small></button></li>
                <?php endforeach; ?>
            </ul>
        </aside>
    </div>
    <?php elseif ($textoPreviaAnexo !== null): ?>
        <h3>Vista previa <small class="suave">(con datos de ejemplo)</small></h3>
        <div class="texto-contrato"><?= e($textoPreviaAnexo) ?></div>
        <form method="post" action="<?= e(url('contrato', ['accion' => 'restaurar_anexo'])) ?>" style="margin-top:10px">
            <?= campo_csrf() ?>
            <button class="boton boton-claro boton-chico" type="submit" data-confirmar="¿Volver al texto de fábrica del anexo? Se pierden tus cambios.">Restaurar el texto de fábrica</button>
        </form>
    <?php endif; ?>
</section>
