<?php
use TuRenta\{Eventos, WhatsApp};
/** @var array $clientes @var string $q */
?>
<div class="cabecera">
    <div>
        <h1>Clientes</h1>
        <p><?= $total ?> <?= $total === 1 ? 'cliente' : 'clientes' ?>. Se agregan solos al crear eventos o al llegar consultas de tu página.</p>
    </div>
</div>

<?php if ($puedeCrear): ?>
<details class="bloque" <?= $abrirNuevo ? 'open' : '' ?>>
    <summary class="boton">Nuevo cliente</summary>
    <form method="post" action="<?= e(url('clientes')) ?>" style="margin-top:14px" novalidate>
        <?= campo_csrf() ?>
        <?php if ($error): ?><p class="aviso aviso-error" role="alert"><?= e($error) ?></p><?php endif; ?>
        <div class="rejilla-form">
            <div class="campo">
                <label for="nombre">Nombre</label>
                <input type="text" id="nombre" name="nombre" value="<?= e($d['nombre']) ?>" required maxlength="150">
            </div>
            <div class="campo">
                <label for="telefono">Celular</label>
                <input type="tel" id="telefono" name="telefono" value="<?= e($d['telefono']) ?>" placeholder="0991234567">
            </div>
            <div class="campo">
                <label for="identificacion">Cédula o RUC <small>(opcional)</small></label>
                <input type="text" id="identificacion" name="identificacion" value="<?= e($d['identificacion']) ?>" inputmode="numeric" maxlength="13">
            </div>
            <div class="campo">
                <label for="email">Correo <small>(opcional)</small></label>
                <input type="email" id="email" name="email" value="<?= e($d['email']) ?>">
            </div>
            <div class="campo">
                <label for="origen">¿Cómo nos conoció?</label>
                <select id="origen" name="origen">
                    <option value="">— Elegir —</option>
                    <?php foreach (Eventos::ORIGENES as $k => $t): ?><option value="<?= e($k) ?>" <?= $d['origen'] === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
                </select>
            </div>
        </div>
        <button class="boton" type="submit">Guardar cliente</button>
    </form>
</details>
<?php endif; ?>

<form method="get" class="bloque" role="search">
    <input type="hidden" name="r" value="clientes">
    <label class="sr-only" for="q">Buscar cliente</label>
    <div class="fila-en-linea">
        <input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="Buscar por nombre, celular, cédula o correo">
        <button class="boton boton-claro" type="submit">Buscar</button>
    </div>
</form>

<section class="bloque">
    <?php if (!$clientes): ?>
        <div class="vacio">
            <?php if ($q !== ''): ?>
                <p>Nadie coincide con "<?= e($q) ?>".</p>
                <a class="boton boton-claro" href="<?= e(url('clientes')) ?>">Ver todos</a>
            <?php else: ?>
                <p><strong>Todavía no tienes clientes.</strong></p>
                <p>Se crean solos cuando registras un evento o cuando alguien te escribe desde tu página.</p>
                <?php if ($puedeCrear): ?><a class="boton" href="<?= e(url('evento_nuevo')) ?>">Registrar un evento</a><?php endif; ?>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <ul class="lista">
            <?php foreach ($clientes as $c):
                $wa = WhatsApp::enlace($c['telefono'], 'Hola ' . explode(' ', trim($c['nombre']))[0] . ', te saludamos de ' . $u['negocio'] . '. '); ?>
                <li>
                    <a href="<?= e(url('cliente', ['id' => $c['id']])) ?>" class="fila-enlace">
                        <span class="principal-linea"><?= e($c['nombre']) ?></span>
                        <span class="linea-sub" style="display:block">
                            <?= $c['telefono'] ? e($c['telefono']) : 'Sin celular' ?>
                            · <?= (int) $c['eventos'] ?> <?= (int) $c['eventos'] === 1 ? 'evento' : 'eventos' ?>
                            <?= $c['ultimo_evento'] ? '· último ' . e(fecha_corta($c['ultimo_evento'])) : '' ?>
                            <?= $c['origen'] ? '· ' . e(Eventos::ORIGENES[$c['origen']] ?? $c['origen']) : '' ?>
                        </span>
                    </a>
                    <?php if ($wa): ?>
                        <a class="boton boton-wa boton-chico" href="<?= e($wa) ?>" target="_blank" rel="noopener" aria-label="WhatsApp a <?= e($c['nombre']) ?>">WhatsApp</a>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php if (count($clientes) >= 300): ?><p class="suave">Se muestran los 300 más recientes. Usa la búsqueda para encontrar a alguien.</p><?php endif; ?>
    <?php endif; ?>
</section>
