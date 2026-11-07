<?php
use TuRenta\{DiaEvento, Invitados};

/**
 * Panel de invitados que comparten /?r=invitados (negocio) y /?r=portal_invitados (novios u organizadores).
 * @var array $ev @var array $negocio @var string $verInv @var array $f @var ?array $previo @var array $lista @var array $cont
 * @var array $mesas @var array $grupos @var ?array $plano @var bool $editable @var bool $esNegocio
 * @var callable $ruta  fn(array $extra): string   URL de esta misma pantalla
 * @var array $oculto   campos ocultos para el formulario de filtros (GET)
 */
$hayFiltro = $f['buscar'] !== '' || $f['estado'] !== '' || $f['grupo'] !== '' || $f['mesa_id'] !== '';
$filtros = '<input type="hidden" name="ver" value="' . e($verInv) . '">';
foreach (['buscar' => $f['buscar'], 'estado' => $f['estado'], 'grupo' => $f['grupo'], 'mesa' => $f['mesa_id']] as $k => $v) {
    if ($v !== '') $filtros .= '<input type="hidden" name="f_' . $k . '" value="' . e($v) . '">';
}
$accion = fn(string $a) => campo_csrf() . '<input type="hidden" name="accion" value="' . e($a) . '">' . $filtros;
$estadoEtq = ['pendiente' => 'etq-pendiente', 'confirmado' => 'etq-confirmada', 'no_asiste' => 'etq-cancelada'];
$selectMesa = function (array $i, string $idCampo) use ($mesas): string {
    $h = '<select id="' . e($idCampo) . '" name="mesa_id" data-autoenviar aria-label="Mesa de ' . e($i['nombre']) . '"><option value="0">Sin mesa</option>';
    foreach ($mesas as $m) {
        $h .= '<option value="' . (int) $m['id'] . '"' . ((int) $i['mesa_id'] === (int) $m['id'] ? ' selected' : '') . '>'
            . e($m['nombre']) . ' (' . (int) $m['ocupados'] . '/' . (int) $m['capacidad'] . ')</option>';
    }
    return $h . '</select>';
};
$pv = fn(string $k, $def = '') => e($previo[$k] ?? $def);
?>
<nav class="dia-secciones dia-secciones-inv" aria-label="Partes de la lista de invitados">
    <a href="<?= e($ruta(['ver' => 'lista'])) ?>" class="<?= $verInv === 'lista' ? 'activa' : '' ?>">Lista <span class="dia-cuenta"><?= (int) $cont['invitados'] ?></span></a>
    <a href="<?= e($ruta(['ver' => 'mesas'])) ?>" class="<?= $verInv === 'mesas' ? 'activa' : '' ?>">Mesas <?php if ($mesas): ?><span class="dia-cuenta"><?= count($mesas) ?></span><?php endif; ?>
        <?php if (array_filter($mesas, fn($m) => $m['se_pasa'])): ?><span class="dia-punto" title="Hay mesas que se pasan"></span><?php endif; ?></a>
    <a href="<?= e($ruta(['ver' => 'invitacion'])) ?>" class="<?= $verInv === 'invitacion' ? 'activa' : '' ?>">Invitaciones</a>
</nav>

<div class="cifras dia-cifras-inv">
    <div class="cifra-tarjeta"><small>Invitados</small><strong><?= (int) $cont['invitados'] ?></strong></div>
    <div class="cifra-tarjeta"><small>Pases</small><strong><?= (int) $cont['pases'] ?></strong></div>
    <div class="cifra-tarjeta bien"><small>Confirmados</small><strong><?= (int) $cont['confirmados'] ?></strong></div>
    <div class="cifra-tarjeta <?= $cont['no_asisten'] ? 'alerta' : '' ?>"><small>No asisten</small><strong><?= (int) $cont['no_asisten'] ?></strong></div>
    <div class="cifra-tarjeta"><small>Pendientes</small><strong><?= (int) $cont['pendientes'] ?></strong></div>
    <?php if ($cont['ingresaron']): ?><div class="cifra-tarjeta"><small>Ingresaron</small><strong><?= (int) $cont['ingresaron'] ?></strong></div><?php endif; ?>
</div>
<?php if ($cont['pases'] > (int) $ev['invitados'] && $esNegocio): ?>
    <p class="aviso dia-aviso-amarillo">La lista tiene <?= (int) $cont['pases'] ?> pases y el evento está contratado para <?= (int) $ev['invitados'] ?> invitados. Revisa con el cliente.</p>
<?php endif; ?>

<?php /* ============================================ LISTA */ if ($verInv === 'lista'): ?>

<?php if ($editable): ?>
<div class="rejilla rejilla-2">
    <details class="bloque dia-plegable" <?= !$cont['invitados'] || ($previo['accion'] ?? '') === 'agregar' ? 'open' : '' ?>>
        <summary><h2>Agregar invitado</h2></summary>
        <form method="post"><?= $accion('agregar') ?>
            <div class="campo"><label for="inom">Nombre</label><input type="text" id="inom" name="nombre" value="<?= $pv('nombre') ?>" required maxlength="150" placeholder="Familia Pérez Andrade"></div>
            <div class="dia-fila-3">
                <div class="campo"><label for="ipas">Pases</label><input type="number" id="ipas" name="pases" value="<?= $pv('pases', '1') ?>" min="1" max="20" required></div>
                <div class="campo"><label for="itel">Celular</label><input type="tel" id="itel" name="telefono" value="<?= $pv('telefono') ?>" placeholder="0991234567"></div>
            </div>
            <div class="campo"><label for="igru">Grupo <small>(opcional)</small></label>
                <input type="text" id="igru" name="grupo" value="<?= $pv('grupo') ?>" maxlength="60" list="grupos-lista" placeholder="Familia de la novia"></div>
            <div class="campo"><label for="ires">Comida especial <small>(opcional)</small></label>
                <input type="text" id="ires" name="restricciones" value="<?= $pv('restricciones') ?>" maxlength="200" placeholder="Vegetariano, alergia al maní…"></div>
            <?php if ($mesas): ?>
            <div class="campo"><label for="imes">Mesa</label><select id="imes" name="mesa_id"><option value="0">Sin mesa</option>
                <?php foreach ($mesas as $m): ?><option value="<?= (int) $m['id'] ?>"><?= e($m['nombre']) ?> (<?= (int) $m['ocupados'] ?>/<?= (int) $m['capacidad'] ?>)</option><?php endforeach; ?></select></div>
            <?php endif; ?>
            <button class="boton boton-ancho" type="submit">Agregar</button>
        </form>
    </details>
    <details class="bloque dia-plegable" <?= ($previo['accion'] ?? '') === 'importar' ? 'open' : '' ?>>
        <summary><h2>Pegar lista</h2></summary>
        <form method="post"><?= $accion('importar') ?>
            <div class="campo"><label for="ilis">Un invitado por línea</label>
                <textarea id="ilis" name="lista" rows="7" required placeholder="Ana Pérez; 0991234567; 2; Familia de la novia&#10;Luis Mora, 0987654321, 3&#10;Tía Rosa"><?= $pv('lista') ?></textarea>
                <small>Nombre; celular; pases; grupo. Puedes separar con punto y coma, coma o copiar las columnas de Excel. Solo el nombre es obligatorio (si no pones pases, es 1).</small></div>
            <div class="campo"><label for="igrd">Grupo para los que no tienen <small>(opcional)</small></label>
                <input type="text" id="igrd" name="grupo" value="<?= $pv('grupo') ?>" maxlength="60" list="grupos-lista" placeholder="Amigos del novio"></div>
            <button class="boton boton-ancho" type="submit">Agregar a la lista</button>
            <p class="suave" style="margin:8px 0 0"><small>No se repiten los que ya están (mismo nombre o mismo celular).</small></p>
        </form>
    </details>
</div>
<datalist id="grupos-lista"><?php foreach ($grupos as $g): ?><option value="<?= e($g['grupo']) ?>"><?php endforeach; ?>
    <option value="Familia de la novia"><option value="Familia del novio"><option value="Amigos"><option value="Trabajo"></datalist>
<?php endif; ?>

<section class="bloque">
    <form method="get" class="dia-filtros" role="search">
        <?php foreach ($oculto as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
        <input type="hidden" name="ver" value="lista">
        <label for="fbus" class="sr-only">Buscar invitado</label>
        <input type="search" id="fbus" name="buscar" value="<?= e($f['buscar']) ?>" placeholder="Buscar por nombre o celular">
        <label for="fest" class="sr-only">Respuesta</label>
        <select id="fest" name="estado" data-autoenviar><option value="">Todas las respuestas</option>
            <?php foreach (Invitados::ESTADOS as $k => $t): ?><option value="<?= e($k) ?>" <?= $f['estado'] === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select>
        <?php if ($grupos): ?>
        <label for="fgru" class="sr-only">Grupo</label>
        <select id="fgru" name="grupo" data-autoenviar><option value="">Todos los grupos</option>
            <?php foreach ($grupos as $g): ?><option value="<?= e($g['grupo']) ?>" <?= $f['grupo'] === $g['grupo'] ? 'selected' : '' ?>><?= e($g['grupo']) ?> (<?= (int) $g['n'] ?>)</option><?php endforeach; ?></select>
        <?php endif; ?>
        <?php if ($mesas): ?>
        <label for="fmes" class="sr-only">Mesa</label>
        <select id="fmes" name="mesa" data-autoenviar><option value="">Todas las mesas</option><option value="sin" <?= $f['mesa_id'] === 'sin' ? 'selected' : '' ?>>Sin mesa</option>
            <?php foreach ($mesas as $m): ?><option value="<?= (int) $m['id'] ?>" <?= $f['mesa_id'] === (string) $m['id'] ? 'selected' : '' ?>><?= e($m['nombre']) ?></option><?php endforeach; ?></select>
        <?php endif; ?>
        <button class="boton boton-claro boton-chico" type="submit">Buscar</button>
        <?php if ($hayFiltro): ?><a class="boton-enlace" href="<?= e($ruta(['ver' => 'lista'])) ?>">Quitar filtros</a><?php endif; ?>
    </form>

    <?php if (!$lista): ?>
        <div class="vacio">
            <?php if ($hayFiltro): ?>
                <p>Nadie coincide con la búsqueda.</p>
            <?php else: ?>
                <p><strong>La lista está vacía.</strong></p>
                <p>Agrega invitados uno por uno o pega la lista completa (desde WhatsApp, Excel o el bloc de notas). Después mandas la invitación digital a cada uno por WhatsApp.</p>
            <?php endif; ?>
        </div>
    <?php else: ?>
    <ul class="dia-invitados">
        <?php $grupoAnt = false; foreach ($lista as $i):
            $g = $i['grupo'] ?: 'Sin grupo';
            if ($g !== $grupoAnt): $grupoAnt = $g; ?>
            <li class="dia-grupo"><?= e($g) ?></li>
        <?php endif;
            $enlaceInv = url_completa('invitacion', ['t' => $i['token']]); ?>
        <li class="dia-invitado">
            <div class="dia-invitado-info">
                <strong><?= e($i['nombre']) ?></strong>
                <span class="etq <?= $estadoEtq[$i['estado']] ?>"><?= $i['estado'] === 'confirmado' ? 'Confirmó ' . (int) $i['confirmados'] . ' de ' . (int) $i['pases'] : e(Invitados::ESTADOS[$i['estado']]) ?></span>
                <?php if ((int) $i['ingresaron']): ?><span class="etq etq-atendida">Entró <?= (int) $i['ingresaron'] ?></span><?php endif; ?>
                <br><small><?= (int) $i['pases'] ?> <?= (int) $i['pases'] === 1 ? 'pase' : 'pases' ?><?= $i['telefono'] ? ' · ' . e($i['telefono']) : '' ?><?= $i['mesa'] ? ' · ' . e($i['mesa']) : '' ?>
                    <?= $i['invitacion_enviada'] ? ' · <span class="dia-ok-texto">invitación enviada</span>' : '' ?></small>
                <?php if ($i['restricciones']): ?><br><small class="dia-comida"><?= e($i['restricciones']) ?></small><?php endif; ?>
                <?php if ($i['mensaje']): ?><br><small class="suave">“<?= e($i['mensaje']) ?>”</small><?php endif; ?>
            </div>
            <div class="dia-invitado-acciones">
                <?php if ($editable): ?>
                <form method="post" target="_blank"><?= $accion('enviar_wa') ?><input type="hidden" name="invitado_id" value="<?= (int) $i['id'] ?>">
                    <button class="boton boton-wa boton-chico" type="submit" aria-label="Mandar invitación a <?= e($i['nombre']) ?> por WhatsApp"><?= $i['invitacion_enviada'] ? 'Reenviar' : 'Invitar' ?></button></form>
                <?php if ($mesas): ?>
                <form method="post" class="dia-mesa-sel"><?= $accion('mesa') ?><input type="hidden" name="invitado_id" value="<?= (int) $i['id'] ?>">
                    <?= $selectMesa($i, 'm' . (int) $i['id']) ?><noscript><button class="boton boton-chico" type="submit">Ok</button></noscript></form>
                <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php if ($editable): ?>
            <details class="dia-editar">
                <summary>Editar</summary>
                <form method="post" class="dia-form-bloque"><?= $accion('editar') ?><input type="hidden" name="invitado_id" value="<?= (int) $i['id'] ?>">
                    <div class="campo"><label for="en<?= (int) $i['id'] ?>">Nombre</label><input type="text" id="en<?= (int) $i['id'] ?>" name="nombre" value="<?= e($i['nombre']) ?>" maxlength="150" required></div>
                    <div class="dia-fila-3">
                        <div class="campo"><label for="ep<?= (int) $i['id'] ?>">Pases</label><input type="number" id="ep<?= (int) $i['id'] ?>" name="pases" value="<?= (int) $i['pases'] ?>" min="1" max="20" required></div>
                        <div class="campo"><label for="et<?= (int) $i['id'] ?>">Celular</label><input type="tel" id="et<?= (int) $i['id'] ?>" name="telefono" value="<?= e($i['telefono']) ?>"></div>
                        <div class="campo"><label for="eg<?= (int) $i['id'] ?>">Grupo</label><input type="text" id="eg<?= (int) $i['id'] ?>" name="grupo" value="<?= e($i['grupo']) ?>" maxlength="60" list="grupos-lista"></div>
                    </div>
                    <div class="campo"><label for="er<?= (int) $i['id'] ?>">Comida especial</label><input type="text" id="er<?= (int) $i['id'] ?>" name="restricciones" value="<?= e($i['restricciones']) ?>" maxlength="200"></div>
                    <?php if ($esNegocio): ?>
                    <div class="dia-fila-3">
                        <div class="campo"><label for="es<?= (int) $i['id'] ?>">Respuesta</label><select id="es<?= (int) $i['id'] ?>" name="estado">
                            <?php foreach (Invitados::ESTADOS as $k => $t): ?><option value="<?= e($k) ?>" <?= $i['estado'] === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
                        <div class="campo"><label for="ec<?= (int) $i['id'] ?>">¿Cuántos van?</label><input type="number" id="ec<?= (int) $i['id'] ?>" name="confirmados" value="<?= e($i['confirmados'] ?? $i['pases']) ?>" min="0" max="20"></div>
                    </div>
                    <?php endif; ?>
                    <div class="acciones">
                        <button class="boton boton-chico" type="submit">Guardar</button>
                        <button type="button" class="boton boton-claro boton-chico" data-copiar="<?= e($enlaceInv) ?>">Copiar su invitación</button>
                        <a class="boton boton-claro boton-chico" href="<?= e($enlaceInv) ?>" target="_blank" rel="noopener">Ver invitación</a>
                    </div>
                </form>
                <?php if (!(int) $i['ingresaron']): ?>
                <form method="post" class="dia-en-linea"><?= $accion('borrar') ?><input type="hidden" name="invitado_id" value="<?= (int) $i['id'] ?>">
                    <button class="boton boton-peligro boton-chico" type="submit" data-confirmar="¿Quitar a <?= e($i['nombre']) ?> de la lista?">Quitar de la lista</button></form>
                <?php elseif ($esNegocio): ?>
                <form method="post" class="dia-en-linea"><?= $accion('ingreso_deshacer') ?><input type="hidden" name="invitado_id" value="<?= (int) $i['id'] ?>"><input type="hidden" name="cuantos" value="1">
                    <button class="boton boton-claro boton-chico" type="submit" data-confirmar="¿Restar 1 al ingreso de <?= e($i['nombre']) ?>?">Corregir ingreso (−1)</button></form>
                <?php endif; ?>
            </details>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</section>

<?php /* ============================================ MESAS */ elseif ($verInv === 'mesas'):
    $puestos = array_sum(array_map(fn($m) => (int) $m['capacidad'], $mesas));
    $ocupados = array_sum(array_map(fn($m) => (int) $m['ocupados'], $mesas)); ?>

<?php if ($mesas): ?>
<p class="aviso <?= $ocupados > $puestos ? 'aviso-error' : 'aviso-ok' ?>" role="status">
    <?= count($mesas) ?> mesas · <?= $ocupados ?> de <?= $puestos ?> puestos ocupados<?= $plano['sin_mesa'] ? ' · ' . count($plano['sin_mesa']) . ' invitados todavía sin mesa' : '' ?>.
</p>
<?php endif; ?>
<?php require RAIZ . '/app/vistas/_plano_mesas.php'; /* plano del salón dibujado (módulo 080) */ ?>

<?php if ($editable): ?>
<form method="post" class="bloque dia-crear-mesas"><?= $accion('mesas_crear') ?>
    <h2><?= $mesas ? 'Agregar mesas' : 'Crear las mesas' ?></h2>
    <div class="dia-fila-3">
        <div class="campo"><label for="mcan">¿Cuántas mesas?</label><input type="number" id="mcan" name="cantidad" min="1" max="200" required value="<?= $mesas ? 1 : max(1, (int) ceil(max((int) $cont['pases'], (int) $ev['invitados']) / 10)) ?>"></div>
        <div class="campo"><label for="mpue">Puestos por mesa</label><input type="number" id="mpue" name="puestos" min="1" max="100" required value="10"></div>
        <div class="campo"><label for="mpre">Nombre</label><input type="text" id="mpre" name="prefijo" value="Mesa" maxlength="40"></div>
    </div>
    <button class="boton" type="submit">Crear mesas</button>
    <p class="suave" style="margin:8px 0 0"><small>Se numeran solas (Mesa 1, Mesa 2…). Después puedes renombrar una como “Mesa de honor”.</small></p>
</form>
<?php endif; ?>

<?php if (!$mesas): ?>
    <p class="vacio">Todavía no hay mesas. Créalas arriba y después sienta a cada invitado eligiendo su mesa.</p>
<?php else: ?>
<div class="dia-mesas">
    <?php foreach ($plano['mesas'] as $m): ?>
    <section class="dia-mesa <?= $m['se_pasa'] ? 'dia-mesa-pasa' : ($m['libres'] === 0 ? 'dia-mesa-llena' : '') ?>">
        <header>
            <strong><?= e($m['nombre']) ?></strong>
            <span class="dia-mesa-ocupa"><?= (int) $m['ocupados'] ?> / <?= (int) $m['capacidad'] ?></span>
        </header>
        <div class="barra dia-mesa-barra"><span style="width:<?= min(100, (int) round((int) $m['ocupados'] * 100 / max(1, (int) $m['capacidad']))) ?>%"></span></div>
        <?php if ($m['se_pasa']): ?><p class="dia-alerta-texto"><small>Se pasa por <?= abs((int) $m['libres']) ?>. Mueve a alguien a otra mesa.</small></p><?php endif; ?>
        <?php if (!$m['lista']): ?><p class="suave"><small>Mesa vacía</small></p><?php else: ?>
        <ul class="dia-mesa-lista">
            <?php foreach ($m['lista'] as $i): $n = $i['estado'] === 'confirmado' ? (int) $i['confirmados'] : (int) $i['pases']; ?>
            <li><?= e($i['nombre']) ?><?= $n > 1 ? ' <small>(' . $n . ')</small>' : '' ?><?= $i['estado'] === 'pendiente' ? ' <small class="suave" title="Todavía no confirma">?</small>' : '' ?></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
        <?php if ($editable): ?>
        <details class="dia-editar"><summary>Cambiar</summary>
            <form method="post" class="dia-form-bloque"><?= $accion('mesa_editar') ?><input type="hidden" name="mesa_id" value="<?= (int) $m['id'] ?>">
                <div class="dia-fila-3">
                    <div class="campo"><label for="mn<?= (int) $m['id'] ?>">Nombre</label><input type="text" id="mn<?= (int) $m['id'] ?>" name="nombre" value="<?= e($m['nombre']) ?>" maxlength="60" required></div>
                    <div class="campo"><label for="mc<?= (int) $m['id'] ?>">Puestos</label><input type="number" id="mc<?= (int) $m['id'] ?>" name="capacidad" value="<?= (int) $m['capacidad'] ?>" min="1" max="100" required></div>
                </div>
                <button class="boton boton-chico" type="submit">Guardar</button>
            </form>
            <form method="post" class="dia-en-linea"><?= $accion('mesa_borrar') ?><input type="hidden" name="mesa_id" value="<?= (int) $m['id'] ?>">
                <button class="boton boton-peligro boton-chico" type="submit" data-confirmar="¿Borrar <?= e($m['nombre']) ?>? Sus invitados quedan sin mesa.">Borrar mesa</button></form>
        </details>
        <?php endif; ?>
    </section>
    <?php endforeach; ?>
</div>

<section class="bloque">
    <h2>Sentar invitados</h2>
    <?php $todos = array_values(array_filter($lista, fn($i) => $i['estado'] !== 'no_asiste'));
    usort($todos, fn($a, $b) => ((int) (bool) $a['mesa_id']) <=> ((int) (bool) $b['mesa_id']) ?: strcmp((string) $a['grupo'], (string) $b['grupo']) ?: strcmp($a['nombre'], $b['nombre'])); ?>
    <?php if (!$todos): ?>
        <p class="vacio">Agrega invitados en la lista para sentarlos en las mesas.</p>
    <?php else: ?>
    <p class="suave" style="font-size:.92rem">Elige la mesa de cada invitado. Primero salen los que todavía no tienen mesa. Entre paréntesis: ocupados / puestos.</p>
    <ul class="filas dia-sentar">
        <?php foreach ($todos as $i): ?>
        <li>
            <div><label for="s<?= (int) $i['id'] ?>"><strong><?= e($i['nombre']) ?></strong></label>
                <?php $nn = (int) ($i['estado'] === 'confirmado' ? $i['confirmados'] : $i['pases']); ?>
                <br><small><?= $nn ?> <?= $i['estado'] === 'confirmado' ? ($nn === 1 ? 'confirmado' : 'confirmados') : ($nn === 1 ? 'pase' : 'pases') ?><?= $i['grupo'] ? ' · ' . e($i['grupo']) : '' ?></small></div>
            <?php if ($editable): ?>
            <form method="post" class="derecha dia-mesa-sel"><?= $accion('mesa') ?><input type="hidden" name="invitado_id" value="<?= (int) $i['id'] ?>">
                <?= $selectMesa($i, 's' . (int) $i['id']) ?><noscript><button class="boton boton-chico" type="submit">Ok</button></noscript></form>
            <?php else: ?><div class="derecha"><?= e($i['mesa'] ?? 'Sin mesa') ?></div><?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php /* ============================================ INVITACIONES */ else:
    $sinEnviar = array_values(array_filter($lista, fn($i) => !$i['invitacion_enviada'] && $i['estado'] === 'pendiente'));
    $ejemplo = $lista[0] ?? null; ?>

<div class="rejilla rejilla-2">
    <section class="bloque">
        <h2>Así llega por WhatsApp</h2>
        <pre class="dia-mensaje"><?= e(Invitados::mensajeInvitacion($ejemplo ?? ['nombre' => 'Ana Pérez', 'pases' => 2], $ev, $negocio, url_completa('invitacion', ['t' => $ejemplo['token'] ?? str_repeat('0', 32)]))) ?></pre>
        <?php if ($ejemplo): ?><a class="boton boton-claro" href="<?= e(url_completa('invitacion', ['t' => $ejemplo['token']])) ?>" target="_blank" rel="noopener">Ver la invitación de <?= e($ejemplo['nombre']) ?></a><?php endif; ?>
        <p class="suave" style="margin-top:10px"><small>Al abrirla, cada invitado ve la fecha, la hora y el mapa, confirma cuántos van y recibe su código QR para la entrada.
            <?= $ev['transmision_url'] ? 'También ve el botón “Ver en vivo” de la transmisión.' : '' ?></small></p>
    </section>
    <?php if ($editable): ?>
    <form method="post" class="bloque"><?= $accion('invitacion') ?>
        <h2>Texto de la invitación</h2>
        <div class="campo"><label for="itxt">Mensaje para los invitados <small>(opcional)</small></label>
            <textarea id="itxt" name="invitacion_texto" rows="4" maxlength="600" placeholder="Con la bendición de Dios y de nuestros padres, queremos compartir contigo este día tan especial."><?= e($ev['invitacion_texto'] ?? '') ?></textarea></div>
        <div class="campo"><label for="ihas">Confirmar hasta <small>(opcional)</small></label>
            <input type="date" id="ihas" name="confirmar_hasta" value="<?= e($ev['confirmar_hasta'] ?? '') ?>" max="<?= e($ev['fecha']) ?>"></div>
        <button class="boton boton-ancho" type="submit">Guardar</button>
    </form>
    <?php endif; ?>
</div>

<section class="bloque">
    <h2>Por enviar <small class="suave">(<?= count($sinEnviar) ?>)</small></h2>
    <?php if (!$cont['invitados']): ?>
        <p class="vacio">Primero agrega invitados en la lista.</p>
    <?php elseif (!$sinEnviar): ?>
        <p class="vacio">¡Listo! Ya se mandaron todas las invitaciones de los que no han respondido.</p>
    <?php else: ?>
    <p class="suave" style="font-size:.92rem">Toca “Invitar” en cada uno: se abre WhatsApp con el mensaje listo y queda marcado como enviado.</p>
    <ul class="filas">
        <?php foreach ($sinEnviar as $i): ?>
        <li><div><strong><?= e($i['nombre']) ?></strong><br><small><?= $i['telefono'] ? e($i['telefono']) : 'Sin celular: eliges el contacto en WhatsApp' ?></small></div>
            <?php if ($editable): ?>
            <form method="post" target="_blank" class="derecha" data-dia-enviado><?= $accion('enviar_wa') ?><input type="hidden" name="invitado_id" value="<?= (int) $i['id'] ?>">
                <button class="boton boton-wa boton-chico" type="submit">Invitar</button></form>
            <?php endif; ?></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</section>
<?php endif; ?>
