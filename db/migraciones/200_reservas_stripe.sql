-- 200 · TuRenta: reserva en línea (en inglés para negocios de EE.UU.) y cobro con Stripe.
-- Se puede correr muchas veces.

-- Negocio: claves de Stripe, idioma de las páginas del cliente y reglas de la reserva en línea
ALTER TABLE negocios ADD COLUMN IF NOT EXISTS stripe_secret_key      VARCHAR(200);
ALTER TABLE negocios ADD COLUMN IF NOT EXISTS stripe_publishable_key VARCHAR(200);
ALTER TABLE negocios ADD COLUMN IF NOT EXISTS stripe_webhook_secret  VARCHAR(200);
ALTER TABLE negocios ADD COLUMN IF NOT EXISTS idioma_publico         VARCHAR(2) NOT NULL DEFAULT 'es';
ALTER TABLE negocios ADD COLUMN IF NOT EXISTS reserva_en_linea       BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE negocios ADD COLUMN IF NOT EXISTS reserva_hora_desde     TIME NOT NULL DEFAULT '09:00';
ALTER TABLE negocios ADD COLUMN IF NOT EXISTS reserva_hora_hasta     TIME NOT NULL DEFAULT '19:00';   -- última hora de inicio
ALTER TABLE negocios ADD COLUMN IF NOT EXISTS reserva_paso_min       INT NOT NULL DEFAULT 30;          -- cada cuánto se ofrece una hora
ALTER TABLE negocios ADD COLUMN IF NOT EXISTS reserva_dias_min       INT NOT NULL DEFAULT 2;           -- aviso mínimo (días)
ALTER TABLE negocios ADD COLUMN IF NOT EXISTS reserva_condiciones    TEXT;                             -- condiciones que acepta el cliente
ALTER TABLE negocios ADD COLUMN IF NOT EXISTS sitio_externo          VARCHAR(300);                     -- página web propia del negocio
ALTER TABLE negocios ADD COLUMN IF NOT EXISTS logo_url               VARCHAR(400);                     -- logo en la página web propia
DO $$ BEGIN
    ALTER TABLE negocios ADD CONSTRAINT negocios_idioma_publico_check CHECK (idioma_publico IN ('es','en'));
EXCEPTION WHEN duplicate_object THEN NULL; END $$;
DO $$ BEGIN
    ALTER TABLE negocios ADD CONSTRAINT negocios_reserva_paso_check CHECK (reserva_paso_min BETWEEN 10 AND 240);
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

-- Paquetes que se reservan en línea: nombre en la dirección, duración, foto, máximo de niños y qué equipos (espacios) lo pueden hacer
ALTER TABLE paquetes ADD COLUMN IF NOT EXISTS slug            VARCHAR(60);
ALTER TABLE paquetes ADD COLUMN IF NOT EXISTS publico         BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE paquetes ADD COLUMN IF NOT EXISTS duracion_min    INT NOT NULL DEFAULT 60;
ALTER TABLE paquetes ADD COLUMN IF NOT EXISTS maximo_invitados INT;
ALTER TABLE paquetes ADD COLUMN IF NOT EXISTS foto_url        VARCHAR(400);
ALTER TABLE paquetes ADD COLUMN IF NOT EXISTS orden           INT NOT NULL DEFAULT 0;
CREATE UNIQUE INDEX IF NOT EXISTS paquetes_slug ON paquetes (negocio_id, slug) WHERE slug IS NOT NULL;

CREATE TABLE IF NOT EXISTS paquete_espacios (
    paquete_id            INT NOT NULL REFERENCES paquetes(id) ON DELETE CASCADE,
    espacio_id            INT NOT NULL REFERENCES espacios(id) ON DELETE CASCADE,
    PRIMARY KEY (paquete_id, espacio_id)
);

-- Pagos con Stripe (Checkout): método y origen nuevos, y la sesión de Stripe no se repite
ALTER TABLE pagos DROP CONSTRAINT IF EXISTS pagos_metodo_check;
ALTER TABLE pagos ADD CONSTRAINT pagos_metodo_check
    CHECK (metodo IN ('efectivo','transferencia','deuna','tarjeta','payphone','stripe','cheque','otro'));
ALTER TABLE pagos DROP CONSTRAINT IF EXISTS pagos_origen_check;
ALTER TABLE pagos ADD CONSTRAINT pagos_origen_check CHECK (origen IN ('negocio','cliente','payphone','stripe'));
CREATE UNIQUE INDEX IF NOT EXISTS pagos_stripe_sesion ON pagos (referencia) WHERE metodo = 'stripe' AND referencia IS NOT NULL;

-- Evento reservado en línea: paquete elegido y dirección de la fiesta (el negocio va a la casa del cliente)
ALTER TABLE eventos ADD COLUMN IF NOT EXISTS reserva_paquete_id INT REFERENCES paquetes(id) ON DELETE SET NULL;
ALTER TABLE eventos ADD COLUMN IF NOT EXISTS direccion          VARCHAR(250);
ALTER TABLE eventos ADD COLUMN IF NOT EXISTS codigo_postal      VARCHAR(10);
