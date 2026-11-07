#!/usr/bin/env python3
"""Capturas reales de TuRenta con el local de ejemplo (docs/manual/generar/datos_ejemplo.php) para el manual.
Uso: python3 docs/manual/generar/capturas.py --base http://127.0.0.1:8241 --salida docs/manual/capturas [--solo nombre1,nombre2]
Entra como dueño, coordinador, cliente, invitado, personal y proveedor, y guarda cada pantalla en celular (390 px) o computadora (1280 px).
Lee los enlaces secretos (portal, cotización, contrato, invitación…) de la base con psql (TURENTA_DB_NAME, TURENTA_DB_USER, TURENTA_DB_PASS)."""
import argparse, os, subprocess
from pathlib import Path
from playwright.sync_api import sync_playwright

ap = argparse.ArgumentParser()
ap.add_argument('--base', required=True)
ap.add_argument('--salida', required=True)
ap.add_argument('--clave', default='EventoDemo2026')
ap.add_argument('--solo', default='')
a = ap.parse_args()
SAL = Path(a.salida); SAL.mkdir(parents=True, exist_ok=True)
SOLO = set(x for x in a.solo.split(',') if x)
B = a.base.rstrip('/')
CORREOS = {'dueno': 'demo@turenta.tukanec.com', 'coordinador': 'carla@turenta.tukanec.com'}

# (archivo, quién entra, ruta, ancho, acción opcional antes de la foto)
PANTALLAS = [
    ('01_login_celular', 'nadie', '/?r=login', 390, None),
    ('02_inicio_compu', 'dueno', '/?r=inicio', 1280, None),
    ('03_inicio_celular', 'dueno', '/?r=inicio', 390, 'bajar:.g-cifras'),
    ('04_calendario', 'dueno', '/?r=calendario&a={anio_sig}&m={mes_sig}', 1280, None),
    ('05_evento_nuevo', 'dueno', '/?r=evento_nuevo', 1280, 'llenar_evento'),
    ('06_evento_resumen', 'dueno', '/?r=evento&id={boda}', 1280, None),
    ('07_seguimiento', 'dueno', '/?r=seguimiento', 1280, None),
    ('08_mensajes', 'dueno', '/?r=mensajes', 1280, None),
    ('09_cotizacion_armar', 'dueno', '/?r=cotizacion&id={quince}', 1280, None),
    ('10_cotizacion_cliente_celular', 'nadie', '/?r=cot&t={tok_cot}', 390, None),
    ('11_contrato', 'dueno', '/?r=contrato&id={boda}', 1280, None),
    ('12_firmar_celular', 'nadie', '/?r=firmar&t={tok_contrato}', 390, 'firmar'),
    ('13_pagos_evento', 'dueno', '/?r=evento_pagos&id={boda}', 1280, 'bajar:.tabla'),
    ('14_cobros', 'dueno', '/?r=cobros', 1280, None),
    ('15_portal_celular', 'nadie', '/?r=portal&t={tok_portal}', 390, None),
    ('16_formulario_celular', 'nadie', '/?r=portal_cuestionario&t={tok_portal}', 390, None),
    ('17_extras_local', 'dueno', '/?r=evento_formulario&id={boda}', 1280, 'bajar_texto:Extras que pidió el cliente'),
    ('18_dia_evento', 'dueno', '/?r=evento_dia&id={boda}', 1280, None),
    ('19_vivo_celular', 'nadie', '/?r=vivo&t={tok_vivo}', 390, 'empezar'),
    ('20_personal_celular', 'nadie', '/?r=staff&t={tok_staff}', 390, None),
    ('21_invitados', 'dueno', '/?r=invitados&id={boda}', 1280, None),
    ('22_invitacion_celular', 'nadie', '/?r=invitacion&t={tok_invitado}', 390, None),
    ('23_plano_evento', 'dueno', '/?r=plano&id={boda}', 1280, 'esperar_plano'),
    ('24_proveedores', 'dueno', '/?r=proveedores', 1280, None),
    ('25_proveedor_ficha', 'dueno', '/?r=proveedor&id={dj}', 1280, None),
    ('26_por_pagar', 'dueno', '/?r=por_pagar', 1280, None),
    ('27_portal_proveedor_celular', 'nadie', '/?r=portal_proveedor&t={tok_proveedor}', 390, None),
    ('28_reportes', 'dueno', '/?r=reportes&p=anio', 1280, None),
    ('29_configuracion', 'dueno', '/?r=configuracion', 1280, None),
    ('30_espacios', 'dueno', '/?r=espacios', 1280, None),
    ('31_catalogo', 'dueno', '/?r=catalogo', 1280, None),
    ('32_usuarios', 'dueno', '/?r=usuarios', 1280, None),
    ('33_mi_plan_modulos', 'dueno', '/?r=planes', 1280, 'modulos'),
    ('34_pagina_publica_celular', 'nadie', '/?r=sitio&s=quinta-demo-tukanec', 390, None),
    ('35_coordinador_celular', 'coordinador', '/?r=evento_dia&id={baut}', 390, None),
    ('36_ayuda', 'dueno', '/?r=ayuda', 1280, 'preguntar'),
    ('37_cotizacion_mensaje', 'dueno', '/?r=ia_cotizacion', 1280, 'mensaje'),
]


def datos():
    """Los ids y enlaces secretos que necesitan las rutas, leídos de la base del local de ejemplo."""
    entorno = {**os.environ, 'PGPASSWORD': os.environ.get('TURENTA_DB_PASS', 'prueba')}
    q = lambda sql: subprocess.run(['psql', '-h', '127.0.0.1', '-U', os.environ.get('TURENTA_DB_USER', 'turenta'), '-d', os.environ['TURENTA_DB_NAME'],
                                    '-tAc', sql], capture_output=True, text=True, env=entorno).stdout.strip()
    ev = lambda nombre: q(f"SELECT id FROM eventos WHERE nombre = '{nombre}'")
    import datetime
    hoy = datetime.date.today()
    boda, quince, corp, baut = ev('Boda de Ana y Diego'), ev('Quince años de Valentina Ruiz'), ev('Aniversario Comercial Andina'), ev('Bautizo de Mateo Ríos')
    sig = (hoy.replace(day=1) + datetime.timedelta(days=32)).replace(day=1)
    return {
        'anio_sig': sig.year, 'mes_sig': sig.month, 'sabado': (hoy + datetime.timedelta(days=(5 - hoy.weekday()) % 7 + 63)).isoformat(), 'boda': boda, 'quince': quince, 'baut': baut,
        'dj': q("SELECT id FROM proveedores WHERE nombre = 'DJ Fiesta Total'"),
        'tok_cot': q(f"SELECT token FROM cotizaciones WHERE evento_id = {quince} ORDER BY id DESC LIMIT 1"),
        'tok_contrato': q(f"SELECT token FROM contratos WHERE evento_id = {corp} ORDER BY id DESC LIMIT 1"),
        'tok_portal': q(f"SELECT token_cliente FROM eventos WHERE id = {boda}"),
        'tok_vivo': q(f"SELECT token_vivo FROM eventos WHERE id = {baut}"),
        'tok_staff': q("SELECT token FROM personal WHERE nombre = 'Carlos Andrade'"),
        'tok_invitado': q(f"SELECT token FROM invitados WHERE evento_id = {boda} AND nombre = 'Rosa Vega'"),
        'tok_proveedor': q("SELECT token FROM proveedores WHERE nombre = 'DJ Fiesta Total'"),
    }


with sync_playwright() as p:
    # Chromium toma el idioma de las fechas y horas de los campos (21/11/2026, 06:00 p. m.) de LANG/LANGUAGE
    entorno = {**os.environ, 'LANG': 'es_EC.UTF-8', 'LANGUAGE': 'es_EC:es'}
    try:
        nav = p.chromium.launch(channel='chromium', args=['--lang=es-EC'], env=entorno)
    except Exception:
        nav = p.chromium.launch(args=['--lang=es-EC'], env=entorno)
    D = datos()
    sesiones = {}

    def contexto(quien, ancho):
        k = (quien, ancho)
        if k in sesiones:
            return sesiones[k]
        movil = ancho < 600
        ctx = nav.new_context(viewport={'width': ancho, 'height': 844 if movil else 820}, device_scale_factor=2 if movil else 1.5,
                              locale='es-EC', timezone_id='America/Guayaquil', is_mobile=movil, has_touch=movil)
        pg = ctx.new_page()
        if quien in CORREOS:
            pg.goto(B + '/?r=login'); pg.fill('input[name=email]', CORREOS[quien]); pg.fill('input[name=clave]', a.clave)
            pg.click('form button[type=submit]'); pg.wait_for_load_state('networkidle')
        sesiones[k] = pg
        return pg

    for nombre, quien, ruta, ancho, accion in PANTALLAS:
        if SOLO and nombre not in SOLO:
            continue
        pg = contexto(quien, ancho)
        pg.goto(B + ruta.format(**D)); pg.wait_for_load_state('networkidle')
        clip = None
        if accion and accion.startswith('bajar:'):
            pg.evaluate("(s) => { const e = document.querySelector(s); if (e) window.scrollTo(0, e.getBoundingClientRect().top + window.scrollY - 70); }", accion[6:])
        elif accion and accion.startswith('bajar_texto:'):
            pg.evaluate("(t) => { const e = [...document.querySelectorAll('h2,h3')].find(x => x.innerText.includes(t)); if (e) window.scrollTo(0, e.getBoundingClientRect().top + window.scrollY - 20); }", accion[12:])
        elif accion == 'llenar_evento':
            pg.fill('input[name=cliente_nombre]', 'Daniela Ortega y Felipe Ruiz')
            pg.fill('input[name=cliente_telefono]', '0998887766')
            pg.select_option('select[name=origen]', 'instagram')
            pg.fill('input[name=fecha]', D['sabado'])
            pg.fill('input[name=invitados]', '160')
            pg.wait_for_timeout(600)
        elif accion == 'firmar':
            pg.fill('input[name=nombre]', 'Patricio Andrade')
            for sel in ('input[name=identificacion]', 'input[name=cedula]'):
                if pg.locator(sel).count():
                    pg.fill(sel, '1712345678'); break
            lienzo = pg.locator('canvas').first
            lienzo.scroll_into_view_if_needed()
            caja = lienzo.bounding_box()
            if caja:
                x0, y0 = caja['x'] + 30, caja['y'] + caja['height'] * 0.6
                pg.mouse.move(x0, y0); pg.mouse.down()
                for i in range(1, 40):
                    pg.mouse.move(x0 + i * 7, y0 - 25 * ((i % 6) / 5.0) + (i % 3) * 4)
                pg.mouse.up()
            pg.check('input[name=acepto]')
            pg.evaluate("() => { const c = document.querySelector('canvas'); if (c) window.scrollTo(0, c.getBoundingClientRect().top + window.scrollY - 230); }")
        elif accion == 'empezar':
            b = pg.locator('button', has_text='Empezar')
            if b.count():
                b.first.click(); pg.wait_for_load_state('networkidle')
        elif accion == 'preguntar':
            pg.fill('textarea[name=pregunta]', '¿Cómo registro un pago?')
            pg.click('#form-ayuda button[type=submit]'); pg.wait_for_load_state('networkidle')
        elif accion == 'mensaje':
            pg.fill('textarea[name=mensaje]', 'Hola, buenas tardes. Quisiera cotizar una boda para 150 personas el sábado 14 de febrero, '
                    'en el salón, con DJ y fotógrafo. Soy Daniela Ortega, mi número es 0998887766. ¡Gracias!')
        elif accion == 'esperar_plano':
            pg.wait_for_timeout(1200)
        elif accion == 'modulos':
            caja = pg.locator('section[aria-labelledby=mp-t-mods]').bounding_box()
            if caja:
                pg.evaluate("(y) => window.scrollTo(0, y)", caja['y'] - 10)
                pg.wait_for_timeout(200)
                caja = pg.locator('section[aria-labelledby=mp-t-mods]').bounding_box()
                clip = {'x': caja['x'] - 6, 'y': max(0, caja['y'] - 6), 'width': caja['width'] + 12, 'height': min(caja['height'] + 12, 700)}
        pg.wait_for_timeout(400)
        pg.screenshot(path=str(SAL / f'{nombre}.png'), clip=clip)
        print('captura', nombre)
    nav.close()
