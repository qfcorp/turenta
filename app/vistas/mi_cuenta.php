<?php use TuRenta\Usuarios;
/** @var array $yo @var ?string $tgEnlace @var bool $tgDisponible */ ?>
<div class="cabecera">
    <div><h1>Mi cuenta</h1>
        <p><?= e($yo['email']) ?> · <span class="g-rol <?= e($yo['rol']) ?>"><?= e(Usuarios::ROLES[$yo['rol']]) ?></span></p></div>
</div>

<div class="rejilla rejilla-2">
    <form method="post" action="<?= e(url('mi_cuenta', ['accion' => 'perfil'])) ?>" class="bloque">
        <?= campo_csrf() ?>
        <h2>Mis datos</h2>
        <div class="campo"><label for="nombre">Nombre</label>
            <input type="text" id="nombre" name="nombre" value="<?= e($yo['nombre']) ?>" autocomplete="name" required></div>
        <div class="campo"><label for="telefono">Celular</label>
            <input type="tel" id="telefono" name="telefono" value="<?= e($yo['telefono'] ?? '') ?>" placeholder="0991234567" autocomplete="tel"></div>
        <div class="campo"><span class="etiqueta">Correo para entrar</span>
            <span><?= e($yo['email']) ?> <small>(si necesitas cambiarlo, pídeselo al dueño o escríbenos)</small></span></div>
        <button class="boton" type="submit">Guardar mis datos</button>
    </form>

    <form method="post" action="<?= e(url('mi_cuenta', ['accion' => 'clave'])) ?>" class="bloque" id="clave">
        <?= campo_csrf() ?>
        <h2>Cambiar mi contraseña</h2>
        <?php if ($yo['clave_temporal']): ?><p class="aviso aviso-error" style="margin-bottom:12px">Estás usando una contraseña temporal. Cámbiala por una que solo tú sepas.</p><?php endif; ?>
        <div class="campo"><label for="actual">Contraseña actual</label>
            <input type="password" id="actual" name="actual" autocomplete="current-password" required></div>
        <div class="campo"><label for="nueva">Contraseña nueva <small>(mínimo 8 caracteres)</small></label>
            <div class="g-enlace-copiar"><input type="password" id="nueva" name="nueva" autocomplete="new-password" minlength="8" required>
                <button type="button" class="boton boton-claro boton-chico" data-ver-clave="nueva">Ver</button></div></div>
        <div class="campo"><label for="repetir">Escríbela otra vez</label>
            <input type="password" id="repetir" name="repetir" autocomplete="new-password" minlength="8" required></div>
        <button class="boton" type="submit">Cambiar contraseña</button>
    </form>

    <?php if (modulo_activo($u, 'telegram')): /* Anexo B: módulo "Avisos por Telegram" */ ?>
    <section class="bloque" id="telegram">
        <h2>Avisos en Telegram</h2>
        <p>Recibe al instante en tu celular los pagos, firmas de contrato, consultas nuevas y tu lista de seguimientos de cada mañana.</p>
        <?php if ($yo['telegram']): ?>
            <p class="aviso aviso-ok">✅ Tu Telegram está conectado.</p>
            <form method="post" action="<?= e(url('mi_cuenta', ['accion' => 'tg_desconectar'])) ?>"><?= campo_csrf() ?>
                <button class="boton boton-claro" type="submit" data-confirmar="¿Dejar de recibir avisos por Telegram?">Desconectar Telegram</button></form>
        <?php elseif ($tgEnlace): ?>
            <ol class="g-ayuda" style="padding-left:32px">
                <li>Toca el botón: se abre Telegram.</li>
                <li>Toca <strong>Iniciar</strong> (o <em>Start</em>).</li>
                <li>Listo: el bot te confirmará con un mensaje.</li>
            </ol>
            <a class="boton boton-ancho" href="<?= e($tgEnlace) ?>" target="_blank" rel="noopener">Abrir Telegram y conectar</a>
            <p class="g-nota" style="margin-top:8px">Este enlace sirve una sola vez. Si no funciona, vuelve a pedir uno.</p>
        <?php elseif ($tgDisponible): ?>
            <form method="post" action="<?= e(url('mi_cuenta', ['accion' => 'tg_conectar'])) ?>"><?= campo_csrf() ?>
                <button class="boton" type="submit">Conectar mi Telegram</button></form>
            <p class="g-nota" style="margin-top:8px">Necesitas tener la app de Telegram instalada en tu celular.</p>
        <?php else: ?>
            <p class="vacio">Los avisos por Telegram se activan pronto en este servidor. Mientras tanto, los verás en la campana de Avisos.</p>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <section class="bloque">
        <h2>Sesión</h2>
        <?php if ($yo['ultimo_ingreso']): ?><p class="suave">Último ingreso: <?= e(fecha_corta(substr((string) $yo['ultimo_ingreso'], 0, 10))) ?> a las <?= e(substr((string) $yo['ultimo_ingreso'], 11, 5)) ?></p><?php endif; ?>
        <a class="boton boton-claro" href="<?= e(url('salir')) ?>">Cerrar sesión</a>
    </section>

    <?php /* Google Play pide que desde la app se pueda pedir el borrado de la cuenta y los datos (docs/GOOGLE_PLAY.md) */ ?>
    <section class="bloque" id="borrar-cuenta">
        <h2>Borrar mi cuenta</h2>
        <?php if (es_admin_total($u)): ?>
            <p class="suave">Si quieres dejar TuRenta, pide que se borre la cuenta de <?= e($u['negocio']) ?> y todos sus datos. Lo hacemos en un máximo de 15 días
                (solo se guarda lo que la ley obliga, como contratos y registros de pagos).</p>
        <?php else: ?>
            <p class="suave">Para quitar tu usuario, pídele al dueño del negocio que te desactive en Usuarios, o escríbenos para borrar tus datos.</p>
        <?php endif; ?>
        <div class="acciones">
            <a class="boton boton-claro" href="mailto:info@tukansoftware.com?subject=<?= e(rawurlencode('Borrar mi cuenta de TuRenta')) ?>&amp;body=<?= e(rawurlencode('Negocio: ' . $u['negocio'] . "\nCorreo de la cuenta: " . $u['email'] . "\n\nQuiero que borren " . (es_admin_total($u) ? 'la cuenta del negocio y todos sus datos.' : 'mi usuario y mis datos.'))) ?>">Pedir que borren mi cuenta</a>
            <a href="<?= e(url('privacidad')) ?>#borrar-cuenta">Cómo se borran los datos</a>
        </div>
    </section>
</div>
