-- =====================================================================
-- 099 — Administradores (pedido del dueño de Tukán).
--   El dueño puede crear hasta 4 usuarios con rol 'admin': mismo nivel que el dueño en todo el programa
--   (menú, Usuarios, Configuración, Mi plan, dinero) y NO cuentan en planes.max_usuarios.
--   El límite de 4 y las reglas (nadie toca al dueño ni a sí mismo) están en src/Usuarios.php.
-- Se puede correr muchas veces en una base nueva o en la que ya está.
-- =====================================================================
SET client_min_messages = warning;

ALTER TABLE usuarios DROP CONSTRAINT IF EXISTS usuarios_rol_check;
ALTER TABLE usuarios ADD CONSTRAINT usuarios_rol_check
    CHECK (rol IN ('dueno','admin','vendedor','coordinador','consulta'));
