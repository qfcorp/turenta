<?php
use TuRenta\{DiaEvento, Eventos, Cotizaciones, WhatsApp};

/**
 * @var array $ev @var string $tab @var array $secciones @var string $ver @var array $negocio @var array $cronograma
 * @var array $personalEv @var array $resumen @var array $proveedoresEv @var array $mobiliario @var array $conteos
 * @var string $enlaceVivo @var array $vivo @var ?string $coordTel
 */
$editable = puede_editar($u);
$id = (int) $ev['id'];
$accion = fn(string $a) => '<input type="hidden" name="accion" value="' . e($a) . '"><input type="hidden" name="ver" value="' . e($ver) . '">';
$hoyTxt = date('Y-m-d');
$yaPaso = $ev['fecha'] <= $hoyTxt;
$msgVivo = "Hola, te comparto el cronograma en vivo de {$ev['nombre']} (" . fecha_corta($ev['fecha']) . ").\n"
         . "Ábrelo el día del evento: muestra qué va ahora, qué sigue y cuánto vamos de atraso. Se actualiza solo.\n$enlaceVivo";
$telServicio = function (string $servicio) use ($proveedoresEv): ?string {
    foreach ($proveedoresEv as $p) if ($p['servicio'] === $servicio && $p['telefono']) return $p['telefono'];
    return null;
};
?>
<?php require RAIZ . '/app/vistas/_evento_tabs.php'; ?>

<div class="dia-barra">
    <nav class="dia-secciones" aria-label="Partes del día del evento">
        <?php foreach ($secciones as $k => $t): ?>
            <a href="<?= e(url('evento_dia', ['id' => $id, 'ver' => $k])) ?>" class="<?= $ver === $k ? 'activa' : '' ?>" <?= $ver === $k ? 'aria-current="page"' : '' ?>>
                <?= e($t) ?><?php if (!empty($conteos[$k])): ?> <span class="dia-cuenta"><?= (int) $conteos[$k] ?></span><?php endif; ?>
                <?php if ($k === 'personal' && $resumen['nivel'] === 'amarillo'): ?> <span class="dia-punto" title="Falta personal"></span><?php endif; ?>
                <?php if ($k === 'vivo' && $ev['transmision_estado'] !== 'no'): ?> <span class="dia-punto dia-punto-ok"></span><?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>
    <a class="boton boton-claro boton-chico" href="<?= e(url('pdf', ['tipo' => 'hoja', 'id' => $id])) ?>" target="_blank" rel="noopener">Hoja del evento (PDF)</a>
</div>

<?php /* ===================================================== CRONOGRAMA */ if ($ver === 'cronograma'): ?>

<section class="bloque dia-vivo-enlace">
    <div class="dia-vivo-cab">
        <div>
            <h2>Modo en vivo</h2>
            <p class="suave">Para el coordinador, el DJ y el fotógrafo: qué va ahora, qué sigue y el atraso. Con un botón grande para pasar al siguiente momento.</p>
        </div>
        <?php if ($vivo['empezado']): $act = $vivo['actual'] !== null ? $vivo['bloques'][$vivo['actual']] : null; ?>
            <p class="dia-en-vivo"><span class="dia-luz"></span><?= $vivo['terminado'] ? 'Terminado' : 'En curso: ' . e($act['actividad'] ?? '') ?>
                <?php if (!$vivo['terminado']): ?> · <?= e(mb_strtolower(DiaEvento::textoAtraso($vivo))) ?><?php endif; ?></p>
        <?php endif; ?>
    </div>
    <div class="acciones">
        <a class="boton" href="<?= e($enlaceVivo) ?>" target="_blank" rel="noopener">Abrir modo en vivo</a>
        <button type="button" class="boton boton-claro" data-copiar="<?= e($enlaceVivo) ?>">Copiar enlace</button>
        <a class="boton boton-wa" href="<?= e(DiaEvento::wa($telServicio('musica'), $msgVivo)) ?>" target="_blank" rel="noopener">WhatsApp al DJ</a>
        <a class="boton boton-wa" href="<?= e(DiaEvento::wa($telServicio('foto_video'), $msgVivo)) ?>" target="_blank" rel="noopener">Al fotógrafo</a>
        <a class="boton boton-wa" href="<?= e(DiaEvento::wa($coordTel, $msgVivo)) ?>" target="_blank" rel="noopener">Al coordinador</a>
    </div>
    <?php if ($vivo['empezado'] && $editable): ?>
        <form method="post" class="dia-en-linea"><?= campo_csrf() ?><?= $accion('vivo_reiniciar') ?>
            <button class="boton-enlace" type="submit" data-confirmar="¿Borrar las horas reales y volver a empezar el modo en vivo?">Volver a empezar el modo en vivo</button></form>
    <?php endif; ?>
</section>

<section class="bloque">
    <div class="dia-titulo-acciones">
        <h2>Cronograma <small class="suave"><?= e(hora_corta($ev['hora_inicio'])) ?> a <?= e(hora_corta($ev['hora_fin'])) ?></small></h2>
        <?php if ($cronograma): ?>
        <div class="acciones">
            <?php if ($editable && count($cronograma) > 1): ?>
            <form method="post"><?= campo_csrf() ?><?= $accion('ordenar') ?><button class="boton boton-claro boton-chico" type="submit">Ordenar por hora</button></form>
            <?php endif; ?>
            <a class="boton boton-claro boton-chico" href="<?= e(url('pdf', ['tipo' => 'cronograma', 'id' => $id])) ?>" target="_blank" rel="noopener">PDF</a>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!$cronograma): ?>
        <div class="vacio dia-vacio">
            <p><strong>Todavía no hay cronograma.</strong></p>
            <p>Empieza con uno de ejemplo armado desde la hora de inicio (<?= e(hora_corta($ev['hora_inicio'])) ?>) y ajústalo a lo que pidió el cliente.</p>
            <?php if ($editable): ?>
            <form method="post" class="dia-ejemplo"><?= campo_csrf() ?><?= $accion('ejemplo') ?>
                <label for="plantilla" class="sr-only">Tipo de cronograma</label>
                <select id="plantilla" name="plantilla">
                    <?php foreach (DiaEvento::PLANTILLAS as $k => $t): ?><option value="<?= e($k) ?>" <?= DiaEvento::plantillaDe($ev['tipo']) === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
                </select>
                <button class="boton" type="submit">Cargar cronograma de ejemplo</button>
            </form>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <ol class="dia-crono">
            <?php foreach ($cronograma as $i => $b): $bv = $vivo['bloques'][$i] ?? null; ?>
            <li class="dia-crono-item <?= $bv ? 'dia-est-' . e($bv['estado']) : '' ?>" id="b<?= (int) $b['id'] ?>">
                <div class="dia-crono-hora"><strong><?= e(hora_corta($b['hora'])) ?></strong><small><?= (int) $b['duracion_min'] ?> min</small></div>
                <div class="dia-crono-texto">
                    <strong><?= e($b['actividad']) ?></strong>
                    <?php if ($bv && $bv['estado'] !== 'pendiente'): ?>
                        <span class="etq <?= $bv['estado'] === 'ahora' ? 'etq-confirmada' : 'etq-reservada' ?>"><?= $bv['estado'] === 'ahora' ? 'Ahora' : 'Hecho' ?> · empezó <?= e(substr((string) $b['inicio_real'], 11, 5)) ?></span>
                    <?php endif; ?>
                    <?php if ($b['responsable']): ?><br><small><?= e($b['responsable']) ?></small><?php endif; ?>
                    <?php if (!empty($b['evento_proveedor_id']) && $bv && $bv['atraso'] !== null && !$vivo['prueba']): ?>
                        <span class="pv-chip <?= $bv['atraso'] <= $toleranciaProv ? 'pv-chip-verde' : 'pv-chip-rojo' ?>"><?= $bv['atraso'] <= $toleranciaProv ? 'Proveedor a tiempo' : 'Proveedor ' . (int) $bv['atraso'] . ' min tarde' ?></span>
                    <?php endif; ?>
                    <?php if ($b['notas']): ?><br><small class="suave"><?= e($b['notas']) ?></small><?php endif; ?>
                    <?php if ($editable): ?>
                    <details class="dia-editar">
                        <summary>Editar</summary>
                        <form method="post" class="dia-form-bloque"><?= campo_csrf() ?><?= $accion('bloque_editar') ?>
                            <input type="hidden" name="bloque_id" value="<?= (int) $b['id'] ?>">
                            <div class="dia-fila-3">
                                <div class="campo"><label for="h<?= (int) $b['id'] ?>">Hora</label><input type="time" id="h<?= (int) $b['id'] ?>" name="hora" value="<?= e(hora_corta($b['hora'])) ?>" required></div>
                                <div class="campo"><label for="d<?= (int) $b['id'] ?>">Minutos</label><input type="number" id="d<?= (int) $b['id'] ?>" name="duracion_min" value="<?= (int) $b['duracion_min'] ?>" min="1" max="600" required></div>
                            </div>
                            <div class="campo"><label for="a<?= (int) $b['id'] ?>">Qué pasa</label><input type="text" id="a<?= (int) $b['id'] ?>" name="actividad" value="<?= e($b['actividad']) ?>" maxlength="150" required></div>
                            <div class="campo"><label for="r<?= (int) $b['id'] ?>">Responsable</label><input type="text" id="r<?= (int) $b['id'] ?>" name="responsable" value="<?= e($b['responsable']) ?>" maxlength="100"></div>
                            <div class="campo"><label for="n<?= (int) $b['id'] ?>">Notas <small>(canción, quién habla…)</small></label><input type="text" id="n<?= (int) $b['id'] ?>" name="notas" value="<?= e($b['notas']) ?>" maxlength="300"></div>
                            <?php if ($proveedoresEv): ?>
                            <div class="campo"><label for="pr<?= (int) $b['id'] ?>">Proveedor a cargo <small>(para saber si cumplió a tiempo)</small></label>
                                <select id="pr<?= (int) $b['id'] ?>" name="evento_proveedor_id"><option value="0">— Ninguno —</option>
                                    <?php foreach ($proveedoresEv as $pv): ?><option value="<?= (int) $pv['id'] ?>" <?= (int) ($b['evento_proveedor_id'] ?? 0) === (int) $pv['id'] ? 'selected' : '' ?>><?= e($pv['nombre']) ?></option><?php endforeach; ?></select></div>
                            <?php endif; ?>
                            <button class="boton boton-chico" type="submit">Guardar</button>
                        </form>
                        <form method="post" class="dia-en-linea"><?= campo_csrf() ?><?= $accion('bloque_borrar') ?><input type="hidden" name="bloque_id" value="<?= (int) $b['id'] ?>">
                            <button class="boton boton-peligro boton-chico" type="submit" data-confirmar="¿Quitar «<?= e($b['actividad']) ?>» del cronograma?">Quitar</button></form>
                    </details>
                    <?php endif; ?>
                </div>
                <?php if ($editable): ?>
                <div class="dia-mover">
                    <form method="post"><?= campo_csrf() ?><?= $accion('bloque_subir') ?><input type="hidden" name="bloque_id" value="<?= (int) $b['id'] ?>">
                        <button type="submit" class="dia-flecha" aria-label="Subir <?= e($b['actividad']) ?>" <?= $i === 0 ? 'disabled' : '' ?>>▲</button></form>
                    <form method="post"><?= campo_csrf() ?><?= $accion('bloque_bajar') ?><input type="hidden" name="bloque_id" value="<?= (int) $b['id'] ?>">
                        <button type="submit" class="dia-flecha" aria-label="Bajar <?= e($b['actividad']) ?>" <?= $i === count($cronograma) - 1 ? 'disabled' : '' ?>>▼</button></form>
                </div>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
</section>

<?php if ($editable): ?>
<div class="rejilla rejilla-2">
    <form method="post" class="bloque"><?= campo_csrf() ?><?= $accion('bloque_agregar') ?>
        <h2>Agregar un momento</h2>
        <div class="dia-fila-3">
            <div class="campo"><label for="nh">Hora</label><input type="time" id="nh" name="hora" required></div>
            <div class="campo"><label for="nd">Minutos</label><input type="number" id="nd" name="duracion_min" value="15" min="1" max="600" required></div>
        </div>
        <div class="campo"><label for="na">Qué pasa</label><input type="text" id="na" name="actividad" maxlength="150" required placeholder="Lanzamiento del ramo"></div>
        <div class="campo"><label for="nr">Responsable</label><input type="text" id="nr" name="responsable" maxlength="100" placeholder="DJ"></div>
        <div class="campo"><label for="nn">Notas <small>(opcional)</small></label><input type="text" id="nn" name="notas" maxlength="300" placeholder="Canción: Perfect – Ed Sheeran"></div>
        <?php if ($proveedoresEv): ?>
        <div class="campo"><label for="npr">Proveedor a cargo <small>(opcional)</small></label>
            <select id="npr" name="evento_proveedor_id"><option value="0">— Ninguno —</option>
                <?php foreach ($proveedoresEv as $pv): ?><option value="<?= (int) $pv['id'] ?>"><?= e($pv['nombre']) ?></option><?php endforeach; ?></select></div>
        <?php endif; ?>
        <button class="boton boton-ancho" type="submit">Agregar al cronograma</button>
        <p class="suave" style="margin:8px 0 0"><small>Se ubica solo según la hora. Lo de madrugada (después de las 00:00) va al final.</small></p>
    </form>
    <?php if ($cronograma): ?>
    <form method="post" class="bloque"><?= campo_csrf() ?><?= $accion('ejemplo') ?>
        <h2>Empezar de nuevo</h2>
        <p class="suave">Borra este cronograma y carga uno de ejemplo desde la hora de inicio.</p>
        <div class="campo"><label for="plantilla2">Tipo</label>
            <select id="plantilla2" name="plantilla">
                <?php foreach (DiaEvento::PLANTILLAS as $k => $t): ?><option value="<?= e($k) ?>" <?= DiaEvento::plantillaDe($ev['tipo']) === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
            </select></div>
        <input type="hidden" name="reemplazar" value="1">
        <button class="boton boton-peligro" type="submit" data-confirmar="Se borra el cronograma actual (<?= count($cronograma) ?> momentos). ¿Seguir?">Reemplazar por el de ejemplo</button>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php /* ===================================================== PERSONAL */ elseif ($ver === 'personal'):
    $yaAsignados = array_map(fn($p) => (int) $p['personal_id'], $personalEv); ?>

<p class="aviso <?= $resumen['nivel'] === 'verde' ? 'aviso-ok' : 'dia-aviso-amarillo' ?>" role="status">
    <?php if ($resumen['faltan_meseros']): ?>
        Faltan <strong><?= (int) $resumen['faltan_meseros'] ?> meseros</strong>: tienes <?= (int) $resumen['meseros'] ?> y para <?= (int) $ev['invitados'] ?> invitados se sugieren <?= (int) $resumen['meseros_sugeridos'] ?>.
    <?php else: ?>
        Meseros completos: <?= (int) $resumen['meseros'] ?> de <?= (int) $resumen['meseros_sugeridos'] ?> sugeridos para <?= (int) $ev['invitados'] ?> invitados.
    <?php endif; ?>
    <?php if ($resumen['faltan_personal']): ?> También faltan <?= (int) $resumen['faltan_personal'] ?> personas para el mínimo de <?= (int) $resumen['personal_minimo'] ?>.<?php endif; ?>
</p>

<div class="cifras">
    <div class="cifra-tarjeta"><small>Asignados</small><strong><?= (int) $resumen['personas'] ?></strong></div>
    <div class="cifra-tarjeta <?= $resumen['confirmados'] < $resumen['personas'] ? 'alerta' : 'bien' ?>"><small>Confirmaron</small><strong><?= (int) $resumen['confirmados'] ?> de <?= (int) $resumen['personas'] ?></strong></div>
    <div class="cifra-tarjeta"><small>Total a pagar</small><strong><?= dinero($resumen['total']) ?></strong></div>
    <div class="cifra-tarjeta <?= $resumen['por_pagar'] > 0 && $yaPaso ? 'alerta' : '' ?>"><small>Falta pagar</small><strong><?= dinero($resumen['por_pagar']) ?></strong></div>
</div>

<section class="bloque">
    <div class="dia-titulo-acciones">
        <h2>Personal del evento</h2>
        <?php if ($editable && $yaPaso && $resumen['por_pagar'] > 0): ?>
        <form method="post"><?= campo_csrf() ?><?= $accion('pagar_todos') ?>
            <button class="boton boton-claro boton-chico" type="submit" data-confirmar="¿Marcar como pagado a todos los que faltan (<?= dinero($resumen['por_pagar']) ?>)?">Marcar todos pagados</button></form>
        <?php endif; ?>
    </div>
    <?php if (!$personalEv): ?>
        <p class="vacio">Nadie asignado todavía. Elige abajo a tus meseros y demás personal.</p>
    <?php else: ?>
    <ul class="dia-tarjetas">
        <?php foreach ($personalEv as $p):
            $msg = DiaEvento::mensajeStaff($p, $p, $ev, $negocio, url_completa('staff', ['t' => $p['token']]));
            $wa = WhatsApp::enlace($p['telefono'], $msg); ?>
        <li class="dia-tarjeta">
            <div class="dia-tarjeta-cab">
                <div>
                    <strong><?= e($p['nombre']) ?></strong>
                    <br><small><?= e(DiaEvento::ROLES[$p['rol']] ?? $p['rol']) ?> · <?= e(hora_corta($p['hora_entrada']) ?: '—') ?> a <?= e(hora_corta($p['hora_salida']) ?: '—') ?> · <?= dinero($p['pago']) ?></small>
                    <?php if ($p['respuesta'] === 'no'): ?><br><span class="etq etq-cancelada">Avisó que no puede ir</span><?php endif; ?>
                </div>
                <?php if ($wa): ?><a class="boton boton-wa boton-chico" href="<?= e($wa) ?>" target="_blank" rel="noopener" aria-label="Pedir confirmación a <?= e($p['nombre']) ?> por WhatsApp">WhatsApp</a>
                <?php else: ?><small class="suave">Sin celular</small><?php endif; ?>
            </div>
            <?php if ($editable): ?>
            <div class="dia-marcas">
                <?php foreach ([['confirmado', 'Confirmó', (bool) $p['confirmado']], ['asistio', 'Asistió', $p['asistio'] === true], ['pagado', 'Pagado', (bool) $p['pagado']]] as [$campoM, $txt, $on]): ?>
                <form method="post"><?= campo_csrf() ?><?= $accion('marcar') ?><input type="hidden" name="asig_id" value="<?= (int) $p['id'] ?>">
                    <input type="hidden" name="campo" value="<?= e($campoM) ?>"><input type="hidden" name="valor" value="<?= $on ? '0' : '1' ?>">
                    <button type="submit" class="dia-marca <?= $on ? 'si' : '' ?>" aria-pressed="<?= $on ? 'true' : 'false' ?>"><?= $on ? '✓ ' : '' ?><?= e($txt) ?></button></form>
                <?php endforeach; ?>
                <?php if ($p['asistio'] === null && $yaPaso): ?>
                <form method="post"><?= campo_csrf() ?><?= $accion('marcar') ?><input type="hidden" name="asig_id" value="<?= (int) $p['id'] ?>">
                    <input type="hidden" name="campo" value="asistio"><input type="hidden" name="valor" value="0">
                    <button type="submit" class="dia-marca">No vino</button></form>
                <?php elseif ($p['asistio'] === false): ?><span class="etq etq-cancelada">No vino</span><?php endif; ?>
            </div>
            <details class="dia-editar"><summary>Cambiar horario o pago</summary>
                <form method="post" class="dia-form-bloque"><?= campo_csrf() ?><?= $accion('asig_editar') ?><input type="hidden" name="asig_id" value="<?= (int) $p['id'] ?>">
                    <div class="dia-fila-3">
                        <div class="campo"><label for="ar<?= (int) $p['id'] ?>">Rol</label><select id="ar<?= (int) $p['id'] ?>" name="rol">
                            <?php foreach (DiaEvento::ROLES as $k => $t): ?><option value="<?= e($k) ?>" <?= $p['rol'] === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
                        <div class="campo"><label for="ae<?= (int) $p['id'] ?>">Entra</label><input type="time" id="ae<?= (int) $p['id'] ?>" name="hora_entrada" value="<?= e(hora_corta($p['hora_entrada'])) ?>"></div>
                        <div class="campo"><label for="as<?= (int) $p['id'] ?>">Sale</label><input type="time" id="as<?= (int) $p['id'] ?>" name="hora_salida" value="<?= e(hora_corta($p['hora_salida'])) ?>"></div>
                        <div class="campo"><label for="ap<?= (int) $p['id'] ?>">Pago</label><input type="text" inputmode="decimal" id="ap<?= (int) $p['id'] ?>" name="pago" value="<?= e($p['pago']) ?>"></div>
                    </div>
                    <button class="boton boton-chico" type="submit">Guardar</button>
                </form>
                <form method="post" class="dia-en-linea"><?= campo_csrf() ?><?= $accion('asig_quitar') ?><input type="hidden" name="asig_id" value="<?= (int) $p['id'] ?>">
                    <button class="boton boton-peligro boton-chico" type="submit" data-confirmar="¿Quitar a <?= e($p['nombre']) ?> de este evento?">Quitar del evento</button></form>
            </details>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</section>

<?php if ($editable): ?>
<div class="rejilla rejilla-2">
    <form method="post" class="bloque" id="form-asignar"><?= campo_csrf() ?><?= $accion('asignar') ?>
        <h2>Asignar personal</h2>
        <?php $libres = array_filter($personalLista, fn($p) => !in_array((int) $p['id'], $yaAsignados, true)); ?>
        <?php if (!$personalLista): ?>
            <p class="vacio">Todavía no tienes personal registrado. <a href="<?= e(url('personal')) ?>">Agrega a tus meseros y demás personal</a> y vuelve aquí.</p>
        <?php elseif (!$libres): ?>
            <p class="vacio">Ya asignaste a todo tu personal activo. <a href="<?= e(url('personal')) ?>">Agrega más personas</a>.</p>
        <?php else: ?>
        <div class="campo"><label for="per">Persona</label>
            <select id="per" name="personal_id" required data-dia-persona>
                <option value="">Elige…</option>
                <?php foreach (DiaEvento::ROLES as $rol => $nomRol):
                    $deRol = array_filter($libres, fn($p) => $p['rol'] === $rol); if (!$deRol) continue; ?>
                <optgroup label="<?= e($nomRol) ?>">
                    <?php foreach ($deRol as $p): $ocu = $ocupados[$p['id']] ?? null; ?>
                    <option value="<?= (int) $p['id'] ?>" data-rol="<?= e($p['rol']) ?>" data-pago="<?= e($p['pago_evento']) ?>"><?= e($p['nombre']) ?> · <?= dinero($p['pago_evento']) ?><?= $ocu ? ' (ya trabaja ese día)' : '' ?></option>
                    <?php endforeach; ?>
                </optgroup>
                <?php endforeach; ?>
            </select></div>
        <div class="dia-fila-3">
            <div class="campo"><label for="arol">Rol en este evento</label><select id="arol" name="rol"><option value="">El de siempre</option>
                <?php foreach (DiaEvento::ROLES as $k => $t): ?><option value="<?= e($k) ?>"><?= e($t) ?></option><?php endforeach; ?></select></div>
            <div class="campo"><label for="ae">Entra</label><input type="time" id="ae" name="hora_entrada" placeholder="2 h antes"></div>
            <div class="campo"><label for="as">Sale</label><input type="time" id="as" name="hora_salida" value="<?= e(hora_corta($ev['hora_fin'])) ?>"></div>
            <div class="campo"><label for="apago">Pago</label><input type="text" inputmode="decimal" id="apago" name="pago" placeholder="Su tarifa"></div>
        </div>
        <p class="suave" style="margin-top:-6px"><small>Si dejas la hora de entrada vacía, entra 2 horas antes del evento. El pago vacío usa su tarifa.</small></p>
        <button class="boton boton-ancho" type="submit">Asignar</button>
        <?php endif; ?>
    </form>
    <section class="bloque">
        <h2>Meseros sugeridos</h2>
        <p>Para <strong><?= (int) $ev['invitados'] ?> invitados</strong> se sugieren <strong><?= (int) $resumen['meseros_sugeridos'] ?> meseros</strong> (uno cada <?= max(1, (int) ceil((int) $ev['invitados'] / max(1, $resumen['meseros_sugeridos']))) ?> invitados aprox.). Puedes cambiar la regla en Configuración.</p>
        <?php if ($resumen['faltan_meseros'] > 0): ?>
        <form method="post"><?= campo_csrf() ?><?= $accion('meseros_auto') ?>
            <button class="boton boton-claro" type="submit">Asignar los <?= (int) $resumen['faltan_meseros'] ?> meseros que faltan</button></form>
        <p class="suave"><small>Elige automáticamente meseros activos que no trabajan en otro evento ese día.</small></p>
        <?php endif; ?>
    </section>
</div>
<?php endif; ?>

<?php /* ===================================================== PROVEEDORES */ elseif ($ver === 'proveedores'):
    $enEvento = array_filter(array_map(fn($p) => (int) $p['proveedor_id'], $proveedoresEv)); ?>

<section class="bloque">
    <h2>Proveedores del evento</h2>
    <?php if (!$proveedoresEv): ?>
        <p class="vacio">Sin proveedores todavía. Agrega al DJ, al fotógrafo, la florería… y también los que trae el cliente, con su hora de llegada.</p>
    <?php else: ?>
    <ul class="dia-tarjetas">
        <?php foreach ($proveedoresEv as $p): $wa = WhatsApp::enlace($p['telefono'], DiaEvento::mensajeProveedor($p, $ev, $negocio)); ?>
        <li class="dia-tarjeta">
            <div class="dia-tarjeta-cab">
                <div>
                    <span class="dia-hora-grande"><?= e(hora_corta($p['hora_llegada']) ?: '--:--') ?></span>
                    <?php if ($p['proveedor_id']): ?><a href="<?= e(url('proveedor', ['id' => $p['proveedor_id']])) ?>"><strong><?= e($p['nombre']) ?></strong></a><?php else: ?><strong><?= e($p['nombre']) ?></strong><?php endif; ?>
                    <?php if (($t = \TuRenta\ProveedoresFicha::textoPuntaje($puntajesProv[(int) $p['proveedor_id']] ?? null)) !== ''): ?><span class="pv-chip pv-nivel-<?= e($puntajesProv[(int) $p['proveedor_id']]['nivel'] ?? 'sin') ?>"><?= e($t) ?></span><?php endif; ?>
                    <br><small><?= e(Cotizaciones::CATEGORIAS[$p['servicio']] ?? $p['servicio']) ?><?= $p['telefono'] ? ' · ' . e($p['telefono']) : '' ?><?= $p['contacto'] ? ' · ' . e($p['contacto']) : '' ?></small>
                    <?php if ($p['lo_trae_cliente']): ?><br><span class="etq etq-pendiente">Lo trae el cliente</span><?php endif; ?>
                    <?php if ($p['notas']): ?><br><small class="suave"><?= e($p['notas']) ?></small><?php endif; ?>
                </div>
                <?php if ($wa): ?><a class="boton boton-wa boton-chico" href="<?= e($wa) ?>" target="_blank" rel="noopener" aria-label="WhatsApp a <?= e($p['nombre']) ?>">WhatsApp</a><?php endif; ?>
            </div>
            <?php require RAIZ . '/app/vistas/_pv_cumplimiento.php';   // compra, llegada y evaluación (060) ?>
            <?php if ($editable): ?>
            <div class="dia-marcas">
                <form method="post"><?= campo_csrf() ?><?= $accion('prov_confirmar') ?><input type="hidden" name="prov_id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="valor" value="<?= $p['confirmado'] ? '0' : '1' ?>">
                    <button type="submit" class="dia-marca <?= $p['confirmado'] ? 'si' : '' ?>" aria-pressed="<?= $p['confirmado'] ? 'true' : 'false' ?>"><?= $p['confirmado'] ? '✓ ' : '' ?>Confirmó</button></form>
            </div>
            <details class="dia-editar"><summary>Cambiar hora o notas</summary>
                <form method="post" class="dia-form-bloque"><?= campo_csrf() ?><?= $accion('prov_editar') ?><input type="hidden" name="prov_id" value="<?= (int) $p['id'] ?>">
                    <div class="dia-fila-3">
                        <div class="campo"><label for="ph<?= (int) $p['id'] ?>">Llega</label><input type="time" id="ph<?= (int) $p['id'] ?>" name="hora_llegada" value="<?= e(hora_corta($p['hora_llegada'])) ?>"></div>
                        <div class="campo"><label for="pt<?= (int) $p['id'] ?>">Celular</label><input type="tel" id="pt<?= (int) $p['id'] ?>" name="telefono" value="<?= e($p['telefono']) ?>"></div>
                    </div>
                    <div class="campo"><label for="pn<?= (int) $p['id'] ?>">Notas</label><input type="text" id="pn<?= (int) $p['id'] ?>" name="notas" value="<?= e($p['notas']) ?>" maxlength="300"></div>
                    <label class="opcion" style="margin-bottom:10px"><input type="checkbox" name="lo_trae_cliente" value="1" <?= $p['lo_trae_cliente'] ? 'checked' : '' ?>><span><strong>Lo trae el cliente</strong></span></label>
                    <button class="boton boton-chico" type="submit">Guardar</button>
                </form>
                <form method="post" class="dia-en-linea"><?= campo_csrf() ?><?= $accion('prov_quitar') ?><input type="hidden" name="prov_id" value="<?= (int) $p['id'] ?>">
                    <button class="boton boton-peligro boton-chico" type="submit" data-confirmar="¿Quitar a <?= e($p['nombre']) ?> de este evento?">Quitar del evento</button></form>
            </details>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</section>

<?php if ($editable): ?>
<form method="post" class="bloque"><?= campo_csrf() ?><?= $accion('prov_agregar') ?>
    <h2>Agregar proveedor</h2>
    <div class="campo"><label for="origen_prov">¿De dónde viene?</label>
        <select id="origen_prov" name="origen_prov" data-mostrar="dia-origen-prov">
            <option value="lista">De mi lista de proveedores</option>
            <option value="cliente">Lo trae el cliente (o es nuevo)</option>
        </select></div>
    <div class="dia-origen-prov" data-si="lista">
        <?php $disp = array_filter($catalogoProv, fn($p) => !in_array((int) $p['id'], $enEvento, true)); ?>
        <?php if ($disp): ?>
        <div class="campo"><label for="prid">Proveedor</label>
            <select id="prid" name="proveedor_id">
                <option value="">Elige…</option>
                <?php foreach (Cotizaciones::CATEGORIAS as $cat => $nomCat): $deCat = array_filter($disp, fn($p) => $p['servicio'] === $cat); if (!$deCat) continue; ?>
                <optgroup label="<?= e($nomCat) ?>"><?php foreach ($deCat as $p): $tp = \TuRenta\ProveedoresFicha::textoPuntaje($puntajesProv[(int) $p['id']] ?? null); ?><option value="<?= (int) $p['id'] ?>"><?= ($p['preferido'] ?? false) ? '★ ' : '' ?><?= e($p['nombre']) ?><?= $tp !== '' ? ' · ' . e($tp) : '' ?></option><?php endforeach; ?></optgroup>
                <?php endforeach; ?>
            </select></div>
        <?php else: ?>
            <p class="suave"><?= $catalogoProv ? 'Todos tus proveedores ya están en este evento.' : 'Todavía no tienes proveedores en tu lista.' ?> <a href="<?= e(url('proveedores')) ?>">Ir a Proveedores</a></p>
        <?php endif; ?>
    </div>
    <div class="dia-origen-prov" data-si="cliente" hidden>
        <div class="fila-campos">
            <div class="campo"><label for="prnom">Nombre</label><input type="text" id="prnom" name="nombre" maxlength="150" placeholder="Florería de la tía"></div>
            <div class="campo"><label for="prser">Servicio</label><select id="prser" name="servicio">
                <?php foreach (Cotizaciones::CATEGORIAS as $k => $t): ?><option value="<?= e($k) ?>"><?= e($t) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="campo"><label for="prtel">Celular</label><input type="tel" id="prtel" name="telefono" placeholder="0991234567"></div>
    </div>
    <div class="fila-campos">
        <div class="campo"><label for="prh">Hora de llegada</label><input type="time" id="prh" name="hora_llegada"></div>
        <div class="campo"><label for="prn">Notas <small>(opcional)</small></label><input type="text" id="prn" name="notas" maxlength="300" placeholder="Necesita toma de 220 V"></div>
    </div>
    <button class="boton boton-ancho" type="submit">Agregar al evento</button>
</form>
<?php endif; ?>

<?php /* ===================================================== INCIDENCIAS (060) */ elseif ($ver === 'incidencias'):
    $listaInc = $incidenciasEv; $conDinero = es_comercial($u); $volverA = 'evento_dia'; $volverId = $id; $ocultarEvento = true; $eventoFijo = $id;
    $comprasSel = $conDinero ? array_values(array_filter($comprasEv, fn($c) => $c['saldo'] > 0)) : [];
    $provsSel = array_values(array_map(fn($pv) => ['id' => $pv['proveedor_id'], 'nombre' => $pv['nombre']], array_filter($proveedoresEv, fn($pv) => $pv['proveedor_id'])));
    $pre = ['evento' => $id, 'proveedor' => (int) ($_GET['proveedor'] ?? 0)]; ?>
<div class="rejilla rejilla-2">
    <section class="bloque">
        <div class="dia-titulo-acciones"><h2>Problemas del evento</h2><a class="enlace-chico" href="<?= e(url('incidencias', ['evento' => $id, 'estado' => 'todas'])) ?>">Ver en Incidencias</a></div>
        <?php if (!$listaInc): ?><p class="vacio">Sin problemas registrados. Si pasa algo (un proveedor llega tarde, se daña algo, falta comida), anótalo aquí con una foto.</p>
        <?php else: require RAIZ . '/app/vistas/_pv_incidencias.php'; endif; ?>
    </section>
    <?php if ($editable) require RAIZ . '/app/vistas/_pv_incidencia_form.php'; ?>
</div>

<?php /* ===================================================== MOBILIARIO */ elseif ($ver === 'mobiliario'):
    $reservados = array_filter($mobiliario, fn($m) => $m['reservado'] > 0);
    $faltan = array_filter($mobiliario, fn($m) => $m['faltan'] > 0); ?>

<?php if (!in_array($ev['estado'], DiaEvento::RESERVAN, true)): ?>
    <p class="aviso dia-aviso-amarillo">Este evento está <?= e(mb_strtolower(Eventos::ESTADOS[$ev['estado']])) ?>: lo que anotes aquí todavía no aparta el mobiliario para otros eventos. Se aparta cuando el evento queda separado.</p>
<?php endif; ?>
<?php if ($faltan): ?>
    <p class="aviso aviso-error" role="alert">No alcanza para este día:
        <?= e(implode(', ', array_map(fn($m) => "{$m['nombre']} (faltan {$m['faltan']})", $faltan))) ?>. Consigue o alquila lo que falta.</p>
<?php endif; ?>

<form method="post" class="bloque"><?= campo_csrf() ?><?= $accion('mobiliario') ?>
    <div class="dia-titulo-acciones">
        <h2>Mobiliario para el <?= e(fecha_corta($ev['fecha'])) ?></h2>
        <a class="boton boton-claro boton-chico" href="<?= e(url('mobiliario')) ?>">Inventario</a>
    </div>
    <?php if (!$mobiliario): ?>
        <p class="vacio">No tienes mobiliario registrado. <a href="<?= e(url('mobiliario')) ?>">Agrega tus sillas, mesas y mantelería</a>.</p>
    <?php else: ?>
    <p class="suave" style="font-size:.92rem">“Libres ese día” ya descuenta lo que reservaron otros eventos separados o confirmados de la misma fecha.</p>
    <div class="tabla-scroll">
    <table class="tabla dia-tabla-mob">
        <thead><tr><th>Ítem</th><th class="num">Tienes</th><th class="num">Libres ese día</th><th class="num">Para este evento</th></tr></thead>
        <tbody>
        <?php $cat = null; foreach ($mobiliario as $m): if ($m['categoria'] !== $cat): $cat = $m['categoria']; ?>
            <tr class="dia-fila-cat"><td colspan="4"><?= e(DiaEvento::CATEGORIAS_MOBILIARIO[$cat] ?? $cat) ?></td></tr>
        <?php endif; ?>
            <tr class="<?= $m['faltan'] ? 'dia-falta' : '' ?>">
                <td><label for="cant<?= (int) $m['id'] ?>"><?= e($m['nombre']) ?></label><?= $m['faltan'] ? '<br><small class="dia-alerta-texto">Faltan ' . (int) $m['faltan'] . '</small>' : '' ?></td>
                <td class="num"><?= (int) $m['cantidad_total'] ?></td>
                <td class="num"><strong><?= (int) $m['disponible'] ?></strong><?= $m['otros'] ? '<br><small>' . (int) $m['otros'] . ' en otro evento</small>' : '' ?></td>
                <td class="num"><input class="dia-cant" type="number" id="cant<?= (int) $m['id'] ?>" name="cant[<?= (int) $m['id'] ?>]" value="<?= (int) $m['reservado'] ?: '' ?>" min="0" step="1" placeholder="0" data-libres="<?= (int) $m['disponible'] ?>" <?= $editable ? '' : 'disabled' ?>></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php if ($editable): ?><button class="boton" type="submit" style="margin-top:12px">Guardar mobiliario</button><?php endif; ?>
    <?php endif; ?>
</form>

<?php if ($reservados): ?>
<form method="post" class="bloque"><?= campo_csrf() ?><?= $accion('danados') ?>
    <h2>Después del evento: dañados o perdidos</h2>
    <?php if (!$yaPaso): ?>
        <p class="suave">Esto se llena el día del evento o después, al recoger y contar todo.</p>
    <?php else: ?>
    <p class="suave" style="font-size:.92rem">Cuenta lo que volvió. Por cada ítem dañado se cobra su costo de reposición.</p>
    <ul class="filas">
        <?php foreach ($reservados as $m): ?>
        <li>
            <div><label for="dan<?= (int) $m['id'] ?>"><strong><?= e($m['nombre']) ?></strong></label><br><small>Salieron <?= (int) $m['reservado'] ?> · reponer <?= dinero($m['costo_reposicion']) ?> c/u</small></div>
            <div class="derecha"><input class="dia-cant" type="number" id="dan<?= (int) $m['id'] ?>" name="danados[<?= (int) $m['id'] ?>]" value="<?= (int) $m['danados'] ?: '' ?>" min="0" max="<?= (int) $m['reservado'] ?>" step="1" placeholder="0" <?= $editable ? '' : 'disabled' ?>></div>
        </li>
        <?php endforeach; ?>
    </ul>
    <div class="dia-danos-total">
        <span>Valor a cobrar por daños</span><strong class="<?= $valorDanos > 0 ? 'dia-alerta-texto' : '' ?>"><?= dinero($valorDanos) ?></strong>
    </div>
    <div class="acciones">
        <?php if ($editable): ?><button class="boton" type="submit">Guardar daños</button><?php endif; ?>
        <?php if ($valorDanos > 0 && es_comercial($u)): ?><a class="boton boton-claro" href="<?= e(url('evento_pagos', ['id' => $id])) ?>">Retener de la garantía en Pagos</a><?php endif; ?>
    </div>
    <?php if ($valorDanos > 0): ?><p class="suave" style="margin-top:8px"><small>En Pagos registra un “cargo por daños” de <?= dinero($valorDanos) ?> y devuelve el resto de la garantía (<?= dinero($ev['garantia']) ?>).</small></p><?php endif; ?>
    <?php endif; ?>
</form>
<?php endif; ?>

<?php /* ===================================================== DETALLES */ elseif ($ver === 'detalles'): ?>

<div class="rejilla rejilla-2">
    <section class="bloque">
        <h2>Montaje</h2>
        <p class="dia-dato-grande"><?= $ev['montaje'] ? e(Eventos::MONTAJES[$ev['montaje']] ?? $ev['montaje']) : '<span class="suave">Por definir</span>' ?></p>
        <p class="suave"><?= (int) $ev['invitados'] ?> invitados · <?= e($ev['espacios_texto']) ?></p>
        <h2 style="margin-top:16px">Menú elegido</h2>
        <?= $ev['menu_elegido'] ? '<p class="dia-texto">' . nl2br(e($ev['menu_elegido'])) . '</p>' : '<p class="suave">Todavía no se eligió el menú.</p>' ?>
    </section>
    <section class="bloque">
        <h2>Lo que pidió el cliente</h2>
        <?= $ev['detalles_cliente'] ? '<p class="dia-texto">' . nl2br(e($ev['detalles_cliente'])) . '</p>' : '<p class="suave">Sin detalles anotados (colores, música, alergias, decoración…).</p>' ?>
        <h2 style="margin-top:16px">Comida especial de los invitados</h2>
        <?php if (!$restricciones): ?>
            <p class="suave">Ningún invitado anotó restricciones de comida. Se llenan cuando confirman su asistencia.</p>
        <?php else: ?>
            <ul class="filas"><?php foreach ($restricciones as $r): ?>
                <li><div><strong><?= e(mb_strtoupper(mb_substr($r['texto'], 0, 1)) . mb_substr($r['texto'], 1)) ?></strong><br><small><?= e(implode(', ', array_slice($r['quienes'], 0, 6))) ?><?= count($r['quienes']) > 6 ? '…' : '' ?></small></div>
                    <div class="derecha monto">× <?= (int) $r['veces'] ?></div></li>
            <?php endforeach; ?></ul>
        <?php endif; ?>
    </section>
</div>
<?php if ($u['rol'] !== 'consulta' && $u['rol'] !== 'coordinador'): ?>
    <p><a class="boton boton-claro" href="<?= e(url('evento', ['id' => $id])) ?>#detalles">Editar montaje, menú y detalles en el resumen</a></p>
<?php endif; ?>

<?php /* ===================================================== BODA EN VIVO */ elseif ($ver === 'vivo'): ?>

<div class="rejilla rejilla-2">
    <section class="bloque dia-transmision">
        <h2>Boda en vivo</h2>
        <p>Transmisión privada en HD para la familia que no puede asistir, desde cualquier país. La hace <strong>QF Producciones</strong>.</p>
        <p>Estado: <span class="etq <?= $ev['transmision_estado'] === 'confirmada' ? 'etq-confirmada' : ($ev['transmision_estado'] === 'solicitada' ? 'etq-pendiente' : 'etq-reservada') ?>"><?= e(DiaEvento::TRANSMISION[$ev['transmision_estado']]) ?></span></p>
        <?php if ($ev['transmision_url']): ?>
            <p><a href="<?= e($ev['transmision_url']) ?>" target="_blank" rel="noopener"><?= e($ev['transmision_url']) ?></a></p>
            <p class="suave"><small>Este enlace ya aparece en el portal del cliente y en las invitaciones digitales (botón “Ver en vivo”).</small></p>
        <?php endif; ?>
        <?php if ($editable && $ev['transmision_estado'] === 'no'): ?>
            <form method="post" target="_blank"><?= campo_csrf() ?><?= $accion('pedir_transmision') ?>
                <button class="boton boton-wa boton-ancho" type="submit">Pedir transmisión a QF Producciones</button></form>
            <p class="suave" style="margin-top:8px"><small>Se abre WhatsApp con los datos del evento listos para enviar.</small></p>
        <?php elseif ($ev['transmision_estado'] === 'solicitada'): ?>
            <a class="boton boton-wa" href="<?= e(DiaEvento::wa($negocio['whatsapp_transmision'], DiaEvento::mensajeTransmision($ev, $negocio))) ?>" target="_blank" rel="noopener">Escribir otra vez a QF Producciones</a>
        <?php endif; ?>
    </section>
    <?php if ($editable): ?>
    <form method="post" class="bloque"><?= campo_csrf() ?><?= $accion('transmision') ?>
        <h2>Enlace de la transmisión</h2>
        <div class="campo"><label for="turl">Enlace <small>(YouTube, Facebook o el que mande QF Producciones)</small></label>
            <input type="url" id="turl" name="transmision_url" value="<?= e($ev['transmision_url']) ?>" placeholder="https://youtube.com/live/…"></div>
        <div class="campo"><label for="test">Estado</label>
            <select id="test" name="transmision_estado">
                <?php foreach (DiaEvento::TRANSMISION as $k => $t): ?><option value="<?= e($k) ?>" <?= $ev['transmision_estado'] === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
            </select></div>
        <button class="boton boton-ancho" type="submit">Guardar</button>
    </form>
    <?php endif; ?>
</div>

<?php endif; ?>
