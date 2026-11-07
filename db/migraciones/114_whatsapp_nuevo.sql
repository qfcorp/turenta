-- Numero nuevo de WhatsApp de Tukan (6 nov 2026): el +593 99 640 8397 fue robado y suspendido; el nuevo es el +593 98 366 2181.
-- Solo cambia donde todavia esta el numero viejo (si Tukan ya puso otro, no se toca). Se puede correr muchas veces.
UPDATE ajustes SET valor = '593983662181' WHERE clave = 'whatsapp_ventas' AND valor IN ('593996408397', '+593996408397', '0996408397');
-- WhatsApp de la transmision de cada negocio (si sigue con el de Tukan)
ALTER TABLE negocios ALTER COLUMN whatsapp_transmision SET DEFAULT '593983662181';
UPDATE negocios SET whatsapp_transmision = '593983662181' WHERE whatsapp_transmision = '593996408397';
