# Guía para programar en TuRenta

TuRenta es el programa de la familia Tukán para **salones de eventos, quintas y haciendas** (Ecuador).
Misma forma de trabajar que TuSalón (qfcorp/tusalon): PHP 8.3+ sin framework, PostgreSQL 16, sin librerías de JavaScript.

## Estructura
| Carpeta | Qué tiene |
|---|---|
| `public/index.php` | Lista de rutas (`/?r=<ruta>`): públicas, privadas, de coordinador y del panel Tukán |
| `app/bootstrap.php` | Configuración, sesión y funciones de ayuda: `db()`, `e()`, `url()`, `url_completa()`, `redirigir()`, `dinero()`, `fecha_corta()`, `fecha_larga()`, `hora_corta()`, `aviso()`, `campo_csrf()`, `verificar_csrf()`, `es_post()`, `usuario()`, `es_admin_total()`, `es_dueno()`, `es_comercial()`, `puede_editar()`, `exigir()`, `es_quinta()`, `requiere_quinta()`, `vista()`, `vista_publica()`, `vista_admin()`, `numero()`, `campo()`, `registrar_cambio()`, `ip_visitante()` |
| `app/paginas/<ruta>.php` | La lógica de cada pantalla (lee el pedido, llama a `src/`, muestra una vista) |
| `app/vistas/<nombre>.php` | El HTML de cada pantalla. `_diseno.php` (sistema), `_publico.php` (páginas públicas), `_admin.php` (panel Tukán), `_evento_tabs.php` (cabecera + pestañas de un evento) |
| `app/pdf/<tipo>.php` | Cada PDF (`/?r=pdf&tipo=<tipo>&id=<id>`), con la base `src/Pdf.php` (TCPDF) |
| `src/` | La lógica del negocio (clases `TuRenta\...`) |
| `db/schema.sql` | Todas las tablas. Cambios posteriores: `db/migraciones/NNN_nombre.sql` (se pueden correr muchas veces: `IF NOT EXISTS`) |
| `public/assets/` | `app.css` y `app.js` comunes; cada módulo puede tener `mod_<modulo>.css` y `mod_<modulo>.js` (se cargan solos) |
| `tests/` | Pruebas: `logica_*.php` (lógica) y `web_*.sh` (pantallas de punta a punta) |

## Reglas
- Todo el texto en **español sencillo de Ecuador** (usuarios que saben poco de tecnología). Mensajes de error claros que dicen qué hacer.
- Cada tabla lleva `negocio_id`: **siempre** filtrar por el negocio del usuario (`$u['negocio_id']`). Un negocio nunca ve datos de otro.
- Formularios: siempre `campo_csrf()` y `verificar_csrf()`. Después de un POST correcto: `aviso('...')` + `redirigir(...)`.
- Errores de negocio: las clases lanzan `RuntimeException` con un mensaje para el usuario; la página lo atrapa y lo muestra.
- Mostrar con `e()` todo lo que venga de la base o del usuario.
- Dinero con `dinero()`, fechas con `fecha_corta()` / `fecha_larga()`.
- Permisos: `consulta` solo mira (`puede_editar`), `coordinador` solo el día del evento (lista `$deCoordinador`), ventas y cobros solo `es_comercial` (dueño, administrador o vendedor), configuración, usuarios, Mi plan y todo lo «del dueño» con `es_admin_total` (dueño o administrador: hasta 4 administradores con el mismo nivel). `es_dueno` no es un permiso: solo para la identidad de la cuenta ante Tukán.
- Planes (migración 112, 28 oct 2026): solo **Básico** (`esencial`, $20: la base + 3 módulos que elige el cliente, cada uno más $5) y **Completo** (`quinta`, $45: todo con el Asistente IA). Lo que hace cada pantalla lo decide su **módulo** (`Modulos::RUTAS`, `modulo_activo()`), nunca el código del plan. `planes.modulos_elegir` / `planes.precio_modulo_extra` y `Modulos::FUERA_DEL_CUPO` (Asistente IA, no cuenta entre los elegidos). Precios: internos, nunca en pantallas del cliente para lo que no tiene.
- Diseño: celular primero. Usar las clases de `app.css`: `cabecera`, `bloque`, `campo`, `fila-campos`, `rejilla-form`, `boton`, `boton-claro`, `boton-chico`, `boton-peligro`, `boton-wa`, `tabla`, `tabla-scroll`, `num`, `cifras`/`cifra-tarjeta`, `ev-estado ev-<estado>`, `pestanas`, `filas`, `aviso aviso-ok|aviso-error`, `vacio`, `barra`.
- WhatsApp con un toque: `\TuRenta\WhatsApp::enlace($telefono, $mensaje)`.

## Pruebas
```bash
# Lógica (borra y recrea la base de prueba)
TURENTA_DB_NAME=turenta_prueba TURENTA_DB_USER=turenta TURENTA_DB_PASS=prueba php tests/run_tests.php
# Pantallas (levanta un sitio de prueba solo)
bash tests/run_web.sh
```

## Cuidados (aprendidos al construirlo)
- `vista()` y `vista_publica()` usan `extract(..., EXTR_SKIP)`: una vista **no recibe** variables llamadas `nombre`, `datos`, `titulo`, `marca` ni `u` (chocan con los parámetros). Usa otros nombres.
- Las clases genéricas de `app.css` (`.hora`, `.columna`, `.ahora`…) vienen de TuSalón: en los módulos usa un prefijo propio (`doc-`, `dia-`, `g-`, `ev-`).
- `Eventos::guardarCliente` no deja vaciar datos al editar; para la ficha del cliente se usa `Ventas::editarCliente()`.
- `Pagos::registrar()` deja en `$pagos->ultimoMensaje` lo que pasó con la fecha (ej. "Reserva pagada: la fecha quedó separada.").
- Números: `numero()` y `Cotizaciones::num()` entienden 1.234,50 · 1234,50 · 1234.50 · 12.000.
- Formulario del evento (070): cada evento tiene una **copia** de la plantilla de su tipo (se crea la primera vez que alguien lo abre: `Cuestionarios::deEvento`). Cambiar la plantilla no cambia lo ya copiado. En el formulario cada pregunta manda `en[]=<id>`: una pregunta presente sin valor se guarda vacía (así se desmarcan todas las casillas de "varias").
- Extras aprobados (070): `Extras::aprobar` crea una versión NUEVA de la cotización aceptada con las líneas del pedido (la anterior y los borradores quedan "reemplazada"), cambia `eventos.total` y agrega UNA cuota "Extras: …" al final del plan (vence con el saldo) sin rehacer las cuotas; si el evento no tenía plan, arma el plan completo. Las cuotas siguen sumando el total. El contrato firmado no cambia.
- Anexos (090): tabla aparte `contrato_anexos` (no van en `contratos`, así `Contratos::vigente`, la numeración y los recordatorios del contrato no cambian). Se crean en `Extras::aprobar` solo si el evento tiene contrato FIRMADO; si el contrato se anula, el anexo pendiente queda sin efecto (el contrato nuevo ya trae los extras). La plantilla del anexo es `plantillas_contrato.tipo = 'anexo'` (una por negocio, con sus propias variables `Anexos::VARIABLES`); las consultas de plantillas de contrato filtran `tipo = 'contrato'`. Un pedido aprobado NO se puede deshacer: si el cliente se arrepiente, se hace una cotización nueva sin los extras (al aceptarla se rehace el plan).
- Mensajes de hoy: los tipos `cuestionario` (070) y `anexo` (090) están en el CHECK de `mensajes_enviados`. Si otra migración vuelve a crear ese CHECK, debe incluir los dos. El mensaje de reseña lleva el enlace de la encuesta (`?r=encuesta&t=<eventos.token_encuesta>`) mientras no se responda.
- Plano (080): rutas `/?r=plano` (distribuciones de los espacios), `&p=<plano>` (una distribución), `&id=<evento>` (pestaña "Plano" del evento), `&json=1`. El editor guarda con `fetch` (POST `accion=guardar`, `csrf`, `version`, `elementos` = JSON) y la página SIEMPRE responde JSON (400 CSRF, 403 permiso, 404 de otro negocio, 409 otra persona guardó, 422 dato malo). `Planos::validar()` revisa todo otra vez en el servidor (centro dentro del salón, tamaños, puestos 1–100, máximo 300).
- Mesas del plano = tabla `mesas` de invitados (`plano_elementos.mesa_id`, ON DELETE CASCADE): mesa nueva en el plano crea su mesa; nombre y puestos del plano cambian la mesa; el plano siempre muestra lo de invitados (`Planos::datos`). Quitar del plano una mesa vacía la borra; con invitados se queda "sin ubicar". Una `mesa_id` de otro evento o que ya no existe NO se une: se crea una mesa nueva. Ocupados = confirmados + pases de los que no responden (igual que `Invitados::mesas`).
- Mismo dibujo en tres lugares: `Planos::svg()` (portal, vistas chicas, sin JavaScript), `mod_plano.js` y `PdfPlano` (polígonos girados a mano). Si cambias colores (`Planos::COLORES`), sillas (`Planos::sillas`) o el giro del texto (`Planos::giroTexto`), cámbialo en los tres.
- Metas (080): "vendido" sale de `Reportes::vendidos(..., 'venta')` (la misma regla de Reportes → Ventas); no inventes otra. `metas_ventas.vendedor_id` NULL = del local y `mes` NULL = la de siempre (`UNIQUE NULLS NOT DISTINCT`). Los meses que todavía no llegan no cuentan en los totales del reporte. Los avances del Inicio y de Reportes salen de vistas parciales (`_metas_inicio.php`, `_metas_reporte.php`) con variables `$mt…` para no pisar las de la página.
- Proveedores (060): saldo de una compra = (factura_total si ya llegó, si no total) − retención − descuentos de incidencias − pagos válidos. Usa `Compras::SQL_SALDO` / `Compras::completar()`; no lo vuelvas a calcular a mano. Solo las compras `pedida`, `confirmada` y `entregada` son deuda y costo (`Compras::ACTIVAS`); `cotizacion` (pedido de precio) y `cancelada`, no.
- Ganancia (060): el costo real del evento = gastos + compras (`Compras::sqlCostoEvento`, valor − descuentos; la retención NO baja el costo) + personal. Una compra no crea un gasto: no registres el mismo pago en los dos lados. Las compras sin evento suman a los gastos generales del reporte.
- Pagos a proveedores nunca se borran (`anularPago` con motivo). No se paga más que el saldo (la compra se bloquea con `FOR UPDATE`). Una compra con pagos no se cancela.
- Al pasar una compra con evento a pedida/confirmada se agrega (o se une) el proveedor en `evento_proveedores.compra_id`; al cancelarla se suelta (`compra_id = NULL`) pero el proveedor queda en el día del evento.
- `proveedor_movimientos` es la bitácora: escribe siempre con `ProveedoresFicha::anotar()` (también copia al `registro_cambios` del evento). Origen `usuario`, `proveedor` (portal) o `sistema`.
- Archivos de proveedores: `Adjuntos` (foto limpia con Fotos, PDF revisado por `%PDF-`, XML) en `uploads/proveedores`; se sirven por `/?r=proveedor_archivo&tipo=documento|incidencia|factura|xml|comprobante&id=` (el XML siempre se descarga, con `CSP: sandbox`). `FacturaXml::leer` rechaza `<!DOCTYPE>`/`<!ENTITY>` (XXE).
- Puntaje de proveedores (091): la fórmula vive SOLO en `PuntajeProveedores::calcular()` y las reglas en `puntaje_proveedores_config` (sin fila = `PuntajeProveedores::RECOMENDADO`, lo de siempre). No escribas pesos, umbrales ni la tolerancia en otro lado. `ProveedoresFicha::puntajes($nid, $hoy, $cfg)` acepta otra configuración para la vista previa (no guarda). Los pesos deben sumar 100 (lo revisa `validar()` y un CHECK de la base). Guardar anota en `registro_cambios` (sin evento) "Puntaje de proveedores: … antes → después". Solo el dueño guarda en `/?r=puntaje_proveedores`; los demás ven la explicación.
- Puntualidad: la llegada pactada es el mismo día del evento (solo de madrugada en un evento de noche es el día siguiente: `ProveedoresFicha::momentoLlegada`), tolerancia del negocio (`ProveedoresFicha::tolerancia($negocioId)`; pásala a `puntualidad($ep, $tol)`, sin ella usa la recomendada). Los bloques del cronograma con `evento_proveedor_id` también cuentan. `DiaEvento::guardarBloque` solo toca `evento_proveedor_id` si viene esa clave.
- Dinero de proveedores: lo ve quien no es coordinador; lo cambia solo `es_comercial`. El coordinador entra a `proveedor` (ficha sin cuenta, precios ni banco), `incidencias` y `proveedor_archivo` (sin facturas ni comprobantes), marca llegadas y califica; en incidencias no pone costo ni descuento.
- En pruebas: los botones con `name="accion"` dentro de un form que también tiene `<input hidden name="accion">` ganan porque van después (PHP toma el último).
- Texto en PHP: `"«$etq»"` NO funciona (PHP toma los bytes de « » como parte del nombre de la variable); usa `"«{$etq}»"`.
- Los `mod_*.js` se cargan con `defer` en todas las páginas: llama a la función de arranque al FINAL del archivo (si la llamas antes de definir los `const`, falla con "Cannot access … before initialization").

- Panel Tukán Central (095): el contrato es el protocolo **Tukán Central v1** (igual en todos los programas Tukán; no lo cambies solo aquí). `public/tukan_central.php` no pasa por `index.php`; valida en el orden del protocolo con `TukanCentral::validar()` (firma con `hash_equals`, v/tipo/inst, tiempo ±30 s y máximo 60 s, nonce de un solo uso en `tukan_central_nonces`, correo) y antes cuenta el límite de 30/min/IP (`tukan_central_pedidos`, responde 429). Sin ID o secreto (o secreto de menos de 32) responde 404. El estado sale de `PanelTukan` (`negocios()`, `cobradoMes()`, `ingresoMensual()`): si cambias cómo cuenta el panel, el estado cambia igual. `negocios.vencidos` = Vencido + Prueba terminada (lo mismo que "Sin pagar") y `suspendidos` = Suspendido + Cancelado, así los grupos suman el total. El estado no abre sesión (`TUKAN_SIN_SESION`). La sesión del SSO es la misma del ingreso normal (`admin_id`) más `desde_central` (muestra "← Panel Tukán"; `admin_salir` y el ingreso local la quitan). `TUKAN_SOLO_CENTRAL=1` solo apaga el ingreso local si el conector está configurado.
- Panel Tukán Central v2 (096): el contrato es `PROTOCOLO_V2.md` del Central (§10.2 es el mapeo de TuRenta; no lo cambies solo aquí). Todo lo que no es `sso`/`estado` es v2 y lo atiende `TukanCentralV2::procesar()` (POST, `Content-Type: application/json`, cuerpo ≤ 64 KiB, token en `X-Tukan-Token` con `v:2` y `bh` = sha256 del cuerpo exacto; lecturas ≤ 60 s, `ejecutar` ≤ 30 s). Validación en el orden de §3.1 y errores `{"ok":false,"protocolo":2,"codigo",…}` con `TukanCentralError` (422 con `campo` para datos malos). Los rechazos v1 (`TukanCentralRechazo`) también traen `codigo` (`X-Tukan-Codigo` en el SSO). Límite: 120 pedidos y 30 rechazados (401) por minuto por IP (`tukan_central_pedidos.rechazados`).
  - Estado del cliente (§4.2), una sola regla para `clientes.contadores` y `estado.negocios`: `prueba`→prueba; `activo`→`por_vencer` si `activo_hasta` ≤ hoy+7 (sin fecha → activo); `por_vencer` de TuRenta (gracia) y `prueba_vencida`→`vencido`; `cancelado`→`suspendido`. Lo calcula `TukanCentralV2::clasificar()`; `TukanCentral::estado()` usa `contadores()`. Si cambias `Planes::estadoCuenta()`, las dos cifras cambian igual.
  - Solicitudes: se arman al momento (no hay tabla) en `todasLasSolicitudes()`; ids estables con la fecha de la etapa (`seguimiento_prueba:{id}:{etapa}:{prueba_hasta}`, `renovacion:{id}:{etapa}:{activo_hasta}`, `registro_nuevo:{id}`, `dominio_gratis:{pago}`); lo atendido va en `tukan_central_atenciones`. Alargar la prueba borra las atenciones de su seguimiento (reinicia los avisos). `negocios.origen` (`registro`, `super`, `central`) lo pone `Altas::crearNegocioConDueno(..., $origen)`.
  - `ejecutar`: `operacion_id` en `tukan_central_operaciones` (fila `en_curso` fuera de la transacción; la operación va en una transacción con `SELECT … FOR UPDATE` del negocio; respuesta guardada SIN claves con `sinClaves()`; error de validación = `error` guardado; excepción = se borra la fila). Mismo id con otro cuerpo → 409; repetido → la respuesta guardada con `repetida: true`. Cada operación correcta deja una línea `Panel Tukán Central · … (op xxxxxxxx)` en `registro_admin` con el super usuario del correo del token (`TukanCentral::buscarSuper()`).
  - Cuidado: `PDOException` es `RuntimeException`; en el conector siempre se relanza antes de convertir los `RuntimeException` del negocio en 422 (si no, un error de base saldría como "dato inválido" con el texto técnico).
  - `Planes::suscribir(..., $pagadoEn)` y `PanelTukan::registrarPago(['fecha' => …])` guardan la fecha del pago (`pagado_en`); sin ella, hoy. Anular el único pago de un negocio activo lo vuelve a `prueba` (el panel no lo hacía: quedaba "al día sin vencimiento").
  - Anexo A (097, `Papelera`): `pausar` = `suspendido` + `negocios.pausa_tipo/pausa_motivo/pausa_en` (`PanelTukan::cambiarEstado()` los borra al salir de suspendido; `suspender` guarda el motivo sin tipo → texto genérico). Papelera = `negocios.eliminado_en/_motivo/_por/_copia` (borrado lógico).
  - **Toda consulta nueva que liste o cuente negocios debe excluir `eliminado_en IS NOT NULL`** (`PanelTukan::negocios()` ya lo hace; pagos: `PanelTukan::SQL_PAGO_CUENTA` / `SQL_PAGO_VIVO`). `usuario()` y el ingreso no aceptan negocios en la papelera.
  - Las tablas del negocio salen del esquema (`Papelera::tablas()`: las que tienen `negocio_id` y sus hijas por clave foránea). Una tabla nueva entra sola en la copia y en la purga; si una tabla nueva **no** debe borrarse con el negocio, que su clave sea `ON DELETE SET NULL` (como `registro_admin`).
  - **Página pública nueva**: agrégala a `Papelera::RUTAS_PUBLICAS` (cómo se encuentra su negocio por el enlace) o a `PUBLICAS_SIN_NEGOCIO`; `public/index.php` contesta 503 (pausado) o 404 (papelera) antes de abrirla. logica_97 falla si una página pública no está en ninguna de las dos.
  - Copias: `TURENTA_BORRADOS` (por defecto `respaldos/borrados/`, carpeta 700 y archivos 600 de `www-data`). La purga (`Papelera::purgar()`, desde `Automaticas::correr()`) borra de hija a madre (respeta los `RESTRICT`), mueve los archivos subidos a `<copia>.archivos/` y borra las copias de más de 365 días. Por eso `bin/tareas.php` corre en el crontab de `www-data`.

## Módulos (Anexo B) — reglas para programar
- `src/Modulos.php` es el registro: `BASE`, `CATALOGO` (nombre, ícono, descripción, precio por defecto, dependencias, grupo),
  `RUTAS` (cada ruta de `public/index.php` → módulo, o `''` = base), `PDFS` (cada `app/pdf/*.php`), `MENSAJES` (grupos de Mensajes de hoy)
  y `SIN_CORTE` (rutas de un módulo que el router no corta: `payphone` confirma pagos ya iniciados). Precio, descripción y orden se
  guardan en la tabla `modulos`; los módulos de cada plan, en `planes.modulos` (`*` = todos).
- **Una sola pregunta**: `modulo_activo($u o $negocio o $id, 'id')`. `requiere_modulo($u, 'id')` muestra "Este módulo no está en tu plan".
  El router corta solo las páginas privadas (403 + `modulo_apagado`) y públicas (403 + "No disponible", usa `Papelera::negocioDeRuta`).
  Dentro de las páginas se esconden secciones, pestañas, botones y enlaces del módulo apagado; los POST de un módulo apagado se niegan.
- **Ruta nueva = agregarla en `Modulos::RUTAS`** (y PDF nuevo en `PDFS`): `logica_98` falla si una ruta o un PDF no está mapeado.
- Apagar nunca borra: no se tocan los datos. Tareas automáticas y Telegram revisan el módulo (`Automaticas::correr`, `Telegram::textoAUsuarios`).
- Precio: `Modulos::estado()` (del_plan, extra, quitados, activos, pagados, precio_plan, precio_extra, descuento, precio_acordado, mensual);
  `Planes::cotizarNegocio()` lo usa para sugerir el monto. El descuento de los quitados nunca deja el precio en negativo.
- Demo (estado `prueba`): todos los módulos, o `negocios.modulos_prueba` si Tukán eligió otros. No hay registro abierto con periodo de prueba:
  `PedidosDemo` guarda los pedidos de `/?r=registro`.
- En las pruebas de pantallas, `registrar` crea la cuenta en demo (como Tukán, con `tests/crear_cuenta.php`) y `pagar_plan salon` la pasa a pagada.

## Archivos de cada módulo
- 098 módulos y pedidos de demo: `src/Modulos.php`, `src/PedidosDemo.php`, `app/paginas/planes.php` (Mi plan), `app/paginas/registro.php` (Solicitar demo), `app/vistas/modulo_apagado.php`, `app/vistas/_admin_modulos.php`, `public/assets/mod_modulos.*`, `tests/logica_98_modulos.php`, `tests/web_98_modulos.sh`
| Módulo | Pruebas | Migración | Clases |
|---|---|---|---|
| Núcleo | logica_00, web_00 | schema.sql | Eventos, Cotizaciones, Pagos, Contratos, Planes, Altas, Notificaciones, Telegram, Fotos, Pdf, WhatsApp, Letras |
| Ventas y calendario | logica_10, web_10 | 010 | Ventas |
| Cotizaciones y contratos | logica_20, web_20 | 020 | Catalogo, PdfDocumentos |
| Cobros y portal del cliente | logica_30, web_30 | 030 | Cobranza, PdfCobros |
| Día del evento e invitados | logica_40, web_40 | 040 | DiaEvento, Invitados, PdfDia, Qr |
| Gestión y panel Tukán | logica_50, web_50 | 050 | Automaticas, Reportes, PanelTukan, Usuarios, Configuracion |
| Proveedores y compras: ficha, documentos, compras, pagos, por pagar, XML del SRI, cumplimiento, incidencias, portal del proveedor, puntaje configurable | logica_60, web_60 | 060, 091 | Compras, ProveedoresFicha, Incidencias, FacturaXml, PdfCompras, Adjuntos, AvisosProveedores, PuntajeProveedores (CSS `mod_proveedores.css`, prefijo `pv-`) |
| Portal del cliente 2: formulario del evento, extras en línea, encuesta de satisfacción | logica_70, web_70 | 070 | Cuestionarios, Extras, Encuestas, PdfFormulario |
| Anexos de contrato por extras aprobados | logica_70, web_70 | 090 | Anexos (PDF con PdfDocumentos::contrato) |
| Plano visual del salón y metas de ventas | logica_80, web_80 | 080 | Planos, PdfPlano, Metas (editor: `public/assets/mod_plano.js` + `mod_plano.css`, prefijo `pl-`; metas: `mod_metas.css`, prefijo `mt-`) |
| Conector con el Panel Tukán Central (SSO, estado, solo central, `deploy/conectar_central.sh`) | logica_95, web_95 | 095 | TukanCentral, TukanCentralRechazo (entrada `public/tukan_central.php`, fuera del enrutador) |
| Panel Tukán Central v2 (capacidades, clientes, cliente, cotizar, planes, solicitudes, finanzas, ejecutar; SSO con `ir`) | logica_96, web_96 | 096 | TukanCentralV2, TukanCentralError |
| Pausar y borrar clientes (Anexo A: pausar, papelera, restaurar, purga a los 30 días) | logica_97, web_97 | 097 | Papelera (+ `public/index.php`, `prueba_terminada`, `admin_negocios` con filtro Papelera) |

## Ayuda e IA (110) — reglas para programar
- Librerías compartidas del Panel Tukán Central, copiadas SIN cambios: `src/tukan_ia.php` (`compartido/LEEME_IA.md`) y `src/tukan_correo.php`
  (`compartido/LEEME_CORREO.md`). Si sale otra versión (`TUKAN_IA_VERSION` / `TUKAN_CORREO_VERSION`), se vuelven a copiar; no se editan aquí.
- Todo lo de TuRenta pasa por `src/Ia.php` (`ia()` en bootstrap): `tope()`, `guardian()`, `ayuda()`, `manual()`, `proponerCotizacion()`,
  `validarPropuesta()`, `leerBorrador()`, `guardarBorrador()`, `uso()` (conector). Una función nueva con IA: `tukan_ia_llamar([...], ['guardian' => ia()->guardian($nid),
  'programa' => Ia::PROGRAMA, 'funcion' => '…'])` y, si `!$r['ok']`, **siempre** `tukan_ia_mensaje_neutro()` con el enlace para hacerlo a mano.
  Nunca mostrar «límite», «cupo», «saldo», «crédito» ni el motivo. Texto de otras personas: `tukan_ia_datos('etiqueta', $texto)`; JSON: `'json' => true` + `'validar'`.
- `asistente_ia` está en `Modulos::SOLO_A_PEDIDO`: `*` solo no lo trae (`Modulos::todos()`); un plan lo incluye con `*,asistente_ia` (Quinta, migración 111;
  `Modulos::parse` / `Modulos::csvPlan`). En los demás planes, precio 0 = por cotización (`Modulos::porCotizacion`). Tope con el módulo: `Ia::TOPE_MODULO` = 4,00.
- `tukan_ia_gasto.negocio_id` es TEXTO: la Papelera solo toma tablas con `negocio_id` entero (el gasto de IA no se copia ni se borra con el negocio).
- Pruebas: `tests/base.php` y `tests/run_web.sh` apuntan `TUKAN_IA_ENV` y `TUKAN_CORREO_ENV` a archivos de prueba (nunca `/etc`). La API y el SMTP son de mentira
  (`tests/anthropic_falso.py` con `RESPONDER:{json}` en el texto, `tests/smtp_falso.py --modo plano` con `TUKAN_SMTP_SEGURIDAD=ninguna` en 127.0.0.1).
- La contraseña de la demo nunca se guarda: `CorreoDemo::sellar()` (cifrada con la llave de la sesión del súper, 30 min) y `gastar()` al enviar bien.

| Módulo | Pruebas | Migración | Clases |
|---|---|---|---|
| Ayuda, Asistente IA, FAQ del súper, `ia_uso`/`ia_tope`, demo por correo | logica_110, web_110 | 110, 111 | Ia, CorreoDemo (+ `src/tukan_ia.php`, `src/tukan_correo.php`, `mod_ia.css`, prefijo `ia-`) |
| App de Android (Google Play): assetlinks, modo app, cuenta del revisor | logica_120, web_120 | — | AppAndroid, DemoGoogle (+ `deploy/app_android.sh`, `deploy/demo_google.php`, docs/GOOGLE_PLAY.md) |

## App de Android (Google Play) — reglas para programar
- Dentro de la app (`en_app_android()`, cookie `te_app` que deja `?app=play`) **nunca** se muestran precios del software, planes para comprar,
  «Pedir cotización», «Pagar», «Renovar» ni enlaces (WhatsApp, transferencia, Payphone) para pagar el software por fuera. Toda pantalla nueva que
  muestre algo de eso lo envuelve en `if (!en_app_android())` y se agrega su caso a `tests/web_120_app_android.sh`.
- Lo que el local cobra a **sus** clientes (servicios reales) no se esconde.

## Servidor
- Cron cada hora (crontab de `www-data`): `php /var/www/turenta/bin/tareas.php` (también purga la papelera) · respaldo diario 3:25: `deploy/respaldo.sh`
- Copias de los negocios borrados: `/var/www/turenta/respaldos/borrados/<id>_<fecha>.json.gz` (gzip JSON; `TuRenta\Papelera::leerCopia()` la lee)
- Super usuario: `php bin/crear_admin.php` · Telegram: `TELEGRAM_BOT_TOKEN`, `TELEGRAM_BOT_USERNAME`, `TELEGRAM_WEBHOOK_SECRET`
- Panel Tukán Central: `sudo bash /var/www/turenta/deploy/conectar_central.sh {ID} {SECRETO} {URL}` · `TUKAN_SOLO_CENTRAL=1` (solo desde el central; 0 = vuelve el ingreso local)
