-- =====================================================================
-- 040 — Día del evento: cronograma en vivo, personal, proveedores, mobiliario
--       e invitados con QR (invitación digital, modo puerta, plano de mesas).
-- Se puede correr muchas veces (IF NOT EXISTS / DROP ... IF EXISTS).
-- =====================================================================
SET client_min_messages = warning;   -- sin avisos de "no existe, se omite"

-- Personal: notas internas y respuesta desde su enlace "Mis eventos"
ALTER TABLE personal          ADD COLUMN IF NOT EXISTS notas        VARCHAR(300);
ALTER TABLE personal          ADD COLUMN IF NOT EXISTS creado_en    TIMESTAMP NOT NULL DEFAULT now();
ALTER TABLE evento_personal   ADD COLUMN IF NOT EXISTS respuesta    VARCHAR(2) CHECK (respuesta IN ('si','no'));  -- NULL = no ha respondido
ALTER TABLE evento_personal   ADD COLUMN IF NOT EXISTS respondio_en TIMESTAMP;
ALTER TABLE evento_proveedores ADD COLUMN IF NOT EXISTS creado_en   TIMESTAMP NOT NULL DEFAULT now();

-- Invitación digital: texto propio del evento y fecha límite para confirmar
ALTER TABLE eventos   ADD COLUMN IF NOT EXISTS invitacion_texto   VARCHAR(600);
ALTER TABLE eventos   ADD COLUMN IF NOT EXISTS confirmar_hasta    DATE;
-- Invitados: saludo que deja al confirmar y día en que se le mandó la invitación
ALTER TABLE invitados ADD COLUMN IF NOT EXISTS mensaje            VARCHAR(400);
ALTER TABLE invitados ADD COLUMN IF NOT EXISTS invitacion_enviada DATE;

-- Reglas que cuida la base de datos (aunque alguien se salte el programa)
ALTER TABLE evento_mobiliario DROP CONSTRAINT IF EXISTS evento_mobiliario_danados_max;
ALTER TABLE evento_mobiliario ADD CONSTRAINT evento_mobiliario_danados_max CHECK (danados <= cantidad);
ALTER TABLE invitados DROP CONSTRAINT IF EXISTS invitados_ingreso_max;
ALTER TABLE invitados ADD CONSTRAINT invitados_ingreso_max CHECK (ingresaron >= 0 AND ingresaron <= pases);
ALTER TABLE invitados DROP CONSTRAINT IF EXISTS invitados_confirmados_max;
ALTER TABLE invitados ADD CONSTRAINT invitados_confirmados_max CHECK (confirmados IS NULL OR (confirmados >= 0 AND confirmados <= pases));

-- Búsquedas frecuentes del día del evento
CREATE INDEX IF NOT EXISTS cronograma_evento         ON cronograma (evento_id, orden);
CREATE INDEX IF NOT EXISTS evento_personal_persona   ON evento_personal (personal_id);
CREATE INDEX IF NOT EXISTS evento_proveedores_evento ON evento_proveedores (evento_id);
CREATE INDEX IF NOT EXISTS evento_mobiliario_mob     ON evento_mobiliario (mobiliario_id);
CREATE INDEX IF NOT EXISTS mesas_evento              ON mesas (evento_id, orden);
CREATE INDEX IF NOT EXISTS personal_negocio          ON personal (negocio_id, activo);
CREATE INDEX IF NOT EXISTS proveedores_negocio       ON proveedores (negocio_id, activo);
CREATE INDEX IF NOT EXISTS mobiliario_negocio        ON mobiliario (negocio_id, activo);
