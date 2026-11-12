# TuRenta

Programa para **salones de eventos, quintas y haciendas** de Ecuador, de la marca Tukán Software.
Dirección: **turenta.tukanec.com** (servidor qfcorp). Web de la marca: tukansoftware.com.

- Plan, avance y pendientes: [BLOC_DE_NOTAS.md](BLOC_DE_NOTAS.md)
- Cómo está hecho y reglas para programar: [docs/GUIA_DESARROLLO.md](docs/GUIA_DESARROLLO.md)
- **Manual para el cliente** (con capturas reales): [docs/Manual_TuRenta.pdf](docs/Manual_TuRenta.pdf) · versión por módulo: [docs/MANUAL.md](docs/MANUAL.md) · se regenera con `bash docs/manual/generar/generar.sh`

## Qué es TuRenta
Copia de **TuEvento** (qfcorp/tuevento) para **negocios de alquiler para fiestas en EE.UU.** (espuma, nieve, inflables, carpas…),
nacida el 7 nov 2026 para el cliente **Zombie Ice** (Florida). Además de todo lo de TuEvento trae (migración 200):

- **Reserva en línea en inglés** (`/?r=reservar&s=<slug>`): el cliente elige la fiesta (paquete publicado), la fecha y una **hora libre**
  (un paquete puede hacerlo uno o varios **equipos** = espacios; se cuenta el viaje e instalación con las horas de montaje y desmontaje),
  escribe la dirección de la fiesta y sus datos, y pasa a pagar. La hora queda retenida solo ese día.
- **Cobro con Stripe Checkout**: el cliente **elige pagar el depósito (% de reserva) o todo**. Al pagar, la fecha queda separada sola.
  Confirmación al volver (`/?r=stripe`) y por **webhook firmado** (`/?r=stripe_webhook&s=<slug>`); nunca cobra dos veces; si otro tomó el
  equipo mientras pagaba, pasa a otro equipo libre del mismo paquete. Modo prueba con claves `sk_test_`.
- **Portal «My Party» en inglés** (cuando el negocio tiene `idioma_publico = en`): estado, cuenta regresiva, pasos, pagos y botón para pagar el saldo.
- Panel: **Configuración → Reserva en línea y Stripe** (horas, aviso mínimo, condiciones, claves) y en **Paquetes** la sección «Reserva en línea».
- **Tiempo de viaje con mapa** (migración 201, como las rutas de TuGuardería): Google Maps con tráfico si el negocio pone su clave, si no
  mapas gratuitos. Cada fiesta ocupa el equipo con el viaje desde la base hasta su dirección; el cliente pone la dirección antes de la hora.
  Cada equipo tiene sus **minutos de montaje y desmontaje** (Espacios).
- Zona horaria configurable (`TURENTA_ZONA`, el instalador pone `America/New_York`).
- Datos de Zombie Ice: `php bin/cargar_zombieice.php [correo]` (6 fiestas, extras, equipos y reserva en línea).

## Qué hace
- Calendario por espacios **sin doble reserva** (la base de datos lo impide), con horas de montaje y desmontaje, fechas cerradas y "próximos sábados".
- Embudo de ventas: consulta → cotizado → separado → confirmado → realizado, con seguimiento (llamadas, visitas, degustaciones) y tareas por evento.
- Catálogo, paquetes y **cotizaciones** con IVA por producto, descuento, versiones, ganancia, enlace para que el cliente la acepte y PDF con QR.
- **Contratos** desde plantillas con variables, **firmados por el cliente en su celular**, con verificación por QR (detecta si alguien cambió el texto).
- **Plan de cuotas** automático, pagos, recibos, comprobantes de transferencia/DeUna que sube el cliente, **Payphone**, garantía por daños, gastos y ganancia real del evento. La fecha se separa sola al pagar la reserva.
- **Portal del cliente** (sin contraseña, con enlace secreto): su evento, documentos, pagos, invitados, cronograma y transmisión.
- **Formulario del evento** por tipo (boda, quince años, corporativo…): el cliente lo llena en su portal (se guarda solo) hasta X días antes; el local ve y corrige las respuestas, que salen en la hoja del evento. Recordatorio por WhatsApp.
- **Extras en línea**: el cliente pide desde su portal hora extra, hora loca, fotocabina…; el local aprueba y se suma solo a la cotización y al plan de pagos. Si el contrato ya estaba firmado, se prepara un **anexo de contrato** que el cliente firma con el dedo (con QR de verificación y PDF).
- **Encuesta de satisfacción** después del evento (NPS y estrellas): a quien le encantó se le pide la reseña en Google; una nota baja avisa al dueño. Reporte de satisfacción.
- **Día del evento**: cronograma minuto a minuto con **modo en vivo**, personal con portal propio, proveedores, mobiliario con disponibilidad por fecha, hoja del evento (PDF).
- **Invitados con QR**: invitación digital, confirmación de asistencia, mesas, control de ingreso en la puerta escaneando el QR.
- **Proveedores y compras**: ficha de cada proveedor (RUC, cuenta del banco, días y cupo de crédito, anticipo), documentos con vencimiento (permisos, ARCSA, póliza) con aviso, órdenes de compra por evento (pedido de precio → pedida → confirmada → entregada) en PDF con QR y por WhatsApp, comparar precios y elegir, factura del proveedor a mano o **leyendo el XML del SRI**, pagos con cheques posfechados y anulación, **Por pagar** (vencido, esta semana, antigüedad de saldos, cupo, Excel), puntualidad y calificación el día del evento, **incidencias** con foto y descuento al proveedor, ranking de proveedores con **puntaje configurable por el dueño** (pesos, tolerancia, incidencias, niveles, periodo y mínimo de eventos, con vista previa), bitácora de todo y **portal del proveedor** (sus eventos, confirmar órdenes, subir facturas, sus pagos y "Ya llegué"). Las compras cuentan en la ganancia real del evento.
- **Plano visual del salón**: distribuciones de cada espacio en metros (mesas redondas, rectangulares e imperiales, pista, tarima, barra, buffet, DJ…) que se arrastran con el dedo o el mouse; el plano de cada evento está unido a las mesas de los invitados (se ve quién está sentado y qué mesa está llena), en PDF y para que los novios lo miren.
- **Metas de ventas**: meta del mes (y por vendedor), avance en el Inicio y reporte con la comparación con el mismo mes del año anterior.
- **Boda en vivo** con QF Producciones (pedido por WhatsApp y enlace en portal e invitaciones).
- Página pública del local con consulta de fechas libres y formulario que entra al sistema.
- **Ayuda** «¿Qué necesita hacer?» para todos (responde al instante con el manual y guarda las preguntas frecuentes) y módulo **Asistente IA** (incluido en el plan Completo; extra aparte en el Básico): cotización a partir del mensaje de WhatsApp del cliente.
- Inicio con el día, cobranza, mensajes por WhatsApp listos para enviar, reportes (ventas, cobrado, ocupación, conversión, rentabilidad, comisiones), exportar a Excel, calendario para Google Calendar, avisos por Telegram.
- Usuarios: dueño, **administradores** (hasta 4, mismo nivel que el dueño y no cuentan en el límite de usuarios del plan), vendedor, coordinador (solo el día del evento) y consulta (solo mira). Los permisos se preguntan con `es_admin_total()` (dueño o administrador); `es_dueno()` queda solo para la identidad de la cuenta ante Tukán. Panel Tukán para administrar todos los negocios y sus planes.

## Planes y módulos (Anexo B: el programa se arma por módulos)
Cada cliente tiene la **base** (siempre) y los **módulos** que paga. Se encienden y apagan por cliente desde el Panel Tukán
(ficha del negocio → **Módulos**) o desde el Panel Tukán Central. **Apagar nunca borra datos**: se esconden menús, páginas,
botones, portales públicos ("No disponible"), PDF, tareas automáticas, avisos y Mensajes de hoy; al encenderlo vuelve todo.

**Base** (todos): calendario sin doble reserva, eventos, seguimiento y tareas, clientes, espacios y catálogo, cotizaciones con PDF,
plan de pagos y recibos, Mensajes de WhatsApp del día, página pública con consulta, reportes básicos, usuarios y roles, app instalable.

**Solo dos planes** (migración 112, decisión de Dimitry del 28 oct 2026, igual en todos los programas Tukán). Los precios son internos:
**nunca se publican al cliente** (al cliente se le dice «Básico con 3 módulos que tú eliges» y «Completo con todo y el Asistente IA»;
en Mi plan solo ve el precio de lo que ya paga). Se cambian en Panel Tukán → Planes y precios o desde el Panel Tukán Central.

| Plan (código) | Precio interno | Límites | Módulos incluidos |
|---|---|---|---|
| **Básico** (`esencial`) | sin precio fijo para el cliente: consulta por WhatsApp (interno: $12,99 + IVA al mes) | 1 espacio, 2 usuarios | La base + **3 módulos que el cliente elige**; cada módulo más **$5** al mes |
| **Completo** (`quinta`) | $25 + IVA al mes | sin límite | Todos, **incluido el Asistente IA** (con su tope de uso) |

- El **Asistente IA** en Básico es un extra de **$5** al mes que **no cuenta** entre los 3 elegidos (`Modulos::FUERA_DEL_CUPO`).
- Columnas `planes.modulos_elegir` (cuántos extra van incluidos) y `planes.precio_modulo_extra` (cada uno más; vacío = precio del catálogo).
  Los primeros N extra **en el orden del catálogo** van incluidos (`Modulos::estado()` devuelve `elegidos`, `por_elegir` y `precio_por_extra`).
- El plan del medio (Salón, `salon`) ya no existe: la migración 112 pasó sus negocios y pagos a Completo; quien pagaba Esencial conserva lo que traía
  su plan como módulos elegidos. Los códigos se conservan (`esencial`, `quinta`) por las llaves foráneas; `PLAN_DEFECTO` sigue en `quinta` (las demos tienen todo).
- Precios, nombres y módulos se ponen **una sola vez** (marca `planes_20_45` en `ajustes`): si Dimitry los cambia después, el instalador no los pisa.

| Módulo | Qué trae | Necesita |
|---|---|---|
| Paquetes y simulador | Paquetes armados que cargan la cotización en un toque; simulador por invitados | — |
| Contratos con firma | Contrato que el cliente firma con el dedo, QR de verificación, anexos por extras | — |
| Portal del cliente | Enlace del cliente sin claves: pagos, contrato, comprobantes | — |
| Cobros en línea (Payphone) | El cliente paga con tarjeta desde su portal | Portal del cliente |
| Formulario, extras y encuesta | Formulario del evento, extras pedidos en línea, encuesta NPS y reseña | Portal del cliente |
| Día del evento | Cronograma con modo en vivo, personal con su portal, hoja del evento | — |
| Invitados con QR | Invitación, confirmación, mesas, control de ingreso con QR | — |
| Plano del salón | Plano de cada espacio y de cada evento, PDF a escala | — |
| Mobiliario y menaje | Sillas, mesas, mantelería: reservas, disponibilidad y daños | — |
| Boda en vivo | Transmisión con el botón "Ver en vivo" en portal e invitación | Día del evento |
| Proveedores y compras | Ficha, documentos, órdenes de compra, facturas (XML del SRI), por pagar | — |
| Portal del proveedor | El proveedor ve sus eventos, confirma órdenes, sube facturas | Proveedores |
| Incidencias y puntaje | Problemas con foto, puntualidad y puntaje configurable | Proveedores |
| Metas y reportes avanzados | Metas de ventas, embudo, ganancia por evento, comisiones | — |
| Avisos por Telegram | Pagos, firmas, consultas, cuotas y seguimientos al celular | — |
| Exportar a Excel y Google Calendar | Descargas para Excel y calendario externo | — |
| **Asistente IA** (**incluido en Completo**; en Básico, extra de $5 que no cuenta entre los 3) | Cotización a partir del mensaje de WhatsApp del cliente (borrador para revisar); tope de gasto mayor | — |

- **Precio al mes** de cada cliente = plan + módulos extra (los primeros 3 del Básico, incluidos; los demás $5; el Asistente IA $5) − módulos quitados (o un **precio acordado** fijo). Es el que sugiere
  "Registrar pago", el `cotizar` y el `mensual_estimado` del Panel Central. Precios, descripciones y qué trae cada plan se cambian en
  Panel Tukán → **Planes y precios → Catálogo de módulos**.
- **Mi plan** (dueño y administradores): ve sus módulos encendidos y lo que paga por su plan (su contrato). Los módulos y planes que no tiene
  **no muestran precio** («Precio por cotización»): **Pedir cotización** (en Mi plan, en cada plan y en la pantalla del módulo
  apagado) crea un pedido (solicitud `cambio_modulos`) que llega al Panel Tukán Central, que cotiza, aprueba o rechaza.
  El superusuario de Tukán y el Panel Central sí ven los precios.
- **Todo se pide demo**: no hay registro abierto ni periodo de prueba. En textos de venta se habla de «meses de regalo». `/?r=registro` es **Solicitar demo** (nombre, negocio,
  ciudad, WhatsApp, correo, qué necesita y módulos que le interesan) y llega como solicitud `demo_pedida` al Panel Central y al Resumen
  del Panel Tukán (**Crear demo**). La demo tiene todos los módulos encendidos (o los que elija Tukán) por los días que Tukán decida.

## Direcciones
| Quién | Dirección |
|---|---|
| Dueño y equipo | `/` (entrar con correo y contraseña) · **Solicitar demo**: `/?r=registro` |
| Página pública del local | `/?r=sitio&s=<nombre-del-local>` |
| Cliente (portal) | `/?r=portal&t=<enlace secreto>` · formulario `/?r=portal_cuestionario&t=…` · extras `/?r=portal_extras&t=…` · encuesta `/?r=encuesta&t=…` |
| Cotización / firma de contrato | `/?r=cot&t=…` · `/?r=firmar&t=…` · anexo `/?r=firmar_anexo&t=…` · verificación `/?r=verificar&c=<código>` |
| Invitado / puerta / en vivo / personal | `/?r=invitacion&t=…` · `/?r=puerta&t=…` · `/?r=vivo&t=…` · `/?r=staff&t=…` |
| Proveedor (portal) / verificar orden de compra | `/?r=portal_proveedor&t=<enlace secreto>` · `/?r=verificar_oc&c=<código>` |
| Tukán (super administrador) | `/?r=admin_entrar` · cuenta creada con `php bin/crear_admin.php` en el servidor · o con un clic desde el Panel Tukán Central |
| Panel Tukán Central (conector) | v1: `/tukan_central.php?accion=sso&t=…` (entrar, con enlace directo `ir`) · `/tukan_central.php?accion=estado&t=…` (cifras en JSON) · v2: `POST /tukan_central.php?accion=<capacidades, clientes, cliente, cotizar, planes, modulos, solicitudes, solicitud, finanzas, ejecutar>` (incluye pausar, borrar_cliente, restaurar_cliente, cambiar_modulos y guardar_modulos) |

## Tecnología
PHP 8.3+, PostgreSQL 16 (extensiones `btree_gist` y `pgcrypto`), TCPDF (con composer) para los PDF. Sin librerías de JavaScript.

## Instalar en el servidor (Ubuntu + PHP + PostgreSQL + nginx)
Una sola línea, como el usuario normal (no root). Se puede repetir para actualizar: si ya hay negocios, no borra la base.

```bash
curl -fsSL https://raw.githubusercontent.com/qfcorp/turenta/main/deploy/instalar.sh -o ~/instalar_turenta.sh && bash ~/instalar_turenta.sh
```

Después, en Cloudflare (Zero Trust → Networks → Tunnels → tu túnel → Public Hostname) agregar `turenta.tukanec.com` → `HTTP` → `localhost:80`, con *HTTP Host Header* = `turenta.tukanec.com`.

Super usuario del panel Tukán: `php /var/www/turenta/bin/crear_admin.php`

## App de Android (Google Play)
App **TuRenta** (`com.tukansoftware.turenta`, repositorio `qfcorp/tukan-apps`): abre `https://turenta.tukanec.com/?r=inicio&app=play` a pantalla completa.
**Versión 1.0.0 sin cobro dentro de la app**: con la cookie de la app (`en_app_android()`) no se ven precios del software, «Pedir cotización»,
«Pagar», «Renovar» ni el WhatsApp de ventas (lo que el local cobra a sus clientes sí). Todo en [docs/GOOGLE_PLAY.md](docs/GOOGLE_PLAY.md).
- `/.well-known/assetlinks.json` (src/AppAndroid.php) · privacidad https://turenta.tukanec.com/privacidad.php (sección `#borrar-cuenta`) · **Mi cuenta → Borrar mi cuenta**.
- En el servidor, después del instalador: `sudo bash /var/www/turenta/deploy/app_android.sh` (termina en `OK`); con la huella de Google: `... app_android.sh HUELLA`.
- Cuenta del revisor de Google: `php /var/www/turenta/deploy/demo_google.php crear` (negocio de ejemplo aparte, la contraseña sale una vez) · `... borrar`.

## Ayuda e inteligencia artificial (migración 110)
Con la librería compartida del Panel Tukán Central **`src/tukan_ia.php`** (copiada SIN cambios de `compartido/`, guía `compartido/LEEME_IA.md`).
- **Ayuda** (todos los planes y cargos): **Más → Ayuda**, cuadro «¿Qué necesita hacer?» → `tukan_ia_ayuda()`: pregunta frecuente guardada (costo 0) →
  IA (modelo rápido) con las secciones del manual del cliente (`docs/manual/manual.html` convertido a texto y guardado en caché en la carpeta temporal;
  si no está, `docs/MANUAL.md`) y la respuesta queda como FAQ automática → sin poder gastar, el manual tal cual + el WhatsApp. POST con CSRF, 10 preguntas
  por minuto por usuario. Sin el módulo: máximo **30 respuestas nuevas al mes** por negocio. Nunca dice «límite», «cupo» ni «saldo».
- **Módulo «Asistente IA»** (`asistente_ia`, grupo Extras): **viene incluido en el plan Completo** (`planes.modulos = '*,asistente_ia'`,
  migraciones 111 y 112). En Básico es un extra de **$5 al mes** (migración 112) que no cuenta entre los 3 módulos que elige el cliente; en Mi plan,
  si no lo tiene, dice «Pídalo y le cotizamos» (sin precio). «*» solo no lo trae (`Modulos::SOLO_A_PEDIDO`): la demo con todos los módulos lo tiene solo si su plan lo incluye. Trae **Cotización a partir de un mensaje**
  (`/?r=ia_cotizacion`, botón «✨ Desde un mensaje» en Eventos y Nuevo evento): JSON validado contra los espacios, paquetes y productos del negocio
  (`Ia::validarPropuesta`), se muestra como borrador y al guardar crea el evento (consulta) y la cotización (borrador). Si no se puede usar la IA:
  `tukan_ia_mensaje_neutro('texto', …)` con el enlace a Nuevo evento.
- **Tope por negocio** (guardián `TukanIaGuardianPdo`, tabla `tukan_ia_gasto`): **4,00 USD al mes con el módulo** (incluido en Completo o comprado aparte; decisión del dueño) **y 0,50 sin él**; una **demo** (estado `prueba`) **0,50 en total** aunque tenga todos los módulos (al pagar vuelve sola a lo normal);
  otro tope en `negocios.ia_tope_usd` (lo cambia el Panel Central con la operación `ia_tope`). Al llegar al tope no se llama a la API, en silencio.
- **Panel Tukán → Preguntas IA** (`/?r=admin_faq`): listar (Por revisar primero), aprobar, editar (queda aprobada y «manual»), borrar y agregar. Todo con CSRF y en registro_admin.
- **Conector v2** (Anexo C de `PROTOCOLO_V2.md`): acción `ia_uso` (gasto del mes por negocio, totales y FAQ; sin la clave) y operación `ia_tope`
  (`{"tope_usd": número | null}`, solo el dueño del Central, botón «Tope de IA» en la ficha del cliente).
- **Configuración**: `/etc/tukan-ia.env` (la escribe `bash deploy/configurar_ia.sh` del Panel Tukán Central; **no** se copia la clave aquí). El usuario de PHP
  (`www-data`) debe poder leerlo. **Sin ese archivo la IA queda apagada** y la Ayuda funciona solo con las FAQ y el manual. Pruebas: `TUKAN_IA_ENV=/otro/archivo`.

## Enviar la demo por correo
Con **`src/tukan_correo.php`** (copiada SIN cambios del Panel Tukán Central, guía `compartido/LEEME_CORREO.md`). En la ficha de una demo recién creada
(Panel Tukán → Nueva demo, o «Nueva contraseña del dueño» de una demo) aparece **Enviar por correo** debajo del WhatsApp: llega «Su demo de TuRenta está lista»
desde info@tukansoftware.com. La contraseña se ve una sola vez y no se guarda: viaja en un **sello cifrado** (XChaCha20-Poly1305 con una llave al azar de la
sesión del súper usuario, atado a él, 30 minutos, un solo envío). En la auditoría queda «Envió la demo por correo a …» sin la contraseña. Si
`/etc/tukan-correo.env` no existe: botón desactivado **Configure el correo de Tukán** con el comando
`cd /var/www/tukan-central && git pull && bash deploy/configurar_correo.sh`.

## Conectar con el Panel Tukán Central (panel.tukanec.com)
El panel central muestra las cifras de todos los programas Tukán y deja entrar a cada panel con un clic.
1. En el panel central, agrega la instalación de TuRenta: te da un **ID** (ej. `turenta`) y un **SECRETO** (64 letras y números).
2. En el servidor, una sola línea:
   ```bash
   sudo bash /var/www/turenta/deploy/conectar_central.sh {ID} {SECRETO} {URL}
   ```
   Ejemplo: `sudo bash /var/www/turenta/deploy/conectar_central.sh turenta 3f9a…c1 https://panel.tukanec.com`.
   Guarda `TUKAN_CENTRAL_ID`, `TUKAN_CENTRAL_SECRET` y `TUKAN_CENTRAL_URL` en `config/.env` (lo demás no se toca), prepara las tablas y hace una prueba: debe terminar en `OK`.
3. Listo: al entrar desde el panel central, el panel de TuRenta muestra el botón **← Panel Tukán** para volver.

- Sin `TUKAN_CENTRAL_ID` o `TUKAN_CENTRAL_SECRET`, `/tukan_central.php` responde 404 y todo sigue como antes. El instalador conserva estas líneas al actualizar.
- **Entrar solo desde el panel central:** agrega `TUKAN_SOLO_CENTRAL=1` en `config/.env`. La página `/?r=admin_entrar` dice "El ingreso del super usuario es solo desde el Panel Tukán" y no acepta contraseñas. El ingreso de los negocios no cambia.
- **Emergencia** (el panel central no responde): pon `TUKAN_SOLO_CENTRAL=0` en `config/.env` y entra con tu clave; si no la recuerdas, `php /var/www/turenta/bin/crear_admin.php` la cambia.
- Cada entrada desde el panel central queda en "Últimos movimientos del panel" ("Entró desde el Panel Tukán Central"). La primera vez se crea el super usuario con el correo del panel central (con una contraseña al azar que nadie conoce).
- Seguridad: cada enlace dura 60 segundos y sirve una sola vez (30 s las operaciones); máximo 120 pedidos y 30 rechazados por minuto por IP; el estado no lleva datos personales (solo nombres de negocios). Detalle del protocolo: `PROTOCOLO.md` (v1) y `PROTOCOLO_V2.md` (v2) del panel central.

### Qué puede hacer el Panel Tukán Central (protocolo v2)
Con el mismo ID y el mismo secreto, el panel central ve y maneja a los clientes de TuRenta sin entrar al panel:
- **Clientes**: todos los negocios con su estado (en demo, al día, por vencer en 7 días, vencido —incluye los días de gracia— y suspendido/cancelado), plan, vencimiento, dueño, último uso, pagos, sus **módulos** (`modulos`: del plan, extra, quitados, activos y precio) y lo que paga al mes con sus módulos (`mensual_estimado`). La ficha trae los pagos (también los anulados), las últimas acciones, las notas internas y los mensajes de WhatsApp listos.
- **Solicitudes**: **pedidos de demo** (`demo_pedida`, con Crear demo prellenado), **pedidos de módulos** desde Mi plan (`cambio_modulos`, con Aprobar = `cambiar_modulos` y Rechazar), demos que terminan en 2 días / hoy / terminadas sin pagar (60 días), planes que vencen en 5 días o vencidos sin renovar (30 días) y dominios incluidos por registrar. Se marcan "en proceso", "atendida" o "descartada" (tabla `tukan_central_atenciones`).
- **Operaciones** (con las mismas funciones del panel de Tukán y una línea en "Últimos movimientos del panel" con el correo del Central): crear demo (con datos de ejemplo, plan y módulos opcionales, desde un pedido de demo; la contraseña temporal se muestra una sola vez), alargar la demo, registrar pago (con la fecha en que pagó), anular pago, suspender, reactivar, cancelar, cambiar plan, nueva clave del dueño, nota interna, atender solicitud, guardar plan/precios, **cambiar_modulos** (encender/apagar con dependencias, precio acordado; solo el rol dueño) y **guardar_modulos** (catálogo y módulos de cada plan).
- **Finanzas**: cobrado por mes (por plan, tiempo y forma de pago), nuevos pagadores, devengado, ingreso mensual recurrente (lo que pagan al mes con sus módulos) y `por_modulo` (cuántos lo tienen y cuánto suman los extras).
- **IA** (Anexo C): `ia_uso` (gasto de IA del mes por negocio, para **Más → IA** del Central) y `ia_tope` (tope del mes de un negocio; solo el rol dueño).
- Si el Central repite una operación (se cortó la red), TuRenta no la hace dos veces: devuelve el mismo resultado (`tukan_central_operaciones`, 90 días, sin contraseñas).
- **Pausar y borrar clientes** (Anexo A del protocolo):
  - `pausar` (tipo: falta de pago, pedido del cliente, revisión, uso indebido u otro + motivo): el negocio no puede trabajar y ve "Tu cuenta está pausada" con el motivo (en revisión y uso indebido, un texto genérico) y el WhatsApp de Tukán; su página pública y todos sus portales y enlaces dicen "temporalmente no disponible". `reactivar` la quita.
  - `borrar_cliente` (escribir el nombre exacto del negocio o su número + motivo; solo el rol dueño del Central): guarda una **copia completa** (todas las filas de todas las tablas del negocio, sacadas del esquema, y la lista de sus archivos subidos) en `respaldos/borrados/<id>_<fecha>.json.gz` (fuera de la web, carpeta 700 de `www-data`; otra carpeta con `TURENTA_BORRADOS`) y lo manda a la **papelera**: no entra, no sale en listas ni cifras (`estado`, `clientes`, `finanzas` desde ese día), sus páginas y portales dan 404 y no recibe tareas ni avisos.
  - `restaurar_cliente` dentro de 30 días lo devuelve tal cual (también desde el Panel Tukán del programa: Negocios → filtro **Papelera** → Restaurar). Pasados 30 días, la tarea de cada hora lo borra de verdad (sus archivos pasan junto a la copia); las copias se borran al año.
  - `clientes` con `filtros.estado = "papelera"` los lista (cada uno con `papelera: {desde, hasta, motivo}`); cada ficha trae los botones que corresponden (`pausar`/`reactivar`, `borrar_cliente` o `restaurar_cliente`).

## Tareas automáticas y respaldo
El instalador deja programado:
```
5 * * * * php /var/www/turenta/bin/tareas.php          (cada hora, en el crontab de www-data)
25 3 * * * /var/www/turenta/deploy/respaldo.sh         (cada día 3:25, guarda 14 días en /var/backups/turenta)
```
Tareas: vencer cotizaciones, avisar fechas retenidas que se liberan, cuotas por vencer, revisar precios, evento en 7 días, pasar a realizado, devolver garantías, reporte del mes, seguimientos del día, pagos a proveedores que vencen, documentos de proveedores por vencer, proveedores sin confirmar a 3 días del evento y la **papelera** (borra de verdad los negocios con más de 30 días en ella y las copias de más de 1 año). Los negocios pausados o en la papelera no reciben tareas ni avisos, y cada tarea de un módulo (Telegram, proveedores) solo corre si el negocio lo tiene encendido.

**Al actualizar a esta versión** hay que volver a correr el instalador (la línea de arriba): aplica las migraciones 098 (módulos y pedidos de demo), 099 (rol administrador), 110 (ayuda e IA), 111 (Asistente IA incluido en el plan más caro) y 112 (planes de $20 y $45: Básico y Completo), crea `respaldos/borrados` y pasa las tareas al usuario `www-data`.

Telegram (opcional) en `config/.env`: `TELEGRAM_BOT_TOKEN`, `TELEGRAM_BOT_USERNAME` y `TELEGRAM_WEBHOOK_SECRET` (obligatorio si se usa el webhook).

## Pruebas (entorno simulado)
Solo en bases de prueba (se borran y se vuelven a crear):

```bash
# Lógica (2627 pruebas)
TURENTA_DB_NAME=turenta_prueba TURENTA_DB_USER=turenta TURENTA_DB_PASS=prueba php tests/run_tests.php
# Pantallas de punta a punta (2255 pruebas; levanta un sitio de prueba solo)
PGPASSWORD=prueba bash tests/run_web.sh
# Solo ayuda, IA y correo de la demo (API de Anthropic y SMTP de mentira: tests/anthropic_falso.py y tests/smtp_falso.py)
TURENTA_DB_NAME=turenta_prueba TURENTA_DB_USER=turenta TURENTA_DB_PASS=prueba php tests/run_tests.php logica_110
PGPASSWORD=prueba bash tests/run_web.sh web_110
# Solo el conector con el Panel Tukán Central (v1 = logica_95/web_95, v2 = logica_96/web_96, pausar y papelera = logica_97/web_97, módulos y pedidos de demo = logica_98/web_98, administradores = logica_99/web_99)
TURENTA_DB_NAME=turenta_prueba TURENTA_DB_USER=turenta TURENTA_DB_PASS=prueba php tests/run_tests.php logica_9
PGPASSWORD=prueba bash tests/run_web.sh web_9
# Solo la app de Android (assetlinks, modo app sin precios del software, privacidad, cuenta del revisor de Google)
TURENTA_DB_NAME=turenta_prueba TURENTA_DB_USER=turenta TURENTA_DB_PASS=prueba php tests/run_tests.php logica_120
PGPASSWORD=prueba bash tests/run_web.sh web_120
# Solo los planes de $20 y $45 (migración 112): Básico con 3 módulos a elegir y Completo
TURENTA_DB_NAME=turenta_prueba TURENTA_DB_USER=turenta TURENTA_DB_PASS=prueba php tests/run_tests.php logica_112
PGPASSWORD=prueba bash tests/run_web.sh web_112
# Si otras pruebas usan los mismos puertos: PUERTO (sitio; usa también PUERTO+5 y PUERTO+6), IA_PUERTO y SMTP_PUERTO (pantallas),
# IA_PUERTO_LOGICA y SMTP_PUERTO_LOGICA (lógica). Ej.: PUERTO=8349 IA_PUERTO=8350 SMTP_PUERTO=8351 bash tests/run_web.sh
```
Las pruebas escritas con los planes de antes (Esencial, el plan del medio y el más caro) los emulan con `planes_de_antes` (`tests/base.php` y `tests/web_base.sh`).
