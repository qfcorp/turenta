<?php
use TuRenta\Contratos;

/** @var string $codigo @var ?array $k */
$enmascarar = function (?string $id): string {
    $id = (string) $id;
    return strlen($id) > 4 ? str_repeat('•', strlen($id) - 4) . substr($id, -4) : $id;
};
?>
<header class="doc-pub-cabeza-simple">
    <span class="marca-sello doc-pub-sello" aria-hidden="true">✓</span>
    <div><h1>Verificar un contrato</h1><p class="suave">Comprueba que un contrato de TuRenta es auténtico y que nadie cambió su texto.</p></div>
</header>

<?php if ($codigo !== '' && $k): ?>
    <?php $ok = $k['integro'] && $k['estado'] !== 'anulado'; $doc = $k['documento_corto'] ?? 'Contrato'; // 'Anexo' para los anexos (090) ?>
    <section class="bloque doc-verif <?= $ok ? 'doc-verif-ok' : 'doc-verif-mal' ?>" aria-live="polite">
        <p class="doc-verif-icono" aria-hidden="true"><?= $ok ? '✓' : '✕' ?></p>
        <?php if (!$k['integro']): ?>
            <h2>El texto NO coincide</h2>
            <p>El contrato existe, pero su texto fue cambiado después de prepararlo. <strong>No confíes en este documento</strong> y consulta con el local.</p>
        <?php elseif ($k['estado'] === 'anulado'): ?>
            <h2><?= e($doc) ?> anulado</h2>
            <p>Este contrato existe y su texto está íntegro, pero el local lo <strong>anuló</strong>. Ya no tiene validez.</p>
        <?php elseif ($k['estado'] === 'firmado'): ?>
            <h2><?= e($doc) ?> auténtico y firmado</h2>
            <p>El texto está íntegro: no fue cambiado desde que se preparó.</p>
        <?php else: ?>
            <h2><?= e($doc) ?> auténtico, sin firmar todavía</h2>
            <p>El texto está íntegro, pero el cliente aún no lo ha firmado.</p>
        <?php endif; ?>
    </section>
    <section class="bloque">
        <dl class="ficha-datos doc-verif-datos">
            <dt>Local</dt><dd><?= e($k['negocio']) ?><?= $k['negocio_ruc'] ? ' · RUC ' . e($k['negocio_ruc']) : '' ?></dd>
            <dt><?= e($doc) ?></dt><dd><?= isset($k['titulo']) ? e($k['titulo']) : 'N.º ' . (int) $k['numero'] ?> · <?= e(isset($k['titulo']) ? (\TuRenta\Anexos::ESTADOS[$k['estado']] ?? $k['estado']) : (Contratos::ESTADOS[$k['estado']] ?? $k['estado'])) ?></dd>
            <dt>Evento</dt><dd><?= e($k['evento']) ?> (<?= e($k['evento_codigo']) ?>)</dd>
            <dt>Fecha del evento</dt><dd><?= e(fecha_larga($k['evento_fecha'])) ?></dd>
            <?php if ($k['estado'] === 'firmado'): ?>
            <dt>Firmado por</dt><dd><?= e($k['firmante_nombre']) ?> · cédula/RUC <?= e($enmascarar($k['firmante_identificacion'])) ?></dd>
            <dt>Fecha de la firma</dt><dd><?= e(date('d/m/Y \a \l\a\s H:i', strtotime((string) $k['firmado_en']))) ?></dd>
            <?php endif; ?>
            <dt>Íntegro</dt><dd class="<?= $k['integro'] ? 'libre' : 'ocupado' ?>"><?= $k['integro'] ? 'Sí, el texto no fue cambiado' : 'No, el texto fue cambiado' ?></dd>
            <dt>Código</dt><dd class="doc-codigo"><?= e($k['codigo_verificacion']) ?></dd>
            <dt>Huella SHA-256</dt><dd class="doc-huella"><?= e($k['hash_sha256']) ?></dd>
        </dl>
        <p class="suave">La huella es un "resumen" único del texto. Si alguien cambia una sola letra del contrato, la huella deja de coincidir.</p>
    </section>
<?php elseif ($codigo !== ''): ?>
    <section class="bloque doc-verif doc-verif-mal" aria-live="polite">
        <p class="doc-verif-icono" aria-hidden="true">?</p>
        <h2>No encontramos ese código</h2>
        <p>Revisa que esté bien escrito (12 letras y números, como aparece al pie del contrato) o escanea el código QR del PDF.</p>
    </section>
<?php endif; ?>

<form method="get" class="bloque">
    <input type="hidden" name="r" value="verificar">
    <div class="campo"><label for="vcod">Código de verificación</label>
        <input type="text" id="vcod" name="c" value="<?= e($codigo) ?>" required maxlength="20" placeholder="Ej.: 3FA2B91C04DE" autocapitalize="characters" autocomplete="off" class="doc-codigo-input"></div>
    <button class="boton boton-ancho" type="submit">Verificar</button>
    <p class="suave" style="margin-top:8px">El código está al pie de cada contrato, junto al código QR.</p>
</form>
