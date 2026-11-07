-- 111 · El módulo «Asistente IA» viene INCLUIDO en el plan más caro (código quinta) — decisión del dueño de Tukán (21 oct 2026).
-- En los demás planes sigue como extra por cotización. "*,asistente_ia" = todos los módulos + el Asistente IA (Modulos::parse).
-- Se puede correr muchas veces: se aplica una sola vez (ajuste asistente_ia_111), así no vuelve si Tukán lo quita después del plan.
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM ajustes WHERE clave = 'asistente_ia_111') THEN
        UPDATE planes SET modulos = CASE WHEN trim(modulos) LIKE '*%' THEN '*,asistente_ia' WHEN trim(modulos) = '' THEN 'asistente_ia'
                                         ELSE modulos || ',asistente_ia' END,
                          descripcion = trim(COALESCE(descripcion, '') || ' Incluye el Asistente IA.')
         WHERE codigo = 'quinta' AND modulos NOT LIKE '%asistente_ia%';
        INSERT INTO ajustes (clave, valor) VALUES ('asistente_ia_111', '1');
    END IF;
END $$;
