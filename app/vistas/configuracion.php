<?php
use TuRenta\Eventos;
/** @var array $negocio @var string $tab @var array $tabs @var array $tareas @var string $enlacePagina @var string $enlaceIcal */
$n = $negocio;
$colores = ['#6B2D3C' => 'Vino', '#4A1E2A' => 'Vino oscuro', '#3E7C59' => 'Verde jardín', '#1F4E5F' => 'Azul petróleo', '#2B4A78' => 'Azul',
            '#7A5300' => 'Dorado', '#8A3D50' => 'Rosa vino', '#5B3A73' => 'Morado', '#2E2629' => 'Negro', '#B53D33' => 'Rojo'];
if (!isset($colores[strtoupper((string) $n['color'])])) $colores[strtoupper((string) $n['color'])] = 'Tu color';
$qrSvg = function (string $texto): string {
    try {
        if (class_exists(\TuRenta\Qr::class)) return \TuRenta\Qr::svg($texto, null, '#1F1A1C', 'Código QR de tu página');
        if (class_exists(\TCPDF2DBarcode::class)) {
            $svg = (new \TCPDF2DBarcode($texto, 'QRCODE,M'))->getBarcodeSVGcode(4, 4);
            return (string) preg_replace('/^.*?(<svg)/s', '$1', $svg);
        }
    } catch (\Throwable $e) { /* sin QR */ }
    return '';
};
$f = fn(string $k) => e($n[$k] ?? '');
$num = fn(string $k) => e(rtrim(rtrim(number_format((float) $n[$k], 2, '.', ''), '0'), '.') ?: '0');
?>
<div class="cabecera">
    <div><h1>Configuración</h1>
        <p>Los datos y reglas de <?= e($n['nombre']) ?>. Solo el dueño y los administradores ven esta pantalla.</p></div>
</div>

<nav class="g-tabs" aria-label="Secciones de la configuración">
    <?php foreach ($tabs as $k => $txt): ?>
        <a href="<?= e(url('configuracion', ['tab' => $k])) ?>" class="<?= $tab === $k ? 'activa' : '' ?>" <?= $tab === $k ? 'aria-current="page"' : '' ?>><?= e($txt) ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($tab === 'datos'): ?>
<div class="rejilla rejilla-2">
    <form method="post" action="<?= e(url('configuracion', ['tab' => 'datos', 'accion' => 'datos'])) ?>" class="bloque" style="grid-row:span 2">
        <?= campo_csrf() ?>
        <h2>Datos del negocio</h2>
        <p class="g-nota">Salen en tus cotizaciones, contratos, recibos y en tu página pública.</p>
        <div class="campo"><label for="nombre">Nombre comercial</label>
            <input type="text" id="nombre" name="nombre" value="<?= $f('nombre') ?>" required maxlength="120"></div>
        <div class="fila-campos">
            <div class="campo"><label for="razon_social">Razón social <small>(como dice tu RUC)</small></label>
                <input type="text" id="razon_social" name="razon_social" value="<?= $f('razon_social') ?>" maxlength="150"></div>
            <div class="campo"><label for="ruc">RUC</label>
                <input type="text" id="ruc" name="ruc" value="<?= $f('ruc') ?>" inputmode="numeric" maxlength="13" placeholder="1790000000001"></div>
        </div>
        <div class="fila-campos">
            <div class="campo"><label for="telefono">Teléfono</label>
                <input type="tel" id="telefono" name="telefono" value="<?= $f('telefono') ?>" placeholder="022345678"></div>
            <div class="campo"><label for="whatsapp">WhatsApp del negocio</label>
                <input type="tel" id="whatsapp" name="whatsapp" value="<?= $f('whatsapp') ?>" placeholder="0991234567">
                <small>Aquí te escriben los clientes desde tu página.</small></div>
        </div>
        <div class="fila-campos">
            <div class="campo"><label for="email">Correo</label>
                <input type="email" id="email" name="email" value="<?= $f('email') ?>"></div>
            <div class="campo"><label for="ciudad">Ciudad</label>
                <input type="text" id="ciudad" name="ciudad" value="<?= $f('ciudad') ?>" maxlength="80"></div>
        </div>
        <div class="campo"><label for="direccion">Dirección</label>
            <input type="text" id="direccion" name="direccion" value="<?= $f('direccion') ?>" maxlength="250" placeholder="Vía a Tumbaco km 5, junto a…"></div>
        <div class="campo"><label for="mapa_url">Enlace de Google Maps</label>
            <input type="url" id="mapa_url" name="mapa_url" value="<?= $f('mapa_url') ?>" placeholder="https://maps.app.goo.gl/...">
            <small>En Google Maps busca tu local → Compartir → Copiar enlace, y pégalo aquí.</small></div>
        <div class="fila-campos">
            <div class="campo"><label for="instagram">Instagram</label>
                <input type="text" id="instagram" name="instagram" value="<?= $n['instagram'] ? '@' . e(ltrim((string) $n['instagram'], '@')) : '' ?>" placeholder="@tuquinta"></div>
            <div class="campo"><label for="facebook">Facebook <small>(enlace)</small></label>
                <input type="url" id="facebook" name="facebook" value="<?= $f('facebook') ?>" placeholder="https://facebook.com/tuquinta"></div>
        </div>
        <div class="campo"><label for="resenas_url">Enlace para dejar reseña en Google</label>
            <input type="url" id="resenas_url" name="resenas_url" value="<?= $f('resenas_url') ?>" placeholder="https://g.page/r/...">
            <small>En tu perfil de Google → Pedir reseñas → copiar el enlace. Se usa en el mensaje de agradecimiento después del evento.</small></div>
        <div class="campo"><label for="descripcion">Descripción para tu página pública</label>
            <textarea id="descripcion" name="descripcion" rows="5" maxlength="3000" placeholder="Ej.: Quinta con jardines, capilla y salón para 300 personas, a 20 minutos de Quito. Bodas, quince años y eventos corporativos."><?= $f('descripcion') ?></textarea></div>
        <fieldset class="campo"><legend>Color de tu marca</legend>
            <div class="g-colores">
                <?php foreach ($colores as $hex => $nom): ?>
                    <label title="<?= e($nom) ?>"><input type="radio" name="color" value="<?= e($hex) ?>" <?= strtoupper((string) $n['color']) === $hex ? 'checked' : '' ?>>
                        <span style="background:<?= e($hex) ?>"></span><span class="sr-only"><?= e($nom) ?></span></label>
                <?php endforeach; ?>
            </div>
            <small>Se usa en tu página pública, cotizaciones y contratos.</small>
        </fieldset>
        <button class="boton boton-ancho" type="submit">Guardar datos del negocio</button>
    </form>

    <section class="bloque" id="logo">
        <h2>Logo</h2>
        <div class="g-logo-caja">
            <div id="g-logo-vista">
                <?php if ($n['logo']): ?><img class="g-logo" src="<?= e(url('archivo', ['tipo' => 'logo', 'id' => $n['id'], 'v' => substr((string) $n['logo'], 0, 6)])) ?>" alt="Tu logo">
                <?php else: ?><span class="g-logo-vacio" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($n['nombre'], 0, 1))) ?></span><?php endif; ?>
            </div>
            <form method="post" action="<?= e(url('configuracion', ['tab' => 'datos', 'accion' => 'logo'])) ?>" enctype="multipart/form-data" style="flex:1;min-width:200px">
                <?= campo_csrf() ?>
                <div class="campo"><label for="g-logo-archivo"><?= $n['logo'] ? 'Cambiar el logo' : 'Subir tu logo' ?></label>
                    <input type="file" id="g-logo-archivo" name="logo" accept="image/jpeg,image/png,image/webp" required>
                    <small>Foto JPG o PNG, de preferencia cuadrada y con fondo blanco.</small></div>
                <button class="boton" type="submit">Guardar logo</button>
            </form>
        </div>
        <?php if ($n['logo']): ?>
            <form method="post" action="<?= e(url('configuracion', ['tab' => 'datos', 'accion' => 'quitar_logo'])) ?>" style="margin-top:12px"><?= campo_csrf() ?>
                <button class="boton boton-claro boton-chico" type="submit" data-confirmar="¿Quitar el logo?">Quitar logo</button></form>
        <?php endif; ?>
    </section>
</div>

<?php elseif ($tab === 'reglas'): ?>
<form method="post" action="<?= e(url('configuracion', ['tab' => 'reglas', 'accion' => 'reglas'])) ?>" class="bloque">
    <?= campo_csrf() ?>
    <h2>Reglas comerciales</h2>
    <p class="g-nota">Se usan para las cotizaciones, el plan de pagos y la organización del día. Los cambios valen para lo nuevo; lo que ya está hecho no cambia.</p>
    <div class="rejilla-form">
        <div class="campo"><label for="iva_pct">IVA (%)</label>
            <input type="number" id="iva_pct" name="iva_pct" value="<?= $num('iva_pct') ?>" min="0" max="30" step="0.01" required></div>
        <div class="campo"><label for="validez_cotizacion">Días que vale una cotización</label>
            <input type="number" id="validez_cotizacion" name="validez_cotizacion" value="<?= (int) $n['validez_cotizacion'] ?>" min="1" max="120" required></div>
        <div class="campo"><label for="dias_retencion">Días que se retiene una fecha cotizada</label>
            <input type="number" id="dias_retencion" name="dias_retencion" value="<?= (int) $n['dias_retencion'] ?>" min="0" max="60" required>
            <small>Durante estos días la fecha aparece "retenida" (sin bloquearla). Si no pagan la reserva, se libera sola y te avisamos.</small></div>
        <div class="campo"><label for="reserva_pct">Reserva para separar la fecha (%)</label>
            <input type="number" id="reserva_pct" name="reserva_pct" value="<?= $num('reserva_pct') ?>" min="1" max="100" step="0.01" required>
            <small>Al pagar esto, la fecha queda separada y bloqueada.</small></div>
        <div class="campo"><label for="cuotas_defecto">Número de pagos</label>
            <input type="number" id="cuotas_defecto" name="cuotas_defecto" value="<?= (int) $n['cuotas_defecto'] ?>" min="1" max="24" required>
            <small>Contando la reserva y el saldo. Ej.: 3 = reserva, una cuota y el saldo.</small></div>
        <div class="campo"><label for="dias_saldo_antes">El saldo se paga hasta (días antes del evento)</label>
            <input type="number" id="dias_saldo_antes" name="dias_saldo_antes" value="<?= (int) $n['dias_saldo_antes'] ?>" min="0" max="120" required></div>
        <div class="campo"><label for="garantia_defecto">Garantía por daños ($)</label>
            <input type="number" id="garantia_defecto" name="garantia_defecto" value="<?= $num('garantia_defecto') ?>" min="0" step="0.01" required>
            <small>Se pide aparte y se devuelve después del evento. 0 = no se pide.</small></div>
        <div class="campo"><label for="hora_limite">Hora máxima de fin</label>
            <input type="time" id="hora_limite" name="hora_limite" value="<?= e(hora_corta($n['hora_limite'])) ?>" required>
            <small>La que permite la ordenanza de tu ciudad. Sale en el contrato.</small></div>
        <div class="campo"><label for="horas_montaje">Horas de montaje antes del evento</label>
            <input type="number" id="horas_montaje" name="horas_montaje" value="<?= $num('horas_montaje') ?>" min="0" max="24" step="0.5" required>
            <small>El espacio queda ocupado desde antes (decoración, cocina). Evita que dos eventos choquen.</small></div>
        <div class="campo"><label for="horas_desmontaje">Horas de desmontaje después</label>
            <input type="number" id="horas_desmontaje" name="horas_desmontaje" value="<?= $num('horas_desmontaje') ?>" min="0" max="24" step="0.5" required></div>
        <div class="campo"><label for="invitados_por_mesero">Invitados por mesero</label>
            <input type="number" id="invitados_por_mesero" name="invitados_por_mesero" value="<?= (int) $n['invitados_por_mesero'] ?>" min="1" max="200" required>
            <small>Para sugerir cuántos meseros necesita cada evento.</small></div>
        <div class="campo"><label for="meses_revisar_precio">Avisar para revisar el precio (meses antes)</label>
            <input type="number" id="meses_revisar_precio" name="meses_revisar_precio" value="<?= (int) $n['meses_revisar_precio'] ?>" min="1" max="36" required>
            <small>Si vendiste un evento con mucha anticipación, te avisamos cuando falten estos meses para que revises tus costos.</small></div>
        <div class="campo"><label for="comision_vendedor_pct">Comisión del vendedor (%)</label>
            <input type="number" id="comision_vendedor_pct" name="comision_vendedor_pct" value="<?= $num('comision_vendedor_pct') ?>" min="0" max="50" step="0.01" required>
            <small>Sobre lo vendido sin IVA. Se ve en Reportes. 0 = sin comisión.</small></div>
        <div class="campo ancho"><label for="condiciones">Condiciones que salen en la cotización</label>
            <textarea id="condiciones" name="condiciones" rows="7" maxlength="5000"><?= $f('condiciones') ?></textarea></div>
    </div>
    <button class="boton" type="submit">Guardar reglas</button>
</form>

<?php elseif ($tab === 'cobros'): ?>
<form method="post" action="<?= e(url('configuracion', ['tab' => 'cobros', 'accion' => 'cobros'])) ?>" class="bloque" autocomplete="off">
    <?= campo_csrf() ?>
    <h2>Cómo te pagan tus clientes</h2>
    <p class="g-nota">Esto lo ve tu cliente en su portal, junto con su plan de pagos. Ahí mismo puede subir la foto de su transferencia o pagar con tarjeta.</p>
    <div class="campo"><label for="pago_banco">Datos para transferencia</label>
        <textarea id="pago_banco" name="pago_banco" rows="4" maxlength="500" placeholder="Banco Pichincha · Cuenta de ahorros N.º 2200000000&#10;A nombre de: Arrayanes Eventos S.A. · RUC 1790000000001&#10;Correo: pagos@tuquinta.com"><?= $f('pago_banco') ?></textarea></div>
    <div class="campo"><label for="pago_deuna">Celular de DeUna</label>
        <input type="tel" id="pago_deuna" name="pago_deuna" value="<?= $f('pago_deuna') ?>" placeholder="0991234567" style="max-width:260px"></div>

    <?php if (modulo_activo($u, 'cobros_linea')): /* Anexo B: módulo "Cobros en línea (Payphone)" */ ?>
    <h2 style="margin-top:20px">Pago con tarjeta (Payphone)</h2>
    <?php $conPayphone = trim((string) $n['payphone_token']) !== '' && trim((string) $n['payphone_store_id']) !== ''; ?>
    <?php if ($conPayphone): ?><p class="aviso aviso-ok">✅ Payphone está activo: tus clientes pueden pagar con tarjeta desde su portal.</p><?php endif; ?>
    <div class="g-ayuda">
        <strong>¿Dónde saco estas claves?</strong>
        <ol>
            <li>Entra a <strong>payphone.app</strong> con tu cuenta de negocio (si no tienes, créala; Payphone revisa tus datos en 1 o 2 días).</li>
            <li>Ve a <strong>Developer</strong> (desarrolladores) → <strong>Configuración</strong> → crea una aplicación de tipo <strong>"WEB"</strong>.</li>
            <li>En "Dominio web" escribe <code><?= e(parse_url(url_completa('inicio'), PHP_URL_HOST) ?: 'turenta.tukanec.com') ?></code> y en "URL de respuesta" pega: <code><?= e(url_completa('payphone')) ?></code></li>
            <li>Copia el <strong>Token</strong> (es largo) y el <strong>Store ID</strong> y pégalos aquí.</li>
        </ol>
        Payphone cobra su comisión por cada pago y te deposita en tu cuenta.
    </div>
    <div class="fila-campos">
        <div class="campo"><label for="payphone_token">Token de Payphone</label>
            <input type="password" id="payphone_token" name="payphone_token" value="" placeholder="<?= $conPayphone ? '•••••• (guardado; déjalo vacío para no cambiarlo)' : 'Pega aquí el token' ?>" autocomplete="off"></div>
        <div class="campo"><label for="payphone_store_id">Store ID</label>
            <input type="text" id="payphone_store_id" name="payphone_store_id" value="<?= $f('payphone_store_id') ?>" autocomplete="off"></div>
    </div>
    <?php if ($conPayphone): ?>
        <div class="casillas" style="margin-bottom:14px"><label><input type="checkbox" name="quitar_payphone" value="1"> Quitar Payphone (dejar de cobrar con tarjeta)</label></div>
    <?php endif; ?>
    <?php else: ?>
    <input type="hidden" name="payphone_store_id" value="<?= $f('payphone_store_id') ?>">
    <?php endif; ?>
    <button class="boton" type="submit">Guardar datos de cobro</button>
</form>

<?php elseif ($tab === 'reserva'):
    $conStripe = \TuRenta\Stripe::claveValida(trim((string) $n['stripe_secret_key']));
    $pruebaStripe = str_contains((string) $n['stripe_secret_key'], '_test_');
    $enlaceReserva = url_completa('reservar', ['s' => $n['slug']]);
    $enlaceWebhook = url_completa('stripe_webhook', ['s' => $n['slug']]);
    $hm = fn(string $k) => e(substr((string) $n[$k], 0, 5)); ?>
<form class="bloque" method="post" action="<?= e(url('configuracion', ['tab' => 'reserva', 'accion' => 'reserva'])) ?>">
    <?= campo_csrf() ?>
    <h2>Reserva en línea</h2>
    <p class="g-nota">Tus clientes eligen un paquete, la fecha y una hora libre, y pagan el depósito o todo con tarjeta. Los paquetes que salen
        son los que marcas como <strong>«Se reserva en línea»</strong> en Paquetes, con sus equipos (espacios).</p>
    <div class="casillas" style="margin-bottom:12px"><label><input type="checkbox" name="reserva_en_linea" value="1" <?= $n['reserva_en_linea'] ? 'checked' : '' ?>> Abrir la reserva en línea</label></div>
    <?php if ($n['reserva_en_linea']): ?>
        <p class="aviso aviso-ok">Enlace para tus clientes y tu página web: <a href="<?= e($enlaceReserva) ?>" target="_blank" rel="noopener"><?= e($enlaceReserva) ?></a>
            <br><small>Para abrir un paquete ya elegido, agrega <code>&amp;p=</code> y el nombre corto del paquete (ej. <code>&amp;p=signature-experience</code>).</small></p>
    <?php endif; ?>
    <div class="fila-campos">
        <div class="campo"><label for="idioma_publico">Idioma de las páginas del cliente</label>
            <select id="idioma_publico" name="idioma_publico">
                <option value="es" <?= $n['idioma_publico'] !== 'en' ? 'selected' : '' ?>>Español</option>
                <option value="en" <?= $n['idioma_publico'] === 'en' ? 'selected' : '' ?>>Inglés (negocios de EE.UU.)</option>
            </select></div>
        <div class="campo"><label for="reserva_dias_min">Aviso mínimo (días)</label>
            <input type="number" id="reserva_dias_min" name="reserva_dias_min" min="0" max="60" value="<?= (int) $n['reserva_dias_min'] ?>"></div>
    </div>
    <div class="fila-campos">
        <div class="campo"><label for="reserva_hora_desde">Primera hora de inicio</label>
            <input type="time" id="reserva_hora_desde" name="reserva_hora_desde" value="<?= $hm('reserva_hora_desde') ?>"></div>
        <div class="campo"><label for="reserva_hora_hasta">Última hora de inicio</label>
            <input type="time" id="reserva_hora_hasta" name="reserva_hora_hasta" value="<?= $hm('reserva_hora_hasta') ?>"></div>
        <div class="campo"><label for="reserva_paso_min">Cada cuántos minutos</label>
            <input type="number" id="reserva_paso_min" name="reserva_paso_min" min="10" max="240" step="5" value="<?= (int) $n['reserva_paso_min'] ?>"></div>
    </div>
    <p class="g-nota">Antes y después de cada fiesta el equipo queda ocupado por el <strong>montaje y desmontaje</strong> (los de cada equipo en
        <strong>Espacios</strong>, o si no los tiene, las horas de <strong>Reglas</strong>) y, con el <strong>tiempo de viaje con mapa</strong> (abajo),
        también por el viaje desde tu base hasta la fiesta y de vuelta. El depósito es el <strong>% de reserva</strong> de Reglas.</p>
    <div class="campo"><label for="reserva_condiciones">Condiciones que acepta el cliente</label>
        <textarea id="reserva_condiciones" name="reserva_condiciones" rows="5" maxlength="5000" placeholder="Deposit is non-refundable within 7 days of the party. Rain dates…"><?= $f('reserva_condiciones') ?></textarea></div>
    <div class="fila-campos">
        <div class="campo"><label for="sitio_externo">Tu página web</label>
            <input type="url" id="sitio_externo" name="sitio_externo" value="<?= $f('sitio_externo') ?>" placeholder="https://tunegocio.com"></div>
        <div class="campo"><label for="logo_url">Logo (dirección de la imagen)</label>
            <input type="url" id="logo_url" name="logo_url" value="<?= $f('logo_url') ?>" placeholder="https://tunegocio.com/logo.webp"></div>
    </div>

    <h2 style="margin-top:20px">Pago con tarjeta (Stripe)</h2>
    <?php if ($conStripe): ?>
        <p class="aviso <?= $pruebaStripe ? 'aviso-error' : 'aviso-ok' ?>"><?= $pruebaStripe
            ? '🧪 Stripe está en MODO PRUEBA: nadie paga de verdad (tarjeta de prueba 4242 4242 4242 4242). Cambia a las claves «live» para cobrar.'
            : '✅ Stripe está activo y cobra de verdad.' ?></p>
    <?php endif; ?>
    <div class="g-ayuda">
        <strong>¿Dónde saco estas claves?</strong>
        <ol>
            <li>Entra a <strong>dashboard.stripe.com</strong> con la cuenta de Stripe del negocio.</li>
            <li>Ve a <strong>Developers</strong> (desarrolladores) → <strong>API keys</strong>. Copia la <strong>Secret key</strong> (empieza con <code>sk_test_</code> o <code>sk_live_</code>) y la <strong>Publishable key</strong> (<code>pk_…</code>).</li>
            <li>Ve a <strong>Developers → Webhooks → Add endpoint</strong> y pega esta dirección: <code><?= e($enlaceWebhook) ?></code></li>
            <li>Elige los eventos <code>checkout.session.completed</code>, <code>checkout.session.async_payment_succeeded</code> y <code>checkout.session.expired</code>. Guarda y copia el <strong>Signing secret</strong> (<code>whsec_…</code>).</li>
        </ol>
        Stripe cobra su comisión por cada pago y deposita en la cuenta bancaria del negocio.
    </div>
    <div class="fila-campos">
        <div class="campo"><label for="stripe_secret_key">Secret key</label>
            <input type="password" id="stripe_secret_key" name="stripe_secret_key" value="" autocomplete="off" placeholder="<?= $conStripe ? '•••••• (guardada; déjala vacía para no cambiarla)' : 'sk_test_…' ?>"></div>
        <div class="campo"><label for="stripe_publishable_key">Publishable key</label>
            <input type="text" id="stripe_publishable_key" name="stripe_publishable_key" value="<?= $f('stripe_publishable_key') ?>" autocomplete="off" placeholder="pk_test_…"></div>
    </div>
    <div class="campo"><label for="stripe_webhook_secret">Signing secret del webhook</label>
        <input type="password" id="stripe_webhook_secret" name="stripe_webhook_secret" value="" autocomplete="off" placeholder="<?= trim((string) $n['stripe_webhook_secret']) !== '' ? '•••••• (guardado)' : 'whsec_…' ?>"></div>
    <?php if ($conStripe): ?>
        <div class="casillas" style="margin-bottom:14px"><label><input type="checkbox" name="quitar_stripe" value="1"> Quitar Stripe (dejar de cobrar con tarjeta)</label></div>
    <?php endif; ?>
    <button class="boton" type="submit">Guardar reserva en línea y Stripe</button>
</form>

<?php $conGoogle = trim((string) ($n['google_maps_key'] ?? '')) !== ''; ?>
<form class="bloque" method="post" action="<?= e(url('configuracion', ['tab' => 'reserva', 'accion' => 'viaje'])) ?>" id="viaje">
    <?= campo_csrf() ?>
    <h2>Tiempo de viaje con mapa</h2>
    <p class="g-nota">Si llevas tus equipos a la casa del cliente, TuRenta calcula en el mapa cuánto se demora el viaje desde tu base hasta la
        dirección de la fiesta. Así cada fiesta ocupa el equipo solo lo necesario: una fiesta cerca deja libre más tiempo que una lejos.
        El cliente escribe su dirección antes de elegir la hora y solo ve las horas que alcanzan.</p>
    <div class="casillas" style="margin-bottom:12px"><label><input type="checkbox" name="viaje_mapa" value="1" <?= !empty($n['viaje_mapa']) ? 'checked' : '' ?>> Calcular el tiempo de viaje con el mapa</label></div>
    <?php if ($n['base_lat'] !== null && $n['base_lat'] !== ''): ?>
        <p class="aviso aviso-ok">📍 Tu base está marcada en el mapa:
            <a href="https://www.google.com/maps?q=<?= e((string) $n['base_lat']) ?>,<?= e((string) $n['base_lng']) ?>" target="_blank" rel="noopener">ver en Google Maps</a>.
            Revisa que el punto sea el correcto.</p>
    <?php endif; ?>
    <div class="campo"><label for="base_direccion">Dirección de tu base <small>(de donde salen los equipos)</small></label>
        <input type="text" id="base_direccion" name="base_direccion" maxlength="250" value="<?= $f('base_direccion') ?>" placeholder="2000 N Commerce Pkwy, Weston, FL 33326"></div>
    <div class="fila-campos">
        <div class="campo"><label for="base_lat">Latitud <small>(opcional)</small></label>
            <input type="text" id="base_lat" name="base_lat" inputmode="decimal" value="" placeholder="<?= $n['base_lat'] !== null && $n['base_lat'] !== '' ? e((string) $n['base_lat']) : '26.100512' ?>"></div>
        <div class="campo"><label for="base_lng">Longitud <small>(opcional)</small></label>
            <input type="text" id="base_lng" name="base_lng" inputmode="decimal" value="" placeholder="<?= $n['base_lng'] !== null && $n['base_lng'] !== '' ? e((string) $n['base_lng']) : '-80.398811' ?>"></div>
    </div>
    <p class="g-nota">Déjalas vacías y TuRenta busca tu dirección en el mapa. Si el punto sale mal, en Google Maps haz clic derecho sobre tu base,
        copia los dos números que aparecen arriba y pégalos aquí.</p>
    <div class="fila-campos">
        <div class="campo"><label for="viaje_extra_min">Margen de cada viaje (minutos)</label>
            <input type="number" id="viaje_extra_min" name="viaje_extra_min" min="0" max="120" value="<?= (int) ($n['viaje_extra_min'] ?? 10) ?>">
            <small>Se suma a la ida y a la vuelta: tráfico, gasolina, parqueo.</small></div>
        <div class="campo"><label for="viaje_max_min">Viaje más largo en línea (minutos)</label>
            <input type="number" id="viaje_max_min" name="viaje_max_min" min="5" max="600" value="<?= (int) ($n['viaje_max_min'] ?? 90) ?>">
            <small>Más lejos que esto, la página le pide al cliente que te llame.</small></div>
    </div>

    <h3 style="margin-top:16px">Clave de Google Maps <small>(opcional, recomendada)</small></h3>
    <p class="g-nota">Sin clave, TuRenta usa mapas gratuitos (calles reales, sin tráfico). Con la clave de Google usa el
        <strong>tráfico típico de ese día</strong>, igual que las rutas de TuGuardería.</p>
    <div class="g-ayuda">
        <strong>¿Cómo saco la clave?</strong>
        <ol>
            <li>Entra a <strong>console.cloud.google.com</strong> con la cuenta de Google del negocio y crea un proyecto (ej. «TuRenta»).</li>
            <li>En <strong>APIs y servicios → Biblioteca</strong>, busca y activa <strong>Routes API</strong> y <strong>Geocoding API</strong>.</li>
            <li>En <strong>APIs y servicios → Credenciales → Crear credenciales → Clave de API</strong>. Copia la clave (empieza con <code>AIza</code>).</li>
            <li>Recomendado: en la clave, <strong>Restricciones de API</strong> → deja solo Routes API y Geocoding API.</li>
        </ol>
        Google pide una tarjeta para el proyecto y regala un uso mensual; una reserva usa 2 consultas, y cada dirección se consulta una sola vez.
    </div>
    <div class="campo"><label for="google_maps_key">Clave de Google Maps</label>
        <input type="password" id="google_maps_key" name="google_maps_key" value="" autocomplete="off" placeholder="<?= $conGoogle ? '•••••• (guardada; déjala vacía para no cambiarla)' : 'AIza…' ?>"></div>
    <?php if ($conGoogle): ?>
        <div class="casillas" style="margin-bottom:14px"><label><input type="checkbox" name="quitar_google" value="1"> Quitar la clave de Google (usar los mapas gratuitos)</label></div>
    <?php endif; ?>
    <button class="boton" type="submit">Guardar tiempo de viaje</button>
</form>

<?php elseif ($tab === 'pagina'):
    $qr = $qrSvg($enlacePagina);
    $waTexto = 'Conoce ' . $n['nombre'] . ', mira nuestros espacios y consulta tu fecha aquí: ' . $enlacePagina; ?>
<div class="rejilla rejilla-2">
    <section class="bloque">
        <h2>Tu página pública</h2>
        <p>Una página con tus espacios, fotos y un formulario para que los novios consulten su fecha. Las consultas te llegan directo a TuRenta.</p>
        <div class="campo"><label for="enlace-pagina">Enlace de tu página</label>
            <div class="g-enlace-copiar">
                <input type="text" id="enlace-pagina" value="<?= e($enlacePagina) ?>" readonly data-seleccionar>
                <button type="button" class="boton boton-claro" data-copiar="<?= e($enlacePagina) ?>">Copiar</button>
            </div></div>
        <div class="acciones">
            <a class="boton" href="<?= e($enlacePagina) ?>" target="_blank" rel="noopener">Ver mi página</a>
            <a class="boton boton-wa" href="https://wa.me/?text=<?= e(rawurlencode($waTexto)) ?>" target="_blank" rel="noopener">Compartir por WhatsApp</a>
            <button type="button" class="boton boton-claro" data-compartir="<?= e($enlacePagina) ?>" data-titulo="<?= e($n['nombre']) ?>">Compartir…</button>
        </div>
        <div class="g-ayuda" style="margin-top:14px">
            <strong>Ideas para usarla</strong>
            <ul>
                <li>Ponla en la biografía de tu Instagram y en tu página de Facebook.</li>
                <li>Imprime el código QR en tus tarjetas, en la feria de bodas o en la entrada del local.</li>
                <li>Cámbiale la descripción, el logo y el color en la pestaña <a href="<?= e(url('configuracion', ['tab' => 'datos'])) ?>">Negocio</a>; las fotos, en <a href="<?= e(url('espacios')) ?>">Espacios</a>.</li>
            </ul>
        </div>
    </section>
    <section class="bloque" style="text-align:center">
        <h2>Código QR</h2>
        <?php if ($qr): ?>
            <div class="g-qr" style="margin:0 auto 10px"><?= $qr ?></div>
            <p class="g-nota">Con la cámara del celular se abre tu página. Para imprimirlo, haz una captura de pantalla o imprime esta página.</p>
            <button type="button" class="boton boton-claro" onclick="window.print()">Imprimir</button>
        <?php else: ?>
            <p class="vacio">No se pudo crear el código QR en este servidor.</p>
        <?php endif; ?>
    </section>
</div>

<?php elseif ($tab === 'tareas'): ?>
<div class="rejilla rejilla-2">
    <section class="bloque" style="grid-row:span 2">
        <h2>Lista de tareas de cada evento</h2>
        <p class="g-nota">Cuando creas un evento, estas tareas se copian solas con su fecha (ej. "Degustación" 60 días antes). Te avisamos cuando estén por vencer.
            Cambiarlas aquí no afecta a los eventos que ya existen.</p>
        <?php if (!$tareas): ?><p class="vacio">No hay tareas. Agrega la primera.</p><?php endif; ?>
        <?php foreach ($tareas as $t): $d = (int) $t['dias_antes']; ?>
            <div class="g-tarea-fila">
                <form method="post" action="<?= e(url('configuracion', ['tab' => 'tareas', 'accion' => 'tarea'])) ?>" class="g-tarea-campos" id="t<?= (int) $t['id'] ?>">
                    <?= campo_csrf() ?><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                    <label class="sr-only" for="tt<?= (int) $t['id'] ?>">Tarea</label>
                    <input type="text" id="tt<?= (int) $t['id'] ?>" name="titulo" value="<?= e($t['titulo']) ?>" maxlength="150" required>
                    <label class="sr-only" for="tp<?= (int) $t['id'] ?>">Para qué eventos</label>
                    <select id="tp<?= (int) $t['id'] ?>" name="tipo_evento">
                        <option value="">Todos los eventos</option>
                        <?php foreach (Eventos::TIPOS as $k => $txt): ?><option value="<?= $k ?>" <?= $t['tipo_evento'] === $k ? 'selected' : '' ?>><?= e($txt) ?></option><?php endforeach; ?>
                    </select>
                    <label class="sr-only" for="td<?= (int) $t['id'] ?>">Días antes</label>
                    <input type="number" id="td<?= (int) $t['id'] ?>" name="dias_antes" value="<?= $d ?>" min="-60" max="400" required title="Días antes del evento">
                </form>
                <div class="g-tarea-acciones">
                    <span class="g-dias-txt"><?= $d === 0 ? 'el mismo día' : ($d > 0 ? "$d días antes" : -$d . ' días después') ?></span>
                    <button class="boton boton-claro boton-chico" type="submit" form="t<?= (int) $t['id'] ?>">Guardar</button>
                    <form method="post" action="<?= e(url('configuracion', ['tab' => 'tareas', 'accion' => 'borrar_tarea'])) ?>"><?= campo_csrf() ?>
                        <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                        <button class="boton boton-peligro boton-chico" type="submit" data-confirmar="¿Quitar &quot;<?= e($t['titulo']) ?>&quot; de la plantilla?" aria-label="Quitar <?= e($t['titulo']) ?>">Quitar</button></form>
                </div>
            </div>
        <?php endforeach; ?>
    </section>
    <form method="post" action="<?= e(url('configuracion', ['tab' => 'tareas', 'accion' => 'tarea'])) ?>" class="bloque">
        <?= campo_csrf() ?>
        <h2>Agregar una tarea</h2>
        <div class="campo"><label for="nt-titulo">Tarea</label>
            <input type="text" id="nt-titulo" name="titulo" maxlength="150" required placeholder="Ej.: Prueba de peinado y maquillaje"></div>
        <div class="fila-campos">
            <div class="campo"><label for="nt-tipo">Para qué eventos</label>
                <select id="nt-tipo" name="tipo_evento"><option value="">Todos los eventos</option>
                    <?php foreach (Eventos::TIPOS as $k => $txt): ?><option value="<?= $k ?>"><?= e($txt) ?></option><?php endforeach; ?></select></div>
            <div class="campo"><label for="nt-dias">Días antes del evento</label>
                <input type="number" id="nt-dias" name="dias_antes" value="30" min="-60" max="400" required>
                <small>0 = el mismo día · negativo = después (ej. -8 para devolver la garantía).</small></div>
        </div>
        <button class="boton boton-ancho" type="submit">Agregar tarea</button>
    </form>
</div>

<?php elseif ($tab === 'calendario'): ?>
<div class="rejilla rejilla-2">
    <section class="bloque">
        <h2>Ver tus eventos en Google Calendar</h2>
        <p>Tus eventos separados, confirmados y las fechas retenidas aparecen solos en el calendario de tu celular. Se actualizan cada pocas horas (lo decide Google).</p>
        <div class="campo"><label for="enlace-ical">Tu enlace secreto del calendario</label>
            <div class="g-enlace-copiar">
                <input type="text" id="enlace-ical" class="g-secreto" value="<?= e($enlaceIcal) ?>" readonly data-seleccionar>
                <button type="button" class="boton boton-claro" data-copiar="<?= e($enlaceIcal) ?>">Copiar</button>
            </div>
            <small>No lo compartas en redes: quien lo tenga ve tus eventos (nombres de clientes, espacios e invitados, sin montos).</small></div>
        <div class="g-ayuda">
            <strong>Paso a paso (en una computadora)</strong>
            <ol>
                <li>Toca <strong>Copiar</strong> arriba.</li>
                <li>Abre <strong>calendar.google.com</strong> con tu cuenta de Google.</li>
                <li>A la izquierda, junto a <strong>"Otros calendarios"</strong>, toca el <strong>+</strong> y elige <strong>"Desde URL"</strong>.</li>
                <li>Pega el enlace y toca <strong>"Agregar calendario"</strong>.</li>
                <li>Listo: en unos minutos aparecen tus eventos, también en la app de Google Calendar del celular.</li>
            </ol>
            <p style="margin:8px 0 0"><strong>En iPhone:</strong> Ajustes → Calendario → Cuentas → Agregar cuenta → Otra → Agregar calendario suscrito → pega el enlace.</p>
        </div>
    </section>
    <section class="bloque">
        <h2>¿Alguien vio tu enlace?</h2>
        <p>Crea uno nuevo: el anterior deja de funcionar al instante. Después tendrás que agregar el nuevo en Google Calendar.</p>
        <form method="post" action="<?= e(url('configuracion', ['tab' => 'calendario', 'accion' => 'ical'])) ?>"><?= campo_csrf() ?>
            <button class="boton boton-peligro" type="submit" data-confirmar="¿Crear un enlace nuevo? El anterior dejará de funcionar en todos los calendarios donde lo agregaste.">Cambiar el enlace</button></form>
    </section>
</div>

<?php else: /* otros */ ?>
<div class="rejilla rejilla-2">
    <?php if (modulo_activo($u, 'contratos')): ?>
    <section class="bloque">
        <h2>Plantillas de contrato</h2>
        <p>El texto del contrato que firman tus clientes en el celular, con los datos del evento puestos solos.</p>
        <a class="boton" href="<?= e(url('contrato')) ?>">Editar plantillas de contrato</a>
    </section>
    <?php endif; ?>
    <?php if (modulo_activo($u, 'boda_vivo')): ?>
    <form method="post" action="<?= e(url('configuracion', ['tab' => 'otros', 'accion' => 'transmision'])) ?>" class="bloque">
        <?= campo_csrf() ?>
        <h2>Boda en vivo (transmisiones)</h2>
        <p class="g-nota">Cuando un cliente pide la transmisión en vivo de su evento, se escribe a este WhatsApp para coordinarla.
            Por defecto es el de QF Producciones.</p>
        <div class="campo"><label for="whatsapp_transmision">WhatsApp para transmisiones</label>
            <input type="tel" id="whatsapp_transmision" name="whatsapp_transmision" value="<?= $f('whatsapp_transmision') ?>" required style="max-width:260px"></div>
        <button class="boton" type="submit">Guardar</button>
    </form>
    <?php endif; ?>
    <section class="bloque">
        <h2>Catálogo y espacios</h2>
        <p>Tus precios, paquetes y espacios se cambian en sus propias pantallas.</p>
        <div class="acciones">
            <a class="boton boton-claro" href="<?= e(url('catalogo')) ?>">Catálogo y precios</a>
            <?php if (modulo_activo($u, 'paquetes')): ?><a class="boton boton-claro" href="<?= e(url('paquetes')) ?>">Paquetes</a><?php endif; ?>
            <a class="boton boton-claro" href="<?= e(url('espacios')) ?>">Espacios</a>
        </div>
        <?php if (!$n['precios_revisados']): ?>
            <form method="post" action="<?= e(url('configuracion', ['tab' => 'otros', 'accion' => 'precios_revisados'])) ?>" style="margin-top:12px"><?= campo_csrf() ?>
                <button class="boton boton-chico" type="submit">Ya revisé mis espacios y precios ✓</button></form>
        <?php endif; ?>
    </section>
    <section class="bloque">
        <h2>Usuarios y plan</h2>
        <div class="acciones">
            <a class="boton boton-claro" href="<?= e(url('usuarios')) ?>">Usuarios del equipo</a>
            <a class="boton boton-claro" href="<?= e(url('planes')) ?>">Mi plan de TuRenta</a>
        </div>
        <?php if ($n['pasos_ocultos']): ?>
            <form method="post" action="<?= e(url('configuracion', ['tab' => 'otros', 'accion' => 'ver_pasos'])) ?>" style="margin-top:12px"><?= campo_csrf() ?>
                <button class="boton boton-claro boton-chico" type="submit">Volver a ver los primeros pasos en el Inicio</button></form>
        <?php endif; ?>
    </section>
</div>
<?php endif; ?>
