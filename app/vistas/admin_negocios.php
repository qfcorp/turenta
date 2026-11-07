<?php
use TuRenta\{PanelTukan, WhatsApp};
/** @var array $negocios @var string $filtro @var string $buscar @var string $plan @var array $listaPlanes */ ?>
<div class="cabecera">
    <div><h1>Negocios (<?= count($negocios) ?>)</h1>
        <p>Todos los salones, quintas y haciendas que usan TuRenta.</p></div>
    <a class="boton" href="<?= e(url('admin_nuevo')) ?>">+ Nueva demo</a>
</div>

<form method="get" class="bloque filtros-admin" style="grid-template-columns:unset">
    <input type="hidden" name="r" value="admin_negocios">
    <div class="fila-campos" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr))">
        <div class="campo"><label for="q">Buscar</label>
            <input type="search" id="q" name="q" value="<?= e($buscar) ?>" placeholder="Nombre, dueño, correo, celular, ciudad o RUC"></div>
        <div class="campo"><label for="f">Estado</label>
            <select id="f" name="f" data-autoenviar>
                <option value="">Todos</option>
                <?php foreach (PanelTukan::ESTADOS as $k => $txt): ?><option value="<?= $k ?>" <?= $filtro === $k ? 'selected' : '' ?>><?= $txt ?></option><?php endforeach; ?>
                <option value="papelera" <?= $filtro === 'papelera' ? 'selected' : '' ?>>Papelera (borrados)</option>
            </select></div>
        <div class="campo"><label for="plan">Plan</label>
            <select id="plan" name="plan" data-autoenviar>
                <option value="">Todos</option>
                <?php foreach ($listaPlanes as $p): ?><option value="<?= e($p['codigo']) ?>" <?= $plan === $p['codigo'] ? 'selected' : '' ?>><?= e($p['nombre']) ?></option><?php endforeach; ?>
            </select></div>
    </div>
    <div class="acciones"><button class="boton" type="submit">Buscar</button>
        <?php if ($buscar !== '' || $filtro !== '' || $plan !== ''): ?><a class="boton boton-claro" href="<?= e(url('admin_negocios')) ?>">Quitar filtros</a><?php endif; ?></div>
</form>

<section class="bloque">
    <?php if (!$negocios): ?>
        <p class="vacio">No hay negocios<?= $buscar !== '' || $filtro !== '' || $plan !== '' ? ' con ese filtro' : ' todavía' ?>.</p>
    <?php else: ?>
        <div class="desliza">
        <table class="tabla tabla-admin">
            <thead><tr><th>Negocio</th><th>Dueño</th><th>Plan</th><th>Estado</th><th>Vence</th><th class="der">Eventos 30 días</th><th class="der">Espacios</th><th class="der">Usuarios</th><th>Última actividad</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($negocios as $n):
                $vence = in_array($n['estado_cuenta'], ['prueba', 'prueba_vencida'], true) ? $n['prueba_hasta'] : $n['activo_hasta'];
                $wa = WhatsApp::enlace($n['whatsapp'] ?: ($n['telefono'] ?: $n['dueno_telefono']), 'Hola ' . explode(' ', (string) $n['dueno'])[0] . ', te escribo de TuRenta.'); ?>
                <tr>
                    <td><a href="<?= e(url('admin_negocio', ['id' => $n['id']])) ?>"><strong><?= e($n['nombre']) ?></strong></a><br><small><?= e($n['ciudad']) ?> · desde <?= date('d/m/Y', strtotime($n['creado_en'])) ?></small></td>
                    <td><?= e($n['dueno']) ?><br><small><?= e($n['dueno_email']) ?></small></td>
                    <td><?= e($n['plan_nombre']) ?></td>
                    <td><span class="etq est-<?= e($n['estado_cuenta']) ?>"><?= PanelTukan::ESTADOS[$n['estado_cuenta']] ?></span>
                        <?php if (!empty($n['eliminado_en'])): ?><br><span class="etq est-cancelado">En la papelera</span>
                            <small>desde <?= date('d/m/Y', strtotime($n['eliminado_en'])) ?> · se borra el <?= date('d/m/Y', strtotime(TuRenta\Papelera::fechaPurga($n['eliminado_en']))) ?></small><?php endif; ?></td>
                    <td><?= $vence ? date('d/m/Y', strtotime($vence)) : '—' ?></td>
                    <td class="der"><?= (int) $n['eventos_30'] ?></td>
                    <td class="der"><?= (int) $n['espacios'] ?></td>
                    <td class="der"><?= (int) $n['usuarios'] ?></td>
                    <td><?= $n['ultima_actividad'] ? date('d/m/Y', strtotime($n['ultima_actividad'])) : '—' ?></td>
                    <td class="der"><?php if ($wa): ?><a class="boton boton-wa boton-chico" href="<?= e($wa) ?>" target="_blank" rel="noopener">WhatsApp</a><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</section>
