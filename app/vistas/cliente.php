<?php
use TuRenta\{Eventos, Ventas, WhatsApp};
/** @var array $cliente @var array $d @var array $eventosCliente @var array $actividades @var array $resumen */
$primer = explode(' ', trim((string) $cliente['nombre']))[0];
$wa = WhatsApp::enlace($cliente['telefono'], "Hola $primer, te saludamos de {$u['negocio']}. ");
$hoyTxt = date('Y-m-d');
$fmt = fn(?string $ts) => $ts ? fecha_corta(substr($ts, 0, 10), false) . ' ' . substr($ts, 11, 5) : '';
?>
<div class="cabecera">
    <div>
        <p class="evento-sub" style="margin:0"><a href="<?= e(url('clientes')) ?>">Clientes</a></p>
        <h1><?= e($cliente['nombre']) ?></h1>
        <p>Cliente desde <?= e(fecha_corta(substr($cliente['creado_en'], 0, 10))) ?><?= $cliente['origen'] ? ' · nos conoció por ' . e(Eventos::ORIGENES[$cliente['origen']] ?? $cliente['origen']) : '' ?></p>
    </div>
    <div class="acciones">
        <?php if ($wa): ?><a class="boton boton-wa" href="<?= e($wa) ?>" target="_blank" rel="noopener">WhatsApp</a><?php endif; ?>
        <?php if ($puedeEditar): ?><a class="boton" href="<?= e(url('evento_nuevo', ['cliente_id' => $cliente['id']])) ?>">Nuevo evento</a><?php endif; ?>
    </div>
</div>

<div class="cifras">
    <div class="cifra-tarjeta"><small>Eventos</small><strong><?= $resumen['eventos'] ?></strong></div>
    <div class="cifra-tarjeta"><small>Contratado</small><strong><?= dinero($resumen['contratado']) ?></strong></div>
    <div class="cifra-tarjeta bien"><small>Pagado</small><strong><?= dinero($resumen['pagado']) ?></strong></div>
    <div class="cifra-tarjeta <?= $resumen['saldo'] > 0 ? 'alerta' : '' ?>"><small>Por cobrar</small><strong><?= dinero($resumen['saldo']) ?></strong></div>
</div>

<div class="rejilla rejilla-2">
    <section class="bloque" aria-labelledby="t-ficha">
        <h2 id="t-ficha">Datos</h2>
        <?php if ($puedeEditar): ?>
        <form method="post" action="<?= e(url('cliente', ['id' => $cliente['id'], 'accion' => 'guardar'])) ?>" novalidate>
            <?= campo_csrf() ?>
            <?php if ($error): ?><p class="aviso aviso-error" role="alert"><?= e($error) ?></p><?php endif; ?>
            <div class="campo">
                <label for="nombre">Nombre</label>
                <input type="text" id="nombre" name="nombre" value="<?= e($d['nombre']) ?>" required maxlength="150">
            </div>
            <div class="fila-campos">
                <div class="campo">
                    <label for="telefono">Celular</label>
                    <input type="tel" id="telefono" name="telefono" value="<?= e($d['telefono']) ?>" placeholder="0991234567">
                </div>
                <div class="campo">
                    <label for="identificacion">Cédula o RUC</label>
                    <input type="text" id="identificacion" name="identificacion" value="<?= e($d['identificacion']) ?>" inputmode="numeric" maxlength="13">
                </div>
            </div>
            <div class="campo">
                <label for="email">Correo</label>
                <input type="email" id="email" name="email" value="<?= e($d['email']) ?>">
            </div>
            <div class="campo">
                <label for="direccion">Dirección <small>(para el contrato)</small></label>
                <input type="text" id="direccion" name="direccion" value="<?= e($d['direccion']) ?>" maxlength="250">
            </div>
            <div class="campo">
                <label for="origen">¿Cómo nos conoció?</label>
                <select id="origen" name="origen">
                    <option value="">— Elegir —</option>
                    <?php foreach (Eventos::ORIGENES as $k => $t): ?><option value="<?= e($k) ?>" <?= ($d['origen'] ?? '') === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="campo">
                <label for="notas">Notas</label>
                <textarea id="notas" name="notas" rows="3"><?= e($d['notas']) ?></textarea>
            </div>
            <button class="boton" type="submit">Guardar ficha</button>
        </form>
        <?php else: ?>
            <dl class="ficha-datos">
                <dt>Celular</dt><dd><?= e($cliente['telefono'] ?: '—') ?></dd>
                <dt>Cédula/RUC</dt><dd><?= e($cliente['identificacion'] ?: '—') ?></dd>
                <dt>Correo</dt><dd><?= e($cliente['email'] ?: '—') ?></dd>
                <dt>Dirección</dt><dd><?= e($cliente['direccion'] ?: '—') ?></dd>
            </dl>
            <?php if ($cliente['notas']): ?><p><?= nl2br(e($cliente['notas'])) ?></p><?php endif; ?>
        <?php endif; ?>
    </section>

    <div>
        <section class="bloque" aria-labelledby="t-evs">
            <h2 id="t-evs">Sus eventos</h2>
            <?php if (!$eventosCliente): ?>
                <p class="vacio">Todavía no tiene eventos.</p>
            <?php else: ?>
            <ul class="filas">
                <?php foreach ($eventosCliente as $ev): ?>
                    <li>
                        <a href="<?= e(url('evento', ['id' => $ev['id']])) ?>" class="fila-enlace">
                            <strong><?= e($ev['nombre']) ?></strong>
                            <small class="suave" style="display:block"><?= e(fecha_corta($ev['fecha'])) ?> · <?= e($ev['espacios'] ?? '') ?> · <?= e($ev['codigo']) ?></small>
                        </a>
                        <span class="derecha">
                            <span class="ev-estado ev-<?= e($ev['estado']) ?>"><?= e(Eventos::ESTADOS[$ev['estado']]) ?></span>
                            <?php if ((float) $ev['total'] > 0): ?><small class="monto" style="display:block"><?= dinero($ev['total']) ?></small><?php endif; ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </section>

        <section class="bloque" aria-labelledby="t-act">
            <h2 id="t-act">Seguimiento</h2>
            <?php if ($puedeEditar): ?>
            <form method="post" action="<?= e(url('cliente', ['id' => $cliente['id'], 'accion' => 'actividad'])) ?>" class="form-seguimiento">
                <?= campo_csrf() ?>
                <div class="fila-campos">
                    <div class="campo">
                        <label for="act-tipo">Qué fue</label>
                        <select id="act-tipo" name="tipo">
                            <?php foreach (Ventas::TIPOS_SEGUIMIENTO as $k => $t): ?><option value="<?= e($k) ?>"><?= e($t) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="campo">
                        <label for="act-prog">Agendar para <small>(vacío = ya se hizo)</small></label>
                        <input type="datetime-local" id="act-prog" name="programada">
                    </div>
                </div>
                <?php if ($eventosCliente): ?>
                <div class="campo">
                    <label for="act-evento">Evento <small>(opcional)</small></label>
                    <select id="act-evento" name="evento_id">
                        <option value="">— General del cliente —</option>
                        <?php foreach ($eventosCliente as $ev): ?><option value="<?= (int) $ev['id'] ?>"><?= e($ev['nombre'] . ' · ' . fecha_corta($ev['fecha'])) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="campo">
                    <label for="act-texto">Nota</label>
                    <textarea id="act-texto" name="texto" rows="2" required></textarea>
                </div>
                <button class="boton boton-claro boton-chico" type="submit">Guardar</button>
            </form>
            <?php endif; ?>
            <?php if (!$actividades): ?>
                <p class="vacio">Sin seguimiento todavía.</p>
            <?php else: ?>
            <ul class="filas ev-actividades">
                <?php foreach ($actividades as $a):
                    $atrasada = !$a['hecha'] && $a['programada_para'] && substr($a['programada_para'], 0, 10) < $hoyTxt; ?>
                    <li class="<?= !$a['hecha'] ? 'pendiente' : '' ?>">
                        <span>
                            <span class="etq etq-act"><?= e(Eventos::TIPOS_ACTIVIDAD[$a['tipo']] ?? $a['tipo']) ?></span>
                            <?= nl2br(e($a['texto'])) ?>
                            <small class="<?= $atrasada ? 'ocupado' : 'suave' ?>" style="display:block">
                                <?= !$a['hecha'] ? ($atrasada ? 'Atrasado: ' : 'Agendado: ') : '' ?><?= e($fmt($a['programada_para'] ?? $a['creado_en'])) ?>
                                <?= $a['evento'] ? ' · ' . e($a['evento']) : '' ?><?= $a['usuario'] ? ' · ' . e($a['usuario']) : '' ?></small>
                        </span>
                        <?php if (!$a['hecha'] && $puedeEditar): ?>
                        <form method="post" action="<?= e(url('cliente', ['id' => $cliente['id'], 'accion' => 'actividad_marcar'])) ?>" class="derecha">
                            <?= campo_csrf() ?>
                            <input type="hidden" name="actividad_id" value="<?= (int) $a['id'] ?>">
                            <button class="boton boton-chico" type="submit">Hecho</button>
                        </form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </section>
    </div>
</div>
