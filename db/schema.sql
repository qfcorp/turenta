-- =====================================================================
-- TuRenta — Esquema de base de datos (PostgreSQL 16)
-- Programa para salones de eventos, quintas y haciendas (marca Tukán).
-- Un solo sistema para muchos negocios: cada tabla lleva negocio_id.
-- =====================================================================

BEGIN;

CREATE EXTENSION IF NOT EXISTS btree_gist;
CREATE EXTENSION IF NOT EXISTS pgcrypto;   -- gen_random_bytes para los enlaces secretos

-- ---------------------------------------------------------------
-- Planes de TuRenta, negocios y suscripciones
-- ---------------------------------------------------------------
CREATE TABLE planes (
    codigo                VARCHAR(20) PRIMARY KEY,      -- salon, quinta
    nombre                VARCHAR(50)  NOT NULL,
    precio_mensual        NUMERIC(10,2) NOT NULL,
    max_espacios          INT,                           -- NULL = ilimitado
    max_usuarios          INT,                           -- NULL = ilimitado
    descripcion           VARCHAR(300)
);
INSERT INTO planes (codigo, nombre, precio_mensual, max_espacios, max_usuarios, descripcion) VALUES
    ('salon',  'Salón',  39.00, 2, 3,    'Para salones de recepciones: calendario sin doble reserva, cotizaciones, contratos firmados, cuotas y cobros.'),
    ('quinta', 'Quinta', 69.00, NULL, NULL, 'Para quintas y haciendas: todo lo del plan Salón + portal del cliente, invitados con QR, día del evento, mobiliario y boda en vivo.');

CREATE TABLE negocios (
    id                    SERIAL PRIMARY KEY,
    nombre                VARCHAR(120) NOT NULL,
    slug                  VARCHAR(60)  NOT NULL UNIQUE,  -- página pública /?r=sitio&s=<slug>
    razon_social          VARCHAR(150),
    ruc                   VARCHAR(13),
    telefono              VARCHAR(20),
    whatsapp              VARCHAR(20),
    email                 VARCHAR(120),
    direccion             VARCHAR(250),
    ciudad                VARCHAR(80)  NOT NULL DEFAULT 'Quito',
    mapa_url              VARCHAR(400),
    instagram             VARCHAR(120),
    facebook              VARCHAR(200),
    descripcion           TEXT,                          -- texto de la página pública
    logo                  VARCHAR(60),                   -- archivo en uploads/logos
    color                 VARCHAR(7)   NOT NULL DEFAULT '#6B2D3C',
    plan_codigo           VARCHAR(20)  NOT NULL REFERENCES planes(codigo),
    estado                VARCHAR(15)  NOT NULL DEFAULT 'prueba'
                          CHECK (estado IN ('prueba','activo','suspendido','cancelado')),
    prueba_hasta          DATE,
    activo_hasta          DATE,
    notas_admin           TEXT,
    -- Reglas comerciales
    iva_pct               NUMERIC(5,2) NOT NULL DEFAULT 15,
    validez_cotizacion    INT NOT NULL DEFAULT 15,       -- días que vale una cotización
    dias_retencion        INT NOT NULL DEFAULT 5,        -- días que se "guarda" una fecha cotizada sin pago
    reserva_pct           NUMERIC(5,2) NOT NULL DEFAULT 30, -- % que se paga para separar la fecha
    cuotas_defecto        INT NOT NULL DEFAULT 3,        -- reserva + cuotas + saldo
    dias_saldo_antes      INT NOT NULL DEFAULT 15,       -- el saldo vence X días antes del evento
    garantia_defecto      NUMERIC(10,2) NOT NULL DEFAULT 0, -- depósito en garantía por daños
    meses_revisar_precio  INT NOT NULL DEFAULT 6,        -- avisar revisar precio si el evento es en más de X meses
    invitados_por_mesero  INT NOT NULL DEFAULT 15,       -- para sugerir personal mínimo
    hora_limite           TIME NOT NULL DEFAULT '02:00', -- hora máxima de fin (ordenanza)
    horas_montaje         NUMERIC(4,1) NOT NULL DEFAULT 3, -- el espacio se ocupa X horas antes (decoración, montaje)
    horas_desmontaje      NUMERIC(4,1) NOT NULL DEFAULT 1, -- y X horas después
    comision_vendedor_pct NUMERIC(5,2) NOT NULL DEFAULT 0, -- comisión del asesor sobre lo vendido
    condiciones           TEXT,                          -- condiciones que salen en la cotización
    -- Cobros en línea del cliente (cuotas)
    pago_banco            VARCHAR(500),                  -- datos de transferencia
    pago_deuna            VARCHAR(20),                   -- celular DeUna
    payphone_token        VARCHAR(600),
    payphone_store_id     VARCHAR(80),
    -- Transmisión en vivo con QF Producciones
    whatsapp_transmision  VARCHAR(20) NOT NULL DEFAULT '593983662181',
    -- Calendario externo (Google Calendar) por enlace secreto
    token_ical            VARCHAR(64) NOT NULL DEFAULT encode(gen_random_bytes(16), 'hex'),
    reporte_enviado_mes   CHAR(7),
    creado_en             TIMESTAMP NOT NULL DEFAULT now()
);

CREATE TABLE suscripciones (
    id                    SERIAL PRIMARY KEY,
    negocio_id            INT NOT NULL REFERENCES negocios(id) ON DELETE CASCADE,
    plan_codigo           VARCHAR(20) NOT NULL REFERENCES planes(codigo),
    periodo               VARCHAR(10) NOT NULL CHECK (periodo IN ('mensual','semestral','anual')),
    meses_pagados         INT NOT NULL,
    meses_recibidos       INT NOT NULL,
    monto                 NUMERIC(10,2) NOT NULL,
    inicio                DATE NOT NULL,
    fin                   DATE NOT NULL,
    metodo_pago           VARCHAR(20),
    incluye_dominio       BOOLEAN NOT NULL DEFAULT false,
    dominio               VARCHAR(120),
    pagado_en             DATE NOT NULL DEFAULT CURRENT_DATE,
    anulada               BOOLEAN NOT NULL DEFAULT false,
    CHECK (meses_recibidos >= meses_pagados)
);

-- Usuarios del negocio: dueño, vendedor (asesor comercial), coordinador (día del evento), consulta (solo ver)
CREATE TABLE usuarios (
    id                    SERIAL PRIMARY KEY,
    negocio_id            INT NOT NULL REFERENCES negocios(id) ON DELETE CASCADE,
    nombre                VARCHAR(100) NOT NULL,
    email                 VARCHAR(120) NOT NULL UNIQUE,
    telefono              VARCHAR(20),
    password_hash         VARCHAR(255) NOT NULL,
    rol                   VARCHAR(12) NOT NULL DEFAULT 'dueno'
                          CHECK (rol IN ('dueno','vendedor','coordinador','consulta')),
    activo                BOOLEAN NOT NULL DEFAULT true,
    telegram_chat_id      VARCHAR(30),
    telegram_codigo       VARCHAR(40),
    creado_en             TIMESTAMP NOT NULL DEFAULT now(),
    ultimo_ingreso        TIMESTAMP
);

-- ---------------------------------------------------------------
-- Super administrador (Tukán)
-- ---------------------------------------------------------------
CREATE TABLE superadmins (
    id                    SERIAL PRIMARY KEY,
    nombre                VARCHAR(100) NOT NULL,
    email                 VARCHAR(120) NOT NULL UNIQUE,
    password_hash         VARCHAR(255) NOT NULL,
    activo                BOOLEAN NOT NULL DEFAULT true,
    creado_en             TIMESTAMP NOT NULL DEFAULT now(),
    ultimo_ingreso        TIMESTAMP
);
CREATE TABLE ajustes (
    clave                 VARCHAR(40) PRIMARY KEY,
    valor                 VARCHAR(200) NOT NULL
);
INSERT INTO ajustes (clave, valor) VALUES
    ('dias_prueba', '7'),
    ('semestral_paga', '5'), ('semestral_recibe', '6'),
    ('anual_paga', '10'),    ('anual_recibe', '12'),
    ('dias_gracia', '3'),
    ('whatsapp_ventas', '593983662181');
CREATE TABLE registro_admin (
    id                    SERIAL PRIMARY KEY,
    admin_id              INT REFERENCES superadmins(id) ON DELETE SET NULL,
    negocio_id            INT REFERENCES negocios(id) ON DELETE SET NULL,
    accion                VARCHAR(300) NOT NULL,
    creado_en             TIMESTAMP NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------
-- Espacios (salón principal, jardín, capilla, terraza...) y fechas cerradas
-- ---------------------------------------------------------------
CREATE TABLE espacios (
    id                    SERIAL PRIMARY KEY,
    negocio_id            INT NOT NULL REFERENCES negocios(id) ON DELETE CASCADE,
    nombre                VARCHAR(80) NOT NULL,
    descripcion           VARCHAR(500),
    capacidad_banquete    INT,                           -- sentados
    capacidad_coctel      INT,                           -- de pie
    precio_base           NUMERIC(10,2) NOT NULL DEFAULT 0, -- alquiler del espacio
    color                 VARCHAR(7) NOT NULL DEFAULT '#6B2D3C',
    foto                  VARCHAR(60),
    orden                 INT NOT NULL DEFAULT 0,
    activo                BOOLEAN NOT NULL DEFAULT true
);

-- Días que el negocio no alquila (mantenimiento, feriado propio). espacio_id NULL = todo el negocio.
CREATE TABLE fechas_cerradas (
    id                    SERIAL PRIMARY KEY,
    negocio_id            INT NOT NULL REFERENCES negocios(id) ON DELETE CASCADE,
    espacio_id            INT REFERENCES espacios(id) ON DELETE CASCADE,
    fecha                 DATE NOT NULL,
    motivo                VARCHAR(150)
);

-- ---------------------------------------------------------------
-- Clientes (los novios, la quinceañera, la empresa...)
-- ---------------------------------------------------------------
CREATE TABLE clientes (
    id                    SERIAL PRIMARY KEY,
    negocio_id            INT NOT NULL REFERENCES negocios(id) ON DELETE CASCADE,
    nombre                VARCHAR(150) NOT NULL,
    identificacion        VARCHAR(13),                   -- cédula (10) o RUC (13)
    telefono              VARCHAR(20),
    email                 VARCHAR(120),
    direccion             VARCHAR(250),
    origen                VARCHAR(30),                   -- instagram, facebook, referido, google, feria, pagina, otro
    notas                 TEXT,
    creado_en             TIMESTAMP NOT NULL DEFAULT now()
);
CREATE INDEX clientes_negocio ON clientes (negocio_id, nombre);

-- ---------------------------------------------------------------
-- Eventos: el centro de todo
--   consulta -> cotizado -> separado -> confirmado -> realizado
--   (o perdido / cancelado)
-- ---------------------------------------------------------------
CREATE TABLE eventos (
    id                    SERIAL PRIMARY KEY,
    negocio_id            INT NOT NULL REFERENCES negocios(id) ON DELETE CASCADE,
    codigo                VARCHAR(20) NOT NULL,          -- EV-2026-0001
    cliente_id            INT NOT NULL REFERENCES clientes(id),
    tipo                  VARCHAR(20) NOT NULL DEFAULT 'boda'
                          CHECK (tipo IN ('boda','quince','graduacion','bautizo','primera_comunion','cumpleanos','corporativo','aniversario','otro')),
    nombre                VARCHAR(150) NOT NULL,         -- "Boda de Ana y Luis"
    fecha                 DATE NOT NULL,
    hora_inicio           TIME NOT NULL DEFAULT '18:00',
    hora_fin              TIME NOT NULL DEFAULT '02:00', -- si es menor que el inicio, termina al día siguiente
    invitados             INT NOT NULL DEFAULT 100,
    estado                VARCHAR(12) NOT NULL DEFAULT 'consulta'
                          CHECK (estado IN ('consulta','cotizado','separado','confirmado','realizado','perdido','cancelado')),
    retenida_hasta        DATE,                          -- fecha guardada sin pago (cotizado)
    vendedor_id           INT REFERENCES usuarios(id) ON DELETE SET NULL,
    coordinador_id        INT REFERENCES usuarios(id) ON DELETE SET NULL,
    origen                VARCHAR(30),
    presupuesto_cliente   NUMERIC(12,2),
    motivo_perdida        VARCHAR(200),
    notas                 TEXT,
    -- Montaje y detalles para el día
    montaje               VARCHAR(30),                   -- imperial, redondas, coctel, teatro, escuela, mixto
    menu_elegido          TEXT,
    detalles_cliente      TEXT,                          -- lo que el cliente pidió (colores, música, alergias)
    -- Valores (se toman de la cotización aceptada)
    total                 NUMERIC(12,2) NOT NULL DEFAULT 0,
    costo_estimado        NUMERIC(12,2) NOT NULL DEFAULT 0,
    garantia              NUMERIC(10,2) NOT NULL DEFAULT 0, -- depósito por daños (se devuelve)
    personal_minimo       INT,
    -- Enlaces secretos
    token_cliente         VARCHAR(64) NOT NULL UNIQUE DEFAULT encode(gen_random_bytes(24), 'hex'),
    token_puerta          VARCHAR(64) NOT NULL UNIQUE DEFAULT encode(gen_random_bytes(24), 'hex'),
    token_vivo            VARCHAR(64) NOT NULL UNIQUE DEFAULT encode(gen_random_bytes(24), 'hex'),
    -- Boda en vivo
    transmision_url       VARCHAR(400),
    transmision_estado    VARCHAR(12) NOT NULL DEFAULT 'no'
                          CHECK (transmision_estado IN ('no','solicitada','confirmada')),
    -- Avisos ya enviados (para no repetir)
    aviso_precio_enviado  BOOLEAN NOT NULL DEFAULT false,
    aviso_semana_enviado  BOOLEAN NOT NULL DEFAULT false,
    confirmado_en         TIMESTAMP,
    creado_por            INT REFERENCES usuarios(id) ON DELETE SET NULL,
    creado_en             TIMESTAMP NOT NULL DEFAULT now(),
    actualizado_en        TIMESTAMP NOT NULL DEFAULT now(),
    UNIQUE (negocio_id, codigo)
);
CREATE INDEX eventos_fecha ON eventos (negocio_id, fecha);
CREATE INDEX eventos_estado ON eventos (negocio_id, estado);

-- Espacios que usa cada evento. "bloquea" = la fecha está asegurada (separado, confirmado, realizado).
-- La restricción de exclusión hace IMPOSIBLE guardar dos eventos que bloquean el mismo espacio a la misma hora.
CREATE TABLE evento_espacios (
    id                    SERIAL PRIMARY KEY,
    negocio_id            INT NOT NULL REFERENCES negocios(id) ON DELETE CASCADE,
    evento_id             INT NOT NULL REFERENCES eventos(id) ON DELETE CASCADE,
    espacio_id            INT NOT NULL REFERENCES espacios(id) ON DELETE CASCADE,
    periodo               TSRANGE NOT NULL,               -- desde el montaje hasta el desmontaje
    bloquea               BOOLEAN NOT NULL DEFAULT false,
    UNIQUE (evento_id, espacio_id),
    EXCLUDE USING gist (espacio_id WITH =, periodo WITH &&) WHERE (bloquea)
);
CREATE INDEX evento_espacios_periodo ON evento_espacios USING gist (espacio_id, periodo);

-- Seguimiento comercial: llamadas, visitas al local, degustaciones, notas
CREATE TABLE actividades (
    id                    SERIAL PRIMARY KEY,
    negocio_id            INT NOT NULL REFERENCES negocios(id) ON DELETE CASCADE,
    evento_id             INT REFERENCES eventos(id) ON DELETE CASCADE,
    cliente_id            INT REFERENCES clientes(id) ON DELETE CASCADE,
    tipo                  VARCHAR(15) NOT NULL DEFAULT 'nota'
                          CHECK (tipo IN ('nota','llamada','whatsapp','visita','degustacion','reunion','correo','sistema')),
    texto                 TEXT NOT NULL,
    programada_para       TIMESTAMP,                     -- visita o llamada agendada / próximo contacto
    hecha                 BOOLEAN NOT NULL DEFAULT true,
    usuario_id            INT REFERENCES usuarios(id) ON DELETE SET NULL,
    creado_en             TIMESTAMP NOT NULL DEFAULT now()
);
CREATE INDEX actividades_pendientes ON actividades (negocio_id, programada_para) WHERE NOT hecha;

-- Lista de tareas por evento (degustación, prueba de menú, música...), creada desde una plantilla
CREATE TABLE plantilla_tareas (
    id                    SERIAL PRIMARY KEY,
    negocio_id            INT NOT NULL REFERENCES negocios(id) ON DELETE CASCADE,
    tipo_evento           VARCHAR(20),                   -- NULL = para todos los tipos
    titulo                VARCHAR(150) NOT NULL,
    dias_antes            INT NOT NULL DEFAULT 30,
    orden                 INT NOT NULL DEFAULT 0
);
CREATE TABLE evento_tareas (
    id                    SERIAL PRIMARY KEY,
    evento_id             INT NOT NULL REFERENCES eventos(id) ON DELETE CASCADE,
    titulo                VARCHAR(150) NOT NULL,
    vence                 DATE,
    hecha                 BOOLEAN NOT NULL DEFAULT false,
    hecha_en              TIMESTAMP,
    responsable_id        INT REFERENCES usuarios(id) ON DELETE SET NULL,
    orden                 INT NOT NULL DEFAULT 0
);

-- ---------------------------------------------------------------
-- Catálogo: productos y servicios que se cotizan, y paquetes
-- ---------------------------------------------------------------
CREATE TABLE productos (
    id                    SERIAL PRIMARY KEY,
    negocio_id            INT NOT NULL REFERENCES negocios(id) ON DELETE CASCADE,
    categoria             VARCHAR(20) NOT NULL DEFAULT 'otros'
                          CHECK (categoria IN ('espacio','menu','bebidas','decoracion','musica','foto_video','mobiliario','personal','transmision','otros')),
    nombre                VARCHAR(150) NOT NULL,
    descripcion           VARCHAR(600),
    unidad                VARCHAR(12) NOT NULL DEFAULT 'evento'
                          CHECK (unidad IN ('persona','evento','hora','unidad')),
    precio                NUMERIC(10,2) NOT NULL DEFAULT 0,
    costo                 NUMERIC(10,2) NOT NULL DEFAULT 0, -- lo que le cuesta al negocio (para la ganancia)
    con_iva               BOOLEAN NOT NULL DEFAULT true,
    activo                BOOLEAN NOT NULL DEFAULT true,
    orden                 INT NOT NULL DEFAULT 0
);

CREATE TABLE paquetes (
    id                    SERIAL PRIMARY KEY,
    negocio_id            INT NOT NULL REFERENCES negocios(id) ON DELETE CASCADE,
    nombre                VARCHAR(120) NOT NULL,         -- Paquete Boda Oro
    descripcion           VARCHAR(800),
    tipo_evento           VARCHAR(20),
    minimo_invitados      INT NOT NULL DEFAULT 0,
    activo                BOOLEAN NOT NULL DEFAULT true
);
CREATE TABLE paquete_items (
    id                    SERIAL PRIMARY KEY,
    paquete_id            INT NOT NULL REFERENCES paquetes(id) ON DELETE CASCADE,
    producto_id           INT NOT NULL REFERENCES productos(id) ON DELETE CASCADE,
    cantidad              NUMERIC(10,2) NOT NULL DEFAULT 1, -- si el producto es por persona, se multiplica por invitados
    precio                NUMERIC(10,2)                  -- precio especial en el paquete (NULL = precio del producto)
);

-- ---------------------------------------------------------------
-- Cotizaciones (versiones) y sus líneas
-- ---------------------------------------------------------------
CREATE TABLE cotizaciones (
    id                    SERIAL PRIMARY KEY,
    negocio_id            INT NOT NULL REFERENCES negocios(id) ON DELETE CASCADE,
    evento_id             INT NOT NULL REFERENCES eventos(id) ON DELETE CASCADE,
    numero                INT NOT NULL,                  -- correlativo del negocio
    version               INT NOT NULL DEFAULT 1,
    fecha                 DATE NOT NULL DEFAULT CURRENT_DATE,
    valida_hasta          DATE NOT NULL,
    estado                VARCHAR(12) NOT NULL DEFAULT 'borrador'
                          CHECK (estado IN ('borrador','enviada','aceptada','rechazada','vencida','reemplazada')),
    invitados             INT NOT NULL,
    subtotal              NUMERIC(12,2) NOT NULL DEFAULT 0,
    descuento             NUMERIC(12,2) NOT NULL DEFAULT 0,
    base_iva              NUMERIC(12,2) NOT NULL DEFAULT 0,
    base_cero             NUMERIC(12,2) NOT NULL DEFAULT 0,
    iva                   NUMERIC(12,2) NOT NULL DEFAULT 0,
    total                 NUMERIC(12,2) NOT NULL DEFAULT 0,
    costo                 NUMERIC(12,2) NOT NULL DEFAULT 0,
    iva_pct               NUMERIC(5,2) NOT NULL,
    notas                 TEXT,
    condiciones           TEXT,
    token                 VARCHAR(64) NOT NULL UNIQUE DEFAULT encode(gen_random_bytes(24), 'hex'),
    aceptada_en           TIMESTAMP,
    aceptada_por          VARCHAR(150),
    aceptada_ip           VARCHAR(45),
    creado_por            INT REFERENCES usuarios(id) ON DELETE SET NULL,
    creado_en             TIMESTAMP NOT NULL DEFAULT now(),
    UNIQUE (negocio_id, numero)
);
CREATE TABLE cotizacion_items (
    id                    SERIAL PRIMARY KEY,
    cotizacion_id         INT NOT NULL REFERENCES cotizaciones(id) ON DELETE CASCADE,
    producto_id           INT REFERENCES productos(id) ON DELETE SET NULL,
    categoria             VARCHAR(20) NOT NULL DEFAULT 'otros',
    descripcion           VARCHAR(300) NOT NULL,
    unidad                VARCHAR(12) NOT NULL DEFAULT 'evento',
    cantidad              NUMERIC(10,2) NOT NULL DEFAULT 1,
    precio_unitario       NUMERIC(10,2) NOT NULL DEFAULT 0,
    costo_unitario        NUMERIC(10,2) NOT NULL DEFAULT 0,
    con_iva               BOOLEAN NOT NULL DEFAULT true,
    total                 NUMERIC(12,2) NOT NULL DEFAULT 0,
    orden                 INT NOT NULL DEFAULT 0
);

-- ---------------------------------------------------------------
-- Contratos con firma digital del cliente en el celular
-- ---------------------------------------------------------------
CREATE TABLE plantillas_contrato (
    id                    SERIAL PRIMARY KEY,
    negocio_id            INT NOT NULL REFERENCES negocios(id) ON DELETE CASCADE,
    nombre                VARCHAR(100) NOT NULL,
    texto                 TEXT NOT NULL,                 -- con variables {{cliente}}, {{fecha}}, ...
    predeterminada        BOOLEAN NOT NULL DEFAULT false
);
CREATE TABLE contratos (
    id                    SERIAL PRIMARY KEY,
    negocio_id            INT NOT NULL REFERENCES negocios(id) ON DELETE CASCADE,
    evento_id             INT NOT NULL REFERENCES eventos(id) ON DELETE CASCADE,
    cotizacion_id         INT REFERENCES cotizaciones(id) ON DELETE SET NULL,
    numero                INT NOT NULL,
    codigo_verificacion   VARCHAR(16) NOT NULL UNIQUE,   -- va en el QR del PDF
    texto                 TEXT NOT NULL,                 -- texto final, ya con los datos (no cambia después de enviar)
    hash_sha256           CHAR(64) NOT NULL,             -- huella del texto: si alguien lo cambia, no coincide
    estado                VARCHAR(10) NOT NULL DEFAULT 'borrador'
                          CHECK (estado IN ('borrador','enviado','firmado','anulado')),
    token                 VARCHAR(64) NOT NULL UNIQUE DEFAULT encode(gen_random_bytes(24), 'hex'),
    firmante_nombre       VARCHAR(150),
    firmante_identificacion VARCHAR(13),
    firma_archivo         VARCHAR(60),                   -- imagen PNG de la firma (uploads/firmas)
    firmado_en            TIMESTAMP,
    firma_ip              VARCHAR(45),
    firma_navegador       VARCHAR(300),
    creado_por            INT REFERENCES usuarios(id) ON DELETE SET NULL,
    creado_en             TIMESTAMP NOT NULL DEFAULT now(),
    UNIQUE (negocio_id, numero)
);

-- ---------------------------------------------------------------
-- Plan de pagos (cuotas) y pagos
-- ---------------------------------------------------------------
CREATE TABLE cuotas (
    id                    SERIAL PRIMARY KEY,
    evento_id             INT NOT NULL REFERENCES eventos(id) ON DELETE CASCADE,
    numero                INT NOT NULL,
    concepto              VARCHAR(80) NOT NULL,          -- Reserva, Cuota 2, Saldo
    vence                 DATE NOT NULL,
    monto                 NUMERIC(12,2) NOT NULL CHECK (monto > 0),
    pagado                NUMERIC(12,2) NOT NULL DEFAULT 0, -- se recalcula con los pagos confirmados
    aviso_enviado         DATE,                          -- último recordatorio
    UNIQUE (evento_id, numero)
);

CREATE TABLE pagos (
    id                    SERIAL PRIMARY KEY,
    negocio_id            INT NOT NULL REFERENCES negocios(id) ON DELETE CASCADE,
    evento_id             INT NOT NULL REFERENCES eventos(id) ON DELETE CASCADE,
    tipo                  VARCHAR(20) NOT NULL DEFAULT 'evento'
                          CHECK (tipo IN ('evento','garantia','devolucion_garantia','cargo_danos')),
    fecha                 TIMESTAMP NOT NULL DEFAULT now(),
    monto                 NUMERIC(12,2) NOT NULL CHECK (monto > 0),
    metodo                VARCHAR(15) NOT NULL DEFAULT 'transferencia'
                          CHECK (metodo IN ('efectivo','transferencia','deuna','tarjeta','payphone','cheque','otro')),
    referencia            VARCHAR(120),
    comprobante           VARCHAR(60),                   -- foto (uploads/comprobantes)
    estado                VARCHAR(12) NOT NULL DEFAULT 'confirmado'
                          CHECK (estado IN ('confirmado','por_revisar','rechazado','pendiente','anulado')),
    origen                VARCHAR(10) NOT NULL DEFAULT 'negocio'
                          CHECK (origen IN ('negocio','cliente','payphone')),
    numero_recibo         INT,
    notas                 VARCHAR(300),
    registrado_por        INT REFERENCES usuarios(id) ON DELETE SET NULL,
    creado_en             TIMESTAMP NOT NULL DEFAULT now()
);
CREATE INDEX pagos_evento ON pagos (evento_id);

-- Gastos de cada evento (proveedores externos, compras): para saber la ganancia real
CREATE TABLE gastos (
    id                    SERIAL PRIMARY KEY,
    negocio_id            INT NOT NULL REFERENCES negocios(id) ON DELETE CASCADE,
    evento_id             INT REFERENCES eventos(id) ON DELETE CASCADE,  -- NULL = gasto general del negocio
    fecha                 DATE NOT NULL DEFAULT CURRENT_DATE,
    categoria             VARCHAR(20) NOT NULL DEFAULT 'otros',
    concepto              VARCHAR(200) NOT NULL,
    proveedor_id          INT,
    monto                 NUMERIC(12,2) NOT NULL CHECK (monto > 0),
    registrado_por        INT REFERENCES usuarios(id) ON DELETE SET NULL,
    creado_en             TIMESTAMP NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------
-- Día del evento: proveedores, personal, cronograma, mobiliario
-- ---------------------------------------------------------------
CREATE TABLE proveedores (
    id                    SERIAL PRIMARY KEY,
    negocio_id            INT NOT NULL REFERENCES negocios(id) ON DELETE CASCADE,
    nombre                VARCHAR(150) NOT NULL,
    servicio              VARCHAR(20) NOT NULL DEFAULT 'otros',  -- misma lista que las categorías
    contacto              VARCHAR(100),
    telefono              VARCHAR(20),
    email                 VARCHAR(120),
    notas                 VARCHAR(500),
    activo                BOOLEAN NOT NULL DEFAULT true
);
ALTER TABLE gastos ADD CONSTRAINT gastos_proveedor_fk FOREIGN KEY (proveedor_id) REFERENCES proveedores(id) ON DELETE SET NULL;

CREATE TABLE evento_proveedores (
    id                    SERIAL PRIMARY KEY,
    evento_id             INT NOT NULL REFERENCES eventos(id) ON DELETE CASCADE,
    proveedor_id          INT REFERENCES proveedores(id) ON DELETE SET NULL,
    nombre                VARCHAR(150) NOT NULL,         -- se copia (también para proveedores que trae el cliente)
    servicio              VARCHAR(20) NOT NULL DEFAULT 'otros',
    telefono              VARCHAR(20),
    hora_llegada          TIME,
    lo_trae_cliente       BOOLEAN NOT NULL DEFAULT false,
    confirmado            BOOLEAN NOT NULL DEFAULT false,
    notas                 VARCHAR(300)
);

-- Personal para eventos (meseros, cocina, seguridad, limpieza, coordinación, bartender)
CREATE TABLE personal (
    id                    SERIAL PRIMARY KEY,
    negocio_id            INT NOT NULL REFERENCES negocios(id) ON DELETE CASCADE,
    nombre                VARCHAR(120) NOT NULL,
    identificacion        VARCHAR(13),
    telefono              VARCHAR(20),
    rol                   VARCHAR(15) NOT NULL DEFAULT 'mesero'
                          CHECK (rol IN ('mesero','capitan','cocina','bartender','seguridad','limpieza','coordinador','parqueo','otro')),
    pago_evento           NUMERIC(10,2) NOT NULL DEFAULT 0,  -- tarifa por evento
    token                 VARCHAR(64) NOT NULL UNIQUE DEFAULT encode(gen_random_bytes(24), 'hex'), -- "mis eventos"
    activo                BOOLEAN NOT NULL DEFAULT true
);
CREATE TABLE evento_personal (
    id                    SERIAL PRIMARY KEY,
    evento_id             INT NOT NULL REFERENCES eventos(id) ON DELETE CASCADE,
    personal_id           INT NOT NULL REFERENCES personal(id) ON DELETE CASCADE,
    rol                   VARCHAR(15) NOT NULL,
    hora_entrada          TIME,
    hora_salida           TIME,
    pago                  NUMERIC(10,2) NOT NULL DEFAULT 0,
    confirmado            BOOLEAN NOT NULL DEFAULT false,  -- la persona confirmó que va
    asistio               BOOLEAN,
    pagado                BOOLEAN NOT NULL DEFAULT false,
    pagado_en             DATE,
    UNIQUE (evento_id, personal_id)                         -- nadie asignado dos veces al mismo evento
);

-- Cronograma minuto a minuto (escaleta): ceremonia, cóctel, entrada, vals, cena, baile...
CREATE TABLE cronograma (
    id                    SERIAL PRIMARY KEY,
    evento_id             INT NOT NULL REFERENCES eventos(id) ON DELETE CASCADE,
    orden                 INT NOT NULL DEFAULT 0,
    hora                  TIME NOT NULL,
    duracion_min          INT NOT NULL DEFAULT 15 CHECK (duracion_min BETWEEN 1 AND 600),
    actividad             VARCHAR(150) NOT NULL,
    responsable           VARCHAR(100),
    notas                 VARCHAR(300),
    inicio_real           TIMESTAMP,                      -- modo en vivo
    fin_real              TIMESTAMP
);

-- Mobiliario y menaje (sillas, mesas, mantelería, vajilla, carpas)
CREATE TABLE mobiliario (
    id                    SERIAL PRIMARY KEY,
    negocio_id            INT NOT NULL REFERENCES negocios(id) ON DELETE CASCADE,
    nombre                VARCHAR(120) NOT NULL,
    categoria             VARCHAR(20) NOT NULL DEFAULT 'mobiliario'
                          CHECK (categoria IN ('mobiliario','manteleria','vajilla','cristaleria','decoracion','equipos','carpas','otros')),
    cantidad_total        INT NOT NULL DEFAULT 0 CHECK (cantidad_total >= 0),
    costo_reposicion      NUMERIC(10,2) NOT NULL DEFAULT 0, -- se cobra si se daña o pierde
    activo                BOOLEAN NOT NULL DEFAULT true
);
CREATE TABLE evento_mobiliario (
    id                    SERIAL PRIMARY KEY,
    evento_id             INT NOT NULL REFERENCES eventos(id) ON DELETE CASCADE,
    mobiliario_id         INT NOT NULL REFERENCES mobiliario(id) ON DELETE CASCADE,
    cantidad              INT NOT NULL CHECK (cantidad > 0),
    danados               INT NOT NULL DEFAULT 0 CHECK (danados >= 0),  -- después del evento
    UNIQUE (evento_id, mobiliario_id)
);

-- ---------------------------------------------------------------
-- Invitados con QR, mesas y confirmación de asistencia
-- ---------------------------------------------------------------
CREATE TABLE mesas (
    id                    SERIAL PRIMARY KEY,
    evento_id             INT NOT NULL REFERENCES eventos(id) ON DELETE CASCADE,
    nombre                VARCHAR(60) NOT NULL,          -- "Mesa 1", "Mesa de honor"
    capacidad             INT NOT NULL DEFAULT 10 CHECK (capacidad > 0),
    orden                 INT NOT NULL DEFAULT 0
);
CREATE TABLE invitados (
    id                    SERIAL PRIMARY KEY,
    evento_id             INT NOT NULL REFERENCES eventos(id) ON DELETE CASCADE,
    nombre                VARCHAR(150) NOT NULL,
    telefono              VARCHAR(20),
    grupo                 VARCHAR(60),                   -- "Familia de la novia", "Amigos"
    pases                 INT NOT NULL DEFAULT 1 CHECK (pases BETWEEN 1 AND 20),
    confirmados           INT,                            -- cuántos de los pases confirmaron
    estado                VARCHAR(12) NOT NULL DEFAULT 'pendiente'
                          CHECK (estado IN ('pendiente','confirmado','no_asiste')),
    mesa_id               INT REFERENCES mesas(id) ON DELETE SET NULL,
    restricciones         VARCHAR(200),                  -- vegetariano, alergias
    token                 VARCHAR(64) NOT NULL UNIQUE DEFAULT encode(gen_random_bytes(16), 'hex'),
    ingresaron            INT NOT NULL DEFAULT 0,        -- control de ingreso en la puerta
    ingreso_en            TIMESTAMP,
    respondido_en         TIMESTAMP,
    creado_en             TIMESTAMP NOT NULL DEFAULT now()
);
CREATE INDEX invitados_evento ON invitados (evento_id, nombre);

-- ---------------------------------------------------------------
-- Avisos dentro del sistema y registro de cambios
-- ---------------------------------------------------------------
CREATE TABLE notificaciones (
    id                    SERIAL PRIMARY KEY,
    negocio_id            INT NOT NULL REFERENCES negocios(id) ON DELETE CASCADE,
    usuario_id            INT NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    evento_id             INT REFERENCES eventos(id) ON DELETE CASCADE,
    texto                 VARCHAR(400) NOT NULL,
    leida                 BOOLEAN NOT NULL DEFAULT false,
    creada_en             TIMESTAMP NOT NULL DEFAULT now()
);
CREATE INDEX notificaciones_usuario ON notificaciones (usuario_id, leida);

CREATE TABLE registro_cambios (
    id                    SERIAL PRIMARY KEY,
    negocio_id            INT NOT NULL REFERENCES negocios(id) ON DELETE CASCADE,
    evento_id             INT REFERENCES eventos(id) ON DELETE CASCADE,
    usuario_id            INT REFERENCES usuarios(id) ON DELETE SET NULL,
    quien                 VARCHAR(120),                  -- nombre (también "Cliente" o "Sistema")
    accion                VARCHAR(400) NOT NULL,
    creado_en             TIMESTAMP NOT NULL DEFAULT now()
);
CREATE INDEX registro_cambios_evento ON registro_cambios (evento_id, creado_en);

COMMIT;
