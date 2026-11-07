<?php
use TuRenta\{Eventos, Ventas, WhatsApp};

/** Página pública del negocio. @var array $neg @var array $espacios @var array $d @var ?string $error */
$wa = WhatsApp::enlace($neg['whatsapp'] ?: $neg['telefono'], "Hola, vi su página y quiero información para un evento.");
$insta = Ventas::enlaceInstagram($neg['instagram']);
$face = Ventas::enlaceFacebook($neg['facebook']);
$mapa = Ventas::enlaceSeguro($neg['mapa_url']);
$tiposPub = array_intersect_key(Eventos::TIPOS, array_flip(Ventas::TIPOS_PUBLICOS));
$iconosTipo = ['boda' => '💍', 'quince' => '👑', 'graduacion' => '🎓', 'bautizo' => '🕊️', 'primera_comunion' => '✝️',
               'cumpleanos' => '🎂', 'corporativo' => '💼', 'aniversario' => '🥂'];
$v = fn(string $k, string $def = '') => (string) ($d[$k] ?? $def);
$maxCap = max(array_map(fn($e) => (int) $e['capacidad_banquete'], $espacios ?: [['capacidad_banquete' => 0]]));
$hoy = date('Y-m-d');
?>
<header class="pub-hero sitio-hero">
    <?php if ($neg['logo']): ?>
        <img class="pub-logo" src="<?= e(url('imagen_publica', ['tipo' => 'logo', 's' => $neg['slug']])) ?>" alt="Logo de <?= e($neg['nombre']) ?>" width="72" height="72">
    <?php else: ?>
        <span class="pub-logo sitio-inicial" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($neg['nombre'], 0, 1))) ?></span>
    <?php endif; ?>
    <h1><?= e($neg['nombre']) ?></h1>
    <p class="sitio-lugar"><?= e($neg['ciudad']) ?><?= $neg['direccion'] ? ' · ' . e($neg['direccion']) : '' ?></p>
    <?php if ($neg['descripcion']): ?>
        <p class="sitio-desc"><?= nl2br(e($neg['descripcion'])) ?></p>
    <?php else: ?>
        <p class="sitio-desc">Celebra tu boda, tus quince años o el evento de tu empresa con nosotros<?= $maxCap ? ', hasta ' . $maxCap . ' invitados' : '' ?>.</p>
    <?php endif; ?>
    <div class="acciones sitio-acciones">
        <a class="boton sitio-boton" href="#consulta">Consulta tu fecha</a>
        <?php if ($wa): ?><a class="boton boton-wa" href="<?= e($wa) ?>" target="_blank" rel="noopener">WhatsApp</a><?php endif; ?>
    </div>
    <?php if ($insta || $face || $mapa): ?>
    <p class="sitio-redes">
        <?php if ($insta): ?><a href="<?= e($insta) ?>" target="_blank" rel="noopener">Instagram</a><?php endif; ?>
        <?php if ($face): ?><a href="<?= e($face) ?>" target="_blank" rel="noopener">Facebook</a><?php endif; ?>
        <?php if ($mapa): ?><a href="<?= e($mapa) ?>" target="_blank" rel="noopener">Cómo llegar</a><?php endif; ?>
    </p>
    <?php endif; ?>
</header>

<section class="bloque sitio-libre" id="libre" aria-labelledby="t-libre"
         data-disponible="<?= e(url('disponible', ['s' => $neg['slug']])) ?>">
    <h2 id="t-libre">¿Está libre tu fecha?</h2>
    <p class="suave">Elige el día y te decimos al instante qué espacios están disponibles.</p>
    <div class="fila-en-linea">
        <label class="sr-only" for="fecha-libre">Fecha de tu evento</label>
        <input type="date" id="fecha-libre" min="<?= e($hoy) ?>" value="<?= e($fechaInicial) ?>">
        <button class="boton" type="button" id="ver-libre">Ver</button>
    </div>
    <div id="resultado-libre" class="resultado-libre" aria-live="polite"></div>
    <noscript><p class="suave">Para ver la disponibilidad, escríbenos en el formulario de abajo.</p></noscript>
</section>

<?php if ($espacios): ?>
<section aria-labelledby="t-espacios" class="sitio-seccion">
    <h2 id="t-espacios">Nuestros espacios</h2>
    <div class="pub-espacios">
        <?php foreach ($espacios as $es): ?>
            <article class="pub-espacio">
                <?php if ($es['foto']): ?>
                    <img src="<?= e(url('imagen_publica', ['tipo' => 'espacio', 'id' => $es['id']])) ?>" alt="<?= e($es['nombre']) ?>" loading="lazy" width="400" height="160">
                <?php else: ?>
                    <div class="sitio-sin-foto" style="background:<?= e($es['color']) ?>" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($es['nombre'], 0, 1))) ?></div>
                <?php endif; ?>
                <div>
                    <h3><?= e($es['nombre']) ?></h3>
                    <p class="sitio-cap">
                        <?= $es['capacidad_banquete'] ? 'Hasta <strong>' . (int) $es['capacidad_banquete'] . '</strong> sentados' : '' ?>
                        <?= $es['capacidad_coctel'] ? ($es['capacidad_banquete'] ? ' · ' : '') . (int) $es['capacidad_coctel'] . ' de pie' : '' ?>
                    </p>
                    <?php if ($es['descripcion']): ?><p class="suave"><?= e($es['descripcion']) ?></p><?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<section aria-labelledby="t-tipos" class="sitio-seccion">
    <h2 id="t-tipos">Celebramos</h2>
    <ul class="sitio-tipos">
        <?php foreach ($tiposPub as $k => $t): ?><li><span aria-hidden="true"><?= $iconosTipo[$k] ?? '🎉' ?></span> <?= e($t) ?></li><?php endforeach; ?>
    </ul>
</section>

<section class="bloque sitio-form" id="consulta" aria-labelledby="t-consulta">
    <h2 id="t-consulta">Pide información</h2>
    <p class="suave">Déjanos tus datos y te escribimos por WhatsApp con precios y disponibilidad. Sin compromiso.</p>
    <?php if ($error): ?><p class="aviso aviso-error" role="alert"><?= e($error) ?></p><?php endif; ?>
    <form method="post" action="<?= e(url('consulta', ['s' => $neg['slug']])) ?>" novalidate>
        <?= campo_csrf() ?>
        <div class="trampa" aria-hidden="true">
            <label for="sitio_web">No llenar</label>
            <input type="text" id="sitio_web" name="sitio_web" tabindex="-1" autocomplete="off">
        </div>
        <div class="rejilla-form">
            <div class="campo">
                <label for="c-nombre">Tu nombre</label>
                <input type="text" id="c-nombre" name="nombre" value="<?= e($v('nombre')) ?>" required maxlength="150" autocomplete="name">
            </div>
            <div class="campo">
                <label for="c-telefono">Celular (WhatsApp)</label>
                <input type="tel" id="c-telefono" name="telefono" value="<?= e($v('telefono')) ?>" required placeholder="0991234567" autocomplete="tel" inputmode="tel">
            </div>
            <div class="campo">
                <label for="c-email">Correo <small>(opcional)</small></label>
                <input type="email" id="c-email" name="email" value="<?= e($v('email')) ?>" autocomplete="email">
            </div>
            <div class="campo">
                <label for="c-tipo">¿Qué vas a celebrar?</label>
                <select id="c-tipo" name="tipo" required>
                    <option value="">— Elegir —</option>
                    <?php foreach (Eventos::TIPOS as $k => $t): ?><option value="<?= e($k) ?>" <?= $v('tipo') === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="campo">
                <label for="c-fecha">Fecha</label>
                <input type="date" id="c-fecha" name="fecha" value="<?= e($v('fecha', $fechaInicial)) ?>" min="<?= e($hoy) ?>" required>
            </div>
            <div class="campo">
                <label for="c-invitados">Invitados <small>(aproximado)</small></label>
                <input type="number" id="c-invitados" name="invitados" value="<?= e($v('invitados')) ?>" min="1" max="20000" required inputmode="numeric">
            </div>
            <?php if (count($espacios) > 1): ?>
            <div class="campo ancho">
                <label for="c-espacio">Espacio preferido</label>
                <select id="c-espacio" name="espacio_id">
                    <option value="">Sin preferencia</option>
                    <?php foreach ($espacios as $es): ?><option value="<?= (int) $es['id'] ?>" <?= (int) $v('espacio_id') === (int) $es['id'] ? 'selected' : '' ?>><?= e($es['nombre']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="campo ancho">
                <label for="c-mensaje">Mensaje <small>(opcional)</small></label>
                <textarea id="c-mensaje" name="mensaje" rows="3" maxlength="1000" placeholder="Cuéntanos qué te imaginas: horario, menú, decoración…"><?= e($v('mensaje')) ?></textarea>
            </div>
        </div>
        <button class="boton boton-ancho sitio-boton" type="submit">Enviar consulta</button>
        <p class="suave ayuda-chica">Usamos tus datos solo para responderte. <a href="<?= e(url('privacidad')) ?>">Privacidad</a></p>
    </form>
</section>
