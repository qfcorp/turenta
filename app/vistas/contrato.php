<?php
use TuRenta\{Contratos, Eventos};

/**
 * @var array $ev @var string $tab @var array $historial @var ?array $actual @var ?array $aceptada @var array $plantillas
 * @var ?array $resumen @var bool $reservaPagada @var ?string $enlaceFirma @var ?string $wa @var ?string $enlaceVerificar
 * @var bool $puede @var bool $dueno
 */
require RAIZ . '/app/vistas/_evento_tabs.php';
$estados = Contratos::ESTADOS;
$estado = $actual['estado'] ?? null;
$paso = !$aceptada ? 1 : (!$actual || $estado === 'anulado' ? 2 : ($estado === 'borrador' ? 3 : ($estado === 'enviado' ? 4 : 5)));
$eventoActivo = !in_array($ev['estado'], ['perdido', 'cancelado'], true);
$formGenerar = function (string $boton) use ($ev, $plantillas): string {
    ob_start(); ?>
    <form method="post" action="<?= e(url('contrato', ['id' => $ev['id'], 'accion' => 'generar'])) ?>" class="doc-generar">
        <?= campo_csrf() ?>
        <?php if (count($plantillas) > 1): ?>
        <div class="campo"><label for="gplan">Plantilla</label>
            <select id="gplan" name="plantilla_id">
                <?php foreach ($plantillas as $p): ?><option value="<?= (int) $p['id'] ?>" <?= $p['predeterminada'] ? 'selected' : '' ?>><?= e($p['nombre']) ?><?= $p['predeterminada'] ? ' (predeterminada)' : '' ?></option><?php endforeach; ?>
            </select></div>
        <?php elseif ($plantillas): ?>
            <input type="hidden" name="plantilla_id" value="<?= (int) $plantillas[0]['id'] ?>">
            <p class="suave">Se usa la plantilla «<?= e($plantillas[0]['nombre']) ?>».</p>
        <?php else: ?>
            <p class="suave">Se usa el contrato de fábrica de TuRenta.</p>
        <?php endif; ?>
        <button class="boton" type="submit"><?= e($boton) ?></button>
    </form>
    <?php return ob_get_clean();
};
?>

<section class="bloque doc-flujo">
    <h2>Contrato con firma en el celular</h2>
    <ol class="doc-pasos doc-pasos-largos">
        <li class="<?= $paso > 1 ? 'doc-hecho' : 'doc-ahora' ?>"><span>1</span><div><strong>El cliente acepta la cotización</strong><small>Así el contrato sale con el valor, lo contratado y el plan de pagos.</small></div></li>
        <li class="<?= $paso > 2 ? 'doc-hecho' : ($paso === 2 ? 'doc-ahora' : '') ?>"><span>2</span><div><strong>Generas el contrato</strong><small>Se llena solo con los datos del cliente y del evento.</small></div></li>
        <li class="<?= $paso > 3 ? 'doc-hecho' : ($paso === 3 ? 'doc-ahora' : '') ?>"><span>3</span><div><strong>Lo revisas y lo envías</strong><small>Desde ese momento el texto queda fijo.</small></div></li>
        <li class="<?= $paso > 4 ? 'doc-hecho' : ($paso === 4 ? 'doc-ahora' : '') ?>"><span>4</span><div><strong>El cliente firma en su celular</strong><small>Con su nombre, cédula y la firma con el dedo. Te llega un aviso.</small></div></li>
    </ol>
</section>

<?php if (!$aceptada && !$actual): ?>
<section class="bloque vacio">
    <p><strong>Primero el cliente debe aceptar una cotización.</strong><br>El contrato necesita el valor del evento y lo que se contrató.</p>
    <a class="boton" href="<?= e(url('cotizacion', ['id' => $ev['id']])) ?>">Ir a la cotización</a>
</section>
<?php elseif (!$actual || $estado === 'anulado'): ?>
<section class="bloque">
    <h2><?= $actual ? 'El último contrato fue anulado' : 'Prepara el contrato' ?></h2>
    <?php if ($aceptada): ?>
        <p class="suave">Cotización aceptada: N.º <?= (int) $aceptada['numero'] ?> por <strong><?= dinero($aceptada['total']) ?></strong>.</p>
    <?php endif; ?>
    <?php if ($puede && $eventoActivo && $aceptada): ?>
        <?= $formGenerar($actual ? 'Preparar un contrato nuevo' : 'Generar contrato') ?>
    <?php elseif (!$eventoActivo): ?>
        <p class="aviso aviso-error">Este evento está <?= e(mb_strtolower(Eventos::ESTADOS[$ev['estado']])) ?>.</p>
    <?php elseif (!$puede): ?>
        <p class="vacio">Tu usuario solo puede mirar.</p>
    <?php endif; ?>
    <?php if ($dueno): ?><p style="margin-top:12px"><a href="<?= e(url('contrato')) ?>">Cambiar las plantillas de contrato</a></p><?php endif; ?>
</section>
<?php if ($actual): ?>
<details class="bloque doc-desplegar">
    <summary><strong>Ver el contrato anulado N.º <?= (int) $actual['numero'] ?></strong></summary>
    <p><a href="<?= e(url('pdf', ['tipo' => 'contrato', 'id' => $actual['id']])) ?>" target="_blank" rel="noopener">Ver PDF</a></p>
    <div class="texto-contrato"><?= e($actual['texto']) ?></div>
</details>
<?php endif; ?>
<?php else: ?>

<section class="bloque doc-contrato-estado">
    <div class="doc-version-cabeza">
        <div>
            <h2>Contrato N.º <?= (int) $actual['numero'] ?> <span class="doc-estado doc-estado-<?= e($estado) ?>"><?= e($estados[$estado] ?? $estado) ?></span></h2>
            <p class="suave">Preparado el <?= e(date('d/m/Y', strtotime((string) $actual['creado_en']))) ?>
                <?php if (!$actual['integro']): ?> · <strong class="ocupado">¡El texto no coincide con su huella!</strong><?php endif; ?></p>
        </div>
        <div class="acciones">
            <a class="boton boton-claro boton-chico" href="<?= e(url('pdf', ['tipo' => 'contrato', 'id' => $actual['id']])) ?>" target="_blank" rel="noopener">Ver PDF</a>
        </div>
    </div>

    <?php if ($estado === 'firmado'): ?>
        <p class="aviso aviso-ok">El cliente firmó el contrato.
            <?= $ev['estado'] === 'confirmado' ? 'El evento está confirmado.' : (!$reservaPagada ? 'Falta que pague la reserva para que el evento quede confirmado.' : '') ?></p>
        <div class="doc-firma-datos">
            <figure class="doc-firma-imagen">
                <img src="<?= e(url('archivo', ['tipo' => 'firma', 'id' => $actual['id']])) ?>" alt="Firma de <?= e($actual['firmante_nombre']) ?>">
                <figcaption>Firma del cliente</figcaption>
            </figure>
            <dl class="ficha-datos">
                <dt>Firmó</dt><dd><?= e($actual['firmante_nombre']) ?></dd>
                <dt>Cédula/RUC</dt><dd><?= e($actual['firmante_identificacion']) ?></dd>
                <dt>Fecha</dt><dd><?= e(date('d/m/Y H:i', strtotime((string) $actual['firmado_en']))) ?></dd>
                <dt>IP</dt><dd><?= e($actual['firma_ip'] ?: '—') ?></dd>
                <dt>Código</dt><dd class="doc-codigo"><?= e($actual['codigo_verificacion']) ?></dd>
                <dt>Huella</dt><dd class="doc-huella"><?= e($actual['hash_sha256']) ?></dd>
            </dl>
        </div>
        <div class="acciones" style="margin-top:12px">
            <a class="boton boton-claro boton-chico" href="<?= e($enlaceVerificar) ?>" target="_blank" rel="noopener">Ver la página de verificación</a>
            <button type="button" class="boton boton-claro boton-chico" data-copiar="<?= e($enlaceVerificar) ?>">Copiar enlace de verificación</button>
        </div>
    <?php elseif ($estado === 'enviado'): ?>
        <p class="aviso aviso-ok">Listo para firmar. Mándale el enlace al cliente: lo abre en su celular, lo lee y firma con el dedo.</p>
        <div class="doc-enlace">
            <input type="text" readonly value="<?= e($enlaceFirma) ?>" aria-label="Enlace para firmar" onclick="this.select()">
            <button type="button" class="boton boton-claro" data-copiar="<?= e($enlaceFirma) ?>">Copiar enlace</button>
            <?php if ($wa): ?><a class="boton boton-wa" href="<?= e($wa) ?>" target="_blank" rel="noopener">Enviar por WhatsApp</a>
            <?php else: ?><small>El cliente no tiene celular guardado. <a href="<?= e(url('evento_editar', ['id' => $ev['id']])) ?>">Agregarlo</a></small><?php endif; ?>
            <a class="boton boton-claro" href="<?= e($enlaceFirma) ?>" target="_blank" rel="noopener">Ver como el cliente</a>
        </div>
    <?php endif; ?>
</section>

<?php if ($estado === 'borrador' && $puede): ?>
<form method="post" action="<?= e(url('contrato', ['id' => $ev['id'], 'accion' => 'editar'])) ?>" class="bloque">
    <?= campo_csrf() ?><input type="hidden" name="contrato_id" value="<?= (int) $actual['id'] ?>">
    <h2>Revisa el texto</h2>
    <p class="suave">Ya tiene los datos del evento. Corrige lo que necesites (por ejemplo una cláusula especial para este cliente). Cuando lo envíes, el texto queda fijo.</p>
    <div class="campo"><label for="ktexto" class="sr-only">Texto del contrato</label>
        <textarea id="ktexto" name="texto" rows="22" class="doc-texto-contrato-edit" required><?= e($actual['texto']) ?></textarea></div>
    <div class="acciones">
        <button class="boton boton-claro" type="submit">Guardar cambios</button>
        <button class="boton boton-wa" type="submit" formaction="<?= e(url('contrato', ['id' => $ev['id'], 'accion' => 'enviar_wa'])) ?>"
                data-confirmar="Al enviarlo, el texto ya no se puede cambiar. ¿Enviar el contrato para firmar?">Enviar para firmar por WhatsApp</button>
        <button class="boton" type="submit" formaction="<?= e(url('contrato', ['id' => $ev['id'], 'accion' => 'enviar'])) ?>"
                data-confirmar="Al enviarlo, el texto ya no se puede cambiar. ¿Dejarlo listo para firmar?">Dejar listo para firmar</button>
    </div>
</form>
<?php else: ?>
<details class="bloque doc-desplegar" <?= $estado !== 'firmado' ? 'open' : '' ?>>
    <summary><strong>Texto del contrato</strong></summary>
    <div class="texto-contrato"><?= e($actual['texto']) ?></div>
</details>
<?php endif; ?>

<?php if ($puede && $estado !== 'anulado'): ?>
<details class="bloque doc-desplegar">
    <summary><strong>Anular este contrato</strong> <small>— si cambió algo importante (fecha, valor) o hubo un error</small></summary>
    <form method="post" action="<?= e(url('contrato', ['id' => $ev['id'], 'accion' => 'anular'])) ?>">
        <?= campo_csrf() ?><input type="hidden" name="contrato_id" value="<?= (int) $actual['id'] ?>">
        <div class="campo"><label for="kmot">Motivo</label>
            <input type="text" id="kmot" name="motivo" required minlength="3" maxlength="200" placeholder="Ej.: Cambio de fecha del evento"></div>
        <button class="boton boton-peligro" type="submit" data-confirmar="¿Anular el contrato N.º <?= (int) $actual['numero'] ?>? <?= $estado === 'firmado' ? 'Ya está FIRMADO por el cliente. ' : '' ?>Su enlace de firma deja de funcionar.">Anular contrato</button>
    </form>
</details>
<?php endif; ?>
<?php endif; ?>

<?php require RAIZ . '/app/vistas/_anexos_contrato.php'; // anexos por extras aprobados (migración 090) ?>

<?php if ($historial): ?>
<section class="bloque">
    <h2>Contratos de este evento</h2>
    <ul class="filas">
        <?php foreach ($historial as $h): ?>
        <li class="<?= $actual && (int) $h['id'] === (int) $actual['id'] ? 'doc-fila-actual' : '' ?>">
            <div><a href="<?= e(url('contrato', ['id' => $ev['id'], 'k' => $h['id']])) ?>"><strong>Contrato N.º <?= (int) $h['numero'] ?></strong></a>
                <span class="doc-estado doc-estado-<?= e($h['estado']) ?>"><?= e($estados[$h['estado']] ?? $h['estado']) ?></span>
                <br><small><?= e(date('d/m/Y', strtotime((string) $h['creado_en']))) ?><?= $h['firmado_en'] ? ' · firmado el ' . e(date('d/m/Y', strtotime((string) $h['firmado_en']))) . ' por ' . e($h['firmante_nombre']) : '' ?></small></div>
            <div class="derecha"><a class="enlace-chico" href="<?= e(url('pdf', ['tipo' => 'contrato', 'id' => $h['id']])) ?>" target="_blank" rel="noopener">PDF</a></div>
        </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>
