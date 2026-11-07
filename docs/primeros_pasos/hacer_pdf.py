"""Primeros pasos de TuRenta (guía corta para el cliente, PDF A4).
Uso: python3 hacer_pdf.py   ->  ../TuRenta_Primeros_Pasos.pdf"""
import os
from playwright.sync_api import sync_playwright
D = os.path.dirname(os.path.abspath(__file__))
CAP = os.path.join(D, 'capturas')   # capturas reales del programa (datos de ejemplo)
u = lambda p: 'file://' + p
img = lambda n: u(os.path.join(CAP, n + '.png'))
NAVY, SAND, TEAL, GOLD = '#14213D', '#F5F1EA', '#2A9D8F', '#FFC93C'

def paso(n, titulo, donde, items, consejo=''):
    lis = ''.join(f'<li>{x}</li>' for x in items)
    c = f'<p class="tip"><b>Consejo:</b> {consejo}</p>' if consejo else ''
    return f'<div class="paso"><div class="num">{n}</div><div class="cuerpo"><h3>{titulo}</h3><p class="donde">{donde}</p><ol>{lis}</ol>{c}</div></div>'

css = f"""
@font-face{{font-family:SG;src:url('{u(D)}/fonts/space-grotesk-latin-700-normal.woff2')}}
@font-face{{font-family:DM;src:url('{u(D)}/fonts/dm-sans-latin-400-normal.woff2')}}
@page{{size:A4;margin:16mm 15mm 18mm}}
*{{box-sizing:border-box}} body{{font-family:DM,Arial,sans-serif;color:{NAVY};font-size:10.5pt;line-height:1.42;margin:0}}
h1{{font-family:SG;font-size:30pt;margin:6mm 0 1mm;letter-spacing:-.5px}} h2{{font-family:SG;font-size:15pt;margin:7mm 0 3mm;color:{NAVY};border-bottom:3px solid {TEAL};padding-bottom:1.5mm}}
h3{{font-family:SG;font-size:12pt;margin:0 0 .5mm}}
.top{{display:flex;justify-content:space-between;align-items:center}} .top img{{height:11mm}}
.chip{{display:inline-block;background:{GOLD};font-family:SG;font-size:9pt;padding:1mm 3mm;border-radius:99px}}
.lead{{font-size:11.5pt;color:#33405C;margin:1mm 0 0}}
.paso{{display:flex;gap:4mm;margin:0 0 4mm;break-inside:avoid}}
.num{{min-width:9mm;height:9mm;border-radius:2.5mm;background:{TEAL};color:#fff;font-family:SG;font-size:13pt;text-align:center;line-height:9mm}}
.cuerpo{{flex:1}} .donde{{margin:0 0 1mm;color:{TEAL};font-weight:bold;font-size:9.5pt}}
ol{{margin:0;padding-left:5mm}} li{{margin:.4mm 0}}
.tip{{background:{SAND};border-left:4px solid {GOLD};padding:1.5mm 3mm;margin:1.5mm 0 0;font-size:9.5pt}}
.box{{background:{SAND};border-radius:3mm;padding:3mm 4mm;margin:3mm 0;break-inside:avoid}}
.fotos{{display:flex;gap:4mm;justify-content:center;margin:2mm 0 4mm;break-inside:avoid}}
.fotos figure{{margin:0;text-align:center;font-size:8.5pt;color:#4A5470}}
.fotos img{{border:1px solid #DED8CC;border-radius:2mm}}
.fin{{background:{NAVY};color:#fff;border-radius:3mm;padding:4mm 5mm;margin-top:5mm;break-inside:avoid}}
.fin b{{color:{GOLD}}}
"""

h = []
h.append(f'''<div class="top"><img src="{u(D)}/logo_oscuro.png"><span class="chip">Guía rápida</span></div>
<h1>Primeros pasos en TuRenta</h1>
<p class="lead">Lo básico para empezar a vender y cobrar sus eventos hoy. Para todo lo demás está el <b>Manual de TuRenta</b>.</p>
<div class="box"><b>Antes de empezar:</b> lo que ve en el menú depende de su usuario (dueño, administrador, vendedor, coordinador o solo consulta) y de los módulos que contrató.
En el <b>celular</b>, los botones principales están abajo (Inicio, Calendario, Eventos, Cobros) y todo lo demás en <b>Más</b>.
La configuración se hace mejor en la <b>computadora</b>.</div>''')

h.append('<h2>Parte 1 · Deje listo su local (una sola vez, una tarde)</h2>')
h.append(paso(1, 'Entre al programa', 'La dirección, el correo y la contraseña se los enviamos nosotros', [
    'Abra la dirección de su local en el navegador (celular o computadora).',
    'Escriba su <b>Correo</b> y su <b>Contraseña</b>, y toque <b>Entrar</b>.',
    'Llega al <b>Inicio</b>: eventos de la semana, seguimientos de hoy, cuotas por cobrar y consultas nuevas.']))
h.append(paso(2, 'Datos del negocio y reglas', 'Menú Configuración → Negocio · Reglas', [
    'En <b>Negocio</b>: nombre comercial, razón social, RUC, teléfono, <b>WhatsApp del negocio</b>, correo, ciudad y dirección. Toque <b>Guardar datos del negocio</b> y suba su logo con <b>Guardar logo</b>.',
    'En <b>Reglas</b>: el IVA, los días que vale una cotización, la <b>Reserva para separar la fecha (%)</b>, el <b>Número de pagos</b> y cuántos días antes se paga el saldo. Toque <b>Guardar reglas</b>.']))
h.append(paso(3, 'Sus espacios', 'Menú Más → Espacios', [
    'Vienen dos de ejemplo (Salón principal y Jardín): toque <b>Editar</b> para poner los suyos, o <b>Nuevo espacio</b> para agregar otro.',
    'Ponga nombre, alquiler del espacio, capacidad, color en el calendario y una foto. Toque <b>Crear espacio</b> o <b>Guardar</b>. Cada espacio se reserva por separado: nunca se vende dos veces a la misma hora.',
    'Los días que no alquila, regístrelos en <b>Fechas cerradas</b> con <b>Cerrar fecha</b>.']))
h.append(paso(4, 'Catálogo y precios', 'Menú Más → Catálogo y precios', [
    'Ya viene un catálogo de ejemplo: cambie nombres y precios con <b>Editar</b>, o quite lo que no vende con <b>Desactivar</b>.',
    'Para algo nuevo toque <b>+ Agregar producto</b>: nombre, categoría, cómo <b>Se cobra</b> (por persona, por evento, por hora o por unidad), el <b>Precio al cliente</b> sin IVA y lo que le cuesta (eso solo lo ve usted). Toque <b>Agregar al catálogo</b>.']))
h.append(paso(5, 'Su equipo', 'Menú Más → Usuarios', [
    'En <b>Agregar a alguien</b>: nombre, correo (con ese entra), celular y <b>¿Qué va a hacer?</b>: administrador, vendedor, coordinador o solo consulta.',
    'Toque <b>Crear usuario</b>. El programa muestra una contraseña temporal <b>solo esta vez</b>: mándesela con <b>Enviar por WhatsApp</b>.',
    'El <b>Administrador (mismos permisos que el dueño)</b> maneja todo como usted, incluidos los usuarios y Mi plan. Puede tener hasta 4 y no cuentan en el límite de usuarios de su plan. Nadie puede cambiar ni desactivar su cuenta de dueño.',
    'El coordinador organiza el día del evento pero no ve el dinero; "solo consulta" mira todo sin cambiar nada (ideal para el contador).']))
h.append(f'''<div class="fotos"><figure><img src="{img('config')}" style="height:50mm"><figcaption>Configuración → Negocio</figcaption></figure>
<figure><img src="{img('catalogo')}" style="height:50mm"><figcaption>Catálogo y precios</figcaption></figure></div>''')

h.append('<h2>Parte 2 · El día a día</h2>')
h.append(paso(6, 'Anote cada consulta como un evento', 'Botón Nuevo evento (en el Inicio o en el Calendario)', [
    'Elija <b>Cliente nuevo</b> o <b>Ya está registrado</b>. Con nombre, celular y fecha basta para empezar.',
    'Ponga <b>¿Qué celebran?</b>, la fecha, la hora de inicio y fin, los invitados y el espacio. Toque <b>Crear evento</b>.',
    'El evento nace como <b>Consulta</b>. En el <b>Calendario</b>, el botón <b>Próximos sábados</b> le dice al instante qué sábados tiene libres.'],
    'Con el <b>Asistente IA</b> (incluido en el plan Completo): toque <b>✨ Desde un mensaje de WhatsApp</b>, pegue lo que le escribió el cliente y revise el borrador del evento y la cotización antes de guardarlo.'))
h.append(paso(7, 'Arme la cotización y envíela', 'En el evento → pestaña Cotización', [
    'Elija <b>Desde un paquete</b> (si contrató Paquetes) o <b>En blanco</b>, y toque <b>Crear cotización</b>.',
    'Agregue líneas con <b>Agregar del catálogo</b>, ponga un descuento si lo hay y revise los totales.',
    'Toque <b>Guardar y enviar por WhatsApp</b>: el cliente recibe un enlace para verla y aceptarla desde su celular.',
    'Si acepta por teléfono o en persona, abra <b>El cliente aceptó</b> y toque <b>Aceptar en nombre del cliente</b>. El plan de pagos se arma solo.']))
h.append(f'''<div class="fotos"><figure><img src="{img('evento_nuevo')}" style="height:58mm"><figcaption>Nuevo evento</figcaption></figure>
<figure><img src="{img('cotizacion')}" style="height:58mm"><figcaption>La cotización</figcaption></figure>
<figure><img src="{img('pagos')}" style="height:58mm"><figcaption>Pagos del evento</figcaption></figure></div>''')
h.append(paso(8, 'Contrato (si contrató el módulo de contratos)', 'En el evento → pestaña Contrato', [
    'Con la cotización aceptada, toque <b>Generar contrato</b>: se llena solo con los datos del cliente y del evento.',
    'Revise el texto y toque <b>Enviar para firmar por WhatsApp</b>. El cliente lo firma con el dedo en su celular y a usted le llega un aviso.']))
h.append(paso(9, 'Registre cada pago', 'En el evento → pestaña Pagos', [
    'Ahí ve el valor del evento, lo pagado, lo que falta y el <b>Plan de pagos</b> (reserva, cuotas y saldo).',
    'Toque <b>Registrar un pago</b>: valor recibido, forma de pago (efectivo, transferencia, DeUna…) y fecha. Toque <b>Guardar pago y generar recibo</b>.',
    'Cuando el cliente paga la reserva, la fecha queda <b>separada</b> y bloqueada para ese espacio.']))
h.append(paso(10, 'Cobre y dé seguimiento cada mañana', 'Botón Cobros · Menú Más → Mensajes por WhatsApp', [
    'En <b>Cobros</b> vea el total por cobrar, las <b>Cuotas vencidas</b>, lo que está <b>Por vencer</b> y los <b>Comprobantes por revisar</b> que suben los clientes: confírmelos solo después de ver el dinero en su banco.',
    'En <b>Mensajes por WhatsApp</b> están listos los recordatorios del día: toque <b>Enviar por WhatsApp</b> en cada uno.',
    'En el <b>Inicio</b> revise los <b>Seguimientos de hoy</b> (llamadas y visitas) y márquelos como <b>Hecho</b>.']))

h.append('''<div class="fin"><b>¿Listo? Ya puede trabajar.</b> Para paquetes, portal del cliente, día del evento, invitados con QR, proveedores, reportes y todo lo demás,
vea el <b>Manual de TuRenta</b>.<br><br>¿Cómo se hace algo? En <b>Más → Ayuda</b> escriba su pregunta y toque <b>Preguntar</b>: le respondemos al instante con el manual.<br>¿Dudas? Escríbanos por <b>WhatsApp 098 366 2181</b> · info@tukansoftware.com · tukansoftware.com</div>''')

html = f'<!doctype html><html lang="es"><head><meta charset="utf-8"><style>{css}</style></head><body>{"".join(h)}</body></html>'
open(f'{D}/primeros_pasos.html', 'w').write(html)
out = os.path.join(os.path.dirname(D), 'TuRenta_Primeros_Pasos.pdf')
with sync_playwright() as pw:
    b = pw.chromium.launch(); pg = b.new_page()
    pg.goto(u(f'{D}/primeros_pasos.html')); pg.wait_for_load_state('networkidle'); pg.evaluate('document.fonts.ready')
    pg.pdf(path=out, format='A4', print_background=True, display_header_footer=True, header_template='<span></span>',
           footer_template='<div style="font-size:8px;color:#4A5470;width:100%;text-align:center">TuRenta · Primeros pasos · página <span class="pageNumber"></span> de <span class="totalPages"></span></div>',
           margin={'top': '16mm', 'bottom': '18mm', 'left': '15mm', 'right': '15mm'})
    b.close()
print('ok', out)
