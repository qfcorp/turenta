-- =====================================================================
-- 050 · Gestión: inicio, reportes, configuración, usuarios, tareas automáticas y panel Tukán.
-- Se puede correr muchas veces (IF NOT EXISTS).
-- =====================================================================

-- Marcas de las tareas automáticas (cada aviso sale UNA sola vez)
ALTER TABLE eventos  ADD COLUMN IF NOT EXISTS retencion_avisada  DATE;   -- fecha retenida cuyo vencimiento ya se avisó
ALTER TABLE eventos  ADD COLUMN IF NOT EXISTS aviso_semana_fecha DATE;   -- fecha del evento para la que se envió el aviso de 7 días
ALTER TABLE eventos  ADD COLUMN IF NOT EXISTS aviso_precio_fecha DATE;   -- fecha del evento para la que se envió "revisar precio"
ALTER TABLE negocios ADD COLUMN IF NOT EXISTS aviso_cuotas_dia   DATE;   -- día en que se envió la lista de cuotas por cobrar
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS seguimientos_dia   DATE;   -- día en que se envió su lista de seguimientos por Telegram

-- Primeros pasos de una cuenta nueva
ALTER TABLE negocios ADD COLUMN IF NOT EXISTS precios_revisados  BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE negocios ADD COLUMN IF NOT EXISTS pasos_ocultos      BOOLEAN NOT NULL DEFAULT false;

-- Contraseña temporal (creada por el dueño o por Tukán): se pide cambiarla
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS clave_temporal     BOOLEAN NOT NULL DEFAULT false;

-- Freno de intentos del panel Tukán y lista del registro
CREATE INDEX IF NOT EXISTS registro_admin_fecha ON registro_admin (creado_en DESC);
CREATE INDEX IF NOT EXISTS registro_admin_negocio ON registro_admin (negocio_id, creado_en DESC);
CREATE INDEX IF NOT EXISTS pagos_negocio_fecha ON pagos (negocio_id, fecha);
