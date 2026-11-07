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
