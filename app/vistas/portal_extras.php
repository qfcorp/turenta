<?php
use TuRenta\{Cotizaciones, Extras};
/**
 * Extras que el cliente puede pedir desde su portal.
 * @var array $ev @var array $negocio @var string $token @var ?array $borrador @var array $ofrecidos @var ?string $motivo
 * @var array $pedidos @var float $ivaPct
 */
$logo = $negocio['logo'] ? url('imagen_publica', ['tipo' => 'logo', 's' => $negocio['slug']]) : null;
$iconos = ['espacio' => '🏛️', 'menu' => '🍽️', 'bebidas' => '🥂', 'decoracion' => '💐', 'musica' => '🎶', 'foto_video' => '📸',
           'mobiliario' => '🪑', 'personal' => '🤵', 'transmision' => '🔴', 'otros' => '✨'];
$unidades = ['persona' => 'por persona', 'evento' => 'por evento', 'hora' => 'por hora', 'unidad' => 'cada uno'];
$cant = fn(float $v) => rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',');
$puedePedir = $motivo === null && $ofrecidos;
$etiquetas = ['pendiente' => 'por_revisar', 'aprobado' => 'pagada', 'rechazado' => 'rechazado', 'cancelado' => 'pendiente'];
?>
<header class="publico-cabeza cb-portal-cabeza">
    <?php if ($logo): ?><img class="pub-logo cb-logo" src="<?= e($logo) ?>" alt="Logo de <?= e($negocio['nombre']) ?>">
    <?php else: ?><span class="marca-sello" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($negocio['nombre'], 0, 1))) ?></span><?php endif; ?>
    <div><h1><?= e($negocio['nombre']) ?></h1><p>Portal de tu evento</p></div>
</header>

<p class="pt-volver"><a href="<?= e(url('portal', ['t' => $token])) ?>">← Volver a mi evento</a></p>

<section class="pub-hero pt-hero">
    <h2 class="pt-hero-titulo">Extras para tu evento</h2>
    <p class="pt-hero-texto">¿Quieres sumar algo más? Elige y envía tu pedido: <?= e($negocio['nombre']) ?> lo revisa y, si lo aprueba, se suma a tu plan de pagos.</p>
</section>

<?php if ($motivo): ?>
    <p class="aviso aviso-error pt-motivo" role="status"><?= e($motivo) ?></p>
<?php endif; ?>

<?php if (!$ofrecidos): ?>
    <section class="bloque pt-vacio"><p>Por ahora el local no tiene extras para pedir en línea. Si quieres agregar algo, escríbele por WhatsApp.</p></section>
<?php else: ?>
<form method="post" action="<?= e(url('portal_extras', ['t' => $token])) ?>" class="pt-extras-form" data-pt-extras>
    <?= campo_csrf() ?>
    <input type="hidden" name="accion" value="pedir">
    <ul class="pt-extras">
        <?php foreach ($ofrecidos as $x):
            $pid = (int) $x['id'];
            $valor = $borrador['cant'][$pid] ?? '';
            $entero = !in_array($x['unidad'], ['persona', 'hora'], true); ?>
        <li class="pt-extra" data-pt-extra data-precio="<?= e(number_format($x['precio_final'], 2, '.', '')) ?>">
            <div class="pt-extra-foto">
                <?php if ($x['portal_foto']): ?><img src="<?= e(url('portal_extras', ['t' => $token, 'foto' => $pid])) ?>" alt="<?= e($x['nombre']) ?>" loading="lazy">
                <?php else: ?><span aria-hidden="true"><?= $iconos[$x['categoria']] ?? '✨' ?></span><?php endif; ?>
            </div>
            <div class="pt-extra-info">
                <h3><?= e($x['nombre']) ?></h3>
                <?php if ($x['texto'] !== ''): ?><p><?= e($x['texto']) ?></p><?php endif; ?>
                <p class="pt-extra-precio"><strong><?= dinero($x['precio_final']) ?></strong> <small><?= e($unidades[$x['unidad']] ?? '') ?><?= $x['con_iva'] && $ivaPct > 0 ? ' · IVA incluido' : '' ?></small></p>
                <?php if ($puedePedir): ?>
                <div class="pt-cantidad">
                    <label for="cant-<?= $pid ?>"><?= $x['unidad'] === 'persona' ? 'Personas' : ($x['unidad'] === 'hora' ? 'Horas' : 'Cantidad') ?></label>
                    <div class="pt-stepper">
                        <button type="button" class="pt-menos" data-pt-paso="-1" aria-label="Menos <?= e($x['nombre']) ?>">−</button>
                        <input type="text" inputmode="<?= $entero ? 'numeric' : 'decimal' ?>" id="cant-<?= $pid ?>" name="cant[<?= $pid ?>]" value="<?= e(is_scalar($valor) ? (string) $valor : '') ?>"
                               placeholder="0" maxlength="6" autocomplete="off" data-pt-cant>
                        <button type="button" class="pt-mas" data-pt-paso="1" aria-label="Más <?= e($x['nombre']) ?>">+</button>
                    </div>
                    <?php if ($x['unidad'] === 'persona'): ?><button type="button" class="pt-todos" data-pt-todos="<?= (int) $ev['invitados'] ?>">Para mis <?= (int) $ev['invitados'] ?> invitados</button><?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php if ($puedePedir): ?>
    <div class="pt-fijo" data-pt-fijo hidden>
        <span>Total aprox. <strong data-pt-total>$0,00</strong></span>
        <a class="boton boton-chico" href="#pt-enviar">Revisar y enviar</a>
    </div>
    <div class="bloque pt-pedido" id="pt-enviar">
        <div class="campo"><label for="pt-nota">¿Algo que debamos saber? <small>(opcional)</small></label>
            <textarea id="pt-nota" name="nota" rows="2" maxlength="400" placeholder="Ej.: la hora extra es para seguir bailando hasta las 3:00"><?= e($borrador['nota'] ?? '') ?></textarea></div>
        <p class="pt-total">Total aproximado: <strong data-pt-total>$0,00</strong></p>
        <p class="suave pt-nota">El valor final lo confirma el local al aprobar tu pedido. No pagas nada todavía.</p>
        <button class="boton boton-ancho" type="submit">Enviar pedido al local</button>
    </div>
    <?php endif; ?>
</form>
<?php endif; ?>

<?php if ($pedidos): ?>
<section class="bloque" id="mis-pedidos">
    <h2>Mis pedidos</h2>
    <ul class="pt-pedidos">
        <?php foreach ($pedidos as $p): ?>
        <li class="pt-pedido-fila">
            <div class="pt-pedido-cabeza">
                <span class="cb-etq cb-etq-<?= e($etiquetas[$p['estado']]) ?>"><?= e(Extras::ESTADOS[$p['estado']]) ?></span>
                <small class="suave">Pedido N.º <?= (int) $p['id'] ?> · <?= e(fecha_corta(substr($p['creado_en'], 0, 10))) ?></small>
            </div>
            <ul class="pt-pedido-items">
                <?php foreach ($p['items'] as $i): ?><li><?= e($i['descripcion']) ?> <span class="suave">× <?= e($cant((float) $i['cantidad'])) ?></span></li><?php endforeach; ?>
            </ul>
            <p class="pt-pedido-total">
                <?php if ($p['estado'] === 'aprobado'): ?>Se sumaron <strong><?= dinero($p['monto_agregado']) ?></strong> a tu evento<?= $p['cuota_concepto'] ? ' (en tu plan de pagos: «' . e($p['cuota_concepto']) . '»)' : '' ?>.
                <?php else: ?>Aprox. <strong><?= dinero($p['total']) ?></strong><?php endif; ?></p>
            <?php if ($p['nota_negocio']): ?><p class="pt-pedido-nota"><strong><?= e($negocio['nombre']) ?>:</strong> <?= e($p['nota_negocio']) ?></p><?php endif; ?>
            <?php if ($p['estado'] === 'pendiente'): ?>
            <form method="post" action="<?= e(url('portal_extras', ['t' => $token])) ?>">
                <?= campo_csrf() ?><input type="hidden" name="accion" value="cancelar"><input type="hidden" name="pedido_id" value="<?= (int) $p['id'] ?>">
                <button class="enlace-chico cb-boton-enlace" type="submit" data-confirmar="¿Cancelar este pedido?">Cancelar pedido</button>
            </form>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>
