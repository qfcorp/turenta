-- =====================================================================
-- 091 — Puntaje de proveedores configurable por negocio.
--   Pesos (calificación, puntualidad, cumplimiento, incidencias; suman 100), tolerancia de puntualidad,
--   cuánto resta cada incidencia según su gravedad, tope del nivel con una incidencia grave,
--   umbrales de los niveles, periodo que se evalúa y mínimo de eventos para mostrar el puntaje.
--   Sin fila = valores recomendados (los mismos de antes): los resultados no cambian.
-- Se puede correr muchas veces (IF NOT EXISTS / DROP ... IF EXISTS).
-- =====================================================================
SET client_min_messages = warning;

CREATE TABLE IF NOT EXISTS puntaje_proveedores_config (
    negocio_id            INT PRIMARY KEY REFERENCES negocios(id) ON DELETE CASCADE,
    peso_calificacion     INT NOT NULL DEFAULT 40,
    peso_puntualidad      INT NOT NULL DEFAULT 30,
    peso_cumplimiento     INT NOT NULL DEFAULT 20,
    peso_incidencias      INT NOT NULL DEFAULT 10,
    tolerancia_min        INT NOT NULL DEFAULT 10,      -- minutos de tolerancia para "a tiempo"
    resta_leve            INT NOT NULL DEFAULT 10,      -- puntos que resta cada incidencia (de 100) en la parte de incidencias
    resta_media           INT NOT NULL DEFAULT 25,
    resta_grave           INT NOT NULL DEFAULT 50,
    tope_grave            BOOLEAN NOT NULL DEFAULT true, -- con una incidencia grave, el nivel no pasa de tope_nivel
    tope_nivel            VARCHAR(10) NOT NULL DEFAULT 'bueno',
    umbral_excelente      INT NOT NULL DEFAULT 85,
    umbral_bueno          INT NOT NULL DEFAULT 70,
    umbral_regular        INT NOT NULL DEFAULT 50,
    periodo_meses         INT NOT NULL DEFAULT 0,       -- 0 = todo el historial, 12 o 6 = últimos meses
    minimo_eventos        INT NOT NULL DEFAULT 0,       -- eventos trabajados necesarios para mostrar el puntaje
    actualizado_por       INT REFERENCES usuarios(id) ON DELETE SET NULL,
    actualizado_en        TIMESTAMP NOT NULL DEFAULT now()
);

ALTER TABLE puntaje_proveedores_config DROP CONSTRAINT IF EXISTS puntaje_pesos_ok;
ALTER TABLE puntaje_proveedores_config ADD CONSTRAINT puntaje_pesos_ok
    CHECK (peso_calificacion BETWEEN 0 AND 100 AND peso_puntualidad BETWEEN 0 AND 100 AND peso_cumplimiento BETWEEN 0 AND 100
       AND peso_incidencias BETWEEN 0 AND 100 AND peso_calificacion + peso_puntualidad + peso_cumplimiento + peso_incidencias = 100);
ALTER TABLE puntaje_proveedores_config DROP CONSTRAINT IF EXISTS puntaje_reglas_ok;
ALTER TABLE puntaje_proveedores_config ADD CONSTRAINT puntaje_reglas_ok
    CHECK (tolerancia_min BETWEEN 0 AND 120
       AND resta_leve BETWEEN 0 AND 100 AND resta_media BETWEEN 0 AND 100 AND resta_grave BETWEEN 0 AND 100
       AND resta_leve <= resta_media AND resta_media <= resta_grave
       AND tope_nivel IN ('bueno','regular','malo')
       AND umbral_excelente <= 100 AND umbral_excelente > umbral_bueno AND umbral_bueno > umbral_regular AND umbral_regular >= 1
       AND periodo_meses IN (0, 6, 12) AND minimo_eventos BETWEEN 0 AND 50);
