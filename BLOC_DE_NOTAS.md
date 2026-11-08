# Bloc de notas — TuRenta (salones de eventos, quintas y haciendas · marca Tukán)

Última actualización: 6 de noviembre de 2026

## 7 nov 2026 — Nace TuRenta (copia de TuEvento) para Zombie Ice (decisión de Dimitry)
- Pedido: un programa Tukán que maneje la página de Zombie Ice, con reservas, cobro por **Stripe** y que el cliente vea cómo va su reserva.
- Base: **TuEvento** (TuPlanner no aporta para alquiler de fiestas). Nombre provisional **TuRenta**, repo `qfcorp/turenta`, dirección `turenta.tukanec.com`, servidor de Tukán.
- Stripe: **modo prueba primero**; cuando Zombie Ice acepte se ponen las claves reales de **su** cuenta de Stripe.
- Pago: **el cliente elige** depósito o pago completo.
- Hecho: migración 200, `src/Reservas.php`, `src/Stripe.php`, páginas `reservar`, `stripe`, `stripe_webhook`, portal en inglés, configuración, paquetes,
  `bin/cargar_zombieice.php`, `tests/logica_200_reservas_stripe.php` (81 pruebas). Todo lo de TuEvento sigue pasando (2261 pantallas).
- Pendiente: panel del negocio en inglés (hoy el panel sigue en español), extras pagados en línea desde la reserva, mensajes de WhatsApp/correo en inglés,
  impuesto de Florida si aplica, encuesta y reseña en inglés.

## 7 nov 2026 — Respuesta del hermano (Zombie Ice) a la propuesta
- Le gustó: velocidad (WebP, menos fuentes), barra fija Call/Text/Book en celular, páginas por fiesta con las mismas URL (no pierde Google).
- Aclaración enviada: la reserva con Stripe **ya está hecha** en TuRenta (modo prueba); el dinero va a **su propia cuenta de Stripe**.
- Su regla: el pie con crédito a **Foam Office** se queda (es a propósito). TuRenta se ofrece como complemento, no para quitar Foam Office, salvo que él decida.
- Pendiente:
  - [ ] Poner **contraseña** a la vista previa `zombieice.qfproducciones.com` mientras no contraten (el formulario funciona y un cliente real podría creer que reservó).
  - [ ] Cuando acepten: avisos de reservas al correo de Zombie Ice (`info@zombieice.com`), no al de la demo (`produccion@qfproducciones.com`).
  - [ ] Revisar en el sitio real los restos de plantilla que señaló el informe.

## 6 nov 2026 — Precios nuevos para todos los programas Tukán (decisión de Dimitry)
- **Básico $12 + IVA** con 3 módulos a elegir; **cada módulo más $5 + IVA**; **Completo $25 + IVA** con todo y el Asistente IA.
- **Asistente IA $3 + IVA en Básico, uso limitado** (tope sugerido del mes **$1,50**, antes $4). «Varias sedes» y los módulos gratis no cambian.
- Se aplica UNA sola vez con la migración `113` (marca `planes_12_25` en ajustes). Después se cambia desde el Panel Tukán → Más → **Módulos y precios** (un precio para todos).
- No toca el plan, los módulos ni el precio acordado de cada cliente. Google Play: $14,99 / $70,99 / $141,99 (Básico) y $29,99 / $147,99 / $294,99 (Completo).
- Pruebas: los precios nuevos se revisan aparte; las cuentas de las pruebas viejas usan los precios de antes (así siguen probando las reglas).

## Qué es
Programa para salones de eventos, quintas y haciendas de Ecuador. Nació del estudio de nichos (12 oct 2026): no había ningún programa ecuatoriano para este mercado.
Se armó juntando lo mejor de los programas existentes y lo que Tukán ya tenía:
- **Brinden** (Argentina): agenda con aviso de doble reserva, pipeline presupuestado → señado → reservado, presupuestos en PDF por WhatsApp, caja de señas, perfil público con formulario.
- **EventControl** (México): cotizaciones y contratos con paquetes, compras y gastos por evento.
- **Evento Plus**: avisos automáticos por WhatsApp en cada etapa.
- **OfiEventos** (España, en español): presupuestos, planning, salones, invitados, inventario, control de cobros, escandallo de costos.
- **Anolla / Infor / ReServe**: lista de espera, eventos que se repiten, comunicación automática, órdenes de servicio (BEO), contratos con la marca.
- **ERP QF**: propuestas en PDF, firma con QR, personal por evento y personal mínimo, cronómetro de escaleta, reportes, exportar.
- **TuSalón**: agenda, anticipos con transferencia/DeUna/Payphone, planes y prueba gratis, Telegram, app instalable.
- **TuPass**: accesos con QR y transmisión (boda en vivo).

## Decisiones tomadas
- [x] Nombre provisional: **TuRenta** (se puede cambiar)
- [x] Planes: **Salón $39/mes** (hasta 2 espacios y 3 usuarios) · **Quinta $69/mes** (todo, ilimitado). Semestral paga 5 recibe 6; anual paga 10 recibe 12 + dominio. Demo de 7 días que crea Tukán (ya no hay prueba gratis; 18 oct 2026). Desde el 18 oct 2026 cada plan es base + módulos (ver abajo). Precios y lo que incluye cada plan se cambian después (panel Tukán → Planes).
- [x] Plan Quinta incluye: portal del cliente completo, invitados con QR, día del evento, mobiliario y boda en vivo.
- [x] **Sin facturación electrónica** por ahora (misma decisión pendiente que la veterinaria: opción B, proveedor registrado en el SRI).
- [x] Tecnología igual que TuSalón: PHP + PostgreSQL, en el servidor qfcorp, dirección **turenta.tukanec.com**.

## Hecho (13 oct 2026) — piloto completo
- [x] Base de datos (36 tablas + 5 migraciones) con **bloqueo de doble reserva en la base** (restricción de exclusión por espacio y horario)
- [x] Ventas: calendario mensual y "próximos sábados", eventos (lista y tablero), evento con estados, seguimiento, tareas, clientes, espacios y fechas cerradas, lista de espera
- [x] Página pública del local con "¿está libre tu fecha?" y formulario de consulta (anti-robots, límite por hora)
- [x] Catálogo, paquetes (simulador por invitados), cotizaciones con versiones, IVA por línea, descuento, ganancia (solo dueño), enlace para aceptar, PDF con QR
- [x] Contratos con plantillas y variables, firma del cliente con el dedo, PDF firmado, verificación por QR y huella SHA-256
- [x] Cobros: plan de cuotas automático o a mano, pagos y recibos, comprobantes del cliente, Payphone, garantía, gastos y ganancia real, tablero de cobranza, mensajes de WhatsApp del día
- [x] Portal del cliente (básico en Salón, completo en Quinta)
- [x] Día del evento: cronograma con modo en vivo, personal con su portal, proveedores, mobiliario con disponibilidad, hoja del evento, boda en vivo con QF
- [x] Invitados: invitación digital, confirmación, mesas, pegar lista, control de ingreso con QR, portal de invitados para los novios, PDFs
- [x] Inicio del día, reportes, exportar a Excel, configuración, usuarios y roles, Telegram, calendario de Google, tareas automáticas, panel Tukán, instalador y respaldo
- [x] **Pruebas en entorno simulado: lógica 795/795 y pantallas 916/916**, sin avisos de PHP; revisado en celular y computadora (Chromium) sin errores de JavaScript; QR leídos con un lector real

## Hecho (13 oct 2026) — portal del cliente, segunda parte (ideas de Tripleseat, Perfect Venue, Planning Pod, Aisle Planner, HoneyBook)
- [x] Formulario del evento: plantillas de ejemplo por tipo de evento (8 tipos de pregunta), copia por evento, el cliente lo llena en su portal con guardado automático y avance, cierre X días antes, aviso al local al terminar, respuestas en el evento, en PDF y en la hoja del evento, recordatorio en Mensajes de hoy
- [x] Extras en línea: el local elige qué productos se piden en el portal (texto y foto); el cliente pide, el local aprueba (versión nueva de la cotización + cuota "Extras") o rechaza con nota
- [x] Encuesta de satisfacción con enlace secreto (NPS + estrellas + comentario), botón de reseña en Google para los que recomiendan, aviso al dueño por notas bajas, reporte de satisfacción y NPS en el reporte del mes
- [x] Pruebas: logica_70 y web_70 (migración 070)

## Hecho (13 oct 2026) — plano del salón y metas de ventas (ideas de Tripleseat, Cvent, Planning Pod y Perfect Venue)
- [x] Plano visual del salón: distribuciones por espacio (con ejemplo de boda o corporativo listo), editor con el dedo y el mouse (cuadrícula de 0,5 m, girar, duplicar, deshacer, acercar con dos dedos, se guarda solo)
- [x] Plano de cada evento unido a las mesas de invitados (en los dos sentidos), colores por ocupación, invitados de cada mesa, mesas sin ubicar, PDF a escala con la lista de mesas, y el plano para mirar en el portal de los novios
- [x] Metas de ventas del local y por vendedor (meta de siempre + meses distintos), avance del mes en el Inicio y Reportes → Metas con el año anterior y descarga para Excel
- [x] Pruebas: lógica logica_80 (189) y pantallas web_80 (137), y prueba real en Chromium con dedo (390 px) y mouse

## Hecho (13 oct 2026) — proveedores y compras con trazabilidad completa (ideas de Planning Pod, Odoo, STEL Order, control de pólizas y Momentus)
- [x] Ficha del proveedor: RUC/cédula, banco para transferir, crédito (días, cupo con aviso, anticipo), preferido; estado de cuenta, pagos, eventos, historial de precios, puntaje y bitácora de todo
- [x] Documentos con vencimiento (RUC, permisos, ARCSA, póliza, contrato) con alertas en la lista, la ficha y por Telegram
- [x] Compras / órdenes de compra OC-0001 con ítems e IVA, estados, PDF con QR de verificación, WhatsApp, comparar pedidos de precio y elegir; el proveedor queda solo en el día del evento
- [x] Factura del proveedor a mano o con el XML del SRI (crea el proveedor si el RUC es nuevo); pagos con cheque posfechado, anulación sin borrar, sin pagar de más; retención y descuentos por incidencias
- [x] Por pagar: total, vencido, esta semana, antigüedad 1-30/31-60/61-90/+90, por proveedor, cheques, cupo pasado, Excel; tarjeta en el Inicio; compras en la ganancia real y en Reportes
- [x] Día del evento: llegada real (coordinador o "Ya llegué" del proveedor), puntualidad, cumplimiento y estrellas, bloques del cronograma a cargo de un proveedor; incidencias con foto (graves avisan al dueño), también en la hoja del evento
- [x] Portal del proveedor (Quinta): sus eventos, cronograma, confirmar órdenes, subir factura, pagos y saldo
- [x] Tareas automáticas: pagos por vencer y vencidos, documentos por vencer, proveedores sin confirmar
- [x] Pruebas: logica_60 (321) y web_60 (210), migración 060; revisado en celular (390 px) y computadora sin errores de JavaScript

## Hecho (13 oct 2026) — anexo de contrato por extras
- [x] Si se aprueban extras y el contrato ya está firmado, se prepara solo un anexo ("Anexo 1 al contrato N.º X") con los extras, su valor, el nuevo total y la cuota "Extras"; el cliente lo firma con el dedo (huella SHA-256, QR de verificación, PDF firmado)
- [x] Plantilla editable del anexo en Plantillas de contrato; tarjeta "Tienes un anexo por firmar" en el portal; anexos en la pestaña Contrato; recordatorio en Mensajes de hoy; aviso al local cuando se firma
- [x] Decisión: un pedido de extras aprobado no se deshace (para quitar extras se hace una cotización nueva); sin contrato firmado no hay anexo, los extras entran en el contrato cuando se genere
- [x] Pruebas: logica_70 y web_70 ampliadas (migración 090)

## Hecho (13 oct 2026) — puntaje de proveedores configurable por local
- [x] Proveedores → Cómo se calcula el puntaje: pesos de calificación, puntualidad, cumplimiento e incidencias (suman 100 %; 0 = no cuenta), minutos de tolerancia, cuánto resta cada incidencia (leve, media, grave), tope del nivel con incidencia grave, umbrales de Excelente/Bueno/Regular, periodo (todo, 12 o 6 meses) y mínimo de eventos ("Sin datos suficientes")
- [x] Vista previa en vivo con 3 proveedores reales (puntaje y puesto antes → después) y botón "Volver a los valores recomendados"; solo el dueño guarda, el equipo ve la explicación; cada cambio queda anotado con quién, cuándo, antes y después
- [x] Se usa en la ficha, el ranking, los selectores de compras y del día del evento, y la puntualidad (también en el portal del proveedor). Sin cambiar nada, los resultados son los mismos de antes
- [x] Pruebas: logica_60 (389) y web_60 (258), migración 091

## Hecho (13 oct 2026) — conexión con el Panel Tukán Central
- [x] `tukan_central.php` (protocolo Tukán Central v1): entrar con un clic desde el panel central (crea el super usuario la primera vez y después usa el mismo; queda en el registro) y cifras para el panel central (negocios por estado igual que el panel, nuevos en 30 días, cobrado del mes y del mes anterior, pruebas que vencen en 7 días, pagos vencidos, eventos y cotizaciones del mes, usuarios, ingreso mensual)
- [x] Botón "← Panel Tukán" en el panel cuando se entró desde el central; `TUKAN_SOLO_CENTRAL=1` apaga el ingreso local del super usuario (emergencia: ponerlo en 0 y `bin/crear_admin.php`)
- [x] Cada enlace dura 60 s y sirve una vez, 30 pedidos por minuto por IP, sin datos personales; sin configurar responde 404
- [x] Comando para conectar: `sudo bash /var/www/turenta/deploy/conectar_central.sh {ID} {SECRETO} {URL}` (guarda en config/.env y hace una prueba local)
- [x] Pruebas: logica_95 (120) y web_95 (157), migración 095. Totales: lógica 1829/1829 y pantallas 1717/1717

## Hecho (13 oct 2026) — Panel Tukán Central v2
- [x] El panel central ya puede, para TuRenta: ver todos los clientes con su estado y vencimiento, abrir su ficha (pagos, últimas acciones, notas, WhatsApp listo), ver las solicitudes (registros nuevos, pruebas por terminar o terminadas, planes por vencer o vencidos, dominios gratis) y marcarlas atendidas, y ver las finanzas por mes
- [x] Desde el central: crear demos (con datos de ejemplo; la clave se ve una sola vez), alargar la prueba (no a quien ya paga), registrar pago con la fecha en que pagó, anular pago, suspender, reactivar, cancelar, cambiar plan, nueva clave del dueño, nota interna y cambiar precios de los planes. Si el central reintenta, no se cobra ni se crea dos veces
- [x] "Por vencer" ahora es lo mismo en todos los programas: al día y vence en 7 días o menos. Los que están en días de gracia cuentan como vencidos (antes TuRenta los contaba como "por vencer")
- [x] Entrar desde el central puede abrir directo la ficha de un negocio (`ir`); los errores traen un código para que el central diga qué pasó; límite 120 pedidos y 30 rechazados por minuto
- [x] Se guarda de dónde vino cada negocio (se registró solo, panel de Tukán o demo del central)
- [x] Pruebas: logica_96 (301) y web_96 (86), migración 096. Totales: lógica 2135/2135 y pantallas 1803/1803
- Falta (no existe en TuRenta): pedidos de demo, comprobantes de pago y soporte. Si se quieren en el central, hay que construirlos aquí primero (subir comprobante desde la pantalla de prueba terminada y un formulario de ayuda)

## Hecho (15 oct 2026) — Pausar y borrar clientes desde el Panel Tukán Central (Anexo A)
- [x] **Pausar** (con tipo: falta de pago, pedido del cliente, revisión, uso indebido u otro, y motivo obligatorio): el negocio queda suspendido; al entrar ve "Tu cuenta está pausada" con el motivo (en revisión y uso indebido, un texto genérico) y el botón de WhatsApp de Tukán. Su página pública, portales, invitaciones, firmas y enlaces dicen "temporalmente no disponible". **Reactivar** lo quita
- [x] **Borrar** (escribiendo el nombre exacto y el motivo; solo el dueño del Panel Central): primero se guarda una copia completa del negocio (todas sus filas de todas sus tablas y la lista de sus archivos) en `respaldos/borrados/` (fuera de la web, solo la lee el programa), y queda en la **papelera**: no entra, no sale en listas ni cifras, sus páginas dan 404 y no recibe tareas ni avisos
- [x] **Restaurar** dentro de 30 días (desde el Panel Central o desde el Panel Tukán del programa, filtro "Papelera"): vuelve tal cual. A los 30 días la tarea de cada hora lo borra de verdad; la copia se guarda 1 año
- [x] Pruebas: logica_97 (127) y web_97 (56), migración 097. Totales: lógica 2262/2262 y pantallas 1859/1859

## Hecho (18 oct 2026) — Programa modular (Anexo B) y "todo se pide demo"
Regla de Dimi: "todos los programas deben ser modulares para encender o apagar las funciones que necesita el cliente, para cobrar más o menos" y "nada se da gratis, todo se pide demo".
- [x] **Base + módulos**: la base la tienen todos (calendario, eventos, clientes, espacios, catálogo, cotizaciones, cuotas y recibos, Mensajes de hoy, página pública, reportes básicos, usuarios). Módulos con su precio al mes: paquetes $3, contratos con firma $6, portal del cliente $6, cobros en línea Payphone $4, formulario/extras/encuesta $5, día del evento $8, invitados con QR $8, plano $6, mobiliario $5, boda en vivo $5, proveedores y compras $7, portal del proveedor $4, incidencias y puntaje $3, metas y reportes avanzados $4, Telegram $3, exportar Excel/Google Calendar $3.
- [x] **Planes = precio base + módulos**: Esencial $19 (solo la base, nuevo), Salón $39 (lo mismo que tenía antes) y Quinta $69 (todo). Precios, descripciones y qué trae cada plan se cambian en Panel Tukán → Planes y precios → Catálogo de módulos
- [x] **Por cliente**: módulos extra (se cobran), módulos quitados (se descuentan) y precio acordado. Precio al mes = plan + extras − quitados (o el acordado); es el que se sugiere al registrar el pago
- [x] **Apagar nunca borra datos**: se esconden menús, pestañas, páginas ("Este módulo no está en tu plan" con su precio y el enlace a Mi plan), botones, portales públicos ("No disponible"), PDF, tareas automáticas, Telegram, Mensajes de hoy y tarjetas del Inicio
- [x] **Mi plan** (dueño): lo que paga, sus módulos encendidos y los que puede agregar con su precio; "Pedir este módulo" → pedido que Tukán aprueba o rechaza (Panel Tukán o Panel Central)
- [x] **Panel Tukán**: ficha del negocio → Módulos con interruptores (enciende solo las dependencias), total al mes, precio acordado y pedidos; catálogo de módulos y planes; pedidos de demo en el Resumen con "Crear demo"
- [x] **Nada gratis**: ya no hay registro con prueba gratis. "Solicitar demo" (nombre, negocio, ciudad, WhatsApp, correo, qué necesita y módulos que le interesan) llega como pedido de demo; Tukán crea la demo (todos los módulos o los que elija, por los días que decida). "Tu demo terminó — contáctanos para contratar"
- [x] **Panel Tukán Central** (revisión 3): acción `modulos`, `cliente.modulos`, `mensual_estimado` con módulos, `cambiar_modulos` y `guardar_modulos` (solo el rol dueño, idempotentes y auditados), solicitudes `cambio_modulos` y `demo_pedida`, `crear_demo` con plan, módulos y pedido de demo, `finanzas.por_modulo`
- [x] Pruebas: logica_98 y web_98 (migración 098). Totales: **lógica 2378/2378 y pantallas 1931/1931**, sin avisos de PHP; revisado en celular (390 px) y computadora

## Hecho (20 oct 2026) — Precios del software solo por cotización
- [x] Regla del dueño: el precio del software nunca se publica. En **Mi plan** los módulos que el cliente no tiene dicen «Precio por cotización» con **Pedir cotización**; los otros planes ya no muestran su precio y también tienen **Pedir cotización** (crea el mismo pedido `cambio_modulos` con los módulos de ese plan y la nota «Pide cotización del plan …»). La pantalla «Este módulo no está en tu plan» ya no dice «Agrégalo por $X al mes»: trae **Pedir cotización** y **Ver Mi plan**. Lo que el cliente paga por su propio plan (y su renovación) se sigue viendo. El Panel Tukán y el Panel Central siguen viendo los precios.
- [x] Sin «gratis» en textos visibles (solo demos; en ventas, «meses de regalo»). README sin tabla de precios: «Precios: por cotización (se definen en el Panel Tukán Central)». Manual actualizado y PDF regenerado.
- [x] Pruebas: logica_98 (texto visible sin «gratis», Mi plan y módulo apagado sin precio) y web_98 (sección nueva: sin precios de módulos ni planes que no tiene, Pedir cotización desde el módulo y desde un plan, sin «gratis» en pantallas del cliente y públicas).

## Hecho (21 oct 2026) — Administradores (pedido del dueño de Tukán)
- [x] Rol nuevo **Administrador** (`admin`, migración 099 repetible: cambia el CHECK `usuarios_rol_check`). En Usuarios: «Administrador (mismos permisos que el dueño)» y el contador «Administradores: 2 de 4».
- [x] **Mismo nivel que el dueño** en todo: menú, Usuarios, Configuración, Mi plan, metas, costos y ganancia, plantillas, formularios, espacios/lugares, puntaje de proveedores, avisos «para el dueño» (campana y Telegram), quién vende y metas. Helper `es_admin_total()` (dueño o administrador) en vez de `es_dueno()` en todas las pantallas; `es_comercial()` también incluye al administrador.
- [x] **Máximo 4 administradores activos** sin contar al dueño: un quinto (crear, activar o cambiar de rol) muestra «Puede tener hasta 4 administradores además del dueño». Se cuenta con la fila del negocio bloqueada (dos personas a la vez no pasan el límite).
- [x] **No cuentan en el plan**: `max_usuarios` cuenta a todos los activos menos los administradores (el dueño sigue contando, como antes). Si un administrador pasa a otro rol, empieza a contar (y se revisa el límite). Mi plan también cuenta sin administradores.
- [x] **Seguridad**: nadie se cambia el rol ni se desactiva a sí mismo; al dueño nadie lo cambia, desactiva ni le da clave nueva desde Usuarios (tampoco un administrador); los administradores sí manejan a otros administradores y usuarios. El rol del POST se valida contra la lista: si no vale, error «Ese tipo de usuario no existe…» y no se guarda nada (nunca un rol por defecto). Todos los formularios de Usuarios llevan token CSRF (probado: sin token, 400 y no se guarda).
- [x] **Exclusivo del dueño** (identidad de la cuenta ante Tukán): ser el contacto del negocio en el Panel Tukán Central/Panel Tukán (nombre, correo y teléfono que se envían), «Nueva clave del dueño» desde Tukán, «Entrar como» del súper usuario, el contacto que ven el personal y los proveedores en sus portales, y no poder ser cambiado ni desactivado. Dentro del programa no quedó ninguna pantalla solo para el dueño.
- [x] Pruebas: logica_99 (63) y web_99 (62). Totales: **lógica 2445/2445 y pantallas 2027/2027**, sin avisos de PHP. Manual (capítulo de equipo, con una administradora en los datos de ejemplo y capturas nuevas), MANUAL.md y guía Primeros pasos actualizados y PDF regenerados.

## Hecho (21 oct 2026) — Tope de IA de las demos: 0,50 (decisión de Dimitry)
- [x] Una demo (estado `prueba`) sin tope propio gasta como máximo **0,50 USD al mes en total** (IA y Ayuda), aunque tenga todos los módulos (`Ia::TOPE_DEMO`, `Ia::esDemo()`). El tope propio de Tukán sigue mandando; al registrar el pago (estado `activo`) vuelve sola a 4,00 / 0,50. Pruebas en `logica_110_ia.php`.

## Hecho (21 oct 2026) — Ayuda, Asistente IA y demo por correo (librerías compartidas del Panel Tukán Central)
- [x] Librerías copiadas sin cambios: `src/tukan_ia.php` (versión 1, `compartido/LEEME_IA.md`) y `src/tukan_correo.php` (versión 1, `compartido/LEEME_CORREO.md`).
- [x] Migración **110** repetible: tablas `tukan_ia_faq` y `tukan_ia_gasto` y `negocios.ia_tope_usd`. La papelera no toca `tukan_ia_gasto` (negocio_id de texto).
- [x] **Ayuda** para todos los planes y cargos (Más → Ayuda): «¿Qué necesita hacer?» → FAQ guardada, IA con el manual (manual.html convertido y en caché) o el manual tal cual + WhatsApp. 10 preguntas por minuto por usuario; sin el módulo, 30 respuestas nuevas al mes por negocio. Nunca dice «límite», «cupo» ni «saldo».
- [x] Módulo **Asistente IA** (`asistente_ia`, grupo Extras): **incluido en el plan Quinta** (el más caro; migración 111 repetible, `'*,asistente_ia'`); en Esencial y Salón, extra por cotización («Pídalo y le cotizamos», sin precio visible). «*» solo no lo trae. **Cotización a partir de un mensaje**: pega el WhatsApp del cliente → JSON validado contra espacios, paquetes y productos del negocio → borrador → guarda evento (consulta) y cotización (borrador). Sin IA: mensaje neutro con el enlace a Nuevo evento.
- [x] **Guardián por negocio**: **4,00 USD al mes con el módulo** (incluido en Quinta o comprado aparte: decisión del dueño, 21 oct) y 0,50 sin él, solo para la Ayuda (lo que sugiere la guía; el pedido decía 0,30 «o lo que sugiera la guía»), o el tope que ponga Tukán (`ia_tope`). Tope silencioso.
- [x] Panel Tukán → **Preguntas IA**: listar (Por revisar primero), aprobar, editar, borrar y agregar, con CSRF y en el registro del panel.
- [x] Conector v2 (Anexo C): acción **`ia_uso`** y operación **`ia_tope`** (solo el dueño del Central; botón «Tope de IA» en la ficha).
- [x] **Enviar por correo** en la demo lista (ficha del negocio recién creado): `tukan_correo_demo()` desde info@tukansoftware.com, POST con CSRF, contraseña en un sello cifrado de un solo uso (no se guarda), auditoría «Envió la demo por correo a …» sin la contraseña; sin `/etc/tukan-correo.env`: «Configure el correo de Tukán».
- [x] Pruebas con la API de Anthropic y el SMTP de mentira (`tests/anthropic_falso.py`, `tests/smtp_falso.py`): logica_110 (83) y web_110 (87). Totales: **lógica 2530/2530 y pantallas 2114/2114**, sin avisos de PHP. Manual (capítulo 19 «Ayuda e inteligencia artificial», capturas 36 y 37), MANUAL.md y Primeros pasos actualizados y PDF regenerados.

## Hecho (28 oct 2026) — Planes de $20 y $45 (decisión de Dimitry, igual en todos los programas Tukán)
- [x] Solo **dos planes** (antes Esencial $19 / Salón $39 / Quinta $69). Precios internos, nunca publicados al cliente:
  **Básico $20** (código `esencial`): la base + **3 módulos que el cliente elige** (ej. contratos, invitados y día del evento); cada módulo más **$5**; límites de Esencial (1 espacio, 2 usuarios).
  **Completo $45** (código `quinta`): todos los módulos con el **Asistente IA** incluido (mismo tope de uso), sin límites. `PLAN_DEFECTO` sigue en `quinta`.
- [x] Migración **112** repetible: `planes.modulos_elegir` y `planes.precio_modulo_extra`; el plan del medio (Salón, `salon`) desaparece: sus negocios y pagos pasan a Completo;
  quien pagaba Esencial conserva lo que traía su plan como módulos elegidos; Asistente IA $5 en el catálogo. Nombres y precios se ponen una sola vez (marca `planes_20_45`).
- [x] `Modulos::estado()`: los primeros N extra (orden del catálogo) van incluidos, los demás a `precio_modulo_extra`; `FUERA_DEL_CUPO` = Asistente IA ($5 aparte). Devuelve `elegidos`, `por_elegir` y `precio_por_extra`.
- [x] **Mi plan**: «Tu plan trae 3 módulos a tu elección: te quedan X», los elegidos «Incluido · lo elegiste», los demás «Extra · $5», y «Puedes elegirlo en tu plan» con **Elegir este módulo** (sin precios de lo que no tiene).
  **Planes y precios** (súper usuario) y ficha del negocio con los módulos a elegir. **Conector v2**: `planes` y `modulos` con `modulos_elegir` y `precio_modulo_extra`, `modulos.fuera_del_cupo`, `cliente.modulos` con elegidos, `guardar_plan` con los campos nuevos, `finanzas.por_modulo` y el monto de cada pedido con el precio real.
- [x] Pruebas: logica_112 y web_112 (las pruebas viejas emulan los planes de antes con `planes_de_antes`). Puertos de las pruebas de IA por variable (`IA_PUERTO_LOGICA`, `SMTP_PUERTO_LOGICA`). Totales: **lógica 2587/2587 y pantallas 2156/2156**, sin avisos de PHP. Manual, MANUAL.md, guía y Primeros pasos actualizados y PDF regenerados.

## Hecho (28 oct 2026) — App de Android para Google Play (1.0.0, sin cobro dentro de la app)
- [x] App `com.tukansoftware.turenta` en qfcorp/tukan-apps (ícono: calendario con corazón dorado sobre vino), ficha en español e inglés con 8 capturas y guía `tienda/turenta/PUBLICAR_TURENTA_CON_CLAUDE.md`.
- [x] `/.well-known/assetlinks.json` desde `src/AppAndroid.php` (huella de la llave de subida + la de Google en `config/.env` con `deploy/app_android.sh HUELLA`; el instalador la conserva).
- [x] **Modo app** (`?app=play`, cookie `te_app`, `en_app_android()`): Mi plan sin montos, sin «Pedir cotización», sin «Pagar», sin otros planes ni pagos hechos (solo elegir los módulos incluidos); módulo apagado, franja «Renovar», demo terminada / vencido / pausada y límites de espacios y usuarios sin enlaces para pagar afuera. El servidor rechaza cotizaciones desde la app. Lo que el local cobra a sus clientes (cuotas, Payphone del portal) se queda: son servicios reales.
- [x] Privacidad: app de Google Play, Anthropic (IA), sección `#borrar-cuenta`; `/privacidad.php`; **Mi cuenta → Borrar mi cuenta**.
- [x] Cuenta del revisor de Google: `deploy/demo_google.php crear | borrar` (negocio «Quinta Ejemplo Google Play» aparte, demo 1 año sin pagos, 6 eventos; el revisor no cambia su contraseña).
- [x] Cobro con Google Play preparado y **apagado**: `TURENTA_GOOGLE_PLAY_COBRO=1` (`cobro_google_play()`), sin pantalla de compra todavía (docs/GOOGLE_PLAY.md).
- [x] Pruebas: logica_120 (40) y web_120 (99). Totales: **lógica 2627/2627 y pantallas 2255/2255** (web_96 «El 121: 429» falla a veces en la corrida completa si cruza el cambio de minuto; sola pasa). Manual (capítulo 3: borrar la cuenta y la app) y MANUAL.md actualizados, PDF regenerado.

## Pendiente
0000c. [ ] DIMI: Google Play (ver docs/GOOGLE_PLAY.md): actualizar con el instalador, `sudo bash /var/www/turenta/deploy/app_android.sh` (OK), `php /var/www/turenta/deploy/demo_google.php crear` (copiar la contraseña) y publicar con tukan-apps/tienda/turenta/PUBLICAR_TURENTA_CON_CLAUDE.md; después de la primera subida, `app_android.sh HUELLA_DE_GOOGLE`
0000b. [ ] DIMI: actualizar en el servidor (volver a correr el instalador del README): aplica la migración 112 (planes de $20 y $45). Revisar en Panel Tukán → Planes y precios los dos planes y avisar a los clientes que tenían el plan Salón (pasan a Completo)
0000a. [ ] DIMI: actualizar en el servidor (volver a correr el instalador del README): aplica las migraciones 110 (ayuda e IA) y 111 (Asistente IA incluido en Quinta). La IA usa `/etc/tukan-ia.env` y el correo `/etc/tukan-correo.env` del Panel Tukán Central (`deploy/configurar_ia.sh` y `deploy/configurar_correo.sh`); `www-data` debe poder leerlos. Revisar Panel Tukán → Preguntas IA cada semana
0000. [ ] DIMI: actualizar en el servidor (volver a correr el instalador del README): aplica la migración 099 (rol administrador)
000. [ ] DIMI: actualizar en el servidor (volver a correr el instalador del README): aplica la migración 098 (módulos y pedidos de demo). Revisar los precios de los módulos en Panel Tukán → Planes y precios
00. [ ] DIMI: actualizar en el servidor (volver a correr el instalador del README): crea la carpeta `respaldos/borrados` y pasa las tareas de cada hora al usuario `www-data`
0. [ ] DIMI: cuando el Panel Tukán Central esté listo, correr `sudo bash /var/www/turenta/deploy/conectar_central.sh {ID} {SECRETO} {URL}` con los datos que da el panel (después de actualizar TuRenta)
1. [ ] DIMI: instalar en el servidor (comando en el README) y crear la ruta del túnel `turenta.tukanec.com`
2. [ ] DIMI: crear el super usuario del panel Tukán (`php /var/www/turenta/bin/crear_admin.php`)
3. [ ] Probar Payphone con claves reales de un local (solo se probó con simulador)
4. [ ] Lista de 10 salones/quintas conocidos por QF Producciones → entrevistas y piloto con demo para 2-3
5. [ ] Decidir nombre definitivo (TuRenta / TuFecha / TuQuinta), precios y qué va en cada plan después del piloto
6. [ ] Facturación electrónica (opción B, proveedor registrado en el SRI) cuando se decida
7. [ ] WhatsApp automático (API oficial, pagada); hoy hay WhatsApp con un toque
8. [ ] Agregar TuRenta a tukansoftware.com
9. [ ] Manual en PDF y presentación de ventas (como TuSalón)
