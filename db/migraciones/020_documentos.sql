-- 020 · Módulo documentos (catálogo, paquetes, cotizaciones y contratos)
-- Solo índices para que las listas abran rápido. Se puede correr muchas veces.
CREATE INDEX IF NOT EXISTS productos_negocio_categoria ON productos (negocio_id, categoria, orden);
CREATE INDEX IF NOT EXISTS paquetes_negocio ON paquetes (negocio_id, activo);
CREATE INDEX IF NOT EXISTS paquete_items_paquete ON paquete_items (paquete_id);
CREATE INDEX IF NOT EXISTS cotizaciones_evento ON cotizaciones (evento_id, version);
CREATE INDEX IF NOT EXISTS cotizacion_items_cotizacion ON cotizacion_items (cotizacion_id, orden);
CREATE INDEX IF NOT EXISTS contratos_evento ON contratos (evento_id, numero);
CREATE INDEX IF NOT EXISTS plantillas_contrato_negocio ON plantillas_contrato (negocio_id);
