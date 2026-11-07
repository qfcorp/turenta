<?php
use TuRenta\{Eventos, Fotos};

/** @var array $k @var array $negocio @var ?array $ev @var ?string $error @var array $formulario @var ?string $waDudas @var string $token @var ?string $portal */
$logo = Fotos::ruta('logos', $negocio['logo'] ?? null) ? url('imagen_publica', ['tipo' => 'logo', 's' => $negocio['slug']]) : null;
$inicial = mb_strtoupper(mb_substr((string) $negocio['nombre'], 0, 1));
?>
<header class="doc-pub-hero">
    <div class="doc-pub-marca">
        <?php if ($logo): ?><img class="pub-logo" src="<?= e($logo) ?>" alt="<?= e($negocio['nombre']) ?>"><?php else: ?><span class="marca-sello doc-pub-sello" aria-hidden="true"><?= e($inicial) ?></span><?php endif; ?>
        <div><strong><?= e($negocio['nombre']) ?></strong><small>Contrato N.º <?= (int) $k['numero'] ?></small></div>
    </div>
    <h1><?= e($k['evento']) ?></h1>
    <p class="doc-pub-datos"><?= e(fecha_larga($k['evento_fecha'])) ?>
        <?php if ($ev): ?> · <?= e(hora_corta($ev['hora_inicio'])) ?> a <?= e(hora_corta($ev['hora_fin'])) ?><?= $ev['espacios_texto'] ? ' · ' . e($ev['espacios_texto']) : '' ?> · <?= (int) $ev['invitados'] ?> invitados<?php endif; ?></p>
    <?php if ($ev && (float) $ev['total'] > 0): ?>
    <div class="doc-pub-cifras"><div><small>Valor total</small><strong><?= dinero($ev['total']) ?></strong></div></div>
    <?php endif; ?>
</header>

<?php if ($k['estado'] === 'firmado'): ?>
<section class="bloque doc-pub-estado doc-pub-ok">
    <h2>✓ Contrato firmado</h2>
    <p>Lo firmó <strong><?= e($k['firmante_nombre']) ?></strong> (cédula/RUC <?= e($k['firmante_identificacion']) ?>) el <?= e(date('d/m/Y \a \l\a\s H:i', strtotime((string) $k['firmado_en']))) ?>.</p>
    <p class="suave">Código de verificación: <strong class="doc-codigo"><?= e($k['codigo_verificacion']) ?></strong></p>
    <a class="boton boton-ancho doc-pub-boton" href="<?= e(url('firmar', ['t' => $token, 'pdf' => 1])) ?>" target="_blank" rel="noopener">Descargar mi contrato firmado (PDF)</a>
    <?php if ($portal): ?><a class="boton boton-claro boton-ancho" style="margin-top:8px" href="<?= e($portal) ?>">Ir a mi portal del evento</a><?php endif; ?>
</section>
<details class="bloque doc-desplegar">
    <summary><strong>Ver el texto del contrato</strong></summary>
    <div class="texto-contrato"><?= e($k['texto']) ?></div>
</details>
<?php elseif ($k['estado'] === 'anulado'): ?>
<section class="bloque doc-pub-estado doc-pub-aviso">
    <h2>Este contrato fue reemplazado</h2>
    <p>El local anuló este contrato (por ejemplo, por un cambio en el evento). Pídeles el enlace del contrato nuevo.</p>
    <?php if ($waDudas): ?><a class="boton boton-wa boton-ancho" href="<?= e($waDudas) ?>" target="_blank" rel="noopener">Escribir por WhatsApp</a><?php endif; ?>
</section>
<?php elseif ($k['estado'] === 'borrador'): ?>
<section class="bloque doc-pub-estado doc-pub-aviso">
    <h2>El contrato todavía se está preparando</h2>
    <p>El local te avisará cuando esté listo para firmar.</p>
    <?php if ($waDudas): ?><a class="boton boton-wa boton-ancho" href="<?= e($waDudas) ?>" target="_blank" rel="noopener">Escribir por WhatsApp</a><?php endif; ?>
</section>
<?php else: ?>
<section class="bloque">
    <h2>1. Lee tu contrato</h2>
    <div class="texto-contrato" tabindex="0" aria-label="Texto del contrato"><?= e($k['texto']) ?></div>
    <p class="suave" style="margin-top:8px"><a href="<?= e(url('firmar', ['t' => $token, 'pdf' => 1])) ?>" target="_blank" rel="noopener">Abrir en PDF</a> para leerlo con calma o guardarlo.</p>
</section>

<form method="post" action="<?= e(url('firmar', ['t' => $token])) ?>" class="bloque doc-pub-aceptar" id="firmar" data-doc-firmar>
    <?= campo_csrf() ?>
    <h2>2. Firma</h2>
    <?php if ($error): ?><p class="aviso aviso-error" role="alert"><?= e($error) ?></p><?php endif; ?>
    <div class="campo"><label for="fnom">Tu nombre completo</label>
        <input type="text" id="fnom" name="nombre" required minlength="5" maxlength="150" value="<?= e($formulario['nombre']) ?>" autocomplete="name"></div>
    <div class="campo"><label for="fced">Cédula o RUC</label>
        <input type="text" id="fced" name="identificacion" required inputmode="numeric" pattern="[0-9 ]{10,15}" maxlength="15" value="<?= e($formulario['identificacion']) ?>" placeholder="10 dígitos (cédula) o 13 (RUC)"></div>
    <div class="campo">
        <span class="etiqueta" id="firma-etq">Firma con el dedo aquí</span>
        <canvas class="firma-caja" data-firma="firma-dato" aria-labelledby="firma-etq" role="img"></canvas>
        <input type="hidden" name="firma" id="firma-dato">
        <div class="doc-firma-pie">
            <small>Usa el dedo (o el mouse en computadora).</small>
            <button type="button" class="boton boton-claro boton-chico" data-borrar-firma="firma-dato">Borrar y firmar de nuevo</button>
        </div>
        <p class="doc-firma-falta" data-doc-firma-falta hidden role="alert">Dibuja tu firma en el recuadro antes de continuar.</p>
    </div>
    <label class="opcion" style="margin-bottom:14px"><input type="checkbox" name="acepto" value="1" required>
        <span><strong>Leí y acepto el contrato</strong><small>Mi firma electrónica tiene el mismo valor que mi firma a mano.</small></span></label>
    <button class="boton boton-ancho doc-pub-boton" type="submit">Firmar contrato</button>
    <p class="suave doc-pub-legal">Se guardan tu nombre, tu cédula, la imagen de tu firma, la fecha y hora, y la dirección de internet desde donde firmas, para dar validez al contrato.</p>
</form>
<?php if ($waDudas): ?>
<a class="boton boton-wa boton-ancho" href="<?= e($waDudas) ?>" target="_blank" rel="noopener">¿Tienes dudas? Escríbenos por WhatsApp</a>
<?php endif; ?>
<?php endif; ?>
