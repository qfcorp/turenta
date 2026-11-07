<?php
use TuRenta\{PanelTukan, Planes, WhatsApp};
/** @var array $negocio @var array $pagos @var array $registro @var array $listaPlanes @var array $cotizaciones @var string $inicioSugerido @var ?array $claveNueva @var ?array $correoDemo
 *  @var array $modulosEstado @var array $catalogo @var array $pedidosModulos */
$n = $negocio;
$telefono = $n['whatsapp'] ?: ($n['telefono'] ?: $n['dueno_telefono']);
$waDueno = WhatsApp::enlace($telefono, 'Hola ' . explode(' ', (string) $n['dueno'])[0] . ', te escribo de TuRenta.');
$accion = fn(string $a) => e(url('admin_negocio', ['id' => $n['id'], 'accion' => $a]));
?>
<p><a href="<?= e(url('admin_negocios')) ?>">← Todos los negocios</a></p>
<div class="cabecera">
    <div><h1><?= e($n['nombre']) ?></h1>
        <p><span class="etq est-<?= e($n['estado_cuenta']) ?>"><?= PanelTukan::ESTADOS[$n['estado_cuenta']] ?></span>
           Plan <strong><?= e($n['plan_nombre']) ?></strong> · <?= e($n['ciudad']) ?>
           <?php if (in_array($n['estado_cuenta'], ['prueba', 'prueba_vencida'], true) && $n['prueba_hasta']): ?> · demo hasta <?= date('d/m/Y', strtotime($n['prueba_hasta'])) ?>
           <?php elseif ($n['activo_hasta']): ?> · pagado hasta <?= date('d/m/Y', strtotime($n['activo_hasta'])) ?><?php endif; ?></p></div>
    <form method="post" action="<?= e(url('admin_ver', ['id' => $n['id']])) ?>"><?= campo_csrf() ?>
        <button class="boton boton-claro" type="submit">Ver como el negocio (soporte)</button></form>
</div>

<?php if (!empty($n['eliminado_en'])): $purga = TuRenta\Papelera::fechaPurga($n['eliminado_en']); ?>
    <section class="bloque" style="border-color:var(--rojo);border-width:2px" role="alert">
        <h2>En la papelera</h2>
        <p>Borrado el <?= date('d/m/Y H:i', strtotime($n['eliminado_en'])) ?> por <?= e($n['eliminado_por']) ?>. Motivo: <?= e($n['eliminado_motivo']) ?></p>
        <p>No puede entrar y sus páginas no existen. <strong>Se borra para siempre el <?= date('d/m/Y', strtotime($purga)) ?></strong> (la copia se guarda 1 año).</p>
        <?php if ($purga > date('Y-m-d')): ?>
            <form method="post" action="<?= $accion('restaurar') ?>"><?= campo_csrf() ?><button class="boton" type="submit">Restaurar</button></form>
        <?php endif; ?>
    </section>
<?php elseif ($n['estado'] === 'suspendido' && !empty($n['pausa_motivo'])): ?>
    <section class="bloque" role="status">
        <h2>Cuenta pausada<?= !empty($n['pausa_tipo']) ? ' · ' . e(TuRenta\Papelera::TIPOS_PAUSA[$n['pausa_tipo']] ?? $n['pausa_tipo']) : '' ?></h2>
        <p>Motivo: <?= e($n['pausa_motivo']) ?><?= $n['pausa_en'] ? ' · desde el ' . date('d/m/Y', strtotime($n['pausa_en'])) : '' ?></p>
        <p class="suave">El cliente ve: «<?= e(TuRenta\Papelera::textoPausa($n['pausa_tipo'], $n['pausa_motivo'])) ?>»</p>
    </section>
<?php endif; ?>

<?php if ($claveNueva):
    $msg = "Hola {$claveNueva['nombre']}, tu acceso a TuRenta: " . url_completa('login') . " · Correo: {$claveNueva['email']} · Contraseña temporal: {$claveNueva['clave']} (cámbiala en Mi cuenta)";
    $waClave = WhatsApp::enlace($telefono, $msg); ?>
    <section class="bloque" style="border-color:var(--navaja);border-width:2px" role="status">
        <h2>Contraseña del dueño</h2>
        <p>Correo: <strong><?= e($claveNueva['email']) ?></strong> · Contraseña: <strong class="clave-temporal"><?= e($claveNueva['clave']) ?></strong></p>
        <p class="suave">Se muestra solo esta vez. Envíasela y pídele que la cambie.</p>
        <div class="acciones">
            <?php if ($waClave): ?><a class="boton boton-wa" href="<?= e($waClave) ?>" target="_blank" rel="noopener">Enviársela por WhatsApp</a><?php endif; ?>
            <button class="boton boton-claro" type="button" data-copiar="<?= e($msg) ?>">Copiar mensaje</button>
        </div>
        <?php if ($correoDemo) require RAIZ . '/app/vistas/_correo_demo.php'; ?>
    </section>
<?php endif; ?>

<div class="rejilla rejilla-4">
    <div class="bloque"><div class="cifra"><?= (int) $n['eventos_30'] ?></div><p class="cifra-nombre">Eventos en 30 días · <?= (int) $n['eventos_total'] ?> en total</p></div>
    <div class="bloque"><div class="cifra"><?= (int) $n['vendidos'] ?></div><p class="cifra-nombre">Eventos vendidos</p></div>
    <div class="bloque"><div class="cifra"><?= (int) $n['espacios'] ?> · <?= (int) $n['usuarios'] ?></div><p class="cifra-nombre">Espacios · usuarios activos</p></div>
    <div class="bloque"><div class="cifra cifra-chica"><?= $n['ultima_actividad'] ? date('d/m/Y', strtotime($n['ultima_actividad'])) : '—' ?></div><p class="cifra-nombre">Última actividad</p></div>
</div>

<div class="rejilla rejilla-2">
    <section class="bloque">
        <h2>Registrar un pago</h2>
        <form method="post" action="<?= $accion('pago') ?>" id="form-pago" data-cotizaciones='<?= e(json_encode($cotizaciones)) ?>'>
            <?= campo_csrf() ?>
            <div class="fila-campos">
                <div class="campo"><label for="plan">Plan</label>
                    <select id="plan" name="plan"><?php foreach ($listaPlanes as $p): ?><option value="<?= e($p['codigo']) ?>" <?= $p['codigo'] === $n['plan_codigo'] ? 'selected' : '' ?>><?= e($p['nombre']) ?></option><?php endforeach; ?></select></div>
                <div class="campo"><label for="periodo">Periodo</label>
                    <select id="periodo" name="periodo"><?php foreach (Planes::PERIODOS_NOMBRE as $k => $txt): ?><option value="<?= $k ?>"><?= e(planes()->textoPeriodo($k)) ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="fila-campos">
                <div class="campo"><label for="inicio">Desde</label>
                    <input type="date" id="inicio" name="inicio" value="<?= e($inicioSugerido) ?>" required>
                    <small>Si aún tiene días pagados, empieza al día siguiente.</small></div>
                <div class="campo"><label for="metodo">Cómo pagó</label>
                    <select id="metodo" name="metodo"><?php foreach (PanelTukan::METODOS as $k => $txt): ?><option value="<?= $k ?>"><?= e($txt) ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="fila-campos">
                <div class="campo"><label for="monto">Monto cobrado</label>
                    <input type="number" id="monto" name="monto" step="0.01" min="0" placeholder="">
                    <small id="monto-ayuda">Vacío = precio normal.</small></div>
                <div class="campo" id="campo-dominio"><label for="dominio">Dominio del negocio <small>(anual)</small></label>
                    <input type="text" id="dominio" name="dominio" placeholder="quintalosarrayanes.com"></div>
            </div>
            <button class="boton boton-ancho" type="submit">Registrar pago y activar</button>
        </form>
    </section>

    <section class="bloque">
        <h2>Datos del dueño</h2>
        <ul class="lista">
            <li><span>Dueño</span><strong><?= e($n['dueno'] ?? '—') ?></strong></li>
            <li><span>Correo</span><strong style="overflow-wrap:anywhere"><?= e($n['dueno_email'] ?? '—') ?></strong></li>
            <li><span>Celular</span><strong><?= e($telefono ?: '—') ?></strong></li>
            <li><span>Último ingreso</span><strong><?= $n['dueno_ingreso'] ? date('d/m/Y H:i', strtotime($n['dueno_ingreso'])) : 'nunca' ?></strong></li>
            <li><span>Página pública</span><a href="<?= e(url_completa('sitio', ['s' => $n['slug']])) ?>" target="_blank" rel="noopener"><?= e($n['slug']) ?></a></li>
            <li><span>Registrado</span><strong><?= date('d/m/Y', strtotime($n['creado_en'])) ?></strong></li>
        </ul>
        <div class="acciones" style="margin-top:12px">
            <?php if ($waDueno): ?><a class="boton boton-wa boton-chico" href="<?= e($waDueno) ?>" target="_blank" rel="noopener">WhatsApp</a><?php endif; ?>
            <form method="post" action="<?= $accion('clave') ?>"><?= campo_csrf() ?>
                <button class="boton boton-claro boton-chico" type="submit" data-confirmar="¿Crear una contraseña nueva para el dueño? La anterior deja de funcionar.">Nueva contraseña del dueño</button></form>
        </div>
    </section>

    <section class="bloque">
        <h2>Plan, estado y demo</h2>
        <form method="post" action="<?= $accion('plan') ?>" class="fila-accion"><?= campo_csrf() ?>
            <label for="cambiar-plan">Cambiar plan (sin cobrar)</label>
            <select id="cambiar-plan" name="plan"><?php foreach ($listaPlanes as $p): ?><option value="<?= e($p['codigo']) ?>" <?= $p['codigo'] === $n['plan_codigo'] ? 'selected' : '' ?>><?= e($p['nombre']) ?> · <?= dinero($p['precio_mensual']) ?></option><?php endforeach; ?></select>
            <button class="boton boton-chico" type="submit">Cambiar</button></form>
        <form method="post" action="<?= $accion('prueba') ?>" class="fila-accion"><?= campo_csrf() ?>
            <label for="dias">Dar más días de demo</label>
            <select id="dias" name="dias"><?php foreach ([3, 7, 15, 30] as $d): ?><option value="<?= $d ?>"><?= $d ?> días</option><?php endforeach; ?></select>
            <button class="boton boton-chico" type="submit">Extender</button></form>
        <div class="acciones" style="margin-top:12px">
            <?php if (in_array($n['estado'], ['suspendido', 'cancelado'], true)): ?>
                <form method="post" action="<?= $accion('estado') ?>"><?= campo_csrf() ?><input type="hidden" name="estado" value="<?= $n['activo_hasta'] ? 'activo' : 'prueba' ?>">
                    <button class="boton boton-chico" type="submit">Reactivar</button></form>
            <?php else: ?>
                <form method="post" action="<?= $accion('estado') ?>"><?= campo_csrf() ?><input type="hidden" name="estado" value="suspendido">
                    <button class="boton boton-peligro boton-chico" type="submit" data-confirmar="¿Pausar este negocio? No podrá entrar hasta que lo reactives. Sus datos se guardan.">Pausar (suspender)</button></form>
            <?php endif; ?>
            <?php if ($n['estado'] !== 'cancelado'): ?>
                <form method="post" action="<?= $accion('estado') ?>"><?= campo_csrf() ?><input type="hidden" name="estado" value="cancelado">
                    <button class="boton boton-claro boton-chico" type="submit" data-confirmar="¿Marcar como cancelado (ya no usa TuRenta)? Sus datos se guardan.">Cancelado</button></form>
            <?php endif; ?>
        </div>
    </section>

    <section class="bloque">
        <h2>Notas internas</h2>
        <form method="post" action="<?= $accion('notas') ?>"><?= campo_csrf() ?>
            <textarea name="notas" rows="4" aria-label="Notas internas" placeholder="Ej.: paga por transferencia el 5 de cada mes; contacto: su hija Ana."><?= e($n['notas_admin'] ?? '') ?></textarea>
            <button class="boton boton-claro boton-chico" type="submit" style="margin-top:8px">Guardar notas</button></form>
        <p class="suave">Solo las ve el equipo de Tukán.</p>
    </section>
</div>

<?php require RAIZ . '/app/vistas/_admin_modulos.php'; /* Anexo B */ ?>

<section class="bloque">
    <h2>Pagos de suscripción (<?= count($pagos) ?>)</h2>
    <?php if (!$pagos): ?><p class="vacio">Todavía no ha pagado.</p><?php else: ?>
        <div class="desliza">
        <table class="tabla">
            <thead><tr><th>Pagado</th><th>Plan</th><th>Periodo</th><th>Cubre</th><th>Cómo</th><th class="der">Monto</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($pagos as $p): ?>
                <tr style="<?= $p['anulada'] ? 'opacity:.5;text-decoration:line-through' : '' ?>">
                    <td><?= date('d/m/Y', strtotime($p['pagado_en'])) ?></td><td><?= e($p['plan_nombre']) ?></td>
                    <td><?= e(Planes::PERIODOS_NOMBRE[$p['periodo']]) ?> (<?= (int) $p['meses_recibidos'] ?> meses)<?= $p['dominio'] ? '<br><small>' . e($p['dominio']) . '</small>' : '' ?></td>
                    <td><?= date('d/m/Y', strtotime($p['inicio'])) ?> → <?= date('d/m/Y', strtotime($p['fin'])) ?></td>
                    <td><?= e(PanelTukan::METODOS[$p['metodo_pago']] ?? $p['metodo_pago']) ?></td>
                    <td class="der"><?= dinero($p['monto']) ?></td>
                    <td class="der"><?php if (!$p['anulada']): ?>
                        <form method="post" action="<?= $accion('anular_pago') ?>"><?= campo_csrf() ?><input type="hidden" name="pago" value="<?= (int) $p['id'] ?>">
                            <button class="boton boton-claro boton-chico" type="submit" data-confirmar="¿Anular este pago? Úsalo solo si se registró por error.">Anular</button></form>
                    <?php else: ?><small>anulado</small><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</section>

<section class="bloque">
    <h2>Historial del panel</h2>
    <?php if (!$registro): ?><p class="vacio">Sin movimientos.</p><?php else: ?>
        <ul class="lista">
            <?php foreach ($registro as $m): ?>
                <li><span><?= e($m['accion']) ?> <small>· <?= e($m['admin'] ?? '') ?></small></span><small><?= date('d/m/Y H:i', strtotime($m['creado_en'])) ?></small></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
