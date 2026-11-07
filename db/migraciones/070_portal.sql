-- =====================================================================
-- 070 — Portal del cliente, segunda parte:
--   1) Cuestionario de planificación (formulario del evento) con plantillas por tipo de evento
--   2) Extras que el cliente pide desde su portal (hora extra, fotocabina...) y que el local aprueba
--   3) Encuesta de satisfacción (NPS) después del evento, con botón de reseña en Google
-- Se puede correr muchas veces (IF NOT EXISTS / DROP ... IF EXISTS).
-- =====================================================================
SET client_min_messages = warning;   -- sin avisos de "no existe, se omite"

-- ---------------------------------------------------------------
-- 1) Cuestionario de planificación
-- ---------------------------------------------------------------
-- Ajustes del negocio: el cliente puede llenar/cambiar el formulario hasta X días antes del evento;
-- el recordatorio por WhatsApp sale desde Y días antes. "listos" = ya se crearon las plantillas de ejemplo.
ALTER TABLE negocios ADD COLUMN IF NOT EXISTS cuestionario_dias_cierre INT NOT NULL DEFAULT 7;
ALTER TABLE negocios ADD COLUMN IF NOT EXISTS cuestionario_dias_aviso  INT NOT NULL DEFAULT 30;
ALTER TABLE negocios ADD COLUMN IF NOT EXISTS cuestionarios_listos     BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE negocios DROP CONSTRAINT IF EXISTS negocios_cuestionario_dias_ok;
ALTER TABLE negocios ADD CONSTRAINT negocios_cuestionario_dias_ok
    CHECK (cuestionario_dias_cierre BETWEEN 0 AND 90 AND cuestionario_dias_aviso BETWEEN 1 AND 365);

-- Plantillas (una por tipo de evento y negocio)
CREATE TABLE IF NOT EXISTS cuestionario_plantillas (
    id                    SERIAL PRIMARY KEY,
    negocio_id            INT NOT NULL REFERENCES negocios(id) ON DELETE CASCADE,
    tipo_evento           VARCHAR(20) NOT NULL,           -- boda, quince, ... (lista de Eventos::TIPOS)
    nombre                VARCHAR(100) NOT NULL,
    activo                BOOLEAN NOT NULL DEFAULT true,
    creado_en             TIMESTAMP NOT NULL DEFAULT now(),
    UNIQUE (negocio_id, tipo_evento)
);
CREATE TABLE IF NOT EXISTS cuestionario_preguntas (
    id                    SERIAL PRIMARY KEY,
    plantilla_id          INT NOT NULL REFERENCES cuestionario_plantillas(id) ON DELETE CASCADE,
    orden                 INT NOT NULL DEFAULT 0,
    seccion               VARCHAR(60),                    -- "Música", "Decoración"... (agrupa en pantalla)
    texto                 VARCHAR(200) NOT NULL,
    ayuda                 VARCHAR(200),
    tipo                  VARCHAR(10) NOT NULL DEFAULT 'corto'
                          CHECK (tipo IN ('corto','largo','opcion','varias','sino','numero','fecha','hora')),
    opciones              TEXT,                           -- una por línea (opcion / varias)
    obligatoria           BOOLEAN NOT NULL DEFAULT false
);
CREATE INDEX IF NOT EXISTS cuestionario_preguntas_plantilla ON cuestionario_preguntas (plantilla_id, orden);

-- Formulario de cada evento: COPIA de la plantilla (si después cambian la plantilla, lo ya copiado no cambia)
CREATE TABLE IF NOT EXISTS evento_cuestionarios (
    evento_id             INT PRIMARY KEY REFERENCES eventos(id) ON DELETE CASCADE,
    negocio_id            INT NOT NULL REFERENCES negocios(id) ON DELETE CASCADE,
    plantilla_id          INT REFERENCES cuestionario_plantillas(id) ON DELETE SET NULL,
    nombre                VARCHAR(100),
    enviado_en            TIMESTAMP,                      -- el cliente tocó "Listo, enviar al local"
    actualizado_en        TIMESTAMP,
    creado_en             TIMESTAMP NOT NULL DEFAULT now()
);
CREATE TABLE IF NOT EXISTS evento_preguntas (
    id                    SERIAL PRIMARY KEY,
    evento_id             INT NOT NULL REFERENCES eventos(id) ON DELETE CASCADE,
    negocio_id            INT NOT NULL REFERENCES negocios(id) ON DELETE CASCADE,
    orden                 INT NOT NULL DEFAULT 0,
    seccion               VARCHAR(60),
    texto                 VARCHAR(200) NOT NULL,
    ayuda                 VARCHAR(200),
    tipo                  VARCHAR(10) NOT NULL DEFAULT 'corto'
                          CHECK (tipo IN ('corto','largo','opcion','varias','sino','numero','fecha','hora')),
    opciones              TEXT,
    obligatoria           BOOLEAN NOT NULL DEFAULT false,
    respuesta             TEXT,                           -- NULL = sin responder ("varias": una opción por línea)
    respondido_en         TIMESTAMP,
    respondido_por        VARCHAR(120)                    -- "Cliente" o el nombre del usuario del local
);
CREATE INDEX IF NOT EXISTS evento_preguntas_evento ON evento_preguntas (evento_id, orden);
CREATE INDEX IF NOT EXISTS evento_cuestionarios_negocio ON evento_cuestionarios (negocio_id);

-- Recordatorio por WhatsApp ("Mensajes de hoy") del formulario sin llenar: tipo 'cuestionario' (ref = eventos.id)
ALTER TABLE mensajes_enviados DROP CONSTRAINT IF EXISTS mensajes_enviados_tipo_check;
ALTER TABLE mensajes_enviados ADD CONSTRAINT mensajes_enviados_tipo_check
    CHECK (tipo IN ('cuota','contrato','cotizacion','staff','invitados','resena','cuestionario'));

-- ---------------------------------------------------------------
-- 2) Extras que el cliente pide desde su portal
-- ---------------------------------------------------------------
ALTER TABLE productos ADD COLUMN IF NOT EXISTS en_portal    BOOLEAN NOT NULL DEFAULT false;  -- el cliente puede pedirlo
ALTER TABLE productos ADD COLUMN IF NOT EXISTS portal_texto VARCHAR(300);                    -- texto para el cliente
ALTER TABLE productos ADD COLUMN IF NOT EXISTS portal_foto  VARCHAR(60);                     -- uploads/extras

CREATE TABLE IF NOT EXISTS extras_pedidos (
    id                    SERIAL PRIMARY KEY,
    negocio_id            INT NOT NULL REFERENCES negocios(id) ON DELETE CASCADE,
    evento_id             INT NOT NULL REFERENCES eventos(id) ON DELETE CASCADE,
    estado                VARCHAR(10) NOT NULL DEFAULT 'pendiente'
                          CHECK (estado IN ('pendiente','aprobado','rechazado','cancelado')),
    nota_cliente          VARCHAR(400),
    nota_negocio          VARCHAR(400),
    subtotal              NUMERIC(12,2) NOT NULL DEFAULT 0,   -- precios del momento del pedido, sin IVA
    iva                   NUMERIC(12,2) NOT NULL DEFAULT 0,
    total                 NUMERIC(12,2) NOT NULL DEFAULT 0,
    cotizacion_id         INT REFERENCES cotizaciones(id) ON DELETE SET NULL,  -- versión nueva creada al aprobar
    cuota_id              INT REFERENCES cuotas(id) ON DELETE SET NULL,        -- cuota "Extras" agregada al plan
    monto_agregado        NUMERIC(12,2),                      -- lo que subió el valor del evento
    ip                    VARCHAR(45),
    creado_en             TIMESTAMP NOT NULL DEFAULT now(),
    respondido_en         TIMESTAMP,
    respondido_por        INT REFERENCES usuarios(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS extras_pedidos_evento  ON extras_pedidos (evento_id, creado_en);
CREATE INDEX IF NOT EXISTS extras_pedidos_negocio ON extras_pedidos (negocio_id, estado);
CREATE TABLE IF NOT EXISTS extras_pedido_items (
    id                    SERIAL PRIMARY KEY,
    pedido_id             INT NOT NULL REFERENCES extras_pedidos(id) ON DELETE CASCADE,
    producto_id           INT REFERENCES productos(id) ON DELETE SET NULL,
    categoria             VARCHAR(20) NOT NULL DEFAULT 'otros',
    descripcion           VARCHAR(300) NOT NULL,
    unidad                VARCHAR(12) NOT NULL DEFAULT 'evento',
    cantidad              NUMERIC(10,2) NOT NULL CHECK (cantidad > 0),
    precio_unitario       NUMERIC(10,2) NOT NULL DEFAULT 0,
    costo_unitario        NUMERIC(10,2) NOT NULL DEFAULT 0,
    con_iva               BOOLEAN NOT NULL DEFAULT true,
    total                 NUMERIC(12,2) NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS extras_pedido_items_pedido ON extras_pedido_items (pedido_id);

-- ---------------------------------------------------------------
-- 3) Encuesta de satisfacción (una por evento, con enlace secreto propio)
-- ---------------------------------------------------------------
ALTER TABLE eventos ADD COLUMN IF NOT EXISTS token_encuesta VARCHAR(64) NOT NULL DEFAULT encode(gen_random_bytes(24), 'hex');
CREATE UNIQUE INDEX IF NOT EXISTS eventos_token_encuesta ON eventos (token_encuesta);
CREATE TABLE IF NOT EXISTS encuestas (
    id                    SERIAL PRIMARY KEY,
    negocio_id            INT NOT NULL REFERENCES negocios(id) ON DELETE CASCADE,
    evento_id             INT NOT NULL UNIQUE REFERENCES eventos(id) ON DELETE CASCADE,
    nps                   SMALLINT NOT NULL CHECK (nps BETWEEN 0 AND 10),     -- ¿Nos recomendarías? 0 a 10
    comida                SMALLINT CHECK (comida BETWEEN 1 AND 5),            -- 1 a 5 (NULL = no respondió)
    atencion              SMALLINT CHECK (atencion BETWEEN 1 AND 5),
    instalaciones         SMALLINT CHECK (instalaciones BETWEEN 1 AND 5),
    organizacion          SMALLINT CHECK (organizacion BETWEEN 1 AND 5),
    comentario            VARCHAR(1000),
    ip                    VARCHAR(45),
    creado_en             TIMESTAMP NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS encuestas_negocio ON encuestas (negocio_id, creado_en);
CREATE INDEX IF NOT EXISTS encuestas_ip ON encuestas (ip, creado_en);
