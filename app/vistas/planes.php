<?php
use TuRenta\{Modulos, PanelTukan, Planes};
/** Mi plan (Anexo B). @var array $estadoMod @var array $catalogo @var array $planesMod @var array $precios @var string $estadoCuenta
 *  @var array $pagos @var array $pedidos @var array $pedidosPendientes @var array $uso @var array $listaPlanes */
$me = $estadoMod;
$nombres = ['mensual' => 'Cada mes', 'semestral' => 'Cada 6 meses', 'anual' => 'Cada año'];
$wa = fn(string $texto) => 'https://wa.me/' . whatsapp_ventas() . '?text=' . rawurlencode($texto . ' (' . $u['negocio'] . ', ' . $u['email'] . ')');
$estadoTxt = PanelTukan::ESTADOS[$estadoCuenta] ?? $estadoCuenta;
$hasta = $u['estado'] === 'prueba' ? $u['prueba_hasta'] : $u['activo_hasta'];
$porGrupo = [];
foreach ($catalogo as $m) $porGrupo[$m['grupo_nombre']][] = $m;
$ahorro = function (array $c, array $mensual): int {
    $normal = $mensual['monto'] * $c['meses_recibidos'];
    return $normal > 0 ? (int) round(($normal - $c['monto']) * 100 / $normal) : 0;
};
$encendidos = count($me['pagados']);
// App de Google Play (docs/GOOGLE_PLAY.md): sin precios del software, sin «Pedir cotización», sin «Pagar» ni WhatsApp de ventas.
// Se ve el plan, su estado, los módulos encendidos y se pueden elegir los módulos incluidos en el plan (no cuesta nada más).
$enApp = en_app_android();
?>
<div class="cabecera">
    <div><h1>Mi plan</h1><p>Tus módulos de TuRenta para <?= e($u['negocio']) ?><?= $enApp ? '.' : ': lo que tienes, lo que pagas y lo que puedes agregar.' ?></p></div>
</div>

<section class="bloque mp-resumen" aria-labelledby="mp-t-plan">
    <div>
        <p style="margin:0" id="mp-t-plan">Plan <strong><?= e($u['plan_nombre'] ?? $u['plan_codigo']) ?></strong>
            <span class="etq est-<?= e($estadoCuenta) ?>"><?= e($estadoTxt) ?></span></p>
        <?php if (!$enApp): ?>
        <p class="mp-precio"><?= dinero($me['mensual']) ?> <small>al mes</small></p>
        <ul class="mp-desglose">
            <li>Plan <?= dinero($me['precio_plan']) ?></li>
            <?php if ($me['precio_extra'] > 0): ?><li>módulos extra +<?= dinero($me['precio_extra']) ?></li><?php endif; ?>
            <?php if ($me['descuento'] > 0): ?><li>módulos quitados −<?= dinero($me['descuento']) ?></li><?php endif; ?>
            <?php if ($me['precio_acordado'] !== null): ?><li>precio acordado con Tukán</li><?php endif; ?>
        </ul>
        <?php endif; ?>
        <p class="suave" style="margin:6px 0 0">
            <?php if ($hasta): ?><?= $u['estado'] === 'prueba' ? 'Demo hasta el ' : 'Pagado hasta el ' ?><strong><?= e((new DateTimeImmutable($hasta))->format('d/m/Y')) ?></strong> · <?php endif; ?>
            <?= $encendidos ?> de <?= count($catalogo) ?> módulos · <?= (int) $uso['espacios'] ?> espacios y <?= (int) $uso['usuarios'] ?> usuarios activos <small>(los administradores no cuentan)</small></p>
        <?php if (!$me['demo'] && ($me['modulos_elegir'] ?? 0) > 0): /* planes de $20 y $45 (migración 112) */ ?>
            <p class="mp-elegir" style="margin:6px 0 0" data-por-elegir="<?= (int) $me['por_elegir'] ?>">Tu plan trae <strong><?= (int) $me['modulos_elegir'] ?> módulos a tu elección</strong><?= $me['por_elegir'] > 0
                ? ': te ' . ($me['por_elegir'] === 1 ? 'queda 1 por elegir' : 'quedan ' . (int) $me['por_elegir'] . ' por elegir') . '. Elígelo abajo con «Elegir este módulo».' : '.' ?></p>
        <?php endif; ?>
    </div>
    <?php if (!$enApp): ?><a class="boton boton-wa" href="<?= e($wa('Hola, tengo una pregunta sobre mi plan de TuRenta')) ?>" target="_blank" rel="noopener">Preguntar por WhatsApp</a><?php endif; ?>
</section>
<?php if ($me['demo']): ?>
    <p class="aviso aviso-alerta">Estás en tu demo: <?= $me['demo_todos'] ? 'tienes todos los módulos encendidos para que veas todo' : 'tienes encendidos los módulos que elegimos contigo' ?>.
        <?= $enApp ? 'Nada de lo que cargues se pierde.' : 'Al contratar te quedas con los módulos de tu plan y los que agregues; nada de lo que cargaste se pierde.' ?></p>
<?php endif; ?>

<section class="bloque" aria-labelledby="mp-t-mods">
    <h2 id="mp-t-mods">Tus módulos</h2>
    <p class="g-nota"><?= $enApp ? 'Los módulos de tu cuenta los administra Tukán Software.' : 'Enciende solo lo que necesitas y paga solo eso.' ?> Si apagamos un módulo, tus datos se guardan y vuelven al encenderlo.</p>
    <?php foreach ($porGrupo as $grupo => $lista): ?>
        <div class="mp-grupo">
            <h3><?= e($grupo) ?></h3>
            <div class="mp-lista">
            <?php foreach ($lista as $m):
                $id = $m['id'];
                $on = in_array($id, $me['pagados'], true);
                $pedido = isset($pedidosPendientes[$id]);
                $faltan = array_values(array_filter($m['depende_de'], fn($d) => !in_array($d, $me['pagados'], true))); ?>
                <article class="mp-mod <?= $on ? 'on' : '' ?>" id="mod-<?= e($id) ?>">
                    <div class="mp-mod-cab">
                        <h4><span aria-hidden="true"><?= e($m['icono']) ?></span> <?= e($m['nombre']) ?></h4>
                        <span class="mp-estado <?= $on ? 'on' : ($pedido ? 'pedido' : 'off') ?>"><?= $on ? 'Encendido' : ($pedido ? 'Pedido enviado' : 'No está en tu plan') ?></span>
                    </div>
                    <p><?= e($m['descripcion']) ?></p>
                    <?php if ($faltan && !$on): ?><p class="ayuda-chica">Viene junto con: <?= e(implode(', ', array_map([Modulos::class, 'nombre'], $faltan))) ?>.</p><?php endif; ?>
                    <div class="mp-mod-pie">
                        <?php /* Regla del dueño: el precio de un módulo que el cliente no tiene no se muestra; se pide cotización */ ?>
                        <?php $aPedido = Modulos::porCotizacion($m);   // precio 0 en el catálogo: por cotización
                              // Planes de $20 y $45 (migración 112): en Básico puede elegir módulos incluidos; el Asistente IA no cuenta entre ellos
                              $elegible = !$on && ($me['por_elegir'] ?? 0) > 0 && !in_array($id, Modulos::FUERA_DEL_CUPO, true); ?>
                        <?php if ($enApp): /* app de Google Play: sin precios ni cotizaciones */ ?>
                        <span class="mp-mod-precio"><?= !$on ? ($elegible ? 'Puedes elegirlo en tu plan' : 'No está encendido') : (in_array($id, $me['elegidos'] ?? [], true) ? 'Incluido · lo elegiste' : 'Encendido en tu cuenta') ?></span>
                        <?php if ($elegible && !$pedido): ?>
                            <form method="post"><?= campo_csrf() ?><input type="hidden" name="accion" value="pedir"><input type="hidden" name="modulo" value="<?= e($id) ?>">
                                <button class="boton boton-chico" type="submit">Elegir este módulo</button></form>
                        <?php endif; ?>
                        <?php else: ?>
                        <span class="mp-mod-precio"><?= !$on ? ($elegible ? 'Puedes elegirlo en tu plan' : (in_array($id, Modulos::SOLO_A_PEDIDO, true) || $aPedido ? 'Pídalo y le cotizamos' : 'Precio por cotización'))
                            : (in_array($id, $me['del_plan'], true) ? 'Incluido en tu plan'
                               : (in_array($id, $me['elegidos'] ?? [], true) ? 'Incluido · lo elegiste'
                                  : ($aPedido ? 'Agregado a tu plan' : 'Extra · ' . dinero($me['precio_por_extra'][$id] ?? $m['precio_mensual']) . ' al mes'))) ?></span>
                        <?php if (!$on && !$pedido): ?>
                            <form method="post"><?= campo_csrf() ?><input type="hidden" name="accion" value="pedir"><input type="hidden" name="modulo" value="<?= e($id) ?>">
                                <button class="boton boton-chico" type="submit"><?= $elegible ? 'Elegir este módulo' : 'Pedir cotización' ?></button></form>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
</section>

<section class="bloque" aria-labelledby="mp-t-base">
    <h2 id="mp-t-base">Siempre incluido</h2>
    <p class="g-nota">Todos los planes traen la base de TuRenta:</p>
    <ul class="mp-base"><?php foreach (Modulos::BASE as $b): ?><li><?= e($b) ?></li><?php endforeach; ?></ul>
</section>

<?php if (!$enApp): /* App de Google Play: sin lo que se paga, ni cómo pagar, ni otros planes, ni pagos hechos */ ?>
<section class="bloque" aria-labelledby="mp-t-pagar">
    <h2 id="mp-t-pagar">Cuánto pagas</h2>
    <?php foreach (['mensual', 'semestral', 'anual'] as $per): $c = $precios[$per]; $ah = $ahorro($c, $precios['mensual']); ?>
        <div class="g-periodo">
            <div><strong><?= e($nombres[$per]) ?></strong>
                <?= $ah > 0 ? '<span class="g-ahorro">ahorras ' . $ah . ' %</span>' : '' ?><br>
                <small class="suave"><?= e(planes()->textoPeriodo($per)) ?><?= $per !== 'mensual' ? ' · sale a ' . dinero($c['precio_por_mes']) . ' al mes' : '' ?></small></div>
            <strong class="monto"><?= dinero($c['monto']) ?></strong>
            <a class="boton boton-claro boton-chico" href="<?= e($wa('Hola, quiero pagar TuRenta ' . mb_strtolower($nombres[$per]) . ' (' . dinero($c['monto']) . '). ¿Cómo hago el pago?')) ?>" target="_blank" rel="noopener">Pagar</a>
        </div>
    <?php endforeach; ?>
    <ol style="padding-left:20px;margin:12px 0 0">
        <li>Toca <strong>Pagar</strong>: se abre WhatsApp con el mensaje listo.</li>
        <li>Te enviamos los datos para pagar por <strong>transferencia, DeUna o tarjeta</strong>.</li>
        <li>Envíanos la foto del comprobante y lo activamos el mismo día. Tus datos se mantienen.</li>
    </ol>
    <p class="g-nota" style="margin-top:8px">El pago anual incluye un dominio propio para tu página (ej. tuquinta.com) a nombre de Tukán mientras tengas el plan.</p>
</section>

<section class="bloque" aria-labelledby="mp-t-planes">
    <h2 id="mp-t-planes">Planes</h2>
    <p class="g-nota">Cada plan trae la base y un grupo de módulos. Puedes cambiar de plan o agregar módulos sueltos: pide la cotización y Tukán te escribe con el valor.</p>
    <div class="mp-planes">
        <?php foreach ($listaPlanes as $pl): $pm = $planesMod[$pl['codigo']] ?? null; if (!$pm) continue; ?>
            <div class="mp-plan <?= $pl['codigo'] === $u['plan_codigo'] ? 'actual' : '' ?>">
                <h3>Plan <?= e($pl['nombre']) ?><?= $pl['codigo'] === $u['plan_codigo'] ? ' <small class="suave">(el tuyo)</small>' : '' ?></h3>
                <p><?= $pm['todos'] ? 'Todos los módulos' . (in_array('asistente_ia', $pm['modulos'], true) ? ', con el Asistente IA' : '')
                       : ($pm['modulos_elegir'] > 0 ? 'La base + ' . ($pm['modulos'] ? count($pm['modulos']) . ' módulos + ' : '') . $pm['modulos_elegir'] . ' módulos que tú eliges' : count($pm['modulos']) . ' módulos') ?><?= $pl['codigo'] === $u['plan_codigo'] ? '' : ' · precio por cotización' ?></p>
                <p><?= $pl['max_espacios'] ? 'Hasta ' . (int) $pl['max_espacios'] . ' espacios' : 'Espacios sin límite' ?> · <?= $pl['max_usuarios'] ? 'hasta ' . (int) $pl['max_usuarios'] . ' usuarios' : 'usuarios sin límite' ?></p>
                <p><?= e($pl['descripcion'] ?? '') ?></p>
                <?php if ($pl['codigo'] !== $u['plan_codigo']): ?>
                    <?php $faltanPlan = array_diff($pm['todos'] ? array_keys($catalogo) : $pm['modulos'], $me['pagados']); ?>
                    <?php if ($faltanPlan): ?>
                        <form method="post"><?= campo_csrf() ?><input type="hidden" name="accion" value="pedir_plan"><input type="hidden" name="plan" value="<?= e($pl['codigo']) ?>">
                            <button class="boton boton-claro boton-chico" type="submit">Pedir cotización</button></form>
                    <?php else: ?>
                        <p><a href="<?= e($wa('Hola, quiero cotizar el cambio de mi TuRenta al plan ' . $pl['nombre'])) ?>" target="_blank" rel="noopener">Pedir cotización</a></p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<?php endif; ?>

<?php if ($pedidos): ?>
<section class="bloque" aria-labelledby="mp-t-pedidos">
    <h2 id="mp-t-pedidos">Tus pedidos de módulos</h2>
    <ul class="filas">
        <?php foreach ($pedidos as $s): ?>
            <li><span><?= e(implode(', ', array_map([Modulos::class, 'nombre'], Modulos::parse($s['encender'])))) ?>
                <small class="suave" style="display:block"><?= e((new DateTimeImmutable($s['creado']))->format('d/m/Y')) ?><?= $s['respuesta'] && !$enApp ? ' · ' . e($s['respuesta']) : '' ?></small></span>
                <span class="mp-estado <?= $s['estado'] === 'aprobada' ? 'on' : ($s['estado'] === 'pendiente' ? 'pedido' : 'off') ?>"><?= ['pendiente' => 'Pendiente', 'aprobada' => 'Aprobado', 'rechazada' => 'No aprobado'][$s['estado']] ?></span></li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<?php if (!$enApp): ?>
<section class="bloque">
    <h2>Pagos hechos</h2>
    <?php if (!$pagos): ?><p class="vacio">Todavía no hay pagos registrados.</p><?php else: ?>
        <div class="tabla-scroll"><table class="tabla" style="min-width:480px">
            <thead><tr><th>Pagado</th><th>Plan</th><th>Cubre</th><th class="num">Valor</th></tr></thead>
            <tbody><?php foreach ($pagos as $p): ?>
                <tr style="<?= $p['anulada'] ? 'opacity:.5;text-decoration:line-through' : '' ?>">
                    <td><?= e((new DateTimeImmutable($p['pagado_en']))->format('d/m/Y')) ?></td>
                    <td><?= e($p['plan_nombre']) ?> · <?= e(Planes::PERIODOS_NOMBRE[$p['periodo']] ?? $p['periodo']) ?><?= $p['dominio'] ? '<br><small>' . e($p['dominio']) . '</small>' : '' ?></td>
                    <td><?= e((new DateTimeImmutable($p['inicio']))->format('d/m/Y')) ?> al <?= e((new DateTimeImmutable($p['fin']))->format('d/m/Y')) ?></td>
                    <td class="num"><?= dinero($p['monto']) ?></td></tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
</section>
<?php endif; ?>
