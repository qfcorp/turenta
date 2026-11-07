<?php
/** Portal → "Tus documentos": anexos del contrato (migración 090). Usa $ptAnexos de _pt_portal.php. */
foreach ($ptAnexos ?? [] as $a):
    if (!$a['firmable'] && $a['estado'] !== 'firmado') continue; ?>
<li><div><span class="principal-linea"><?= e($a['titulo']) ?></span>
        <div class="linea-sub">Extras por <?= dinero($a['monto']) ?> · <?= $a['estado'] === 'firmado' ? 'Firmado el ' . e(fecha_corta(substr((string) $a['firmado_en'], 0, 10))) : 'Por firmar' ?></div></div>
    <?php if ($a['estado'] === 'firmado'): ?>
        <span class="cb-cobro-botones"><a class="boton boton-claro boton-chico" href="<?= e(url('firmar_anexo', ['t' => $a['token'], 'pdf' => 1])) ?>" target="_blank">PDF</a>
        <a class="boton boton-claro boton-chico" href="<?= e(url('verificar', ['c' => $a['codigo_verificacion']])) ?>">Verificar</a></span>
    <?php else: ?>
        <a class="boton boton-chico" href="<?= e(url('firmar_anexo', ['t' => $a['token']])) ?>">Firmar anexo</a>
    <?php endif; ?></li>
<?php endforeach; ?>
