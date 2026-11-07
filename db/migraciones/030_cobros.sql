-- =====================================================================
-- 030 — Cobros y portal del cliente
-- Se puede correr muchas veces (IF NOT EXISTS).
-- =====================================================================

-- Mensajes por WhatsApp que el negocio ya marcó como enviados (para no repetirlos).
--   tipo: cuota (ref = cuotas.id), contrato (contratos.id), cotizacion (cotizaciones.id),
--         staff (evento_personal.id), invitados (eventos.id), resena (eventos.id)
CREATE TABLE IF NOT EXISTS mensajes_enviados (
    id                    SERIAL PRIMARY KEY,
    negocio_id            INT NOT NULL REFERENCES negocios(id) ON DELETE CASCADE,
    evento_id             INT REFERENCES eventos(id) ON DELETE CASCADE,
    tipo                  VARCHAR(15) NOT NULL
                          CHECK (tipo IN ('cuota','contrato','cotizacion','staff','invitados','resena')),
    ref_id                INT NOT NULL,
    enviado_en            DATE NOT NULL DEFAULT CURRENT_DATE,
    usuario_id            INT REFERENCES usuarios(id) ON DELETE SET NULL,
    creado_en             TIMESTAMP NOT NULL DEFAULT now(),
    UNIQUE (negocio_id, tipo, ref_id, enviado_en)
);
CREATE INDEX IF NOT EXISTS mensajes_enviados_ref ON mensajes_enviados (negocio_id, tipo, ref_id);

-- Enlace para pedir reseñas (Google, Facebook...) en el mensaje de agradecimiento
ALTER TABLE negocios ADD COLUMN IF NOT EXISTS resenas_url VARCHAR(400);

-- Búsquedas frecuentes de cobranza
CREATE INDEX IF NOT EXISTS gastos_evento ON gastos (evento_id);
CREATE INDEX IF NOT EXISTS pagos_negocio_estado ON pagos (negocio_id, estado);
CREATE INDEX IF NOT EXISTS cuotas_vence ON cuotas (vence) WHERE pagado < monto;
