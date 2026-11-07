-- 110 · Ayuda e inteligencia artificial (compartido/LEEME_IA.md del Panel Tukán Central, Anexo C de PROTOCOLO_V2.md)
-- Se puede correr muchas veces (IF NOT EXISTS).
--  - tukan_ia_faq: preguntas frecuentes de la Ayuda (las mismas para todos los negocios del programa)
--  - tukan_ia_gasto: gasto de IA por negocio y mes (negocio_id es TEXTO: lo pide la librería; Papelera no la toca)
--  - negocios.ia_tope_usd: tope del mes de ese negocio en USD (NULL = el de siempre: 4,00 con «Asistente IA», 0,50 sin él)

CREATE TABLE IF NOT EXISTS tukan_ia_faq (
  id BIGSERIAL PRIMARY KEY,
  pregunta VARCHAR(500) NOT NULL,
  pregunta_normalizada VARCHAR(500) NOT NULL,
  respuesta TEXT NOT NULL,
  usos INTEGER NOT NULL DEFAULT 0,
  origen VARCHAR(12) NOT NULL DEFAULT 'automatica',   -- 'automatica' (la escribió la IA) o 'manual' (una persona)
  aprobada SMALLINT NOT NULL DEFAULT 0,                -- 1 = revisada por el súper usuario
  fecha TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS tukan_ia_faq_norm ON tukan_ia_faq (pregunta_normalizada);

CREATE TABLE IF NOT EXISTS tukan_ia_gasto (
  negocio_id VARCHAR(64) NOT NULL,                    -- el id del negocio (cliente) en este programa
  mes CHAR(7) NOT NULL,                               -- '2026-10'
  costo_usd DECIMAL(12,6) NOT NULL DEFAULT 0,
  llamadas INTEGER NOT NULL DEFAULT 0,                -- llamadas a la IA que salieron bien
  fallidas INTEGER NOT NULL DEFAULT 0,
  tokens_entrada BIGINT NOT NULL DEFAULT 0,
  tokens_salida BIGINT NOT NULL DEFAULT 0,
  ahorradas INTEGER NOT NULL DEFAULT 0,               -- respuestas desde una FAQ o el manual, sin llamar a la IA
  ayudas_nuevas INTEGER NOT NULL DEFAULT 0,           -- respuestas NUEVAS de la ayuda hechas con IA
  actualizado TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (negocio_id, mes)
);

ALTER TABLE negocios ADD COLUMN IF NOT EXISTS ia_tope_usd NUMERIC(8,2);
DO $$ BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'negocios_ia_tope_usd_check') THEN
    ALTER TABLE negocios ADD CONSTRAINT negocios_ia_tope_usd_check CHECK (ia_tope_usd IS NULL OR (ia_tope_usd >= 0 AND ia_tope_usd <= 1000));
  END IF;
END $$;
