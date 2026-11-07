<?php
use TuRenta\{PanelTukan, WhatsApp};
/** @var array $r @var array $registro @var ?array $admin */
$wa = fn(array $n, string $msg) => WhatsApp::enlace($n['whatsapp'] ?: ($n['telefono'] ?: $n['dueno_telefono']), $msg);
$primer = fn(?string $x) => explode(' ', trim((string) $x))[0];
?>
<div class="cabecera">
    <div><h1>Hola, <?= e($primer($admin['nombre'])) ?></h1>
        <p>Así va TuRenta hoy, <?= date('d/m/Y') ?>.</p></div>
    <a class="boton" href="<?= e(url('admin_nuevo')) ?>">+ Nueva demo</a>
</div>

<div class="rejilla rejilla-4">
    <div class="bloque"><div class="cifra"><?= $r['total'] ?></div><p class="cifra-nombre">Negocios registrados · <?= $r['nuevos_semana'] ?> esta semana</p></div>
    <div class="bloque"><div class="cifra"><?= $r['cuenta']['activo'] + $r['cuenta']['por_vencer'] ?></div><p class="cifra-nombre">Pagando · <?= $r['cuenta']['prueba'] ?> en demo</p></div>
    <div class="bloque"><div class="cifra"><?= dinero($r['mensual']) ?></div><p class="cifra-nombre">Ingreso mensual recurrente (lo que vale al mes lo pagado)</p></div>
    <div class="bloque"><div class="cifra"><?= dinero($r['cobrado_mes']) ?></div><p class="cifra-nombre">Cobrado este mes · <?= dinero($r['cobrado_anio']) ?> en el año</p></div>
</div>

<section class="bloque" id="pedidos-demo">
    <h2>Pedidos de demo (<?= count($pedidos) ?>)</h2>
    <?php if (!$pedidos): ?><p class="vacio">No hay pedidos de demo por atender. Llegan desde "Solicitar demo" en la página de entrada.</p><?php else: ?>
        <ul class="filas">
        <?php foreach ($pedidos as $pd): $waPd = TuRenta\WhatsApp::enlace($pd['whatsapp'], 'Hola ' . explode(' ', $pd['nombre'])[0] . ', te escribo de TuRenta por la demo que pediste para ' . $pd['negocio'] . '.'); ?>
            <li><span><strong><?= e($pd['negocio']) ?></strong> · <?= e($pd['nombre']) ?><?= $pd['ciudad'] ? ' · ' . e($pd['ciudad']) : '' ?>
                <small class="suave" style="display:block"><?= e(TuRenta\PedidosDemo::ESTADOS[$pd['estado']]) ?> · <?= e(date('d/m/Y H:i', strtotime($pd['creado']))) ?> · <?= e($pd['whatsapp']) ?> · <?= e($pd['email']) ?>
                    <?= $pd['modulos'] !== '' ? '<br>Le interesa: ' . e(implode(', ', array_map([TuRenta\Modulos::class, 'nombre'], TuRenta\Modulos::parse($pd['modulos'])))) : '' ?>
                    <?= $pd['necesita'] ? '<br>«' . e(mb_substr($pd['necesita'], 0, 200)) . '»' : '' ?></small></span>
                <span class="acciones">
                    <a class="boton boton-chico" href="<?= e(url('admin_nuevo', ['pedido' => $pd['id']])) ?>">Crear demo</a>
                    <?php if ($waPd): ?><a class="boton boton-wa boton-chico" href="<?= e($waPd) ?>" target="_blank" rel="noopener">WhatsApp</a><?php endif; ?>
                    <form method="post"><?= campo_csrf() ?><input type="hidden" name="pedido" value="<?= (int) $pd['id'] ?>">
                        <?php if ($pd['estado'] === 'nueva'): ?><button class="boton boton-claro boton-chico" type="submit" name="estado" value="contactada">Contactado</button><?php endif; ?>
                        <button class="boton boton-claro boton-chico" type="submit" name="estado" value="descartada">Descartar</button></form>
                </span></li>
        <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<section class="bloque">
    <h2>Estado de los negocios</h2>
    <div class="chips-estado">
        <?php foreach (PanelTukan::ESTADOS as $k => $txt): ?>
            <a class="chip-estado est-<?= $k ?>" href="<?= e(url('admin_negocios', ['f' => $k])) ?>"><strong><?= $r['cuenta'][$k] ?></strong> <?= $txt ?></a>
        <?php endforeach; ?>
    </div>
    <p class="suave" style="margin:10px 0 0">
        <?php foreach ($r['por_plan'] as $plan => $c): ?>Plan <?= e($plan) ?>: <strong><?= $c ?></strong> · <?php endforeach; ?>
        <?= $r['eventos_30'] ?> eventos registrados en los últimos 30 días</p>
</section>

<div class="rejilla rejilla-2">
    <section class="bloque">
        <h2>Demos que terminan en 3 días (<?= count($r['pruebas_por_terminar']) ?>)</h2>
        <p class="suave">El mejor momento para escribirles y ofrecer el plan.</p>
        <?php if (!$r['pruebas_por_terminar']): ?><p class="vacio">Ninguna por ahora.</p><?php else: ?>
            <ul class="lista">
                <?php foreach ($r['pruebas_por_terminar'] as $n): $link = $wa($n, 'Hola ' . $primer($n['dueno']) . ', soy de TuRenta. Tu prueba de ' . $n['nombre'] . ' termina el ' . date('d/m', strtotime($n['prueba_hasta'])) . '. ¿Te ayudo a elegir tu plan para que sigas usándolo?'); ?>
                    <li><a href="<?= e(url('admin_negocio', ['id' => $n['id']])) ?>" style="flex:1;color:inherit;text-decoration:none">
                        <div class="principal-linea"><?= e($n['nombre']) ?></div>
                        <div class="linea-sub"><?= e($n['dueno']) ?> · termina <?= date('d/m', strtotime($n['prueba_hasta'])) ?> · <?= (int) $n['eventos_total'] ?> eventos</div></a>
                        <?php if ($link): ?><a class="boton boton-wa boton-chico" href="<?= e($link) ?>" target="_blank" rel="noopener">WhatsApp</a><?php endif; ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
    <section class="bloque">
        <h2>Planes que vencen en 15 días (<?= count($r['por_vencer']) ?>)</h2>
        <p class="suave">Recuérdales renovar.</p>
        <?php if (!$r['por_vencer']): ?><p class="vacio">Ninguno por ahora.</p><?php else: ?>
            <ul class="lista">
                <?php foreach ($r['por_vencer'] as $n): $link = $wa($n, 'Hola ' . $primer($n['dueno']) . ', soy de TuRenta. El plan de ' . $n['nombre'] . ' vence el ' . date('d/m', strtotime($n['activo_hasta'])) . '. ¿Te paso los datos para renovarlo?'); ?>
                    <li><a href="<?= e(url('admin_negocio', ['id' => $n['id']])) ?>" style="flex:1;color:inherit;text-decoration:none">
                        <div class="principal-linea"><?= e($n['nombre']) ?></div>
                        <div class="linea-sub"><?= e($n['plan_nombre']) ?> · vence <?= date('d/m/Y', strtotime($n['activo_hasta'])) ?></div></a>
                        <?php if ($link): ?><a class="boton boton-wa boton-chico" href="<?= e($link) ?>" target="_blank" rel="noopener">WhatsApp</a><?php endif; ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
    <section class="bloque">
        <h2>Sin pagar (<?= count($r['sin_pagar']) ?>)</h2>
        <p class="suave">Demo terminada o plan vencido: no pueden entrar hasta pagar.</p>
        <?php if (!$r['sin_pagar']): ?><p class="vacio">Ninguno. 👏</p><?php else: ?>
            <ul class="lista">
                <?php foreach ($r['sin_pagar'] as $n): $link = $wa($n, 'Hola ' . $primer($n['dueno']) . ', soy de TuRenta. Vi que ' . $n['nombre'] . ' ya no tiene el plan activo. Tus datos siguen guardados. ¿Te ayudo a renovarlo?'); ?>
                    <li><a href="<?= e(url('admin_negocio', ['id' => $n['id']])) ?>" style="flex:1;color:inherit;text-decoration:none">
                        <div class="principal-linea"><?= e($n['nombre']) ?></div>
                        <div class="linea-sub"><span class="etq est-<?= e($n['estado_cuenta']) ?>"><?= PanelTukan::ESTADOS[$n['estado_cuenta']] ?></span> · <?= e($n['dueno']) ?></div></a>
                        <?php if ($link): ?><a class="boton boton-wa boton-chico" href="<?= e($link) ?>" target="_blank" rel="noopener">WhatsApp</a><?php endif; ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
    <section class="bloque">
        <h2>Últimos registrados</h2>
        <?php if (!$r['nuevos']): ?><p class="vacio">Todavía no hay negocios. Crea el primero o comparte el enlace de registro.</p><?php else: ?>
            <ul class="lista">
                <?php foreach ($r['nuevos'] as $n): ?>
                    <li><a href="<?= e(url('admin_negocio', ['id' => $n['id']])) ?>" style="flex:1;color:inherit;text-decoration:none">
                        <div class="principal-linea"><?= e($n['nombre']) ?></div>
                        <div class="linea-sub"><?= e($n['dueno']) ?> · <?= e($n['ciudad']) ?> · <?= date('d/m/Y', strtotime($n['creado_en'])) ?></div></a>
                        <span class="etq est-<?= e($n['estado_cuenta']) ?>"><?= PanelTukan::ESTADOS[$n['estado_cuenta']] ?></span></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>

<section class="bloque">
    <h2>Últimos movimientos del panel</h2>
    <?php if (!$registro): ?><p class="vacio">Sin movimientos.</p><?php else: ?>
        <ul class="lista">
            <?php foreach ($registro as $m): ?>
                <li><span><?= e($m['accion']) ?><?= $m['negocio'] ? ' · <strong>' . e($m['negocio']) . '</strong>' : '' ?><?= $m['admin'] ? ' <small>· ' . e($m['admin']) . '</small>' : '' ?></span>
                    <small style="white-space:nowrap"><?= date('d/m H:i', strtotime($m['creado_en'])) ?></small></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
