<?php
/**
 * Pestaña Contrato → anexos por extras aprobados (migración 090): estado (por firmar / firmado), PDF, enlace de firma,
 * WhatsApp y verificación. Usa $ev, $u y $puede de la vista del contrato.
 */
use TuRenta\{Anexos, WhatsApp};

$anexosEv = (new Anexos(db()))->deEvento((int) $u['negocio_id'], (int) $ev['id']);
if (!$anexosEv) return;
?>
<section class="bloque" id="anexos">
    <h2>Anexos del contrato</h2>
    <p class="suave">Se preparan solos cuando apruebas extras que el cliente pidió desde su portal y el contrato ya estaba firmado.</p>
    <ul class="filas">
        <?php foreach ($anexosEv as $a):
            $enlace = url_completa('firmar_anexo', ['t' => $a['token']]);
            $wa = $a['firmable'] ? WhatsApp::enlace($ev['cliente_telefono'], Anexos::textoFirma($a, (string) $u['negocio'], $enlace)) : null;
            $etq = $a['firmable'] ? 'Por firmar' : ($a['estado'] === 'firmado' ? 'Firmado' : ($a['estado'] === 'enviado' ? 'Sin efecto (contrato anulado)' : 'Anulado')); ?>
        <li class="pt-anexo-fila">
            <div><strong><?= e($a['titulo']) ?></strong>
                <span class="doc-estado doc-estado-<?= e($a['firmable'] ? 'enviado' : ($a['estado'] === 'firmado' ? 'firmado' : 'anulado')) ?>"><?= e($etq) ?></span>
                <br><small>Extras por <?= dinero($a['monto']) ?> · preparado el <?= e(date('d/m/Y', strtotime((string) $a['creado_en']))) ?>
                    <?= $a['firmado_en'] ? ' · firmado el ' . e(date('d/m/Y H:i', strtotime((string) $a['firmado_en']))) . ' por ' . e($a['firmante_nombre']) : '' ?></small>
                <?php if ($a['firmable'] && $puede): ?>
                <div class="acciones" style="margin-top:6px">
                    <?php if ($wa): ?><a class="boton boton-wa boton-chico" href="<?= e($wa) ?>" target="_blank" rel="noopener">Enviar para firmar por WhatsApp</a><?php endif; ?>
                    <button type="button" class="boton boton-claro boton-chico" data-copiar="<?= e($enlace) ?>">Copiar enlace de firma</button>
                </div>
                <?php endif; ?>
            </div>
            <div class="derecha">
                <?php if ($puede): ?><a class="enlace-chico" href="<?= e(url('pdf', ['tipo' => 'anexo', 'id' => $a['id']])) ?>" target="_blank" rel="noopener">PDF</a><br><?php endif; ?>
                <a class="enlace-chico" href="<?= e(url('verificar', ['c' => $a['codigo_verificacion']])) ?>" target="_blank" rel="noopener">Verificar</a>
            </div>
        </li>
        <?php endforeach; ?>
    </ul>
</section>
