-- =====================================================================
-- 010 — Ventas, calendario y página pública
-- Se puede correr muchas veces (IF NOT EXISTS).
-- =====================================================================

-- Consultas que llegan desde la página pública del negocio (/?r=sitio&s=<slug>).
-- Sirve para frenar a los robots: máximo 5 consultas por hora desde la misma IP para el mismo negocio.
CREATE TABLE IF NOT EXISTS consultas_web (
    id                    SERIAL PRIMARY KEY,
    negocio_id            INT NOT NULL REFERENCES negocios(id) ON DELETE CASCADE,
    evento_id             INT REFERENCES eventos(id) ON DELETE SET NULL,
    ip                    VARCHAR(45) NOT NULL DEFAULT '',
    creado_en             TIMESTAMP NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS consultas_web_ip ON consultas_web (negocio_id, ip, creado_en);

-- Búsquedas frecuentes de ventas: actividades por cliente y fechas cerradas por día
CREATE INDEX IF NOT EXISTS actividades_cliente ON actividades (negocio_id, cliente_id);
CREATE INDEX IF NOT EXISTS actividades_evento ON actividades (evento_id);
CREATE INDEX IF NOT EXISTS fechas_cerradas_negocio ON fechas_cerradas (negocio_id, fecha);
