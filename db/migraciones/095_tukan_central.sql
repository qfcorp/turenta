-- =====================================================================
-- 095 — Conector con el Panel Tukán Central (protocolo v1).
--   superadmins.creado_por_central: el super usuario lo creó el panel central al entrar por primera vez
--   tukan_central_nonces: cada enlace del panel central sirve UNA sola vez (se borran los vencidos)
--   tukan_central_pedidos: límite de 30 pedidos por minuto por IP a /tukan_central.php
-- Se puede correr muchas veces (IF NOT EXISTS).
-- =====================================================================
SET client_min_messages = warning;

ALTER TABLE superadmins ADD COLUMN IF NOT EXISTS creado_por_central BOOLEAN NOT NULL DEFAULT false;

CREATE TABLE IF NOT EXISTS tukan_central_nonces (
    nonce                 CHAR(32) PRIMARY KEY,
    vence                 TIMESTAMPTZ NOT NULL           -- después de esto el token ya no sirve (exp + 30 s)
);
CREATE INDEX IF NOT EXISTS tukan_central_nonces_vence ON tukan_central_nonces (vence);

CREATE TABLE IF NOT EXISTS tukan_central_pedidos (
    ip                    VARCHAR(45) NOT NULL,
    minuto                TIMESTAMPTZ NOT NULL,
    n                     INT NOT NULL DEFAULT 1,
    PRIMARY KEY (ip, minuto)
);
