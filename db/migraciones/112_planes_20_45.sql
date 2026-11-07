-- =====================================================================
-- 112 — Planes de $20 y $45 para todos los programas Tukán (decisión de Dimitry, 28 oct 2026).
--   - Básico $20 al mes (código `esencial`, se conserva el código): la base + 3 módulos que el cliente elige.
--     Cada módulo más: $5 al mes. Conserva los límites de Esencial (1 espacio, 2 usuarios).
--   - Completo $45 al mes (código `quinta`): todos los módulos, con el Asistente IA incluido (con el mismo tope de uso),
--     sin límite de espacios ni de usuarios.
--   - Asistente IA en Básico: extra de $5 al mes que NO cuenta entre los 3 que elige (Modulos::FUERA_DEL_CUPO).
--   - El plan del medio (`salon`) desaparece: sus negocios y sus pagos pasan a `quinta` (Completo).
-- Columnas nuevas de planes:
--   modulos_elegir       cuántos módulos extra van incluidos en el precio del plan (0 = ninguno)
--   precio_modulo_extra  lo que cuesta cada módulo extra después de esos (NULL = el precio del catálogo)
-- Nombres, precios y módulos se ponen UNA sola vez (marca 'planes_20_45' en ajustes): si después Dimitry los cambia desde
-- el panel, el instalador no los vuelve a pisar. Se puede correr muchas veces sin dañar nada.
-- =====================================================================
SET client_min_messages = warning;

ALTER TABLE planes ADD COLUMN IF NOT EXISTS modulos_elegir INT NOT NULL DEFAULT 0;
ALTER TABLE planes ADD COLUMN IF NOT EXISTS precio_modulo_extra NUMERIC(10,2) CHECK (precio_modulo_extra IS NULL OR precio_modulo_extra >= 0);

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM ajustes WHERE clave = 'planes_20_45') THEN
        -- 1. Quien ya paga Esencial (no las demos) conserva lo que le traía su plan: pasa a sus módulos extra
        --    (cuentan entre los 3 que elige). Con la instalación de fábrica Esencial no traía módulos.
        UPDATE negocios n
           SET modulos_extra = trim(BOTH ',' FROM concat_ws(',', NULLIF(n.modulos_extra, ''), NULLIF(p.modulos, '')))
          FROM planes p
         WHERE p.codigo = n.plan_codigo AND n.plan_codigo = 'esencial' AND n.estado <> 'prueba'
           AND trim(p.modulos) <> '' AND trim(p.modulos) NOT LIKE '*%';

        -- 2. El plan del medio desaparece: negocios y pagos pasan a Completo (quinta). Lo que tenían quitado del plan
        --    sigue quitado; lo que tenían de extra ya viene en Completo.
        IF EXISTS (SELECT 1 FROM planes WHERE codigo = 'salon') AND EXISTS (SELECT 1 FROM planes WHERE codigo = 'quinta') THEN
            UPDATE negocios SET plan_codigo = 'quinta' WHERE plan_codigo = 'salon';
            UPDATE suscripciones SET plan_codigo = 'quinta' WHERE plan_codigo = 'salon';
            DELETE FROM planes WHERE codigo = 'salon';
        END IF;

        -- 3. Básico y Completo
        UPDATE planes SET nombre = 'Básico', precio_mensual = 20.00, modulos = '', modulos_elegir = 3, precio_modulo_extra = 5.00,
                          max_espacios = 1, max_usuarios = 2,
                          descripcion = 'La base de TuRenta + 3 módulos que tú eliges.'
         WHERE codigo = 'esencial';
        UPDATE planes SET nombre = 'Completo', precio_mensual = 45.00, modulos = '*,asistente_ia', modulos_elegir = 0, precio_modulo_extra = NULL,
                          max_espacios = NULL, max_usuarios = NULL,
                          descripcion = 'Todos los módulos, sin límite de espacios ni de usuarios, con el Asistente IA incluido.'
         WHERE codigo = 'quinta';

        -- 4. Asistente IA: $5 al mes en el catálogo (en Básico; en Completo va incluido)
        INSERT INTO modulos (id, precio_mensual, orden) VALUES ('asistente_ia', 5.00, 170)
        ON CONFLICT (id) DO UPDATE SET precio_mensual = 5.00, actualizado = now();

        INSERT INTO ajustes (clave, valor) VALUES ('planes_20_45', '2026-10-28');
    END IF;
END $$;
