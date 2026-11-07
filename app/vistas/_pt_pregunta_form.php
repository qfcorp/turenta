<?php
/**
 * Formulario para agregar o editar una pregunta (plantillas y formulario de un evento).
 * Usa: $ptAccionUrl (a dónde se envía), $ptOcultos (campos ocultos), $f (valores: texto, tipo, opciones, seccion, ayuda, obligatoria),
 *      $ptSecciones (secciones para sugerir), $ptBoton (texto del botón).
 */
use TuRenta\Cuestionarios;

$ptAccionUrl ??= url('evento_formulario', ['id' => (int) ($ev['id'] ?? 0)]);
$ptOcultos ??= ['accion' => 'pregunta_agregar'];
$ptSecciones ??= array_keys($form['secciones'] ?? []);
$ptBoton ??= 'Agregar pregunta';
$f ??= [];
$ptSufijo = substr(md5(json_encode($ptOcultos)), 0, 6);
$tipoActual = (string) ($f['tipo'] ?? 'corto');
?>
<form method="post" action="<?= e($ptAccionUrl) ?>" class="pt-pregunta-form">
    <?= campo_csrf() ?>
    <?php foreach ($ptOcultos as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
    <div class="rejilla-form">
        <div class="campo ancho"><label for="pt-texto-<?= $ptSufijo ?>">Pregunta</label>
            <input type="text" id="pt-texto-<?= $ptSufijo ?>" name="texto" maxlength="200" required value="<?= e($f['texto'] ?? '') ?>" placeholder="Ej.: ¿Cuál es la canción de entrada?"></div>
        <div class="campo"><label for="pt-tipo-<?= $ptSufijo ?>">Tipo de respuesta</label>
            <select id="pt-tipo-<?= $ptSufijo ?>" name="tipo" data-mostrar="pt-ops-<?= $ptSufijo ?>">
                <?php foreach (Cuestionarios::TIPOS as $k => $t): ?><option value="<?= e($k) ?>" <?= $tipoActual === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
            </select></div>
        <div class="campo"><label for="pt-seccion-<?= $ptSufijo ?>">Sección <small>(opcional)</small></label>
            <input type="text" id="pt-seccion-<?= $ptSufijo ?>" name="seccion" maxlength="60" value="<?= e($f['seccion'] ?? '') ?>" list="pt-secciones-<?= $ptSufijo ?>" placeholder="Ej.: Música">
            <datalist id="pt-secciones-<?= $ptSufijo ?>"><?php foreach ($ptSecciones as $s): ?><option value="<?= e($s) ?>"><?php endforeach; ?></datalist></div>
        <div class="campo ancho pt-ops-<?= $ptSufijo ?>" data-si="opcion,varias"><label for="pt-opciones-<?= $ptSufijo ?>">Opciones <small>(una por línea)</small></label>
            <textarea id="pt-opciones-<?= $ptSufijo ?>" name="opciones" rows="3" placeholder="Rojo&#10;Dorado&#10;Blanco"><?= e($f['opciones'] ?? '') ?></textarea></div>
        <div class="campo ancho"><label for="pt-ayuda-<?= $ptSufijo ?>">Ayuda para el cliente <small>(opcional)</small></label>
            <input type="text" id="pt-ayuda-<?= $ptSufijo ?>" name="ayuda" maxlength="200" value="<?= e($f['ayuda'] ?? '') ?>" placeholder="Ej.: Si no la tienen todavía, déjenla en blanco"></div>
        <label class="opcion"><input type="checkbox" name="obligatoria" value="1" <?= !empty($f['obligatoria']) ? 'checked' : '' ?>>
            <span><strong>Obligatoria</strong><small>El cliente no puede enviar el formulario sin responderla.</small></span></label>
    </div>
    <button class="boton boton-claro boton-chico" type="submit"><?= e($ptBoton) ?></button>
</form>
<?php unset($ptAccionUrl, $ptOcultos, $ptSecciones, $ptBoton); ?>
