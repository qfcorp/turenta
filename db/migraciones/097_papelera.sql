-- =====================================================================
-- 097 — Pausar y borrar clientes desde el Panel Tukán Central (PROTOCOLO_V2.md del Central, Anexo A).
--   pausa_tipo / pausa_motivo / pausa_en: por qué Tukán pausó la cuenta (estado = 'suspendido'). El cliente ve el motivo,
--                    salvo en "revision" y "abuso" (texto genérico). Reactivar los borra.
--   eliminado_en / eliminado_motivo / eliminado_por / eliminado_copia: papelera. Un negocio con eliminado_en no entra,
--                    no sale en listas ni cifras y sus páginas públicas dan 404. Se restaura dentro de 30 días; después
--                    bin/tareas.php lo borra de verdad. eliminado_copia = ruta del JSON comprimido con todas sus filas.
-- Se puede correr muchas veces (IF NOT EXISTS).
-- =====================================================================
SET client_min_messages = warning;

ALTER TABLE negocios ADD COLUMN IF NOT EXISTS pausa_tipo VARCHAR(20);
ALTER TABLE negocios ADD COLUMN IF NOT EXISTS pausa_motivo VARCHAR(300);
ALTER TABLE negocios ADD COLUMN IF NOT EXISTS pausa_en TIMESTAMP;
ALTER TABLE negocios ADD COLUMN IF NOT EXISTS eliminado_en TIMESTAMP;
ALTER TABLE negocios ADD COLUMN IF NOT EXISTS eliminado_motivo VARCHAR(300);
ALTER TABLE negocios ADD COLUMN IF NOT EXISTS eliminado_por VARCHAR(200);
ALTER TABLE negocios ADD COLUMN IF NOT EXISTS eliminado_copia TEXT;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'negocios_pausa_tipo_check') THEN
        ALTER TABLE negocios ADD CONSTRAINT negocios_pausa_tipo_check
            CHECK (pausa_tipo IS NULL OR pausa_tipo IN ('falta_pago','pedido_cliente','revision','abuso','otro'));
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS negocios_eliminado_en ON negocios (eliminado_en) WHERE eliminado_en IS NOT NULL;
