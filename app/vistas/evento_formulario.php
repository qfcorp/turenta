<?php
use TuRenta\{Cuestionarios, Encuestas, Extras, WhatsApp};
/**
 * Pestaña "Formulario y extras" del evento.
 * @var array $ev @var string $tab @var array $form @var bool $comercial @var bool $verDinero @var array $pedidos @var int $borradores
 * @var ?array $encuesta @var ?array $borradorPregunta @var array $anexosPorPedido @var bool $contratoFirmado
 */
require RAIZ . '/app/vistas/_evento_tabs.php';
require_once RAIZ . '/app/vistas/_pt_campos.php';
$id = (int) $ev['id'];
$primerNombre = trim(explode(' ', trim((string) $ev['cliente']))[0]);
$enlaceForm = url_completa('portal_cuestionario', ['t' => $ev['token_cliente']]);
$waForm = WhatsApp::enlace($ev['cliente_telefono'], "Hola $primerNombre, te saludamos de {$u['negocio']}. Para preparar cada detalle de tu evento, llena el formulario aquí (se guarda solo): $enlaceForm"
    . ($form['abierto'] ? ' Lo puedes completar hasta el ' . fecha_corta($form['cierre']) . '.' : ''));
$enlaceEncuesta = url_completa('encuesta', ['t' => $ev['token_encuesta']]);
$waEncuesta = WhatsApp::enlace($ev['cliente_telefono'], "Hola $primerNombre, gracias por celebrar con nosotros en {$u['negocio']}. ¿Nos cuentas cómo te fue? Toma un minuto: $enlaceEncuesta");
$portal = url_completa('portal', ['t' => $ev['token_cliente']]);
$cant = fn(float $v) => rtrim(rtrim(number_format($v, 2, ',', '.'), '0'), ',');
$etiquetas = ['pendiente' => 'por_revisar', 'aprobado' => 'pagada', 'rechazado' => 'rechazado', 'cancelado' => 'pendiente'];
$pendientes = array_values(array_filter($pedidos, fn($p) => $p['estado'] === 'pendiente'));
$f = $borradorPregunta ?? [];
?>
<nav class="pt-indice" aria-label="Ir a">
    <a href="#formulario">Formulario <span class="cb-contador"><?= (int) $form['respondidas'] ?>/<?= (int) $form['total'] ?></span></a>
    <?php if ($verDinero): ?><a href="#extras">Extras pedidos<?php if ($pendientes): ?> <span class="cb-contador pt-alerta"><?= count($pendientes) ?></span><?php endif; ?></a><?php endif; ?>
    <a href="#opinion">Opinión del cliente</a>
</nav>

<!-- Formulario -->
<section class="bloque" id="formulario">
    <div class="bloque-cabeza">
        <h2><?= e($form['cuestionario']['nombre'] ?? 'Formulario del evento') ?></h2>
        <?php if ($form['total']): ?><a class="boton boton-claro boton-chico" href="<?= e(url('pdf', ['tipo' => 'formulario', 'id' => $id])) ?>" target="_blank">PDF</a><?php endif; ?>
    </div>
    <div class="pt-avance-mini">
        <div class="barra" role="progressbar" aria-valuenow="<?= (int) $form['pct'] ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Avance del formulario"><span style="width:<?= (int) $form['pct'] ?>%"></span></div>
        <small><strong><?= (int) $form['respondidas'] ?> de <?= (int) $form['total'] ?></strong> preguntas respondidas
            <?php if ($form['enviado_en']): ?> · <span class="cb-etq cb-etq-pagada">El cliente lo envió el <?= e(fecha_corta(substr($form['enviado_en'], 0, 10))) ?></span><?php endif; ?></small>
    </div>
    <p class="suave ayuda-chica">
        <?php if (!in_array($ev['estado'], Cuestionarios::ESTADOS_CLIENTE, true)): ?>
            El cliente lo verá en su portal cuando la fecha esté separada (al pagar la reserva).
        <?php elseif ($form['abierto']): ?>
            El cliente lo llena desde su portal hasta el <strong><?= e(fecha_corta($form['cierre'])) ?></strong>. Después solo el local puede cambiarlo.
        <?php else: ?>
            Se cerró para el cliente el <?= e(fecha_corta($form['cierre'])) ?>. El local todavía puede corregir las respuestas.
        <?php endif; ?>
    </p>
    <?php if ($comercial && in_array($ev['estado'], Cuestionarios::ESTADOS_CLIENTE, true) && $form['total']): ?>
    <div class="acciones">
        <?php if ($waForm): ?><a class="boton boton-wa boton-chico" href="<?= e($waForm) ?>" target="_blank" rel="noopener">Enviar al cliente por WhatsApp</a><?php endif; ?>
        <button class="boton boton-claro boton-chico" type="button" data-copiar="<?= e($enlaceForm) ?>">Copiar enlace</button>
    </div>
    <?php endif; ?>

    <?php if (!$form['total']): ?>
        <p class="vacio">Este evento no tiene preguntas. <?= $comercial ? 'Agrégalas abajo o carga el formulario de su tipo de evento.' : '' ?></p>
        <?php if ($comercial): ?>
        <form method="post" action="<?= e(url('evento_formulario', ['id' => $id])) ?>">
            <?= campo_csrf() ?><input type="hidden" name="accion" value="recargar">
            <button class="boton boton-chico" type="submit">Cargar el formulario de <?= e(mb_strtolower(\TuRenta\Eventos::TIPOS[$ev['tipo']])) ?></button>
        </form>
        <?php endif; ?>
    <?php elseif ($comercial): ?>
        <form method="post" action="<?= e(url('evento_formulario', ['id' => $id])) ?>" class="pt-form pt-form-local">
            <?= campo_csrf() ?><input type="hidden" name="accion" value="respuestas">
            <?php foreach ($form['secciones'] as $seccion => $preguntas): ?>
                <h3 class="pt-seccion-titulo"><?= e($seccion) ?></h3>
                <?php foreach ($preguntas as $p) echo pt_campo($p, true); ?>
            <?php endforeach; ?>
            <div class="pt-barra-guardar pt-barra-local">
                <button class="boton" type="submit">Guardar respuestas</button>
            </div>
        </form>
    <?php else: ?>
        <?php foreach ($form['secciones'] as $seccion => $preguntas): ?>
            <h3 class="pt-seccion-titulo"><?= e($seccion) ?></h3>
            <div class="pt-respuestas"><?php foreach ($preguntas as $p) echo pt_campo($p, false); ?></div>
        <?php endforeach; ?>
    <?php endif; ?>
</section>

<?php if ($comercial): ?>
<details class="bloque pt-desplegar" id="preguntas" <?= $borradorPregunta ? 'open' : '' ?>>
    <summary><strong>Agregar o quitar preguntas de este evento</strong> <small>— solo cambia el formulario de este evento, no la plantilla</small></summary>
    <?php if ($form['total']): ?>
    <ul class="filas pt-lista-preguntas">
        <?php foreach ($form['preguntas'] as $p): ?>
        <li><span><?= e($p['texto']) ?> <small class="suave">· <?= e(Cuestionarios::TIPOS[$p['tipo']]) ?><?= $p['respuesta'] !== null ? ' · respondida' : '' ?></small></span>
            <form method="post" action="<?= e(url('evento_formulario', ['id' => $id])) ?>" class="derecha">
                <?= campo_csrf() ?><input type="hidden" name="accion" value="pregunta_borrar"><input type="hidden" name="pregunta_id" value="<?= (int) $p['id'] ?>">
                <button class="boton boton-peligro boton-chico" type="submit" aria-label="Quitar: <?= e($p['texto']) ?>"
                        data-confirmar="¿Quitar «<?= e($p['texto']) ?>» del formulario de este evento?<?= $p['respuesta'] !== null ? ' Ya tiene respuesta: se pierde.' : '' ?>">Quitar</button>
            </form></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <?php require RAIZ . '/app/vistas/_pt_pregunta_form.php'; ?>
    <?php if ($form['total'] && $form['respondidas'] === 0): ?>
    <form method="post" action="<?= e(url('evento_formulario', ['id' => $id])) ?>" class="pt-recargar">
        <?= campo_csrf() ?><input type="hidden" name="accion" value="recargar">
        <button class="boton boton-claro boton-chico" type="submit" data-confirmar="¿Volver a cargar el formulario de <?= e(mb_strtolower(\TuRenta\Eventos::TIPOS[$ev['tipo']])) ?>? Se cambian todas las preguntas de este evento.">Volver a cargar el formulario de <?= e(mb_strtolower(\TuRenta\Eventos::TIPOS[$ev['tipo']])) ?></button>
        <small class="suave">Útil si cambiaste el tipo de evento. Solo mientras no haya respuestas.</small>
    </form>
    <?php endif; ?>
</details>
<?php endif; ?>

<?php if ($verDinero): ?>
<!-- Extras pedidos desde el portal -->
<section class="bloque" id="extras">
    <div class="bloque-cabeza">
        <h2>Extras que pidió el cliente</h2>
        <?php if (es_comercial($u)): ?><a class="boton boton-claro boton-chico" href="<?= e(url('extras')) ?>">Configurar</a><?php endif; ?>
    </div>
    <?php if (!$pedidos): ?>
        <p class="vacio">El cliente todavía no pide extras desde su portal. Elige qué extras se ofrecen en <a href="<?= e(url('extras')) ?>">Extras del portal</a>.</p>
    <?php else: ?>
    <ul class="pt-pedidos">
        <?php foreach ($pedidos as $p): ?>
        <li class="pt-pedido-fila <?= $p['estado'] === 'pendiente' ? 'pt-pedido-pendiente' : '' ?>" id="pedido-<?= (int) $p['id'] ?>">
            <div class="pt-pedido-cabeza">
                <span class="cb-etq cb-etq-<?= e($etiquetas[$p['estado']]) ?>"><?= e(Extras::ESTADOS[$p['estado']]) ?></span>
                <small class="suave">Pedido N.º <?= (int) $p['id'] ?> · <?= e(fecha_corta(substr($p['creado_en'], 0, 10))) ?> <?= e(substr($p['creado_en'], 11, 5)) ?>
                    <?= $p['respondio'] ? ' · respondió ' . e($p['respondio']) : '' ?></small>
            </div>
            <ul class="pt-lineas">
                <?php foreach ($p['items'] as $i): ?>
                <li><span><?= e($i['descripcion']) ?> <small class="suave">× <?= e($cant((float) $i['cantidad'])) ?> · <?= dinero($i['precio_unitario']) ?><?= $i['con_iva'] ? ' + IVA' : ' (0 % IVA)' ?></small></span>
                    <span class="monto"><?= dinero($i['total']) ?></span></li>
                <?php endforeach; ?>
                <li class="pt-lineas-total"><span>Total con IVA (aprox.)</span><span class="monto"><?= dinero($p['total']) ?></span></li>
            </ul>
            <?php if ($p['nota_cliente']): ?><p class="pt-pedido-nota"><strong>El cliente dice:</strong> <?= e($p['nota_cliente']) ?></p><?php endif; ?>
            <?php if ($p['estado'] === 'aprobado'): ?>
                <p class="libre">Se sumó <?= dinero($p['monto_agregado']) ?> al evento · cotización N.º <?= (int) $p['cotizacion_numero'] ?> (versión <?= (int) $p['cotizacion_version'] ?>)<?= $p['cuota_concepto'] ? ' · cuota «' . e($p['cuota_concepto']) . '»' : '' ?>.
                    <a href="<?= e(url('evento_pagos', ['id' => $id])) ?>">Ver pagos</a></p>
                <?php $anx = $anexosPorPedido[(int) $p['id']] ?? null; if ($anx): ?>
                    <p class="pt-anexo-estado"><span class="doc-estado doc-estado-<?= $anx['firmable'] ? 'enviado' : ($anx['estado'] === 'firmado' ? 'firmado' : 'anulado') ?>"><?= e($anx['titulo']) ?>: <?= $anx['firmable'] ? 'por firmar' : ($anx['estado'] === 'firmado' ? 'firmado' : 'sin efecto') ?></span>
                        <a href="<?= e(url('contrato', ['id' => $id])) ?>#anexos">Ver el anexo</a></p>
                <?php else: ?>
                    <p class="suave ayuda-chica">Sin anexo: cuando se aprobó, el evento no tenía contrato firmado. Los extras entran en el contrato cuando lo generes.</p>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($p['nota_negocio']): ?><p class="pt-pedido-nota"><strong>Nota para el cliente:</strong> <?= e($p['nota_negocio']) ?></p><?php endif; ?>
            <?php if (in_array($p['estado'], ['aprobado', 'rechazado'], true) && ($wa = WhatsApp::enlace($ev['cliente_telefono'], Extras::textoRespuesta($p, (string) $u['negocio'], $portal)))): ?>
                <a class="boton boton-wa boton-chico" href="<?= e($wa) ?>" target="_blank" rel="noopener">Avisar al cliente por WhatsApp</a>
            <?php endif; ?>
            <?php if ($p['estado'] === 'pendiente' && $comercial): ?>
            <div class="pt-responder">
                <form method="post" action="<?= e(url('evento_formulario', ['id' => $id])) ?>" class="pt-responder-form">
                    <?= campo_csrf() ?><input type="hidden" name="accion" value="aprobar"><input type="hidden" name="pedido_id" value="<?= (int) $p['id'] ?>">
                    <div class="campo"><label for="nota-a<?= (int) $p['id'] ?>">Nota para el cliente <small>(opcional)</small></label>
                        <input type="text" id="nota-a<?= (int) $p['id'] ?>" name="nota" maxlength="400" placeholder="Ej.: ¡Listo! La fotocabina llega a las 20:00"></div>
                    <button class="boton boton-chico" type="submit" data-confirmar="¿Aprobar estos extras? Se crea una versión nueva de la cotización aceptada y se suma una cuota al plan de pagos.<?= $contratoFirmado ? ' Como el contrato ya está firmado, se prepara un anexo para que el cliente lo firme.' : '' ?> Un pedido aprobado no se puede deshacer.">Aprobar y sumar al evento</button>
                    <small class="suave" style="display:block;margin-top:6px"><?= $contratoFirmado ? 'El contrato ya está firmado: al aprobar se prepara un anexo para que el cliente lo firme.' : 'Todavía no hay contrato firmado: los extras entran en el contrato cuando lo generes (no hace falta anexo).' ?></small>
                    <?php if ($borradores): ?><small class="pt-advertencia">Hay <?= $borradores ?> cotización<?= $borradores === 1 ? '' : 'es' ?> en borrador o enviada sin aceptar: al aprobar quedará<?= $borradores === 1 ? '' : 'n' ?> reemplazada<?= $borradores === 1 ? '' : 's' ?>.</small><?php endif; ?>
                </form>
                <details class="pt-rechazar">
                    <summary class="boton boton-peligro boton-chico">No aprobar</summary>
                    <form method="post" action="<?= e(url('evento_formulario', ['id' => $id])) ?>">
                        <?= campo_csrf() ?><input type="hidden" name="accion" value="rechazar"><input type="hidden" name="pedido_id" value="<?= (int) $p['id'] ?>">
                        <div class="campo"><label for="nota-r<?= (int) $p['id'] ?>">¿Por qué? <small>(lo ve el cliente)</small></label>
                            <input type="text" id="nota-r<?= (int) $p['id'] ?>" name="nota" maxlength="400" required minlength="3" placeholder="Ej.: Ese día la fotocabina ya está reservada"></div>
                        <button class="boton boton-peligro boton-chico" type="submit">Confirmar: no aprobar</button>
                    </form>
                </details>
            </div>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</section>
<?php endif; ?>

<!-- Opinión del cliente -->
<section class="bloque" id="opinion">
    <h2>Opinión del cliente</h2>
    <?php if ($encuesta): $g = Encuestas::grupo((int) $encuesta['nps']); ?>
        <div class="pt-opinion">
            <div class="pt-opinion-nps pt-opinion-<?= e($g) ?>"><strong><?= (int) $encuesta['nps'] ?></strong><small>de 10</small></div>
            <div>
                <p><strong><?= $g === 'promotor' ? 'Nos recomendaría' : ($g === 'pasivo' ? 'Quedó conforme' : 'No quedó contento') ?></strong>
                    <small class="suave">· respondió el <?= e(fecha_corta(substr($encuesta['creado_en'], 0, 10))) ?></small></p>
                <ul class="pt-opinion-notas">
                    <?php foreach (Encuestas::ASPECTOS as $k => $txt): if ($encuesta[$k] === null) continue; ?>
                        <li><?= e($txt) ?>: <span class="pt-mini-estrellas" aria-label="<?= (int) $encuesta[$k] ?> de 5"><?= str_repeat('★', (int) $encuesta[$k]) . str_repeat('☆', 5 - (int) $encuesta[$k]) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php if ($encuesta['comentario']): ?><blockquote class="pt-comentario"><?= nl2br(e($encuesta['comentario'])) ?></blockquote><?php endif; ?>
    <?php elseif ($ev['fecha'] < date('Y-m-d') && in_array($ev['estado'], Encuestas::ESTADOS, true)): ?>
        <p class="suave">El cliente todavía no responde la encuesta. Sale sola en "Mensajes de hoy" el día después del evento.</p>
        <?php if ($comercial): ?>
        <div class="acciones">
            <?php if ($waEncuesta): ?><a class="boton boton-wa boton-chico" href="<?= e($waEncuesta) ?>" target="_blank" rel="noopener">Enviar la encuesta por WhatsApp</a><?php endif; ?>
            <button class="boton boton-claro boton-chico" type="button" data-copiar="<?= e($enlaceEncuesta) ?>">Copiar enlace</button>
        </div>
        <?php endif; ?>
    <?php else: ?>
        <p class="suave">Después del evento, el cliente recibe una encuesta corta (¿nos recomendarías?, comida, atención, instalaciones, organización). Si le encantó, se le pide la reseña en Google.</p>
    <?php endif; ?>
</section>
