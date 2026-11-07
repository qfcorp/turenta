<?php
use TuRenta\Pagos;
/**
 * Portal del cliente.
 * @var array $ev @var array $negocio @var string $token @var array $mod (módulos encendidos) @var bool $activo @var array $res @var array $historial
 * @var ?array $cotizacion @var ?array $contrato @var bool $payphone @var string $banco @var string $deuna
 * @var ?array $invitados @var array $cronograma @var ?string $waNegocio @var string $primerNombre
 */
$hoy = new DateTimeImmutable('today');
$fechaEv = new DateTimeImmutable($ev['fecha']);
$faltan = (int) $hoy->diff($fechaEv)->format('%r%a');
$inicio = $ev['fecha'] . 'T' . substr($ev['hora_inicio'], 0, 5) . ':00';
$estadosCliente = [
    'consulta' => 'Estamos preparando tu propuesta', 'cotizado' => 'Cotizado: la fecha se asegura con el pago de la reserva',
    'separado' => 'Fecha separada', 'confirmado' => 'Evento confirmado', 'realizado' => 'Evento realizado',
    'perdido' => 'Sin contratar', 'cancelado' => 'Cancelado',
];
$estadosCuota = ['pagada' => 'Pagada', 'parcial' => 'Abonada', 'pendiente' => 'Pendiente', 'vencida' => 'Vencida'];
$porRevisar = array_filter($historial, fn($p) => $p['estado'] === 'por_revisar');
$logo = $negocio['logo'] ? url('imagen_publica', ['tipo' => 'logo', 's' => $negocio['slug']]) : null;
$sugerido = $res['proxima'] ? (float) $res['proxima']['saldo'] : $res['saldo'];
$sugeridoTxt = number_format($sugerido, 2, ',', '');
?>
<header class="publico-cabeza cb-portal-cabeza">
    <?php if ($logo): ?><img class="pub-logo cb-logo" src="<?= e($logo) ?>" alt="Logo de <?= e($negocio['nombre']) ?>">
    <?php else: ?><span class="marca-sello" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($negocio['nombre'], 0, 1))) ?></span><?php endif; ?>
    <div><h1><?= e($negocio['nombre']) ?></h1><p>Portal de tu evento</p></div>
</header>

<section class="pub-hero cb-hero">
    <p class="cb-saludo">Hola, <?= e($primerNombre) ?> 👋</p>
    <h2 class="cb-hero-titulo"><?= e($ev['nombre']) ?></h2>
    <p class="cb-hero-fecha"><?= e(ucfirst(fecha_larga($ev['fecha']))) ?> · <?= e(hora_corta($ev['hora_inicio'])) ?> a <?= e(hora_corta($ev['hora_fin'])) ?></p>
    <?php if ($activo && $faltan >= 0): ?>
        <div class="cb-cuenta" data-cb-cuenta="<?= e($inicio) ?>" aria-live="off">
            <?php if ($faltan === 0): ?><strong>¡Hoy es el gran día!</strong>
            <?php else: ?><span><strong data-cb-dias><?= $faltan ?></strong> <small><?= $faltan === 1 ? 'día' : 'días' ?></small></span>
                <span><strong data-cb-horas>–</strong> <small>horas</small></span><span><strong data-cb-min>–</strong> <small>min</small></span><?php endif; ?>
        </div>
    <?php endif; ?>
    <p class="cb-estado-cliente"><span class="ev-estado ev-<?= e($ev['estado']) ?>"><?= e($estadosCliente[$ev['estado']]) ?></span></p>
</section>

<?php if (!$activo): ?>
    <p class="aviso aviso-error" role="alert"><?= $ev['estado'] === 'cancelado' ? 'Este evento fue cancelado.' : 'Este evento no se llegó a contratar.' ?>
        Si crees que es un error, escríbele a <?= e($negocio['nombre']) ?>.</p>
<?php endif; ?>

<section class="bloque">
    <h2>Tu evento</h2>
    <dl class="ficha-datos cb-ficha">
        <dt>Fecha</dt><dd><?= e(ucfirst(fecha_larga($ev['fecha']))) ?></dd>
        <dt>Hora</dt><dd><?= e(hora_corta($ev['hora_inicio'])) ?> a <?= e(hora_corta($ev['hora_fin'])) ?></dd>
        <?php if ($ev['espacios_texto']): ?><dt>Lugar</dt><dd><?= e($ev['espacios_texto']) ?></dd><?php endif; ?>
        <dt>Invitados</dt><dd><?= (int) $ev['invitados'] ?></dd>
        <dt>Código</dt><dd><?= e($ev['codigo']) ?></dd>
        <?php if ($negocio['direccion']): ?><dt>Dirección</dt><dd><?= e($negocio['direccion']) ?><?= $negocio['ciudad'] ? ', ' . e($negocio['ciudad']) : '' ?>
            <?php if ($negocio['mapa_url']): ?><br><a href="<?= e($negocio['mapa_url']) ?>" target="_blank" rel="noopener">Ver en el mapa</a><?php endif; ?></dd><?php endif; ?>
    </dl>
</section>

<?php require RAIZ . '/app/vistas/_pt_portal.php'; // formulario del evento, extras y encuesta (migración 070; módulo cuestionarios) ?>

<section class="bloque" id="documentos">
    <h2>Tus documentos</h2>
    <?php if (!$cotizacion && !$contrato): ?>
        <p class="vacio">Aquí verás tu cotización y tu contrato cuando estén listos.</p>
    <?php else: ?>
    <ul class="lista">
        <?php if ($cotizacion): ?>
        <li><div><span class="principal-linea">Cotización N.º <?= (int) $cotizacion['numero'] ?></span>
                <div class="linea-sub"><?= dinero($cotizacion['total']) ?> · <?= $cotizacion['estado'] === 'aceptada' ? 'Aceptada' : 'Por aceptar' ?></div></div>
            <a class="boton <?= $cotizacion['estado'] === 'aceptada' ? 'boton-claro' : '' ?> boton-chico" href="<?= e(url('cot', ['t' => $cotizacion['token']])) ?>"><?= $cotizacion['estado'] === 'aceptada' ? 'Ver' : 'Ver y aceptar' ?></a></li>
        <?php endif; ?>
        <?php if ($contrato): ?>
        <li><div><span class="principal-linea">Contrato N.º <?= (int) $contrato['numero'] ?></span>
                <div class="linea-sub"><?= $contrato['estado'] === 'firmado' ? 'Firmado el ' . e(fecha_corta($contrato['firmado_en'])) : 'Listo para que lo firmes desde tu celular' ?></div></div>
            <?php if ($contrato['estado'] === 'firmado'): ?>
                <span class="cb-cobro-botones"><a class="boton boton-claro boton-chico" href="<?= e(url('portal', ['t' => $token, 'contrato' => 'pdf'])) ?>" target="_blank">PDF</a>
                <a class="boton boton-claro boton-chico" href="<?= e(url('verificar', ['c' => $contrato['codigo_verificacion']])) ?>">Verificar</a></span>
            <?php else: ?>
                <a class="boton boton-chico" href="<?= e(url('firmar', ['t' => $contrato['token']])) ?>">Firmar contrato</a>
            <?php endif; ?></li>
        <?php endif; ?>
        <?php require RAIZ . '/app/vistas/_pt_anexos_portal.php'; // anexos del contrato (migración 090) ?>
    </ul>
    <?php endif; ?>
</section>

<section class="bloque" id="pagos">
    <h2>Tus pagos</h2>
    <?php if ($res['total'] <= 0): ?>
        <p class="vacio">Tu plan de pagos aparece aquí cuando aceptes la cotización.</p>
    <?php else: ?>
        <div class="resumen-cobro">
            <span>Valor del evento</span><strong><?= dinero($res['total']) ?></strong>
            <span>Pagado</span><strong class="cb-verde"><?= dinero($res['pagado']) ?></strong>
            <span>Falta pagar</span><strong class="grande"><?= dinero($res['saldo']) ?></strong>
        </div>
        <div class="barra" role="progressbar" aria-valuenow="<?= (int) $res['pct'] ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Avance de pagos"><span style="width:<?= (int) $res['pct'] ?>%"></span></div>
        <p class="suave cb-pct"><?= (int) $res['pct'] ?> % pagado<?= $res['saldo'] <= 0 ? ' · ¡Gracias, tu evento está pagado por completo!' : '' ?></p>
        <?php if ($porRevisar): ?><p class="aviso aviso-ok">✅ Recibimos tu comprobante de <?= dinero(array_sum(array_map(fn($p) => (float) $p['monto'], $porRevisar))) ?>. El local lo está revisando; cuando lo confirme, se suma a lo pagado.</p><?php endif; ?>

        <?php if ($res['cuotas']): ?>
        <h3>Plan de pagos</h3>
        <ul class="lista cb-cuotas-portal">
            <?php foreach ($res['cuotas'] as $c): ?>
            <li><div><span class="principal-linea"><?= e($c['concepto']) ?></span><div class="linea-sub">Hasta el <?= e(fecha_corta($c['vence'])) ?></div></div>
                <div class="derecha"><span class="monto"><?= dinero($c['monto']) ?></span><br>
                    <span class="cb-etq cb-etq-<?= e($c['estado']) ?>"><?= $estadosCuota[$c['estado']] ?><?= $c['estado'] === 'parcial' ? ' · faltan ' . dinero($c['saldo']) : '' ?></span></div></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php if ($activo && $res['saldo'] > 0 && $res['total'] > 0 && ($banco || $deuna || $payphone)): ?>
<section class="bloque" id="pagar">
    <h2>Pagar</h2>
    <?php if ($res['proxima']): ?><p>Tu próximo pago es <strong><?= e($res['proxima']['concepto']) ?></strong> por <strong><?= dinero($res['proxima']['saldo']) ?></strong>, hasta el <?= e(fecha_corta($res['proxima']['vence'])) ?>.</p><?php endif; ?>
    <?php if ($payphone): ?>
        <form method="post" action="<?= e(url('portal_pagar', ['t' => $token, 'accion' => 'tarjeta'])) ?>" class="cb-tarjeta">
            <?= campo_csrf() ?>
            <h3>💳 Con tarjeta de crédito o débito</h3>
            <div class="campo"><label for="pp_monto">¿Cuánto quieres pagar? ($)</label>
                <input type="text" inputmode="decimal" id="pp_monto" name="monto" value="<?= e($sugeridoTxt) ?>" required>
                <small class="suave">Máximo <?= dinero($res['saldo']) ?>.</small></div>
            <button class="boton boton-ancho" type="submit">Pagar con tarjeta</button>
        </form>
    <?php endif; ?>

    <?php if ($banco || $deuna): ?>
        <h3 class="cb-separado">Por transferencia o DeUna</h3>
        <?php if ($banco): ?><p class="cb-datos-banco"><?= e($banco) ?></p><?php endif; ?>
        <?php if ($deuna): ?><p>DeUna: <strong><?= e($deuna) ?></strong> <button type="button" class="boton boton-claro boton-chico" data-copiar="<?= e($deuna) ?>">Copiar</button></p><?php endif; ?>
        <p class="suave">Cuando pagues, sube aquí la foto o captura del comprobante.</p>
        <form method="post" action="<?= e(url('portal_pagar', ['t' => $token, 'accion' => 'comprobante'])) ?>" enctype="multipart/form-data">
            <?= campo_csrf() ?>
            <div class="fila-campos">
                <div class="campo"><label for="c_monto">¿Cuánto pagaste? ($)</label><input type="text" inputmode="decimal" id="c_monto" name="monto" value="<?= e($sugeridoTxt) ?>" required></div>
                <div class="campo"><label for="c_metodo">¿Cómo pagaste?</label>
                    <select id="c_metodo" name="metodo"><?php if ($banco): ?><option value="transferencia">Transferencia</option><?php endif; ?><?php if ($deuna): ?><option value="deuna">DeUna</option><?php endif; ?></select></div>
            </div>
            <div class="campo"><label for="c_foto">Foto o captura del comprobante</label><input type="file" id="c_foto" name="comprobante" accept="image/*" required></div>
            <div class="campo"><label for="c_ref">Número de comprobante <small>(opcional)</small></label><input type="text" id="c_ref" name="referencia" maxlength="100"></div>
            <button class="boton boton-ancho" type="submit">Enviar comprobante</button>
        </form>
    <?php endif; ?>
</section>
<?php elseif ($activo && $res['saldo'] > 0 && $res['total'] > 0): ?>
<section class="bloque" id="pagar">
    <h2>Pagar</h2>
    <p>Para pagar, escríbele a <?= e($negocio['nombre']) ?> y te enviará los datos.</p>
</section>
<?php endif; ?>

<?php if ($historial): ?>
<section class="bloque" id="historial">
    <h2>Historial de pagos</h2>
    <ul class="lista">
        <?php foreach ($historial as $p): $neg = in_array($p['tipo'], ['devolucion_garantia', 'cargo_danos'], true); ?>
        <li><div><span class="principal-linea"><?= e(Pagos::TIPOS[$p['tipo']]) ?></span>
                <div class="linea-sub"><?= e(fecha_corta($p['fecha'])) ?> · <?= e(Pagos::METODOS[$p['metodo']]) ?>
                    <?= $p['estado'] === 'por_revisar' ? ' · <span class="cb-etq cb-etq-por_revisar">En revisión</span>' : '' ?>
                    <?= $p['estado'] === 'rechazado' ? ' · <span class="cb-etq cb-etq-rechazado">No se pudo validar</span>' : '' ?>
                    <?= $p['tipo'] === 'cargo_danos' && $p['notas'] ? ' · ' . e($p['notas']) : '' ?></div></div>
            <div class="derecha"><span class="monto <?= $p['estado'] === 'rechazado' ? 'cb-tachado' : '' ?>"><?= $neg ? '−' : '' ?><?= dinero($p['monto']) ?></span>
                <?php if ($p['estado'] === 'confirmado' && $p['numero_recibo']): ?><br><a class="enlace-chico" href="<?= e(url('portal', ['t' => $token, 'recibo' => $p['id']])) ?>" target="_blank">Recibo PDF</a><?php endif; ?></div></li>
        <?php endforeach; ?>
    </ul>
    <?php if (array_filter($historial, fn($p) => $p['estado'] === 'rechazado')): ?><p class="suave">Si un comprobante no se pudo validar, revisa los datos y sube otro, o escríbele al local.</p><?php endif; ?>
</section>
<?php endif; ?>

<?php if ($res['garantia']['pedida'] > 0 || $res['garantia']['recibida'] > 0): $g = $res['garantia']; ?>
<section class="bloque">
    <h2>Garantía por daños</h2>
    <p class="suave">Es un depósito aparte, que se devuelve después del evento si no hay daños.</p>
    <dl class="ficha-datos"><dt>Garantía</dt><dd><?= dinero($g['pedida']) ?></dd><dt>Entregada</dt><dd><?= dinero($g['recibida']) ?></dd>
        <?php if ($g['devuelta'] > 0): ?><dt>Te devolvimos</dt><dd><?= dinero($g['devuelta']) ?></dd><?php endif; ?>
        <?php if ($g['retenida'] > 0): ?><dt>Retenido por daños</dt><dd><?= dinero($g['retenida']) ?></dd><?php endif; ?></dl>
</section>
<?php endif; ?>

<?php if ($mod['invitados'] && $activo): ?>
<section class="bloque" id="invitados">
    <h2>Tus invitados</h2>
    <?php if ((int) $invitados['registros'] > 0): ?>
        <div class="cifras cb-cifras-portal">
            <div class="cifra-tarjeta bien"><small>Confirmados</small><strong><?= (int) $invitados['confirmados'] ?></strong></div>
            <div class="cifra-tarjeta"><small>Por responder</small><strong><?= (int) $invitados['pendientes'] ?></strong></div>
            <div class="cifra-tarjeta"><small>En la lista</small><strong><?= (int) $invitados['pases'] ?></strong></div>
            <?php if ((int) $invitados['no_asisten'] > 0): ?><div class="cifra-tarjeta"><small>No asisten</small><strong><?= (int) $invitados['no_asisten'] ?></strong></div><?php endif; ?>
        </div>
    <?php else: ?>
        <p>Carga tu lista de invitados: cada uno recibe su invitación con QR para confirmar y entrar el día del evento.</p>
    <?php endif; ?>
    <a class="boton boton-ancho" href="<?= e(url('portal_invitados', ['t' => $ev['token_cliente']])) ?>"><?= (int) $invitados['registros'] > 0 ? 'Ver y editar mi lista de invitados' : 'Cargar mi lista de invitados' ?></a>
</section>
<?php endif; ?>

<?php if ($mod['dia_evento'] && $activo): ?>
<section class="bloque" id="cronograma">
    <h2>Cronograma del evento</h2>
    <?php if ($cronograma): ?>
        <ol class="cb-cronograma">
            <?php foreach ($cronograma as $c): ?><li><span class="hora"><?= e(hora_corta($c['hora'])) ?></span><span><?= e($c['actividad']) ?> <small class="suave">· <?= (int) $c['duracion_min'] ?> min</small></span></li><?php endforeach; ?>
        </ol>
        <p class="suave">¿Quieres cambiar algo? Escríbele al local.</p>
    <?php else: ?>
        <p class="vacio">El local está preparando el cronograma (ceremonia, cóctel, entrada, vals, cena, baile…). Aparecerá aquí.</p>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($mod['boda_vivo'] && $activo && $ev['transmision_url']): ?>
<section class="bloque cb-vivo">
    <h2>🔴 Transmisión en vivo</h2>
    <p>Comparte este enlace con la familia que no puede asistir para que vea el evento desde cualquier lugar.</p>
    <div class="acciones">
        <a class="boton" href="<?= e($ev['transmision_url']) ?>" target="_blank" rel="noopener">Ver en vivo</a>
        <button type="button" class="boton boton-claro" data-copiar="<?= e($ev['transmision_url']) ?>">Copiar enlace</button>
    </div>
</section>
<?php endif; ?>

<section class="bloque cb-contacto">
    <h2>¿Tienes alguna duda?</h2>
    <p>Escríbele a <?= e($negocio['nombre']) ?><?= $negocio['telefono'] ? ' o llama al ' . e($negocio['telefono']) : '' ?>.</p>
    <div class="acciones">
        <?php if ($waNegocio): ?><a class="boton boton-wa" href="<?= e($waNegocio) ?>" target="_blank" rel="noopener">Escribir por WhatsApp</a><?php endif; ?>
        <?php if ($negocio['telefono']): ?><a class="boton boton-claro" href="tel:<?= e(preg_replace('/[^0-9+]/', '', $negocio['telefono'])) ?>">Llamar</a><?php endif; ?>
    </div>
    <p class="suave cb-privado">Este enlace es solo para ti: no lo compartas en redes sociales.</p>
</section>
