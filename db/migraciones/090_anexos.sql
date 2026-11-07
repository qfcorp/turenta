-- =====================================================================
-- 090 — Anexos de contrato: cuando se aprueban extras pedidos desde el portal y el evento ya tiene
--       el contrato FIRMADO, se prepara un anexo que el cliente firma con el dedo (misma huella SHA-256,
--       código de verificación con QR y PDF firmado que el contrato).
-- Se puede correr muchas veces (IF NOT EXISTS / DROP ... IF EXISTS).
-- =====================================================================
SET client_min_messages = warning;

-- Plantillas: tipo 'contrato' (las de siempre) o 'anexo' (una por negocio, se crea sola con el texto de fábrica)
ALTER TABLE plantillas_contrato ADD COLUMN IF NOT EXISTS tipo VARCHAR(10) NOT NULL DEFAULT 'contrato';
ALTER TABLE plantillas_contrato DROP CONSTRAINT IF EXISTS plantillas_contrato_tipo_ok;
ALTER TABLE plantillas_contrato ADD CONSTRAINT plantillas_contrato_tipo_ok CHECK (tipo IN ('contrato','anexo'));
CREATE UNIQUE INDEX IF NOT EXISTS plantillas_contrato_un_anexo ON plantillas_contrato (negocio_id) WHERE tipo = 'anexo';

-- Anexos (tabla aparte: así el contrato vigente, su numeración y sus recordatorios no cambian)
CREATE TABLE IF NOT EXISTS contrato_anexos (
    id                    SERIAL PRIMARY KEY,
    negocio_id            INT NOT NULL REFERENCES negocios(id) ON DELETE CASCADE,
    evento_id             INT NOT NULL REFERENCES eventos(id) ON DELETE CASCADE,
    contrato_id           INT NOT NULL REFERENCES contratos(id) ON DELETE CASCADE,   -- contrato firmado al que se suma
    pedido_id             INT UNIQUE REFERENCES extras_pedidos(id) ON DELETE SET NULL, -- extras aprobados (un anexo por pedido)
    numero                INT NOT NULL,                                               -- Anexo 1, 2... de ese contrato
    codigo_verificacion   VARCHAR(16) NOT NULL UNIQUE,
    texto                 TEXT NOT NULL,                                              -- texto final (no cambia)
    hash_sha256           CHAR(64) NOT NULL,
    estado                VARCHAR(10) NOT NULL DEFAULT 'enviado' CHECK (estado IN ('enviado','firmado','anulado')),
    token                 VARCHAR(64) NOT NULL UNIQUE DEFAULT encode(gen_random_bytes(24), 'hex'),
    monto                 NUMERIC(12,2) NOT NULL DEFAULT 0,                           -- lo que sumaron los extras (con IVA)
    firmante_nombre       VARCHAR(150),
    firmante_identificacion VARCHAR(13),
    firma_archivo         VARCHAR(60),
    firmado_en            TIMESTAMP,
    firma_ip              VARCHAR(45),
    firma_navegador       VARCHAR(300),
    creado_por            INT REFERENCES usuarios(id) ON DELETE SET NULL,
    creado_en             TIMESTAMP NOT NULL DEFAULT now(),
    UNIQUE (contrato_id, numero)
);
CREATE INDEX IF NOT EXISTS contrato_anexos_evento ON contrato_anexos (evento_id, numero);
CREATE INDEX IF NOT EXISTS contrato_anexos_negocio ON contrato_anexos (negocio_id, estado);

-- Recordatorio por WhatsApp del anexo sin firmar: tipo 'anexo' (ref = contrato_anexos.id). Se mantiene 'cuestionario' (070).
ALTER TABLE mensajes_enviados DROP CONSTRAINT IF EXISTS mensajes_enviados_tipo_check;
ALTER TABLE mensajes_enviados ADD CONSTRAINT mensajes_enviados_tipo_check
    CHECK (tipo IN ('cuota','contrato','cotizacion','staff','invitados','resena','cuestionario','anexo'));
