<?php /** @var array $lista @var int $sinLeer */
$hoy = date('Y-m-d');
$ayer = date('Y-m-d', strtotime('-1 day'));
$grupo = fn(string $f) => match (substr($f, 0, 10)) { $hoy => 'Hoy', $ayer => 'Ayer', default => ucfirst(fecha_corta(substr($f, 0, 10))) };
$actual = null;
?>
<div class="cabecera">
    <div><h1>Avisos</h1>
        <p><?= $sinLeer ? "Tienes $sinLeer sin leer." : 'Estás al día.' ?> Aquí llegan los pagos, firmas, consultas nuevas y recordatorios.</p></div>
    <?php if ($sinLeer): ?>
        <form method="post" action="<?= e(url('notificaciones', ['accion' => 'leidas'])) ?>"><?= campo_csrf() ?>
            <button class="boton boton-claro" type="submit">Marcar todos como leídos</button></form>
    <?php endif; ?>
</div>
<section class="bloque">
    <?php if (!$lista): ?>
        <p class="vacio">Todavía no tienes avisos.<br>Cuando un cliente pague, firme o te escriba, lo verás aquí.</p>
    <?php else: foreach ($lista as $n):
        $g = $grupo((string) $n['creada_en']);
        if ($g !== $actual): $actual = $g; ?>
            <h2 style="font-size:.85rem;text-transform:uppercase;letter-spacing:.05em;color:var(--tinta-suave);margin:14px 0 2px"><?= e($g) ?></h2>
        <?php endif; ?>
        <div class="g-aviso <?= $n['leida'] ? 'leido' : 'nuevo' ?>">
            <span class="g-punto" aria-hidden="true"></span>
            <div class="g-aviso-texto"><?= e($n['texto']) ?><?= !$n['leida'] ? ' <span class="sr-only">(sin leer)</span>' : '' ?>
                <?php if ($n['evento_id']): ?><br><a class="enlace-chico" href="<?= e(url('evento', ['id' => $n['evento_id']])) ?>">Abrir evento <?= e($n['codigo'] ?? '') ?></a><?php endif; ?></div>
            <span class="g-aviso-cuando"><?= e(substr((string) $n['creada_en'], 11, 5)) ?></span>
        </div>
    <?php endforeach; endif; ?>
</section>
