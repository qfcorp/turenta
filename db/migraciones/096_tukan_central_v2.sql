-- =====================================================================
-- 096 — Panel Tukán Central, protocolo v2 (docs del Central: PROTOCOLO_V2.md §7 y §10.2).
--   negocios.origen: de dónde vino cada cliente (registro = se registró solo, super = panel de Tukán,
--                    central = demo creada desde el Panel Tukán Central). Los viejos: "super" si el registro del
--                    panel dice "Negocio creado desde el panel…", si no "registro".
--   tukan_central_pedidos.rechazados: límite de 30 pedidos rechazados (401) por minuto por IP (además de 120 en total)
--   tukan_central_operaciones: cada "ejecutar" del Central con su clave de operación (no se repite un pago ni una demo
--                    si el Central reintenta). Guarda la respuesta SIN contraseñas. Se borran a los 90 días.
--   tukan_central_atenciones: recordatorios y avisos que el Central marcó como "en proceso", "atendida" o "descartada"
--                    (registros nuevos, seguimiento de pruebas, renovaciones, dominios gratis).
-- Se puede correr muchas veces (IF NOT EXISTS).
-- =====================================================================
SET client_min_messages = warning;

ALTER TABLE negocios ADD COLUMN IF NOT EXISTS origen VARCHAR(12);
UPDATE negocios n SET origen = CASE
         WHEN EXISTS (SELECT 1 FROM registro_admin r WHERE r.negocio_id = n.id AND r.accion LIKE 'Negocio creado desde el panel%') THEN 'super'
         ELSE 'registro' END
 WHERE origen IS NULL;
ALTER TABLE negocios ALTER COLUMN origen SET DEFAULT 'registro';
ALTER TABLE negocios ALTER COLUMN origen SET NOT NULL;

ALTER TABLE tukan_central_pedidos ADD COLUMN IF NOT EXISTS rechazados INT NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS tukan_central_operaciones (
    operacion_id          CHAR(32) PRIMARY KEY,
    accion                VARCHAR(40) NOT NULL,
    cliente_id            VARCHAR(80),
    cuerpo_hash           CHAR(43) NOT NULL,             -- el mismo valor que "bh" del token
    estado                VARCHAR(10) NOT NULL CHECK (estado IN ('en_curso','ok','error')),
    http                  INT,
    respuesta             TEXT,                          -- JSON de la respuesta SIN claves
    email                 VARCHAR(120) NOT NULL,
    creado                TIMESTAMP NOT NULL DEFAULT now(),
    terminado             TIMESTAMP
);
CREATE INDEX IF NOT EXISTS tukan_central_operaciones_creado ON tukan_central_operaciones (creado);

CREATE TABLE IF NOT EXISTS tukan_central_atenciones (
    solicitud_id          VARCHAR(80) PRIMARY KEY,       -- "seguimiento_prueba:123:faltan_2:2026-10-20"
    tipo                  VARCHAR(30) NOT NULL,
    estado                VARCHAR(12) NOT NULL CHECK (estado IN ('en_proceso','atendida','descartada')),
    nota                  VARCHAR(300),
    email                 VARCHAR(120) NOT NULL,
    nombre                VARCHAR(100),
    momento               TIMESTAMP NOT NULL DEFAULT now()
);
