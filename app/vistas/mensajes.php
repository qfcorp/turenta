<?php
use TuRenta\Cobranza;
/**
 * Mensajes por WhatsApp de hoy.
 * @var array $grupos @var array $enviados @var int $total @var bool $sinResenas @var array $u
 */
$ayudas = [
    'cuota'      => 'Vencen en los próximos 3 días o ya vencieron. Si lo marcas como enviado, vuelve a salir en 3 días si siguen sin pagar.',
    'contrato'   => 'El cliente todavía no firma. El mensaje lleva su enlace para firmar desde el celular.',
    'cotizacion' => 'Enviadas hace 3 días o más sin respuesta. Un mensaje a tiempo cierra más eventos.',
    'staff'      => 'Personas asignadas a los eventos de mañana que no han confirmado.',
    'invitados'  => 'Eventos de los próximos 10 días: pide la lista para organizar mesas y puerta.',
    'anexo'      => 'Extras aprobados con el contrato ya firmado: el cliente tiene que firmar el anexo. Vuelve a salir en 3 días si sigue sin firmar.',
    'cuestionario' => 'Eventos que se acercan y el cliente todavía no llena su formulario (música, decoración, menú…). Vuelve a salir en 4 días si sigue pendiente.',
    'resena'     => 'Eventos de los últimos días: agradece y manda la encuesta de satisfacción. A quien le encantó, la encuesta le pide la reseña en Google.',
];
$nombres = ['cuota' => 'Cuota', 'contrato' => 'Contrato', 'cotizacion' => 'Cotización', 'staff' => 'Personal', 'invitados' => 'Invitados', 'cuestionario' => 'Formulario', 'anexo' => 'Anexo', 'resena' => 'Reseña'];
?>
<div class="cabecera">
    <div><h1>Mensajes por WhatsApp de hoy</h1>
        <p><?= $total ? "Tienes $total " . ($total === 1 ? 'mensaje listo' : 'mensajes listos') . ' para enviar. Toca "Enviar", revisa el texto en WhatsApp y mándalo.' : 'Estás al día.' ?></p></div>
    <?php if (es_comercial($u)): ?><a class="boton boton-claro" href="<?= e(url('cobros')) ?>">Ir a cobros</a><?php endif; ?>
</div>

<?php if (!$total): ?>
    <section class="bloque vacio cb-vacio">
        <h2>¡No hay mensajes pendientes hoy!</h2>
        <p>Aquí aparecen cada día los recordatorios de cuotas, contratos por firmar, cotizaciones sin respuesta, personal por confirmar, listas de invitados y reseñas.</p>
    </section>
<?php endif; ?>

<?php foreach ($grupos as $tipo => $lista): if (!$lista) continue; ?>
<section class="bloque cb-grupo" id="m-<?= e($tipo) ?>">
    <h2><?= e(Cobranza::MENSAJES[$tipo][0]) ?> <span class="cb-contador"><?= count($lista) ?></span></h2>
    <p class="suave"><?= e($ayudas[$tipo]) ?></p>
    <?php if ($tipo === 'resena' && $sinResenas && es_admin_total($u)): ?>
        <p class="cb-nota">Consejo: pon el enlace de tus reseñas de Google en Configuración para que vaya en el mensaje.</p>
    <?php endif; ?>
    <ul class="cb-mensajes">
        <?php foreach ($lista as $m): ?>
        <li class="cb-mensaje">
            <div class="cb-mensaje-cabeza">
                <div><strong><?= e($m['para']) ?></strong>
                    <small class="suave"><a href="<?= e(url($tipo === 'cuota' ? 'evento_pagos' : ($tipo === 'cuestionario' ? 'evento_formulario' : ($tipo === 'anexo' ? 'contrato' : 'evento')), ['id' => $m['evento_id']])) ?>"><?= e($m['codigo']) ?></a> · <?= e($m['motivo']) ?></small></div>
            </div>
            <details class="cb-texto">
                <summary>Ver el mensaje</summary>
                <p><?= e($m['texto']) ?></p>
            </details>
            <div class="cb-cobro-botones">
                <?php if ($m['wa']): ?>
                    <a class="boton boton-wa boton-chico" href="<?= e($m['wa']) ?>" target="_blank" rel="noopener" data-cb-wa>Enviar por WhatsApp</a>
                <?php else: ?>
                    <span class="cb-sin-tel">Sin celular registrado</span>
                    <button type="button" class="boton boton-claro boton-chico" data-copiar="<?= e($m['texto']) ?>">Copiar mensaje</button>
                <?php endif; ?>
                <?php if (puede_editar($u)): ?>
                <form method="post" action="<?= e(url('mensajes', ['accion' => 'enviado'])) ?>">
                    <?= campo_csrf() ?><input type="hidden" name="tipo" value="<?= e($tipo) ?>"><input type="hidden" name="ref_id" value="<?= (int) $m['ref_id'] ?>">
                    <button class="boton boton-claro boton-chico" type="submit">✓ Marcar como enviado</button>
                </form>
                <?php endif; ?>
            </div>
        </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endforeach; ?>

<?php if ($enviados): ?>
<section class="bloque cb-enviados">
    <h2>Ya enviados hoy (<?= count($enviados) ?>)</h2>
    <ul class="filas">
        <?php foreach ($enviados as $m): ?>
        <li>
            <div><span class="cb-etq cb-etq-pagada"><?= e($nombres[$m['tipo']] ?? $m['tipo']) ?></span> <?= e($m['codigo']) ?> · <?= e($m['evento']) ?>
                <br><small class="suave"><?= e(substr($m['creado_en'], 11, 5)) ?><?= $m['usuario'] ? ' · ' . e($m['usuario']) : '' ?></small></div>
            <?php if (puede_editar($u)): ?>
            <form method="post" action="<?= e(url('mensajes', ['accion' => 'deshacer'])) ?>" class="derecha">
                <?= campo_csrf() ?><input type="hidden" name="tipo" value="<?= e($m['tipo']) ?>"><input type="hidden" name="ref_id" value="<?= (int) $m['ref_id'] ?>">
                <button class="enlace-chico cb-boton-enlace" type="submit">Deshacer</button>
            </form>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>
