-- =====================================================================
-- 080 — Plano visual del salón (plantillas por espacio y plano de cada evento)
--       y metas de ventas (del local y por vendedor, con comparación con el año anterior).
-- Se puede correr muchas veces (IF NOT EXISTS / DROP ... IF EXISTS).
-- =====================================================================
SET client_min_messages = warning;   -- sin avisos de "no existe, se omite"

-- ---------------------------------------------------------------
-- Planos: "plantilla" = distribución base de un espacio ("Boda 150 redondas", "Corporativo escuela");
--         "evento"    = el plano de un evento (copiado de una plantilla o desde cero). Uno por evento.
-- Medidas en metros. Las mesas del plano de un evento son las mesas del módulo de invitados (mesa_id).
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS planos (
    id                    SERIAL PRIMARY KEY,
    negocio_id            INT NOT NULL REFERENCES negocios(id) ON DELETE CASCADE,
    tipo                  VARCHAR(10) NOT NULL DEFAULT 'plantilla' CHECK (tipo IN ('plantilla','evento')),
    espacio_id            INT REFERENCES espacios(id) ON DELETE CASCADE,
    evento_id             INT REFERENCES eventos(id) ON DELETE CASCADE,
    plantilla_id          INT REFERENCES planos(id) ON DELETE SET NULL,   -- de qué plantilla se copió (eventos)
    nombre                VARCHAR(80) NOT NULL,
    ancho                 NUMERIC(6,2) NOT NULL CHECK (ancho BETWEEN 3 AND 300),   -- metros (eje X)
    largo                 NUMERIC(6,2) NOT NULL CHECK (largo BETWEEN 3 AND 300),   -- metros (eje Y)
    version               INT NOT NULL DEFAULT 1,            -- sube en cada guardado (dos personas a la vez)
    creado_por            INT REFERENCES usuarios(id) ON DELETE SET NULL,
    creado_en             TIMESTAMP NOT NULL DEFAULT now(),
    actualizado_en        TIMESTAMP NOT NULL DEFAULT now(),
    CHECK ((tipo = 'plantilla' AND espacio_id IS NOT NULL AND evento_id IS NULL) OR (tipo = 'evento' AND evento_id IS NOT NULL))
);
CREATE UNIQUE INDEX IF NOT EXISTS planos_un_evento ON planos (evento_id) WHERE evento_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS planos_negocio ON planos (negocio_id, tipo, espacio_id);

-- Elementos del plano: mesas, pista, tarima, barra... (x, y = centro, en metros; rotación en grados)
CREATE TABLE IF NOT EXISTS plano_elementos (
    id                    SERIAL PRIMARY KEY,
    plano_id              INT NOT NULL REFERENCES planos(id) ON DELETE CASCADE,
    tipo                  VARCHAR(12) NOT NULL CHECK (tipo IN ('redonda','rectangular','imperial','pista','tarima','barra','buffet',
                                                                'dulces','dj','entrada','banos','columna','texto')),
    x                     NUMERIC(7,2) NOT NULL,
    y                     NUMERIC(7,2) NOT NULL,
    ancho                 NUMERIC(6,2) NOT NULL CHECK (ancho > 0),
    alto                  NUMERIC(6,2) NOT NULL CHECK (alto > 0),
    rotacion              INT NOT NULL DEFAULT 0 CHECK (rotacion BETWEEN 0 AND 359),
    etiqueta              VARCHAR(60),
    puestos               INT CHECK (puestos IS NULL OR puestos BETWEEN 1 AND 100),
    -- Mesa del módulo de invitados (solo en el plano de un evento). Si se borra la mesa, sale del plano.
    mesa_id               INT REFERENCES mesas(id) ON DELETE CASCADE,
    orden                 INT NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS plano_elementos_plano ON plano_elementos (plano_id, orden);
CREATE UNIQUE INDEX IF NOT EXISTS plano_elementos_mesa ON plano_elementos (mesa_id) WHERE mesa_id IS NOT NULL;

-- ---------------------------------------------------------------
-- Metas de ventas. vendedor_id NULL = meta del local; mes NULL = meta de todos los meses (la de siempre).
-- Una meta de un mes en particular reemplaza a la de siempre.
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS metas_ventas (
    id                    SERIAL PRIMARY KEY,
    negocio_id            INT NOT NULL REFERENCES negocios(id) ON DELETE CASCADE,
    vendedor_id           INT REFERENCES usuarios(id) ON DELETE CASCADE,
    mes                   CHAR(7),                                 -- 'AAAA-MM'
    monto                 NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (monto >= 0),   -- valor vendido (con IVA, como en Reportes)
    eventos               INT CHECK (eventos IS NULL OR eventos BETWEEN 0 AND 10000),   -- número de eventos (opcional)
    actualizado_en        TIMESTAMP NOT NULL DEFAULT now(),
    CONSTRAINT metas_ventas_unica UNIQUE NULLS NOT DISTINCT (negocio_id, vendedor_id, mes)
);
ALTER TABLE metas_ventas DROP CONSTRAINT IF EXISTS metas_ventas_mes_valido;
ALTER TABLE metas_ventas ADD CONSTRAINT metas_ventas_mes_valido CHECK (mes IS NULL OR mes ~ '^[0-9]{4}-(0[1-9]|1[0-2])$');
ALTER TABLE metas_ventas DROP CONSTRAINT IF EXISTS metas_ventas_algo;
ALTER TABLE metas_ventas ADD CONSTRAINT metas_ventas_algo CHECK (monto > 0 OR eventos > 0);
CREATE INDEX IF NOT EXISTS metas_ventas_negocio ON metas_ventas (negocio_id, vendedor_id);
