-- =====================================================================
-- 060 — Proveedores y compras: trazabilidad completa de cada proveedor.
--   Ficha (RUC, banco, crédito), documentos con vencimiento, órdenes de compra con ítems,
--   facturas (también desde el XML del SRI), pagos a proveedores (con cheques posfechados y anulación),
--   cumplimiento el día del evento (llegada, puntualidad, calificación), incidencias y bitácora.
-- Se puede correr muchas veces (IF NOT EXISTS / DROP ... IF EXISTS).
-- =====================================================================
SET client_min_messages = warning;   -- sin avisos de "no existe, se omite"

-- ---------------------------------------------------------------
-- Ficha del proveedor
-- ---------------------------------------------------------------
ALTER TABLE proveedores ADD COLUMN IF NOT EXISTS ruc            VARCHAR(13);    -- cédula (10) o RUC (13)
ALTER TABLE proveedores ADD COLUMN IF NOT EXISTS razon_social   VARCHAR(150);
ALTER TABLE proveedores ADD COLUMN IF NOT EXISTS direccion      VARCHAR(250);
ALTER TABLE proveedores ADD COLUMN IF NOT EXISTS ciudad         VARCHAR(80);
ALTER TABLE proveedores ADD COLUMN IF NOT EXISTS banco          VARCHAR(80);
ALTER TABLE proveedores ADD COLUMN IF NOT EXISTS tipo_cuenta    VARCHAR(10);
ALTER TABLE proveedores ADD COLUMN IF NOT EXISTS numero_cuenta  VARCHAR(30);
ALTER TABLE proveedores ADD COLUMN IF NOT EXISTS titular_cuenta VARCHAR(150);
ALTER TABLE proveedores ADD COLUMN IF NOT EXISTS credito_dias   INT NOT NULL DEFAULT 0;      -- 0 = de contado
ALTER TABLE proveedores ADD COLUMN IF NOT EXISTS cupo_credito   NUMERIC(12,2);               -- NULL = sin límite
ALTER TABLE proveedores ADD COLUMN IF NOT EXISTS anticipo_pct   NUMERIC(5,2) NOT NULL DEFAULT 0;
ALTER TABLE proveedores ADD COLUMN IF NOT EXISTS preferido      BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE proveedores ADD COLUMN IF NOT EXISTS token          VARCHAR(64) NOT NULL DEFAULT encode(gen_random_bytes(24), 'hex');  -- portal del proveedor
ALTER TABLE proveedores ADD COLUMN IF NOT EXISTS creado_en      TIMESTAMP NOT NULL DEFAULT now();

ALTER TABLE proveedores DROP CONSTRAINT IF EXISTS proveedores_ruc_ok;
ALTER TABLE proveedores ADD CONSTRAINT proveedores_ruc_ok CHECK (ruc IS NULL OR ruc ~ '^([0-9]{10}|[0-9]{13})$');
ALTER TABLE proveedores DROP CONSTRAINT IF EXISTS proveedores_tipo_cuenta_ok;
ALTER TABLE proveedores ADD CONSTRAINT proveedores_tipo_cuenta_ok CHECK (tipo_cuenta IS NULL OR tipo_cuenta IN ('ahorros','corriente'));
ALTER TABLE proveedores DROP CONSTRAINT IF EXISTS proveedores_credito_ok;
ALTER TABLE proveedores ADD CONSTRAINT proveedores_credito_ok CHECK (credito_dias BETWEEN 0 AND 365 AND (cupo_credito IS NULL OR cupo_credito >= 0)
                                                                    AND anticipo_pct BETWEEN 0 AND 100);
CREATE UNIQUE INDEX IF NOT EXISTS proveedores_token ON proveedores (token);
CREATE UNIQUE INDEX IF NOT EXISTS proveedores_ruc_negocio ON proveedores (negocio_id, ruc) WHERE ruc IS NOT NULL;

-- Documentos del proveedor (RUC, permisos, póliza, contrato...) con fecha de vencimiento
CREATE TABLE IF NOT EXISTS proveedor_documentos (
    id                    SERIAL PRIMARY KEY,
    negocio_id            INT NOT NULL REFERENCES negocios(id) ON DELETE CASCADE,
    proveedor_id          INT NOT NULL REFERENCES proveedores(id) ON DELETE CASCADE,
    tipo                  VARCHAR(25) NOT NULL DEFAULT 'otro'
                          CHECK (tipo IN ('ruc','permiso_funcionamiento','permiso_sanitario','poliza','contrato','otro')),
    nombre                VARCHAR(150),
    archivo               VARCHAR(60),                   -- uploads/proveedores (jpg o pdf)
    vence                 DATE,
    aviso_vence           DATE,                          -- vencimiento para el que ya se avisó
    subido_por            INT REFERENCES usuarios(id) ON DELETE SET NULL,
    creado_en             TIMESTAMP NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS proveedor_documentos_prov ON proveedor_documentos (proveedor_id);
CREATE INDEX IF NOT EXISTS proveedor_documentos_vence ON proveedor_documentos (negocio_id, vence) WHERE vence IS NOT NULL;

-- ---------------------------------------------------------------
-- Compras / órdenes de compra
--   cotizacion (pedido de precio) -> pedida (enviada) -> confirmada -> entregada  (o cancelada)
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS compras (
    id                    SERIAL PRIMARY KEY,
    negocio_id            INT NOT NULL REFERENCES negocios(id) ON DELETE CASCADE,
    numero                INT NOT NULL,                  -- OC-0001, correlativo del negocio
    proveedor_id          INT NOT NULL REFERENCES proveedores(id) ON DELETE RESTRICT,
    evento_id             INT REFERENCES eventos(id) ON DELETE SET NULL,   -- NULL = compra general del negocio
    categoria             VARCHAR(20) NOT NULL DEFAULT 'otros',            -- misma lista que el catálogo (para comparar precios)
    fecha                 DATE NOT NULL DEFAULT CURRENT_DATE,
    estado                VARCHAR(12) NOT NULL DEFAULT 'pedida'
                          CHECK (estado IN ('cotizacion','pedida','confirmada','entregada','cancelada')),
    iva_pct               NUMERIC(5,2) NOT NULL DEFAULT 15,
    subtotal              NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (subtotal >= 0),
    iva                   NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (iva >= 0),
    total                 NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (total >= 0),
    anticipo              NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (anticipo >= 0),  -- anticipo pactado
    vence                 DATE,                          -- fecha límite de pago
    vence_manual          BOOLEAN NOT NULL DEFAULT false,
    retencion             NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (retencion >= 0),
    -- Factura del proveedor
    factura_numero        VARCHAR(17) CHECK (factura_numero IS NULL OR factura_numero ~ '^[0-9]{3}-[0-9]{3}-[0-9]{9}$'),
    factura_fecha         DATE,
    factura_total         NUMERIC(12,2) CHECK (factura_total IS NULL OR factura_total >= 0),
    factura_archivo       VARCHAR(60),                   -- PDF o foto
    factura_xml           VARCHAR(60),                   -- XML del SRI
    factura_clave         VARCHAR(49),                   -- clave de acceso del SRI
    factura_origen        VARCHAR(10) CHECK (factura_origen IS NULL OR factura_origen IN ('negocio','proveedor')),
    notas                 TEXT,
    motivo_cancelacion    VARCHAR(300),
    respuesta_proveedor   VARCHAR(300),                  -- comentario del proveedor al confirmar o rechazar
    elegida               BOOLEAN NOT NULL DEFAULT false, -- se eligió al comparar precios
    codigo_verificacion   VARCHAR(16) NOT NULL DEFAULT upper(encode(gen_random_bytes(8), 'hex')),  -- QR del PDF
    enviada_en            TIMESTAMP,
    confirmada_en         TIMESTAMP,
    confirmada_por        VARCHAR(10) CHECK (confirmada_por IS NULL OR confirmada_por IN ('negocio','proveedor')),
    entregada_en          TIMESTAMP,
    cancelada_en          TIMESTAMP,
    aviso_pago_dia        DATE,                          -- día en que se avisó que vence o venció
    creado_por            INT REFERENCES usuarios(id) ON DELETE SET NULL,
    creado_en             TIMESTAMP NOT NULL DEFAULT now(),
    actualizado_en        TIMESTAMP NOT NULL DEFAULT now(),
    UNIQUE (negocio_id, numero)
);
ALTER TABLE compras DROP CONSTRAINT IF EXISTS compras_retencion_max;
ALTER TABLE compras ADD CONSTRAINT compras_retencion_max CHECK (retencion <= GREATEST(total, COALESCE(factura_total, 0)));
ALTER TABLE compras DROP CONSTRAINT IF EXISTS compras_categoria_ok;
ALTER TABLE compras ADD CONSTRAINT compras_categoria_ok CHECK (categoria IN ('espacio','menu','bebidas','decoracion','musica','foto_video','mobiliario','personal','transmision','otros'));
CREATE UNIQUE INDEX IF NOT EXISTS compras_verificacion ON compras (codigo_verificacion);
CREATE INDEX IF NOT EXISTS compras_proveedor ON compras (proveedor_id, fecha);
CREATE INDEX IF NOT EXISTS compras_evento ON compras (evento_id);
CREATE INDEX IF NOT EXISTS compras_negocio_estado ON compras (negocio_id, estado, vence);

CREATE TABLE IF NOT EXISTS compra_items (
    id                    SERIAL PRIMARY KEY,
    compra_id             INT NOT NULL REFERENCES compras(id) ON DELETE CASCADE,
    descripcion           VARCHAR(300) NOT NULL,
    cantidad              NUMERIC(10,2) NOT NULL DEFAULT 1 CHECK (cantidad > 0),
    precio_unitario       NUMERIC(12,4) NOT NULL DEFAULT 0 CHECK (precio_unitario >= 0),
    con_iva               BOOLEAN NOT NULL DEFAULT true,
    subtotal              NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (subtotal >= 0),
    orden                 INT NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS compra_items_compra ON compra_items (compra_id, orden);

-- ---------------------------------------------------------------
-- Pagos a proveedores (nunca se borran: se anulan con motivo)
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pagos_proveedor (
    id                    SERIAL PRIMARY KEY,
    negocio_id            INT NOT NULL REFERENCES negocios(id) ON DELETE CASCADE,
    compra_id             INT NOT NULL REFERENCES compras(id) ON DELETE RESTRICT,
    proveedor_id          INT NOT NULL REFERENCES proveedores(id) ON DELETE RESTRICT,
    fecha                 DATE NOT NULL DEFAULT CURRENT_DATE,
    monto                 NUMERIC(12,2) NOT NULL CHECK (monto > 0),
    metodo                VARCHAR(15) NOT NULL DEFAULT 'transferencia'
                          CHECK (metodo IN ('efectivo','transferencia','deuna','cheque','tarjeta')),
    referencia            VARCHAR(120),
    cheque_fecha          DATE,                          -- cheque posfechado: día en que se puede cobrar
    comprobante           VARCHAR(60),                   -- foto o PDF (uploads/proveedores)
    estado                VARCHAR(10) NOT NULL DEFAULT 'valido' CHECK (estado IN ('valido','anulado')),
    anulado_motivo        VARCHAR(300),
    anulado_por           INT REFERENCES usuarios(id) ON DELETE SET NULL,
    anulado_en            TIMESTAMP,
    notas                 VARCHAR(300),
    registrado_por        INT REFERENCES usuarios(id) ON DELETE SET NULL,
    creado_en             TIMESTAMP NOT NULL DEFAULT now(),
    CHECK (cheque_fecha IS NULL OR metodo = 'cheque'),
    CHECK (estado = 'valido' OR anulado_motivo IS NOT NULL)
);
CREATE INDEX IF NOT EXISTS pagos_proveedor_compra ON pagos_proveedor (compra_id) WHERE estado = 'valido';
CREATE INDEX IF NOT EXISTS pagos_proveedor_prov ON pagos_proveedor (proveedor_id, fecha);

-- ---------------------------------------------------------------
-- Cumplimiento el día del evento
-- ---------------------------------------------------------------
ALTER TABLE evento_proveedores ADD COLUMN IF NOT EXISTS compra_id      INT REFERENCES compras(id) ON DELETE SET NULL;
ALTER TABLE evento_proveedores ADD COLUMN IF NOT EXISTS llegada_real   TIMESTAMP;
ALTER TABLE evento_proveedores ADD COLUMN IF NOT EXISTS llegada_origen VARCHAR(12);
ALTER TABLE evento_proveedores ADD COLUMN IF NOT EXISTS cumplimiento   VARCHAR(15);
ALTER TABLE evento_proveedores ADD COLUMN IF NOT EXISTS calificacion   SMALLINT;
ALTER TABLE evento_proveedores ADD COLUMN IF NOT EXISTS comentario     VARCHAR(500);
ALTER TABLE evento_proveedores ADD COLUMN IF NOT EXISTS calificado_por INT REFERENCES usuarios(id) ON DELETE SET NULL;
ALTER TABLE evento_proveedores ADD COLUMN IF NOT EXISTS calificado_en  TIMESTAMP;
ALTER TABLE evento_proveedores ADD COLUMN IF NOT EXISTS aviso_confirmar DATE;   -- fecha del evento para la que se avisó "sin confirmar"
ALTER TABLE evento_proveedores DROP CONSTRAINT IF EXISTS evento_proveedores_cumplimiento_ok;
ALTER TABLE evento_proveedores ADD CONSTRAINT evento_proveedores_cumplimiento_ok
    CHECK ((cumplimiento IS NULL OR cumplimiento IN ('cumplio','observaciones','incompleto','no_llego'))
       AND (calificacion IS NULL OR calificacion BETWEEN 1 AND 5)
       AND (llegada_origen IS NULL OR llegada_origen IN ('proveedor','coordinador')));
CREATE INDEX IF NOT EXISTS evento_proveedores_prov ON evento_proveedores (proveedor_id);

-- Bloques del cronograma con un proveedor responsable (para saber si cumplió a tiempo)
ALTER TABLE cronograma ADD COLUMN IF NOT EXISTS evento_proveedor_id INT REFERENCES evento_proveedores(id) ON DELETE SET NULL;

-- ---------------------------------------------------------------
-- Incidencias (problemas de cualquier evento; con o sin proveedor)
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS incidencias (
    id                    SERIAL PRIMARY KEY,
    negocio_id            INT NOT NULL REFERENCES negocios(id) ON DELETE CASCADE,
    evento_id             INT REFERENCES eventos(id) ON DELETE SET NULL,
    proveedor_id          INT REFERENCES proveedores(id) ON DELETE SET NULL,
    compra_id             INT REFERENCES compras(id) ON DELETE SET NULL,   -- a qué compra se le descuenta
    area                  VARCHAR(15) NOT NULL DEFAULT 'otro'
                          CHECK (area IN ('proveedor','cliente','invitados','personal','mobiliario','instalaciones','seguridad','otro')),
    gravedad              VARCHAR(6) NOT NULL DEFAULT 'leve' CHECK (gravedad IN ('leve','media','grave')),
    descripcion           TEXT NOT NULL,
    foto                  VARCHAR(60),
    reportado_por         INT REFERENCES usuarios(id) ON DELETE SET NULL,
    reportado_nombre      VARCHAR(120),
    responsable           VARCHAR(120),
    estado                VARCHAR(10) NOT NULL DEFAULT 'abierta' CHECK (estado IN ('abierta','en_proceso','resuelta')),
    resolucion            TEXT,
    costo                 NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (costo >= 0),     -- impacto en dinero
    descuento             NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (descuento >= 0), -- se descuenta del saldo de la compra
    resuelta_en           TIMESTAMP,
    creado_en             TIMESTAMP NOT NULL DEFAULT now(),
    actualizado_en        TIMESTAMP NOT NULL DEFAULT now(),
    CHECK (descuento = 0 OR compra_id IS NOT NULL)
);
CREATE INDEX IF NOT EXISTS incidencias_negocio ON incidencias (negocio_id, estado, creado_en DESC);
CREATE INDEX IF NOT EXISTS incidencias_evento ON incidencias (evento_id);
CREATE INDEX IF NOT EXISTS incidencias_proveedor ON incidencias (proveedor_id);
CREATE INDEX IF NOT EXISTS incidencias_compra ON incidencias (compra_id) WHERE descuento > 0;

-- ---------------------------------------------------------------
-- Bitácora del proveedor: todo lo que pasó, quién y cuándo
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS proveedor_movimientos (
    id                    SERIAL PRIMARY KEY,
    negocio_id            INT NOT NULL REFERENCES negocios(id) ON DELETE CASCADE,
    proveedor_id          INT NOT NULL REFERENCES proveedores(id) ON DELETE CASCADE,
    compra_id             INT REFERENCES compras(id) ON DELETE SET NULL,
    evento_id             INT REFERENCES eventos(id) ON DELETE SET NULL,
    tipo                  VARCHAR(12) NOT NULL DEFAULT 'otro'
                          CHECK (tipo IN ('compra','factura','pago','documento','llegada','calificacion','incidencia','condiciones','portal','otro')),
    accion                VARCHAR(400) NOT NULL,
    origen                VARCHAR(10) NOT NULL DEFAULT 'usuario' CHECK (origen IN ('usuario','proveedor','sistema')),
    usuario_id            INT REFERENCES usuarios(id) ON DELETE SET NULL,
    quien                 VARCHAR(120),
    creado_en             TIMESTAMP NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS proveedor_movimientos_prov ON proveedor_movimientos (proveedor_id, creado_en DESC);
CREATE INDEX IF NOT EXISTS proveedor_movimientos_compra ON proveedor_movimientos (compra_id);
