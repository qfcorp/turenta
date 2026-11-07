<?php /** @var array $d @var ?string $error @var array $listaPlanes @var ?array $pedido */ ?>
<div class="cabecera">
    <div><h1>Nueva demo</h1>
        <p>Créala por tu cliente. Se carga con datos de ejemplo y todos los módulos encendidos, y el sistema le genera una contraseña temporal que te muestra para que se la envíes.</p></div>
</div>
<form method="post" class="bloque" style="max-width:640px">
    <?= campo_csrf() ?>
    <?php if ($pedido): ?><input type="hidden" name="pedido" value="<?= (int) $pedido['id'] ?>">
        <p class="aviso aviso-ok">Con el pedido de demo N.º <?= (int) $pedido['id'] ?><?= $pedido['necesita'] ? ': «' . e(mb_substr($pedido['necesita'], 0, 200)) . '»' : '' ?></p><?php endif; ?>
    <?php if ($error): ?><p class="aviso aviso-error" role="alert"><?= e($error) ?></p><?php endif; ?>
    <div class="campo"><label for="negocio">Nombre del salón, quinta o hacienda</label>
        <input type="text" id="negocio" name="negocio" value="<?= e($d['negocio']) ?>" required></div>
    <div class="fila-campos">
        <div class="campo"><label for="nombre">Nombre del dueño</label>
            <input type="text" id="nombre" name="nombre" value="<?= e($d['nombre']) ?>" required></div>
        <div class="campo"><label for="telefono">Celular del dueño</label>
            <input type="tel" id="telefono" name="telefono" value="<?= e($d['telefono']) ?>" placeholder="0991234567"></div>
    </div>
    <div class="fila-campos">
        <div class="campo"><label for="email">Correo del dueño <small>(con este entra)</small></label>
            <input type="email" id="email" name="email" value="<?= e($d['email']) ?>" required></div>
        <div class="campo"><label for="ciudad">Ciudad</label>
            <input type="text" id="ciudad" name="ciudad" value="<?= e($d['ciudad']) ?>"></div>
    </div>
    <div class="campo"><label for="plan">Plan</label>
        <select id="plan" name="plan"><?php foreach ($listaPlanes as $p): ?><option value="<?= e($p['codigo']) ?>" <?= $d['plan'] === $p['codigo'] ? 'selected' : '' ?>><?= e($p['nombre']) ?> · <?= dinero($p['precio_mensual']) ?> al mes</option><?php endforeach; ?></select></div>
    <button class="boton boton-ancho" type="submit">Crear demo</button>
</form>
