-- =====================================================================
-- 098 — Programa modular (PROTOCOLO_V2.md del Central, Anexo B) y "todo se pide demo".
--   modulos: precio, descripción y orden de cada módulo (el nombre, el ícono y las dependencias están en src/Modulos.php).
--   planes.modulos: módulos que trae cada plan ("*" = todos). Plan nuevo "esencial" (solo la base).
--     Salón = base + paquetes, contratos, portal del cliente, cobros en línea, formulario/extras/encuesta,
--             proveedores y compras, incidencias, metas, Telegram y exportar (lo mismo que tenía antes).
--     Quinta = todo.
--   negocios.modulos_extra / modulos_quitados: módulos encendidos además del plan (se cobran) y apagados del plan
--     (se descuentan). Empiezan vacíos: cada cuenta queda con lo mismo que tenía. precio_acordado: precio fijo al mes.
--     modulos_prueba: en demo, los módulos que eligió el súper usuario (NULL = todos).
--   modulos_solicitudes: pedidos de módulos desde "Mi plan" (solicitud cambio_modulos del Panel Central).
--   pedidos_demo: ya no hay registro con prueba gratis; quien quiere probar pide una demo (solicitud demo_pedida).
-- Se puede correr muchas veces (IF NOT EXISTS) en una base nueva o en la que ya está.
-- =====================================================================
SET client_min_messages = warning;

CREATE TABLE IF NOT EXISTS modulos (
    id                    VARCHAR(30) PRIMARY KEY,
    precio_mensual        NUMERIC(10,2) NOT NULL CHECK (precio_mensual >= 0),
    descripcion           VARCHAR(400),                  -- NULL = la de src/Modulos.php
    orden                 INT NOT NULL DEFAULT 0,
    actualizado           TIMESTAMP NOT NULL DEFAULT now()
);
INSERT INTO modulos (id, precio_mensual, orden) VALUES
    ('paquetes', 3, 10), ('contratos', 6, 20), ('portal_cliente', 6, 30), ('cobros_linea', 4, 40), ('cuestionarios', 5, 50),
    ('dia_evento', 8, 60), ('invitados', 8, 70), ('plano', 6, 80), ('mobiliario', 5, 90), ('boda_vivo', 5, 100),
    ('proveedores', 7, 110), ('portal_proveedor', 4, 120), ('incidencias', 3, 130), ('metas', 4, 140),
    ('telegram', 3, 150), ('exportar', 3, 160)
ON CONFLICT (id) DO NOTHING;

ALTER TABLE planes ADD COLUMN IF NOT EXISTS modulos TEXT NOT NULL DEFAULT '*';
INSERT INTO planes (codigo, nombre, precio_mensual, max_espacios, max_usuarios, descripcion, modulos) VALUES
    ('esencial', 'Esencial', 19.00, 1, 2, 'Lo básico para empezar: calendario sin doble reserva, eventos, clientes, cotizaciones, cuotas y cobros. Agrega solo los módulos que necesites.', '')
ON CONFLICT (codigo) DO NOTHING;
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM ajustes WHERE clave = 'modulos_098') THEN
        UPDATE planes SET modulos = 'paquetes,contratos,portal_cliente,cobros_linea,cuestionarios,proveedores,incidencias,metas,telegram,exportar',
                          descripcion = 'Para salones de recepciones: todo lo de Esencial + paquetes, contratos firmados, portal del cliente con cobros en línea, formulario y encuesta, proveedores y compras, metas, Telegram y Excel.'
         WHERE codigo = 'salon';
        UPDATE planes SET modulos = '*',
                          descripcion = 'Para quintas y haciendas: todos los módulos, sin límite de espacios ni usuarios (día del evento, invitados con QR, plano, mobiliario, boda en vivo y portal del proveedor).'
         WHERE codigo = 'quinta';
        INSERT INTO ajustes (clave, valor) VALUES ('modulos_098', '1');
    END IF;
END $$;

ALTER TABLE negocios ADD COLUMN IF NOT EXISTS modulos_extra TEXT NOT NULL DEFAULT '';
ALTER TABLE negocios ADD COLUMN IF NOT EXISTS modulos_quitados TEXT NOT NULL DEFAULT '';
ALTER TABLE negocios ADD COLUMN IF NOT EXISTS modulos_prueba TEXT;
ALTER TABLE negocios ADD COLUMN IF NOT EXISTS precio_acordado NUMERIC(10,2) CHECK (precio_acordado IS NULL OR precio_acordado >= 0);
ALTER TABLE negocios ALTER COLUMN origen SET DEFAULT 'super';

CREATE TABLE IF NOT EXISTS modulos_solicitudes (
    id                    SERIAL PRIMARY KEY,
    negocio_id            INT NOT NULL REFERENCES negocios(id) ON DELETE CASCADE,
    usuario_id            INT REFERENCES usuarios(id) ON DELETE SET NULL,
    encender              TEXT NOT NULL DEFAULT '',      -- ids separados por coma
    apagar                TEXT NOT NULL DEFAULT '',
    nota                  VARCHAR(300),
    estado                VARCHAR(10) NOT NULL DEFAULT 'pendiente' CHECK (estado IN ('pendiente','aprobada','rechazada')),
    creado                TIMESTAMP NOT NULL DEFAULT now(),
    atendido_en           TIMESTAMP,
    atendido_por          VARCHAR(200),
    respuesta             VARCHAR(300)
);
CREATE INDEX IF NOT EXISTS modulos_solicitudes_negocio ON modulos_solicitudes (negocio_id, estado);

CREATE TABLE IF NOT EXISTS pedidos_demo (
    id                    SERIAL PRIMARY KEY,
    nombre                VARCHAR(80)  NOT NULL,         -- quién pide
    negocio               VARCHAR(120) NOT NULL,         -- nombre del salón, quinta o hacienda
    ciudad                VARCHAR(80),
    whatsapp              VARCHAR(30)  NOT NULL,
    email                 VARCHAR(120) NOT NULL,
    necesita              VARCHAR(1000),                 -- qué necesita (texto libre)
    modulos               TEXT NOT NULL DEFAULT '',      -- módulos que le interesan (ids)
    estado                VARCHAR(12) NOT NULL DEFAULT 'nueva' CHECK (estado IN ('nueva','contactada','demo_creada','descartada')),
    negocio_id            INT REFERENCES negocios(id) ON DELETE SET NULL,   -- la demo que se creó
    ip                    VARCHAR(45),
    nota                  VARCHAR(300),
    creado                TIMESTAMP NOT NULL DEFAULT now(),
    actualizado           TIMESTAMP NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS pedidos_demo_estado ON pedidos_demo (estado, creado);
CREATE INDEX IF NOT EXISTS pedidos_demo_ip ON pedidos_demo (ip, creado);
