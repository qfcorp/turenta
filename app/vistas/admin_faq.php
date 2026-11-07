<?php
/** Preguntas frecuentes (IA) del súper usuario. @var array $lista @var array $resumen @var array $filtros @var string $filtro
 *  @var ?array $editar @var ?string $error @var array $form @var array $estadoIa */
$accion = fn() => e(url('admin_faq', ['f' => $filtro]));
?>
<div class="cabecera">
    <div><h1>Preguntas frecuentes (IA)</h1>
        <p>Lo que respondió la Ayuda de TuRenta. Cada respuesta guardada sirve para todos los negocios sin volver a usar la IA:
           revisa las automáticas, corrige las malas y agrega las tuyas.</p></div>
</div>

<div class="rejilla rejilla-4">
    <div class="bloque"><div class="cifra"><?= (int) $resumen['total'] ?></div><p class="cifra-nombre">Preguntas guardadas</p></div>
    <div class="bloque"><div class="cifra"><?= (int) $resumen['sin_aprobar'] ?></div><p class="cifra-nombre">Por revisar</p></div>
    <div class="bloque"><div class="cifra"><?= (int) $resumen['manuales'] ?></div><p class="cifra-nombre">Escritas a mano</p></div>
    <div class="bloque"><div class="cifra"><?= (int) $resumen['usos'] ?></div><p class="cifra-nombre">Veces que se usaron</p></div>
</div>
<p class="suave">IA: <?= !$estadoIa['configurada'] ? 'sin configurar (la Ayuda responde solo con estas preguntas y el manual)' : ($estadoIa['pausada'] ? 'en pausa' : 'encendida') ?>
    · el gasto de cada negocio se ve en el Panel Tukán Central → Más → IA.</p>

<?php if ($error): ?><p class="aviso aviso-error" role="alert"><?= e($error) ?></p><?php endif; ?>

<?php if ($editar): ?>
    <section class="bloque ia-faq-form" id="editar" aria-labelledby="ia-t-editar">
        <h2 id="ia-t-editar">Editar la pregunta N.º <?= (int) $editar['id'] ?></h2>
        <form method="post" action="<?= $accion() ?>"><?= campo_csrf() ?>
            <input type="hidden" name="accion" value="editar"><input type="hidden" name="id" value="<?= (int) $editar['id'] ?>">
            <div class="campo"><label for="faq-e-preg">Pregunta</label><input id="faq-e-preg" name="pregunta" maxlength="500" required value="<?= e($editar['pregunta']) ?>"></div>
            <div class="campo"><label for="faq-e-resp">Respuesta</label><textarea id="faq-e-resp" name="respuesta" maxlength="4000" required><?= e($editar['respuesta']) ?></textarea>
                <small>Al guardar queda aprobada y como «Escrita a mano». Puedes usar **negritas** y pasos 1. 2. 3.</small></div>
            <div class="acciones"><button class="boton" type="submit">Guardar</button>
                <a class="boton boton-claro" href="<?= $accion() ?>">Cancelar</a></div>
        </form>
    </section>
<?php endif; ?>

<section class="bloque ia-faq-form" aria-labelledby="ia-t-agregar">
    <details <?= $form['pregunta'] !== '' ? 'open' : '' ?>><summary id="ia-t-agregar"><strong>Agregar pregunta</strong></summary>
        <form method="post" action="<?= $accion() ?>" id="form-faq-agregar"><?= campo_csrf() ?>
            <input type="hidden" name="accion" value="agregar">
            <div class="campo"><label for="faq-preg">Pregunta</label><input id="faq-preg" name="pregunta" maxlength="500" required value="<?= e($form['pregunta']) ?>" placeholder="¿Cómo separo una fecha?"></div>
            <div class="campo"><label for="faq-resp">Respuesta</label><textarea id="faq-resp" name="respuesta" maxlength="4000" required><?= e($form['respuesta']) ?></textarea></div>
            <button class="boton" type="submit">Agregar (queda aprobada)</button>
        </form>
    </details>
</section>

<nav class="ia-faq-filtros" aria-label="Filtro">
    <?php foreach ($filtros as $k => $t): ?>
        <a href="<?= e(url('admin_faq', ['f' => $k])) ?>" <?= $filtro === $k ? 'aria-current="page"' : '' ?>><?= e($t) ?></a>
    <?php endforeach; ?>
</nav>

<section class="bloque">
    <?php if (!$lista): ?>
        <p class="vacio"><?= $filtro === 'sin_aprobar' ? 'No hay preguntas por revisar.' : 'Todavía no hay preguntas guardadas.' ?></p>
    <?php else: ?>
    <div class="tabla-scroll"><table class="tabla" id="tabla-faq">
        <thead><tr><th scope="col">Pregunta</th><th scope="col">Respuesta</th><th scope="col" class="der">Usos</th><th scope="col">Origen</th><th scope="col">Acciones</th></tr></thead>
        <tbody>
        <?php foreach ($lista as $f): $resp = (string) $f['respuesta']; ?>
            <tr id="faq-<?= (int) $f['id'] ?>">
                <td><?= e($f['pregunta']) ?><?= $f['aprobada'] ? '' : '<br><span class="etq etq-porcentaje">Por revisar</span>' ?></td>
                <td class="ia-faq-resp"><?= e(mb_strlen($resp) > 220 ? mb_substr($resp, 0, 220) . '…' : $resp) ?></td>
                <td class="num"><?= (int) $f['usos'] ?></td>
                <td><?= $f['origen'] === 'manual' ? 'Escrita a mano' : 'Automática' ?></td>
                <td><div class="acciones">
                    <?php if (!$f['aprobada']): ?>
                        <form method="post" action="<?= $accion() ?>"><?= campo_csrf() ?><input type="hidden" name="accion" value="aprobar"><input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                            <button class="boton boton-chico" type="submit">Aprobar</button></form>
                    <?php endif; ?>
                    <a class="boton boton-claro boton-chico" href="<?= e(url('admin_faq', ['f' => $filtro, 'editar' => $f['id']])) ?>#editar">Editar</a>
                    <form method="post" action="<?= $accion() ?>"><?= campo_csrf() ?>
                        <input type="hidden" name="accion" value="borrar"><input type="hidden" name="id" value="<?= (int) $f['id'] ?>"><input type="hidden" name="confirmar" value="si">
                        <button class="boton boton-peligro boton-chico" type="submit" data-confirmar="¿Borrar esta pregunta? Se deja de usar para todos.">Borrar</button></form>
                </div></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</section>
