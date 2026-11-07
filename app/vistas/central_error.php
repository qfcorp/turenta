<?php /** Error al entrar desde el Panel Tukán Central. @var string $mensaje @var string $urlCentral */ ?>
<div class="acceso-marca">
    <span class="marca-sello" aria-hidden="true">Tk</span>
    <div><h1>Panel Tukán</h1><p>Administración de TuRenta</p></div>
</div>
<section class="bloque acceso-caja">
    <h2>No se pudo entrar</h2>
    <p class="aviso aviso-error" role="alert"><?= e($mensaje) ?></p>
    <p>Vuelve al Panel Tukán y toca otra vez el botón de TuRenta.</p>
    <a class="boton boton-ancho" href="<?= e($urlCentral) ?>">← Volver al Panel Tukán</a>
</section>
