-- 201 · TuRenta: tiempo de viaje con Google Maps (igual que las rutas de TuGuardería) y montaje/desmontaje por equipo.
-- Se puede correr muchas veces.
--
-- Cómo se ocupa un equipo (camión) para una fiesta:
--   desde = inicio de la fiesta − viaje (base → dirección) − montaje del equipo
--   hasta = fin de la fiesta    + desmontaje del equipo + viaje (dirección → base)
-- Sin viaje con mapa (viaje_mapa = false) el viaje es 0 y todo sigue como antes.
-- Montaje/desmontaje del equipo vacíos = se usan las horas de montaje/desmontaje del negocio (Reglas).

-- Negocio: viaje con mapa, punto de salida (base) y reglas
ALTER TABLE negocios ADD COLUMN IF NOT EXISTS viaje_mapa       BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE negocios ADD COLUMN IF NOT EXISTS base_direccion   VARCHAR(250);
ALTER TABLE negocios ADD COLUMN IF NOT EXISTS base_lat         NUMERIC(9,6);
ALTER TABLE negocios ADD COLUMN IF NOT EXISTS base_lng         NUMERIC(9,6);
ALTER TABLE negocios ADD COLUMN IF NOT EXISTS google_maps_key  VARCHAR(200);                 -- opcional: Routes API + Geocoding API
ALTER TABLE negocios ADD COLUMN IF NOT EXISTS viaje_extra_min  INT NOT NULL DEFAULT 10;       -- margen por tráfico y parqueo (cada sentido)
ALTER TABLE negocios ADD COLUMN IF NOT EXISTS viaje_max_min    INT NOT NULL DEFAULT 90;       -- más lejos que esto no se reserva en línea
DO $$ BEGIN
    ALTER TABLE negocios ADD CONSTRAINT negocios_viaje_extra_check CHECK (viaje_extra_min BETWEEN 0 AND 120);
EXCEPTION WHEN duplicate_object THEN NULL; END $$;
DO $$ BEGIN
    ALTER TABLE negocios ADD CONSTRAINT negocios_viaje_max_check CHECK (viaje_max_min BETWEEN 5 AND 600);
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

-- Equipo (espacio): minutos propios de montaje y desmontaje (un camión grande tarda más que uno chico)
ALTER TABLE espacios ADD COLUMN IF NOT EXISTS montaje_min    INT;
ALTER TABLE espacios ADD COLUMN IF NOT EXISTS desmontaje_min INT;
DO $$ BEGIN
    ALTER TABLE espacios ADD CONSTRAINT espacios_montaje_check CHECK (montaje_min IS NULL OR montaje_min BETWEEN 0 AND 1440);
EXCEPTION WHEN duplicate_object THEN NULL; END $$;
DO $$ BEGIN
    ALTER TABLE espacios ADD CONSTRAINT espacios_desmontaje_check CHECK (desmontaje_min IS NULL OR desmontaje_min BETWEEN 0 AND 1440);
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

-- Evento: minutos de viaje de UNA ida (se guardan para que al editar el evento no se pierdan) y el punto de la fiesta
ALTER TABLE eventos ADD COLUMN IF NOT EXISTS viaje_min    INT;
ALTER TABLE eventos ADD COLUMN IF NOT EXISTS viaje_metodo VARCHAR(40);
ALTER TABLE eventos ADD COLUMN IF NOT EXISTS lat          NUMERIC(9,6);
ALTER TABLE eventos ADD COLUMN IF NOT EXISTS lng          NUMERIC(9,6);
DO $$ BEGIN
    ALTER TABLE eventos ADD CONSTRAINT eventos_viaje_check CHECK (viaje_min IS NULL OR viaje_min BETWEEN 0 AND 1440);
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

-- Lo que ya se buscó en el mapa (cada dirección y cada viaje se paga una sola vez a Google)
CREATE TABLE IF NOT EXISTS mapa_cache (
    id          SERIAL PRIMARY KEY,
    negocio_id  INT NOT NULL REFERENCES negocios(id) ON DELETE CASCADE,
    tipo        VARCHAR(5) NOT NULL CHECK (tipo IN ('punto','viaje')),
    clave       VARCHAR(300) NOT NULL,
    lat         NUMERIC(9,6),
    lng         NUMERIC(9,6),
    minutos     INT,
    km          NUMERIC(8,2),
    metodo      VARCHAR(40) NOT NULL,
    creado_en   TIMESTAMP NOT NULL DEFAULT now(),
    UNIQUE (negocio_id, tipo, clave)
);
