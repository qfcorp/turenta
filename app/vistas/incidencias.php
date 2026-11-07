<?php
use TuRenta\Incidencias;

/** @var array $lista @var string $estadoF @var array $filtros @var array $eventosSel @var array $provs @var array $comprasSel @var bool $conDinero @var bool $editable @var array $pre */
$estados = ['abiertas' => 'Abiertas y en proceso', 'resuelta' => 'Resueltas', 'todas' => 'Todas'];
?>
<div class="cabecera">
    <div>
        <h1>Incidencias</h1>
        <p>Los problemas de cada evento (proveedores, invitados, instalaciones…): quién lo reportó, quién lo resuelve y cómo quedó.</p>
    </div>
    <a class="boton boton-claro" href="<?= e(url('proveedores')) ?>">Proveedores</a>
</div>
<div class="rejilla rejilla-2">
    <section class="bloque">
        <form method="get" class="pv-filtros">
            <input type="hidden" name="r" value="incidencias">
            <div class="campo"><label for="fes">Ver</label><select id="fes" name="estado" data-autoenviar>
                <?php foreach ($estados as $k => $t): ?><option value="<?= e($k) ?>" <?= $estadoF === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
            <div class="campo"><label for="fev">Evento</label><select id="fev" name="evento" data-autoenviar><option value="0">Todos</option>
                <?php foreach ($eventosSel as $x): ?><option value="<?= (int) $x['id'] ?>" <?= $filtros['evento_id'] === (int) $x['id'] ? 'selected' : '' ?>><?= e(fecha_corta($x['fecha'], false)) ?> · <?= e($x['nombre']) ?></option><?php endforeach; ?></select></div>
            <div class="campo"><label for="fpr">Proveedor</label><select id="fpr" name="proveedor" data-autoenviar><option value="0">Todos</option>
                <?php foreach ($provs as $p): ?><option value="<?= (int) $p['id'] ?>" <?= $filtros['proveedor_id'] === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['nombre']) ?></option><?php endforeach; ?></select></div>
        </form>
        <?php if (!$lista): ?>
            <p class="vacio"><?= $estadoF === 'abiertas' ? 'No hay problemas abiertos. 👍' : 'No hay incidencias con ese filtro.' ?></p>
        <?php else: $listaInc = $lista; require RAIZ . '/app/vistas/_pv_incidencias.php'; ?>
        <?php endif; ?>
    </section>
    <?php if ($editable): $provsSel = $provs; require RAIZ . '/app/vistas/_pv_incidencia_form.php'; endif; ?>
</div>
