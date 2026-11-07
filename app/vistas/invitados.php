<?php
use TuRenta\DiaEvento;

/** Invitados (sistema). @var array $ev @var string $tab @var array $negocio @var string $enlacePuerta @var string $enlacePortal @var array $cont */
$msgPuerta = "Hola, este es el control de ingreso de {$ev['nombre']} (" . fecha_corta($ev['fecha']) . ").\n"
           . "Ábrelo en el celular el día del evento: buscas al invitado por su nombre y marcas su ingreso. "
           . "Después puedes escanear los códigos QR de las invitaciones con la cámara.\n$enlacePuerta";
$msgPortal = "Hola {$ev['cliente']}, aquí pueden armar su lista de invitados desde el celular: agregar invitados, pegar la lista, "
           . "armar las mesas y mandar las invitaciones digitales por WhatsApp. Cada invitado confirma y recibe su código QR para la entrada.\n$enlacePortal";
?>
<?php require RAIZ . '/app/vistas/_evento_tabs.php'; ?>

<div class="rejilla rejilla-2 dia-enlaces-inv">
    <section class="bloque">
        <h2>Modo puerta</h2>
        <p class="suave">Para quien recibe en la entrada: buscador grande, marca el ingreso y lee los QR con la cámara del celular.</p>
        <div class="acciones">
            <a class="boton" href="<?= e($enlacePuerta) ?>" target="_blank" rel="noopener">Abrir modo puerta</a>
            <button type="button" class="boton boton-claro" data-copiar="<?= e($enlacePuerta) ?>">Copiar</button>
            <a class="boton boton-wa" href="<?= e(DiaEvento::wa(null, $msgPuerta)) ?>" target="_blank" rel="noopener">WhatsApp</a>
        </div>
    </section>
    <section class="bloque">
        <h2>Para los novios u organizadores</h2>
        <p class="suave">Que armen su lista, las mesas y manden las invitaciones desde su celular, sin usuario ni contraseña.</p>
        <div class="acciones">
            <a class="boton boton-wa" href="<?= e(DiaEvento::wa($ev['cliente_telefono'], $msgPortal)) ?>" target="_blank" rel="noopener">Mandar a <?= e(trim(explode(' ', (string) $ev['cliente'])[0])) ?></a>
            <button type="button" class="boton boton-claro" data-copiar="<?= e($enlacePortal) ?>">Copiar enlace</button>
        </div>
    </section>
</div>

<div class="acciones dia-descargas">
    <a class="boton boton-claro boton-chico" href="<?= e(url('pdf', ['tipo' => 'invitados', 'id' => $ev['id']])) ?>" target="_blank" rel="noopener">Lista (PDF)</a>
    <a class="boton boton-claro boton-chico" href="<?= e(url('pdf', ['tipo' => 'mesas', 'id' => $ev['id']])) ?>" target="_blank" rel="noopener">Plano de mesas (PDF)</a>
    <a class="boton boton-claro boton-chico" href="<?= e(url('plano', ['id' => $ev['id']])) ?>">Plano del salón</a>
    <a class="boton boton-claro boton-chico" href="<?= e(url('invitados', ['id' => $ev['id'], 'csv' => 1])) ?>">Descargar para Excel</a>
</div>

<?php require RAIZ . '/app/vistas/invitados_panel.php'; ?>
