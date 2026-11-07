#!/usr/bin/env bash
# Proveedores y compras (060): ficha con RUC/banco/crédito, documentos con vencimiento, compras con ítems y estados,
# PDF de la orden con QR y verificación pública, pagos (sin pagar de más, anular), factura a mano y XML del SRI
# (con y sin orden), comparar precios, por pagar + Excel, ganancia del evento, inicio, día del evento (llegada,
# evaluación, cronograma, incidencias), portal del proveedor, tareas automáticas, roles y aislamiento entre negocios.
HOY=$(date +%Y-%m-%d)
FECHA=$(date -d '+20 days' +%Y-%m-%d)
FOTO="$TMP/pv_foto.jpg"
php -r '$i=imagecreatetruecolor(320,240);imagefill($i,0,0,imagecolorallocate($i,230,210,200));imagestring($i,5,40,100,"Permiso 2026",0);imagejpeg($i,$argv[1]);' "$FOTO"
PDF="$TMP/pv_doc.pdf"
printf '%%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%%%EOF\n' > "$PDF"
XML="$TMP/pv_factura.xml"
sed "s#<fechaEmision>10/10/2026</fechaEmision>#<fechaEmision>$(date -d '-2 days' +%d/%m/%Y)</fechaEmision>#" tests/fixtures/factura_sri.xml > "$XML"
XML2="$TMP/pv_factura_otra.xml"
sed -e 's/1791234567001/0991112223001/g' -e 's/FLORES Y EVENTOS DEL VALLE CIA. LTDA./MANTELES ANDINOS S.A./' -e 's/FLORERIA EL VALLE/MANTELES ANDINOS/' \
    -e 's/000004567/000000088/' "$XML" > "$XML2"
# POST con archivos: postf <página con el formulario> <ruta> -F campo=valor...
postf() {
  local pagina=$1 ruta=$2; shift 2
  get "$pagina" > /dev/null
  local t; t=$(csrf)
  curl -s -b "$JAR" -c "$JAR" -o "$TMP/post.html" -w "%{http_code} %{redirect_url}" -F "csrf=$t" "$@" "$BASE/?$ruta"
}
clave_nueva() { get "r=usuarios" > /dev/null; grep -o 'class="clave-temporal">[^<]*' "$TMP/pag.html" | head -1 | sed 's/.*>//'; }

echo "1. Ficha del proveedor con RUC, banco y crédito"
registrar quinta "Quinta Proveedores $RANDOM"
NID_A=$NID; EMAIL_A=$EMAIL
sql "UPDATE negocios SET ruc='1790000000001', razon_social='Arrayanes Eventos S.A.', direccion='Vía a Tumbaco km 5' WHERE id=$NID" > /dev/null
EV=$(crear_evento_sql "$FECHA" separado)
sql "UPDATE eventos SET hora_inicio='18:00', hora_fin='02:00', total=11500 WHERE id=$EV" > /dev/null
check "Proveedores abre" "200" "$(get "r=proveedores")"
contiene "Formulario con RUC y banco" "$TMP/pag.html" "RUC, cuenta del banco y crédito"
r=$(post "r=proveedores" --data-urlencode "accion=crear" --data-urlencode "nombre=DJ Fiesta Total" --data-urlencode "servicio=musica" \
  --data-urlencode "telefono=0993334444" --data-urlencode "ruc=12345")
check "RUC mal escrito: no se crea" "0" "$(sql "SELECT count(*) FROM proveedores WHERE negocio_id=$NID")"
get "r=proveedores" > /dev/null
contiene "RUC mal escrito: mensaje claro" "$TMP/pag.html" "El RUC tiene 13 dígitos"
contiene "Recuerda lo escrito" "$TMP/pag.html" 'value="DJ Fiesta Total"'
r=$(post "r=proveedores" --data-urlencode "accion=crear" --data-urlencode "nombre=DJ Fiesta Total" --data-urlencode "servicio=musica" \
  --data-urlencode "telefono=0993334444" --data-urlencode "contacto=Juan Pérez" --data-urlencode "ruc=1712345678001" --data-urlencode "banco=Banco Pichincha" \
  --data-urlencode "tipo_cuenta=ahorros" --data-urlencode "numero_cuenta=2201234567" --data-urlencode "titular_cuenta=Juan Pérez" \
  --data-urlencode "credito_dias=15" --data-urlencode "cupo_credito=1.000" --data-urlencode "anticipo_pct=50" --data-urlencode "preferido=1")
PDJ=$(sql "SELECT id FROM proveedores WHERE negocio_id=$NID AND nombre='DJ Fiesta Total'")
check "Crear lleva a la ficha" "proveedor&id=$PDJ" "$(echo "$r" | grep -o "proveedor&id=$PDJ")"
check "Datos guardados" "1712345678001|15|1000.00|50.00|true" "$(sql "SELECT ruc||'|'||credito_dias||'|'||cupo_credito||'|'||anticipo_pct||'|'||preferido FROM proveedores WHERE id=$PDJ")"
check "Ficha abre" "200" "$(get "r=proveedor&id=$PDJ")"
contiene "Ficha: datos para transferir" "$TMP/pag.html" "Para transferirle"
contiene "Ficha: número de cuenta" "$TMP/pag.html" "2201234567"
contiene "Ficha: crédito" "$TMP/pag.html" "15 días"
contiene "Ficha: portal del proveedor (Quinta)" "$TMP/pag.html" "r=portal_proveedor&amp;t="
contiene "Ficha: botón nueva compra" "$TMP/pag.html" "Nueva compra"
contiene "Ficha: estado de cuenta en PDF" "$TMP/pag.html" "tipo=estado_proveedor"
check "Bitácora abre" "200" "$(get "r=proveedor&id=$PDJ&ver=bitacora")"
contiene "Bitácora: alta y condiciones" "$TMP/pag.html" "crédito de contado a 15 días"
post "r=proveedores" --data-urlencode "accion=crear" --data-urlencode "nombre=Florería El Valle" --data-urlencode "servicio=decoracion" --data-urlencode "telefono=0998765432" > /dev/null
PFL=$(sql "SELECT id FROM proveedores WHERE negocio_id=$NID AND nombre='Florería El Valle'")
post "r=proveedores" --data-urlencode "accion=crear" --data-urlencode "nombre=Catering Delicias" --data-urlencode "servicio=menu" --data-urlencode "telefono=0981112222" \
  --data-urlencode "credito_dias=30" --data-urlencode "cupo_credito=500" > /dev/null
PCA=$(sql "SELECT id FROM proveedores WHERE negocio_id=$NID AND nombre='Catering Delicias'")
r=$(post "r=proveedores&id=$PFL" --data-urlencode "accion=editar" --data-urlencode "proveedor_id=$PFL" --data-urlencode "nombre=Florería El Valle" \
  --data-urlencode "servicio=decoracion" --data-urlencode "telefono=0998765432" --data-urlencode "activo=1" --data-urlencode "ruc=1712345678001")
get "r=proveedores&id=$PFL" > /dev/null
contiene "RUC repetido en otro proveedor: mensaje" "$TMP/pag.html" "ya es de otro proveedor"
check "Ranking abre" "200" "$(get "r=proveedores&ver=ranking")"
contiene "Ranking: explica el puntaje" "$TMP/pag.html" "puntualidad"

echo "2. Documentos con vencimiento"
r=$(postf "r=proveedor&id=$PCA&ver=documentos" "r=proveedor&id=$PCA" -F "accion=documento" -F "tipo=permiso_sanitario" -F "vence=$(date -d '-3 days' +%Y-%m-%d)" -F "archivo=@$PDF;type=application/pdf")
check "Documento PDF guardado" "1" "$(sql "SELECT count(*) FROM proveedor_documentos WHERE proveedor_id=$PCA AND archivo LIKE '%.pdf'")"
postf "r=proveedor&id=$PCA&ver=documentos" "r=proveedor&id=$PCA" -F "accion=documento" -F "tipo=poliza" -F "nombre_doc=Seguros Equinoccial" \
  -F "vence=$(date -d '+20 days' +%Y-%m-%d)" -F "archivo=@$FOTO;type=image/jpeg" > /dev/null
check "Documento foto guardado como JPG" "1" "$(sql "SELECT count(*) FROM proveedor_documentos WHERE proveedor_id=$PCA AND archivo LIKE '%.jpg'")"
postf "r=proveedor&id=$PCA&ver=documentos" "r=proveedor&id=$PCA" -F "accion=documento" -F "tipo=otro" -F "archivo=@$XML;type=text/xml" > /dev/null
get "r=proveedor&id=$PCA&ver=documentos" > /dev/null
contiene "XML no sirve como documento: mensaje" "$TMP/pag.html" "El archivo debe ser"
contiene "Documento vencido marcado" "$TMP/pag.html" "Vencido"
contiene "Documento por vencer marcado" "$TMP/pag.html" "Vence pronto"
contiene "Aviso de documentos en la ficha" "$TMP/pag.html" "vencido"
DOC=$(sql "SELECT id FROM proveedor_documentos WHERE proveedor_id=$PCA AND tipo='permiso_sanitario'")
check "Ver el PDF del documento" "%PDF" "$(curl -s -b "$JAR" "$BASE/?r=proveedor_archivo&tipo=documento&id=$DOC" | head -c 4)"
check "Documento inexistente: 404" "404" "$(get "r=proveedor_archivo&tipo=documento&id=999999")"
get "r=proveedores" > /dev/null
contiene "Lista: alerta de documento vencido" "$TMP/pag.html" "doc. vencido"
contiene "Lista: alerta por vencer" "$TMP/pag.html" "doc. por vencer"

echo "3. Compras: crear con ítems, estados, PDF con QR y verificación"
check "Compras abre" "200" "$(get "r=compras&nueva=1&proveedor=$PDJ&evento=$EV")"
contiene "Formulario de nueva compra abierto" "$TMP/pag.html" 'id="nueva" open'
contiene "El proveedor viene elegido" "$TMP/pag.html" "value=\"$PDJ\" selected"
contiene "Puntaje/crédito junto al nombre" "$TMP/pag.html" "15 días"
r=$(post "r=compras" --data-urlencode "accion=crear" --data-urlencode "proveedor_id=$PDJ" --data-urlencode "evento_id=$EV" --data-urlencode "estado=pedida" \
  --data-urlencode "items[0][descripcion]=DJ 6 horas con sonido" --data-urlencode "items[0][cantidad]=1" --data-urlencode "items[0][precio_unitario]=600" --data-urlencode "items[0][con_iva]=1" \
  --data-urlencode "items[1][descripcion]=Luces robóticas" --data-urlencode "items[1][cantidad]=4" --data-urlencode "items[1][precio_unitario]=25,50" --data-urlencode "items[1][con_iva]=1" \
  --data-urlencode "items[2][descripcion]=Propina técnica" --data-urlencode "items[2][cantidad]=1" --data-urlencode "items[2][precio_unitario]=20" --data-urlencode "items[2][con_iva]=0" \
  --data-urlencode "items[3][descripcion]=" --data-urlencode "items[3][cantidad]=" --data-urlencode "items[3][precio_unitario]=")
C1=$(sql "SELECT id FROM compras WHERE negocio_id=$NID ORDER BY id LIMIT 1")
check "Crear compra lleva a la compra" "compra&id=$C1" "$(echo "$r" | grep -o "compra&id=$C1")"
check "Totales: subtotal, IVA y total" "722.00|105.30|827.30" "$(sql "SELECT subtotal||'|'||iva||'|'||total FROM compras WHERE id=$C1")"
check "Anticipo 50 % y vence en 15 días" "413.65|$(date -d '+15 days' +%Y-%m-%d)" "$(sql "SELECT anticipo||'|'||vence FROM compras WHERE id=$C1")"
check "Compra abre" "200" "$(get "r=compra&id=$C1")"
contiene "Código OC-0001" "$TMP/pag.html" "OC-0001"
contiene "WhatsApp con la orden" "$TMP/pag.html" "wa.me/593993334444"
contiene "Mensaje con el portal del proveedor" "$TMP/pag.html" "portal_proveedor"
contiene "Botón: el proveedor confirmó" "$TMP/pag.html" "El proveedor confirmó"
check "El proveedor quedó en el día del evento" "$C1" "$(sql "SELECT compra_id FROM evento_proveedores WHERE evento_id=$EV AND proveedor_id=$PDJ")"
r=$(post "r=compras" --data-urlencode "accion=crear" --data-urlencode "proveedor_id=$PDJ" --data-urlencode "items[0][descripcion]=" --data-urlencode "items[0][precio_unitario]=")
get "r=compras" > /dev/null
contiene "Compra sin ítems: mensaje" "$TMP/pag.html" "al menos una línea"
check "PDF de la orden: application/pdf" "application/pdf" "$(curl -s -b "$JAR" -o "$TMP/oc.pdf" -w '%{content_type}' "$BASE/?r=pdf&tipo=orden_compra&id=$C1")"
check "PDF de la orden: es un PDF" "%PDF" "$(head -c 4 "$TMP/oc.pdf")"
COD=$(sql "SELECT codigo_verificacion FROM compras WHERE id=$C1")
JAR_A=$JAR; JAR="$TMP/jar_verif"; rm -f "$JAR"
check "Verificar la orden sin sesión" "200" "$(get "r=verificar_oc&c=$COD")"
contiene "Orden auténtica" "$TMP/pag.html" "es auténtica"
contiene "Muestra el total" "$TMP/pag.html" "827,30"
check "Código falso: 404" "404" "$(get "r=verificar_oc&c=ABCDEF0123456789")"
JAR=$JAR_A
post "r=compra&id=$C1" --data-urlencode "accion=estado" --data-urlencode "nuevo=entregada" > /dev/null
get "r=compra&id=$C1" > /dev/null
contiene "No salta a entregada" "$TMP/pag.html" "no puede pasar a entregada"
post "r=compra&id=$C1" --data-urlencode "accion=estado" --data-urlencode "nuevo=confirmada" > /dev/null
check "Confirmada" "confirmada" "$(sql "SELECT estado FROM compras WHERE id=$C1")"
check "Confirmado en el día del evento" "t" "$(sql "SELECT confirmado FROM evento_proveedores WHERE compra_id=$C1")"
post "r=compra&id=$C1" --data-urlencode "accion=estado" --data-urlencode "nuevo=cancelada" --data-urlencode "motivo=" > /dev/null
check "Cancelar sin motivo: no cancela" "confirmada" "$(sql "SELECT estado FROM compras WHERE id=$C1")"
post "r=compra&id=$C1" --data-urlencode "accion=items" --data-urlencode "items[0][descripcion]=DJ 6 horas con sonido" --data-urlencode "items[0][cantidad]=1" \
  --data-urlencode "items[0][precio_unitario]=600" --data-urlencode "items[0][con_iva]=1" --data-urlencode "items[1][descripcion]=Luces robóticas" --data-urlencode "items[1][cantidad]=4" \
  --data-urlencode "items[1][precio_unitario]=25,50" --data-urlencode "items[1][con_iva]=1" --data-urlencode "items[2][descripcion]=Propina técnica" --data-urlencode "items[2][cantidad]=1" \
  --data-urlencode "items[2][precio_unitario]=20" --data-urlencode "items[2][con_iva]=0" --data-urlencode "items[3][descripcion]=Máquina de humo" --data-urlencode "items[3][cantidad]=1" \
  --data-urlencode "items[3][precio_unitario]=40" --data-urlencode "items[3][con_iva]=1" > /dev/null
check "Cambiar el detalle recalcula" "873.30" "$(sql "SELECT total FROM compras WHERE id=$C1")"
post "r=compra&id=$C1" --data-urlencode "accion=editar" --data-urlencode "evento_id=$EV" --data-urlencode "categoria=musica" --data-urlencode "fecha=$HOY" \
  --data-urlencode "retencion=17,46" --data-urlencode "anticipo=413,65" --data-urlencode "vence=" --data-urlencode "notas=Llevar extensiones" > /dev/null
check "Retención guardada" "17.46" "$(sql "SELECT retencion FROM compras WHERE id=$C1")"

echo "4. Pagos al proveedor"
r=$(postf "r=compra&id=$C1" "r=compra&id=$C1" -F "accion=pago" -F "monto=413,65" -F "metodo=transferencia" -F "referencia=TRF-889" -F "fecha=$HOY" -F "comprobante=@$FOTO;type=image/jpeg")
check "Pago registrado" "413.65" "$(sql "SELECT sum(monto) FROM pagos_proveedor WHERE compra_id=$C1 AND estado='valido'")"
PG1=$(sql "SELECT id FROM pagos_proveedor WHERE compra_id=$C1 ORDER BY id LIMIT 1")
check "Comprobante del pago (foto)" "image/jpeg" "$(curl -s -b "$JAR" -o /dev/null -w '%{content_type}' "$BASE/?r=proveedor_archivo&tipo=comprobante&id=$PG1")"
get "r=compra&id=$C1" > /dev/null
contiene "Saldo después del anticipo" "$TMP/pag.html" "442,19"
contiene "Estado de pago: abonada" "$TMP/pag.html" "Abonada"
post "r=compra&id=$C1" --data-urlencode "accion=pago" --data-urlencode "monto=5000" --data-urlencode "metodo=efectivo" > /dev/null
get "r=compra&id=$C1" > /dev/null
contiene "No se paga de más: mensaje" "$TMP/pag.html" "No puedes pagar más que el saldo"
post "r=compra&id=$C1" --data-urlencode "accion=pago" --data-urlencode "monto=200" --data-urlencode "metodo=cheque" --data-urlencode "referencia=Cheque 000145" \
  --data-urlencode "cheque_fecha=$(date -d '+10 days' +%Y-%m-%d)" --data-urlencode "fecha=$HOY" > /dev/null
PG2=$(sql "SELECT id FROM pagos_proveedor WHERE compra_id=$C1 AND metodo='cheque'")
check "Cheque posfechado guardado" "$(date -d '+10 days' +%Y-%m-%d)" "$(sql "SELECT cheque_fecha FROM pagos_proveedor WHERE id=$PG2")"
post "r=compra&id=$C1" --data-urlencode "accion=anular_pago" --data-urlencode "pago_id=$PG2" --data-urlencode "motivo=" > /dev/null
check "Anular sin motivo: no se anula" "valido" "$(sql "SELECT estado FROM pagos_proveedor WHERE id=$PG2")"
post "r=compra&id=$C1" --data-urlencode "accion=anular_pago" --data-urlencode "pago_id=$PG2" --data-urlencode "motivo=Fecha equivocada" > /dev/null
check "Anulado (no se borra)" "anulado|Fecha equivocada" "$(sql "SELECT estado||'|'||anulado_motivo FROM pagos_proveedor WHERE id=$PG2")"
get "r=compra&id=$C1" > /dev/null
contiene "El pago anulado queda en la lista" "$TMP/pag.html" "ANULADO"

echo "5. Factura del proveedor: a mano y con el XML del SRI"
postf "r=compra&id=$C1" "r=compra&id=$C1" -F "accion=factura" -F "factura_numero=1-1-55" -F "factura_fecha=$HOY" -F "factura_total=873,30" -F "archivo=@$PDF;type=application/pdf" > /dev/null
check "Factura a mano" "001-001-000000055|873.30" "$(sql "SELECT factura_numero||'|'||factura_total FROM compras WHERE id=$C1")"
check "Ver el PDF de la factura" "%PDF" "$(curl -s -b "$JAR" "$BASE/?r=proveedor_archivo&tipo=factura&id=$C1" | head -c 4)"
post "r=compras" --data-urlencode "accion=crear" --data-urlencode "proveedor_id=$PFL" --data-urlencode "evento_id=$EV" --data-urlencode "estado=confirmada" \
  --data-urlencode "items[0][descripcion]=Arreglos florales" --data-urlencode "items[0][cantidad]=10" --data-urlencode "items[0][precio_unitario]=25" --data-urlencode "items[0][con_iva]=1" > /dev/null
C2=$(sql "SELECT id FROM compras WHERE negocio_id=$NID AND proveedor_id=$PFL ORDER BY id DESC LIMIT 1")
r=$(postf "r=compra&id=$C2" "r=compra&id=$C2" -F "accion=xml" -F "xml=@$XML;type=text/xml")
check "XML: número, total y clave" "001-002-000004567|445.00|49" "$(sql "SELECT factura_numero||'|'||factura_total||'|'||length(factura_clave) FROM compras WHERE id=$C2")"
check "XML: el proveedor toma el RUC" "1791234567001" "$(sql "SELECT ruc FROM proveedores WHERE id=$PFL")"
get "r=compra&id=$C2" > /dev/null
contiene "XML: aviso de que el total no cuadra" "$TMP/pag.html" "no es igual al de la orden"
contiene "XML: descargar" "$TMP/pag.html" "tipo=xml"
check "Descargar XML: se baja (no se abre)" "attachment" "$(curl -s -b "$JAR" -D - -o /dev/null "$BASE/?r=proveedor_archivo&tipo=xml&id=$C2" | grep -i '^content-disposition' | grep -o attachment)"
postf "r=compra&id=$C1" "r=compra&id=$C1" -F "accion=xml" -F "xml=@$XML;type=text/xml" > /dev/null
get "r=compra&id=$C1" > /dev/null
contiene "XML de otro RUC: mensaje" "$TMP/pag.html" "Revisa que sea la factura correcta"
postf "r=compra&id=$C1" "r=compra&id=$C1" -F "accion=xml" -F "xml=@$FOTO;type=image/jpeg" > /dev/null
get "r=compra&id=$C1" > /dev/null
contiene "Foto en vez de XML: mensaje" "$TMP/pag.html" "debe ser el XML"
# Factura sin orden y de un RUC que no es de ningún proveedor
r=$(postf "r=compras" "r=compras" -F "accion=xml_leer" -F "xml=@$XML2;type=text/xml")
check "Leer XML sin orden" "302" "${r%% *}"
get "r=compras&xml=1" > /dev/null
contiene "Muestra la factura leída" "$TMP/pag.html" "001-002-000000088"
contiene "Ofrece crear el proveedor" "$TMP/pag.html" "no es de ningún proveedor"
r=$(post "r=compras&xml=1" --data-urlencode "accion=xml_guardar" --data-urlencode "servicio=mobiliario" --data-urlencode "compra_id=0" --data-urlencode "evento_id=$EV" --data-urlencode "telefono=0961234567")
PMA=$(sql "SELECT id FROM proveedores WHERE negocio_id=$NID AND ruc='0991112223001'")
check "Proveedor creado desde la factura" "Manteles Andinos|0961234567" "$(sql "SELECT nombre||'|'||telefono FROM proveedores WHERE id=$PMA")"
check "Compra creada desde la factura (entregada, 3 ítems)" "entregada|3|445.00" "$(sql "SELECT c.estado||'|'||(SELECT count(*) FROM compra_items i WHERE i.compra_id=c.id)||'|'||c.factura_total FROM compras c WHERE c.proveedor_id=$PMA")"
check "Unida al evento" "1" "$(sql "SELECT count(*) FROM evento_proveedores WHERE evento_id=$EV AND proveedor_id=$PMA")"
r=$(postf "r=compras" "r=compras" -F "accion=xml_leer" -F "xml=@$PDF;type=application/pdf")
get "r=compras" > /dev/null
contiene "PDF en vez de XML: mensaje" "$TMP/pag.html" "El archivo debe ser el XML"

echo "6. Comparar precios"
post "r=proveedores" --data-urlencode "accion=crear" --data-urlencode "nombre=Foto Uno" --data-urlencode "servicio=foto_video" > /dev/null
post "r=proveedores" --data-urlencode "accion=crear" --data-urlencode "nombre=Foto Dos" --data-urlencode "servicio=foto_video" > /dev/null
PF1=$(sql "SELECT id FROM proveedores WHERE negocio_id=$NID AND nombre='Foto Uno'"); PF2=$(sql "SELECT id FROM proveedores WHERE negocio_id=$NID AND nombre='Foto Dos'")
for par in "$PF1:450" "$PF2:380"; do
  post "r=compras" --data-urlencode "accion=crear" --data-urlencode "proveedor_id=${par%%:*}" --data-urlencode "evento_id=$EV" --data-urlencode "estado=cotizacion" \
    --data-urlencode "items[0][descripcion]=Fotografía 4 horas" --data-urlencode "items[0][cantidad]=1" --data-urlencode "items[0][precio_unitario]=${par##*:}" > /dev/null
done
check "Pedido de precio no va al día del evento" "0" "$(sql "SELECT count(*) FROM evento_proveedores WHERE evento_id=$EV AND proveedor_id IN ($PF1,$PF2)")"
get "r=compras" > /dev/null
contiene "Aviso para comparar precios" "$TMP/pag.html" "precios de foto y video"
check "Comparar abre" "200" "$(get "r=compras&comparar=1&evento=$EV&categoria=foto_video")"
contiene "Marca el más barato" "$TMP/pag.html" "El más barato"
Q2=$(sql "SELECT id FROM compras WHERE proveedor_id=$PF2")
r=$(post "r=compras&comparar=1&evento=$EV&categoria=foto_video" --data-urlencode "accion=elegir" --data-urlencode "compra_id=$Q2")
check "Elegido: pasa a pedida" "pedida" "$(sql "SELECT estado FROM compras WHERE id=$Q2")"
check "El otro se cancela" "cancelada" "$(sql "SELECT estado FROM compras WHERE proveedor_id=$PF1")"
check "El elegido va al día del evento" "1" "$(sql "SELECT count(*) FROM evento_proveedores WHERE evento_id=$EV AND proveedor_id=$PF2")"

echo "7. Por pagar, Excel, inicio y ganancia del evento"
post "r=compras" --data-urlencode "accion=crear" --data-urlencode "proveedor_id=$PCA" --data-urlencode "estado=pedida" --data-urlencode "fecha=$(date -d '-50 days' +%Y-%m-%d)" \
  --data-urlencode "vence=$(date -d '-40 days' +%Y-%m-%d)" --data-urlencode "items[0][descripcion]=Bocaditos feria" --data-urlencode "items[0][cantidad]=1" \
  --data-urlencode "items[0][precio_unitario]=600" --data-urlencode "items[0][con_iva]=0" > /dev/null
check "Por pagar abre" "200" "$(get "r=por_pagar")"
contiene "Total que debes" "$TMP/pag.html" "Total que debes"
contiene "Antigüedad de saldos" "$TMP/pag.html" "Vencido 31 a 60 días"
contiene "Catering pasó su cupo" "$TMP/pag.html" "pasaste su cupo de crédito de \$500,00"
contiene "Proveedor con saldo vencido" "$TMP/pag.html" "venció hace 40 días"
check "Por pagar de un evento" "200" "$(get "r=por_pagar&evento=$EV")"
no_contiene "Filtro por evento: sin la compra general" "$TMP/pag.html" "Bocaditos"
check "Excel: tipo CSV" "text/csv; charset=utf-8" "$(curl -s -b "$JAR" -o "$TMP/pp.csv" -w '%{content_type}' "$BASE/?r=por_pagar&csv=1")"
contiene "Excel: encabezados" "$TMP/pp.csv" "Orden;Proveedor;RUC"
contiene "Excel: compra vencida" "$TMP/pp.csv" "Vencida"
get "r=inicio" > /dev/null
contiene "Inicio: pagos a proveedores" "$TMP/pag.html" "Pagos a proveedores: 3 vencidos, 1 esta semana"
check "Pagos del evento abre" "200" "$(get "r=evento_pagos&id=$EV")"
contiene "Pagos del evento: compras a proveedores" "$TMP/pag.html" "Compras a proveedores"
contiene "Pagos del evento: OC en la lista" "$TMP/pag.html" "OC-0001"
contiene "Ganancia con las compras" "$TMP/pag.html" "<dt>Compras</dt>"
check "Reportes con compras" "200" "$(get "r=reportes")"
contiene "Reporte: la ganancia cuenta las compras" "$TMP/pag.html" "compras a proveedores del evento"

echo "8. Día del evento: llegada, evaluación, cronograma e incidencias"
EP=$(sql "SELECT id FROM evento_proveedores WHERE evento_id=$EV AND proveedor_id=$PDJ")
sql "UPDATE evento_proveedores SET hora_llegada='15:00' WHERE id=$EP" > /dev/null
EVH=$(crear_evento_sql "$HOY" confirmado)
sql "UPDATE eventos SET hora_inicio='18:00', hora_fin='23:00' WHERE id=$EVH" > /dev/null
post_desde "r=evento_dia&id=$EVH&ver=proveedores" "r=evento_dia&id=$EVH" --data-urlencode "accion=prov_agregar" --data-urlencode "origen_prov=lista" \
  --data-urlencode "proveedor_id=$PDJ" --data-urlencode "hora_llegada=15:00" --data-urlencode "ver=proveedores" > /dev/null
EPH=$(sql "SELECT id FROM evento_proveedores WHERE evento_id=$EVH AND proveedor_id=$PDJ")
get "r=evento_dia&id=$EVH&ver=proveedores" > /dev/null
contiene "Día del evento: botón Llegó ahora" "$TMP/pag.html" "Llegó ahora"
contiene "Día del evento: evaluar" "$TMP/pag.html" "Evaluar"
contiene "Día del evento: reportar un problema" "$TMP/pag.html" "Reportar un problema"
post_desde "r=evento_dia&id=$EVH&ver=proveedores" "r=evento_dia&id=$EVH" --data-urlencode "accion=prov_llegada" --data-urlencode "prov_id=$EPH" \
  --data-urlencode "hora_real=15:25" --data-urlencode "ver=proveedores" > /dev/null
check "Llegada anotada por el coordinador" "15:25:00|coordinador" "$(sql "SELECT to_char(llegada_real,'HH24:MI:SS')||'|'||llegada_origen FROM evento_proveedores WHERE id=$EPH")"
get "r=evento_dia&id=$EVH&ver=proveedores" > /dev/null
contiene "Puntualidad: tarde 25 min" "$TMP/pag.html" "tarde 25 min"
post_desde "r=evento_dia&id=$EVH&ver=proveedores" "r=evento_dia&id=$EVH" --data-urlencode "accion=prov_llegada" --data-urlencode "prov_id=$EPH" --data-urlencode "ver=proveedores" > /dev/null
get "r=evento_dia&id=$EVH&ver=proveedores" > /dev/null
contiene "Sin hora ni 'ahora': mensaje" "$TMP/pag.html" "Escribe la hora en que llegó"
post_desde "r=evento_dia&id=$EVH&ver=proveedores" "r=evento_dia&id=$EVH" --data-urlencode "accion=prov_evaluar" --data-urlencode "prov_id=$EPH" \
  --data-urlencode "cumplimiento=observaciones" --data-urlencode "calificacion=4" --data-urlencode "comentario=Buena música" --data-urlencode "ver=proveedores" > /dev/null
check "Evaluación guardada" "observaciones|4" "$(sql "SELECT cumplimiento||'|'||calificacion FROM evento_proveedores WHERE id=$EPH")"
post_desde "r=evento_dia&id=$EVH" "r=evento_dia&id=$EVH" --data-urlencode "accion=bloque_agregar" --data-urlencode "hora=20:00" --data-urlencode "duracion_min=10" \
  --data-urlencode "actividad=Vals" --data-urlencode "evento_proveedor_id=$EPH" --data-urlencode "ver=cronograma" > /dev/null
check "Bloque del cronograma a cargo del DJ" "$EPH|DJ Fiesta Total" "$(sql "SELECT evento_proveedor_id||'|'||responsable FROM cronograma WHERE evento_id=$EVH AND actividad='Vals'")"
get "r=evento_dia&id=$EVH" > /dev/null
contiene "Cronograma: elegir proveedor a cargo" "$TMP/pag.html" "Proveedor a cargo"
check "Incidencias del evento abre" "200" "$(get "r=evento_dia&id=$EVH&ver=incidencias")"
contiene "Formulario para reportar" "$TMP/pag.html" "Reportar un problema"
r=$(postf "r=evento_dia&id=$EVH&ver=incidencias" "r=incidencias" -F "accion=crear" -F "evento_id=$EVH" -F "proveedor_id=$PDJ" -F "gravedad=grave" \
  -F "descripcion=El DJ llegó sin el micrófono inalámbrico" -F "volver=evento_dia" -F "volver_id=$EVH" -F "foto=@$FOTO;type=image/jpeg")
check "Vuelve al día del evento" "ver=incidencias" "$(echo "$r" | grep -o 'ver=incidencias')"
INC=$(sql "SELECT id FROM incidencias WHERE negocio_id=$NID ORDER BY id DESC LIMIT 1")
check "Incidencia guardada con foto" "grave|proveedor|true" "$(sql "SELECT gravedad||'|'||area||'|'||(foto IS NOT NULL) FROM incidencias WHERE id=$INC")"
check "Grave: aviso al dueño" "1" "$(sql "SELECT count(*) FROM notificaciones WHERE negocio_id=$NID AND texto LIKE '🚨 Incidencia grave%'")"
check "Foto de la incidencia" "image/jpeg" "$(curl -s -b "$JAR" -o /dev/null -w '%{content_type}' "$BASE/?r=proveedor_archivo&tipo=incidencia&id=$INC")"
get "r=evento_dia&id=$EVH&ver=incidencias" > /dev/null
contiene "La incidencia aparece en el día del evento" "$TMP/pag.html" "sin el micrófono"
r=$(post "r=incidencias" --data-urlencode "accion=actualizar" --data-urlencode "incidencia_id=$INC" --data-urlencode "estado=resuelta" --data-urlencode "resolucion=" --data-urlencode "gravedad=grave")
get "r=incidencias" > /dev/null
contiene "Resolver sin decir cómo: mensaje" "$TMP/pag.html" "cómo se resolvió"
post "r=incidencias" --data-urlencode "accion=actualizar" --data-urlencode "incidencia_id=$INC" --data-urlencode "estado=resuelta" --data-urlencode "gravedad=grave" \
  --data-urlencode "resolucion=Se usó el micrófono del local" --data-urlencode "descuento=50" --data-urlencode "compra_id=$C1" > /dev/null
check "Resuelta con descuento a la compra" "resuelta|50.00|$C1" "$(sql "SELECT estado||'|'||descuento||'|'||compra_id FROM incidencias WHERE id=$INC")"
check "Incidencias (lista) abre" "200" "$(get "r=incidencias&estado=todas")"
contiene "Lista con la resolución" "$TMP/pag.html" "micrófono del local"
check "Hoja del evento con incidencias: PDF" "%PDF" "$(curl -s -b "$JAR" "$BASE/?r=pdf&tipo=hoja&id=$EVH" | head -c 4)"
check "Ficha: pestaña de eventos" "200" "$(get "r=proveedor&id=$PDJ&ver=eventos")"
contiene "Ficha: puntualidad en el evento" "$TMP/pag.html" "Tarde 25 min"
check "Ficha: precios" "200" "$(get "r=proveedor&id=$PDJ&ver=precios")"
contiene "Historial de precios" "$TMP/pag.html" "Máquina de humo"
check "Ficha: cuenta" "200" "$(get "r=proveedor&id=$PDJ&ver=cuenta")"
contiene "Cuenta con los pagos" "$TMP/pag.html" "TRF-889"
check "Estado de cuenta del proveedor: PDF" "%PDF" "$(curl -s -b "$JAR" "$BASE/?r=pdf&tipo=estado_proveedor&id=$PDJ" | head -c 4)"
get "r=proveedores&ver=ranking" > /dev/null
contiene "Ranking con puntaje" "$TMP/pag.html" "pv-puntaje pv-nivel"

echo "9. Portal del proveedor"
TOK=$(sql "SELECT token FROM proveedores WHERE id=$PDJ")
C3=$(sql "INSERT INTO compras (negocio_id, numero, proveedor_id, evento_id, estado, subtotal, iva, total, vence) VALUES ($NID, 90, $PDJ, $EV, 'pedida', 100, 15, 115, CURRENT_DATE + 15) RETURNING id" | head -1)
sql "INSERT INTO compra_items (compra_id, descripcion, cantidad, precio_unitario, subtotal) VALUES ($C3, 'Sonido para la ceremonia', 1, 100, 100)" > /dev/null
JAR_A=$JAR; JAR="$TMP/jar_prov"; rm -f "$JAR"
check "Portal abre sin sesión" "200" "$(get "r=portal_proveedor&t=$TOK")"
contiene "Saluda al proveedor" "$TMP/pag.html" "Hola, Juan"
contiene "Ve su evento de hoy" "$TMP/pag.html" "¡HOY!"
contiene "Ve sus bloques del cronograma" "$TMP/pag.html" "Lo que te toca en el cronograma"
contiene "Ve la orden por confirmar" "$TMP/pag.html" "OC-0090"
contiene "Ve lo que le deben" "$TMP/pag.html" "Te debemos"
contiene "Mapa del lugar" "$TMP/pag.html" "google.com/maps"
check "PDF de su orden" "%PDF" "$(curl -s -b "$JAR" "$BASE/?r=portal_proveedor&t=$TOK&oc=$C3" | head -c 4)"
C_FL=$C2
check "No ve la orden de otro proveedor" "404" "$(get "r=portal_proveedor&t=$TOK&oc=$C_FL")"
post "r=portal_proveedor&t=$TOK" --data-urlencode "accion=confirmar_oc" --data-urlencode "compra_id=$C3" --data-urlencode "comentario=Listo, confirmado" > /dev/null
check "Confirmó la orden desde el portal" "confirmada|proveedor" "$(sql "SELECT estado||'|'||confirmada_por FROM compras WHERE id=$C3")"
check "Bitácora: origen proveedor" "proveedor" "$(sql "SELECT origen FROM proveedor_movimientos WHERE compra_id=$C3 ORDER BY id DESC LIMIT 1")"
post "r=portal_proveedor&t=$TOK" --data-urlencode "accion=rechazar_oc" --data-urlencode "compra_id=$C_FL" --data-urlencode "comentario=no" > /dev/null
check "No toca órdenes de otro proveedor" "confirmada" "$(sql "SELECT estado FROM compras WHERE id=$C_FL")"
r=$(postf "r=portal_proveedor&t=$TOK" "r=portal_proveedor&t=$TOK" -F "accion=factura" -F "compra_id=$C3" -F "numero=001-001-901" -F "factura=@$PDF;type=application/pdf")
check "Subió su factura (PDF) desde el portal" "001-001-000000901|proveedor" "$(sql "SELECT factura_numero||'|'||factura_origen FROM compras WHERE id=$C3")"
check "Aviso al negocio de la factura" "1" "$(sql "SELECT count(*) FROM notificaciones WHERE negocio_id=$NID_A AND texto LIKE '🧾 DJ Fiesta Total subió su factura%'" | awk '{print ($1>0)?1:0}')"
post_desde "r=portal_proveedor&t=$TOK" "r=evento_dia&id=$EVH" --data-urlencode "accion=prov_sin_llegada" --data-urlencode "prov_id=$EPH" > /dev/null
check "Sin sesión no se cambia el día del evento" "15:25:00" "$(sql "SELECT to_char(llegada_real,'HH24:MI:SS') FROM evento_proveedores WHERE id=$EPH")"
JAR=$JAR_A
post_desde "r=evento_dia&id=$EVH&ver=proveedores" "r=evento_dia&id=$EVH" --data-urlencode "accion=prov_sin_llegada" --data-urlencode "prov_id=$EPH" --data-urlencode "ver=proveedores" > /dev/null
check "El coordinador/dueño borra la llegada" "" "$(sql "SELECT llegada_real FROM evento_proveedores WHERE id=$EPH")"
JAR="$TMP/jar_prov"
get "r=portal_proveedor&t=$TOK" > /dev/null
contiene "Botón Ya llegué el día del evento" "$TMP/pag.html" "Ya llegué"
post "r=portal_proveedor&t=$TOK" --data-urlencode "accion=llegue" --data-urlencode "ep_id=$EPH" > /dev/null
check "Ya llegué: queda marcada por el proveedor" "proveedor" "$(sql "SELECT llegada_origen FROM evento_proveedores WHERE id=$EPH")"
get "r=portal_proveedor&t=$TOK" > /dev/null
contiene "Muestra la llegada" "$TMP/pag.html" "Llegaste a las"
post "r=portal_proveedor&t=$TOK" --data-urlencode "accion=llegue" --data-urlencode "ep_id=$EP" > /dev/null
check "Ya llegué no se marca antes del día" "" "$(sql "SELECT llegada_real FROM evento_proveedores WHERE id=$EP")"
post "r=portal_proveedor&t=$TOK" --data-urlencode "accion=confirmar_evento" --data-urlencode "ep_id=$EP" > /dev/null
check "Confirmó que va al evento" "t" "$(sql "SELECT confirmado FROM evento_proveedores WHERE id=$EP")"
check "Enlace falso: 404" "404" "$(get "r=portal_proveedor&t=0000000000000000000000000000000000000000000000ff")"
sql "UPDATE proveedores SET activo=false WHERE id=$PF1" > /dev/null
check "Proveedor desactivado: 404" "404" "$(get "r=portal_proveedor&t=$(sql "SELECT token FROM proveedores WHERE id=$PF1")")"
JAR=$JAR_A

echo "10. Tareas automáticas"
SALIDA=$(TURENTA_DB_NAME=$DB TURENTA_DB_USER=turenta TURENTA_DB_PASS=$PGPASSWORD php bin/tareas.php "$HOY 09:00" 2>&1)
contiene_txt() { if echo "$2" | grep -q -- "$3"; then OK=$((OK+1)); echo "  OK    $1"; else FALLAS=$((FALLAS+1)); echo "  FALLA $1 — no aparece [$3] en: $2"; fi; }
contiene_txt "La tarea resume los pagos a proveedores" "$SALIDA" "pagos a proveedores"
check "Aviso de pagos a proveedores en la campana" "1" "$(sql "SELECT count(*) > 0 FROM notificaciones WHERE negocio_id=$NID_A AND texto LIKE '🧾 Pagos a proveedores:%'" | sed 's/t/1/;s/f/0/')"
check "Aviso de documentos por vencer" "1" "$(sql "SELECT count(*) > 0 FROM notificaciones WHERE negocio_id=$NID_A AND texto LIKE '📄 Documentos de proveedores:%'" | sed 's/t/1/;s/f/0/')"

echo "11. Roles: vendedor, coordinador y consulta"
VEND="pvvend$RANDOM@prueba.ec"; COORD="pvcoord$RANDOM@prueba.ec"; CONS="pvcons$RANDOM@prueba.ec"
post_desde "r=usuarios" "r=usuarios&accion=crear" --data-urlencode "nombre=Carla Vendedora" --data-urlencode "email=$VEND" --data-urlencode "rol=vendedor" > /dev/null
CL_V=$(clave_nueva)
post_desde "r=usuarios" "r=usuarios&accion=crear" --data-urlencode "nombre=Pedro Coordinador" --data-urlencode "email=$COORD" --data-urlencode "rol=coordinador" > /dev/null
CL_C=$(clave_nueva)
post_desde "r=usuarios" "r=usuarios&accion=crear" --data-urlencode "nombre=Contadora" --data-urlencode "email=$CONS" --data-urlencode "rol=consulta" > /dev/null
CL_K=$(clave_nueva)
entrar "$VEND" "$CL_V"
check "Vendedor: compras" "200" "$(get "r=compras")"
contiene "Vendedor: puede crear compras" "$TMP/pag.html" "Nueva compra o pedido de precio"
post "r=compras" --data-urlencode "accion=crear" --data-urlencode "proveedor_id=$PCA" --data-urlencode "estado=pedida" \
  --data-urlencode "items[0][descripcion]=Postres" --data-urlencode "items[0][cantidad]=100" --data-urlencode "items[0][precio_unitario]=2" > /dev/null
check "Vendedor creó una compra" "1" "$(sql "SELECT count(*) FROM compras WHERE negocio_id=$NID_A AND creado_por=(SELECT id FROM usuarios WHERE email='$VEND')")"
check "Vendedor: por pagar" "200" "$(get "r=por_pagar")"
entrar "$COORD" "$CL_C"
check "Coordinador: no entra a compras" "302" "$(get "r=compras")"
check "Coordinador: no entra a por pagar" "302" "$(get "r=por_pagar")"
check "Coordinador: no entra a una compra" "302" "$(get "r=compra&id=$C1")"
check "Coordinador: ficha del proveedor" "200" "$(get "r=proveedor&id=$PDJ")"
no_contiene "Coordinador: la ficha no muestra lo que se debe" "$TMP/pag.html" "Le debes"
no_contiene "Coordinador: sin datos del banco" "$TMP/pag.html" "2201234567"
no_contiene "Coordinador: sin pestaña de cuenta" "$TMP/pag.html" "ver=cuenta"
check "Coordinador: la pestaña de cuenta no se abre" "200" "$(get "r=proveedor&id=$PDJ&ver=cuenta")"
no_contiene "…y muestra el resumen sin dinero" "$TMP/pag.html" "TRF-889"
get "r=proveedores" > /dev/null
no_contiene "Coordinador: lista sin saldos" "$TMP/pag.html" "Le debes"
no_contiene "Coordinador: sin campos de crédito" "$TMP/pag.html" "Días de crédito"
check "Coordinador: no ve comprobantes de pago" "403" "$(get "r=proveedor_archivo&tipo=comprobante&id=$PG1")"
check "Coordinador: sí ve documentos" "200" "$(get "r=proveedor_archivo&tipo=documento&id=$DOC")"
check "Coordinador: no ve el PDF de la orden" "0" "$(curl -s -b "$JAR" "$BASE/?r=pdf&tipo=orden_compra&id=$C1" | head -c 4 | grep -c '%PDF')"
check "Coordinador: incidencias" "200" "$(get "r=incidencias")"
r=$(post "r=incidencias" --data-urlencode "accion=crear" --data-urlencode "evento_id=$EVH" --data-urlencode "area=instalaciones" --data-urlencode "gravedad=leve" \
  --data-urlencode "descripcion=Se fue la luz cinco minutos" --data-urlencode "costo=100" --data-urlencode "descuento=10" --data-urlencode "compra_id=$C1")
check "Coordinador reporta un problema (sin poner dinero)" "0.00|0.00|" "$(sql "SELECT costo||'|'||descuento||'|'||COALESCE(compra_id::text,'') FROM incidencias WHERE descripcion='Se fue la luz cinco minutos'")"
post_desde "r=evento_dia&id=$EVH&ver=proveedores" "r=evento_dia&id=$EVH" --data-urlencode "accion=prov_evaluar" --data-urlencode "prov_id=$EPH" \
  --data-urlencode "cumplimiento=cumplio" --data-urlencode "calificacion=5" --data-urlencode "ver=proveedores" > /dev/null
check "Coordinador califica al proveedor" "cumplio|5" "$(sql "SELECT cumplimiento||'|'||calificacion FROM evento_proveedores WHERE id=$EPH")"
get "r=evento_dia&id=$EVH&ver=proveedores" > /dev/null
no_contiene "Coordinador: el día del evento no muestra saldos de compras" "$TMP/pag.html" "saldo <strong>"
get "r=inicio" > /dev/null
no_contiene "Coordinador: el inicio no muestra pagos a proveedores" "$TMP/pag.html" "Pagos a proveedores"
get "r=mas" > /dev/null
no_contiene "Coordinador: sin Por pagar en el menú" "$TMP/pag.html" "r=por_pagar"
contiene "Coordinador: Incidencias en el menú" "$TMP/pag.html" "r=incidencias"
entrar "$CONS" "$CL_K"
check "Consulta: compras (solo mira)" "200" "$(get "r=compras")"
no_contiene "Consulta: sin formulario de compra" "$TMP/pag.html" "Nueva compra o pedido de precio"
check "Consulta: no crea compras" "403" "$(post_desde "r=mi_cuenta" "r=compras" --data-urlencode "accion=crear" --data-urlencode "proveedor_id=$PCA" | cut -d' ' -f1)"
check "Consulta: por pagar" "200" "$(get "r=por_pagar")"
check "Consulta: ve la compra" "200" "$(get "r=compra&id=$C1")"
no_contiene "Consulta: sin formulario de pago" "$TMP/pag.html" "Registrar un pago"
check "Consulta: no registra pagos" "403" "$(post_desde "r=mi_cuenta" "r=compra&id=$C1" --data-urlencode "accion=pago" --data-urlencode "monto=1" | cut -d' ' -f1)"
check "Consulta: no reporta incidencias" "403" "$(post_desde "r=mi_cuenta" "r=incidencias" --data-urlencode "accion=crear" --data-urlencode "descripcion=Algo pasó aquí" | cut -d' ' -f1)"
check "Consulta: ficha con la cuenta" "200" "$(get "r=proveedor&id=$PDJ&ver=cuenta")"
entrar "$EMAIL_A" "claveSegura1"

echo "12. Plan Salón y aislamiento entre negocios"
registrar salon "Salón Ajeno $RANDOM"
pagar_plan salon
NID_B=$NID
check "Plan Salón: compras sí" "200" "$(get "r=compras")"
check "Otro negocio: compra ajena 404" "404" "$(get "r=compra&id=$C1")"
check "Otro negocio: ficha ajena 404" "404" "$(get "r=proveedor&id=$PDJ")"
check "Otro negocio: editar proveedor ajeno 404" "404" "$(get "r=proveedores&id=$PDJ")"
check "Otro negocio: documento ajeno 404" "404" "$(get "r=proveedor_archivo&tipo=documento&id=$DOC")"
check "Otro negocio: comprobante ajeno 404" "404" "$(get "r=proveedor_archivo&tipo=comprobante&id=$PG1")"
check "Otro negocio: factura ajena 404" "404" "$(get "r=proveedor_archivo&tipo=factura&id=$C1")"
check "Otro negocio: PDF de orden ajena no sale" "0" "$(curl -s -b "$JAR" "$BASE/?r=pdf&tipo=orden_compra&id=$C1" | head -c 4 | grep -c '%PDF')"
check "Otro negocio: estado de cuenta ajeno no sale" "0" "$(curl -s -b "$JAR" "$BASE/?r=pdf&tipo=estado_proveedor&id=$PDJ" | head -c 4 | grep -c '%PDF')"
get "r=por_pagar" > /dev/null
no_contiene "Otro negocio: por pagar sin datos ajenos" "$TMP/pag.html" "DJ Fiesta Total"
get "r=incidencias&estado=todas" > /dev/null
no_contiene "Otro negocio: sin incidencias ajenas" "$TMP/pag.html" "micrófono del local"
post "r=compra&id=$C1" --data-urlencode "accion=pago" --data-urlencode "monto=1" --data-urlencode "metodo=efectivo" > /dev/null
check "Otro negocio: no paga compras ajenas" "413.65" "$(sql "SELECT sum(monto) FROM pagos_proveedor WHERE compra_id=$C1 AND estado='valido'")"
post "r=incidencias" --data-urlencode "accion=actualizar" --data-urlencode "incidencia_id=$INC" --data-urlencode "estado=abierta" --data-urlencode "gravedad=leve" > /dev/null
check "Otro negocio: no cambia incidencias ajenas" "resuelta|grave" "$(sql "SELECT estado||'|'||gravedad FROM incidencias WHERE id=$INC")"
post "r=compras" --data-urlencode "accion=crear" --data-urlencode "proveedor_id=$PDJ" --data-urlencode "items[0][descripcion]=X" --data-urlencode "items[0][precio_unitario]=1" > /dev/null
check "Otro negocio: no le compra a un proveedor ajeno" "0" "$(sql "SELECT count(*) FROM compras WHERE negocio_id=$NID_B")"
post "r=proveedores" --data-urlencode "accion=crear" --data-urlencode "nombre=DJ Salón" --data-urlencode "servicio=musica" --data-urlencode "telefono=0990001111" > /dev/null
PB=$(sql "SELECT id FROM proveedores WHERE negocio_id=$NID_B")
check "Plan Salón: portal del proveedor no disponible (módulo apagado)" "403" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/?r=portal_proveedor&t=$(sql "SELECT token FROM proveedores WHERE id=$PB")")"
check "Plan Salón: ficha sin enlace al portal" "200" "$(get "r=proveedor&id=$PB")"
no_contiene "…no muestra el portal" "$TMP/pag.html" "portal_proveedor"

echo "13. Cómo se calcula el puntaje (configurable por negocio)"
EMAIL_B=$EMAIL
PESOS_OK=(--data-urlencode "tolerancia_min=10" --data-urlencode "resta_leve=10" --data-urlencode "resta_media=25" --data-urlencode "resta_grave=50"
          --data-urlencode "tope_grave=1" --data-urlencode "tope_nivel=bueno" --data-urlencode "umbral_excelente=85" --data-urlencode "umbral_bueno=70"
          --data-urlencode "umbral_regular=50" --data-urlencode "periodo_meses=0")
check "Otro negocio (B): pantalla del puntaje" "200" "$(get "r=puntaje_proveedores")"
contiene "B: valores recomendados" "$TMP/pag.html" "Valores recomendados"
entrar "$EMAIL_A" "claveSegura1"
check "Dueño: pantalla del puntaje" "200" "$(get "r=puntaje_proveedores")"
contiene "Dueño: formulario para cambiar" "$TMP/pag.html" "Cambiar el cálculo"
contiene "Dueño: pide que los pesos sumen 100" "$TMP/pag.html" "Suman 100 %"
contiene "Dueño: vista previa con proveedores reales" "$TMP/pag.html" "DJ Fiesta Total"
contiene "Dueño: botón para volver a lo recomendado" "$TMP/pag.html" "Volver a los valores recomendados"
contiene "Explica la fórmula de hoy" "$TMP/pag.html" "calificación (estrellas) 40 %"
get "r=proveedores&ver=ranking" > /dev/null
contiene "Ranking: enlace a cómo se calcula" "$TMP/pag.html" "r=puntaje_proveedores"
contiene "Ranking: explica con los pesos del negocio" "$TMP/pag.html" "puntualidad 30 %"
r=$(post "r=puntaje_proveedores&json=1" --data-urlencode "accion=vista_previa" --data-urlencode "peso_calificacion=100" --data-urlencode "peso_puntualidad=0" \
  --data-urlencode "peso_cumplimiento=0" --data-urlencode "peso_incidencias=0" --data-urlencode "minimo_eventos=0" "${PESOS_OK[@]}")
check "Vista previa en vivo (JSON)" "200" "${r%% *}"
contiene "Vista previa: respuesta correcta" "$TMP/post.html" '"ok":true'
contiene "Vista previa: muestra 'Con los cambios'" "$TMP/post.html" "Con los cambios"
check "La vista previa no guarda nada" "0" "$(sql "SELECT count(*) FROM puntaje_proveedores_config WHERE negocio_id=$NID_A")"
r=$(post "r=puntaje_proveedores&json=1" --data-urlencode "accion=vista_previa" --data-urlencode "peso_calificacion=40" --data-urlencode "peso_puntualidad=30" \
  --data-urlencode "peso_cumplimiento=20" --data-urlencode "peso_incidencias=5" --data-urlencode "minimo_eventos=0" "${PESOS_OK[@]}")
check "Vista previa con pesos que no suman 100: responde" "200" "${r%% *}"
contiene "Vista previa: ok=false" "$TMP/post.html" '"ok":false'
contiene "Vista previa: dice cuánto suman" "$TMP/post.html" "Ahora suman 95"
r=$(post "r=puntaje_proveedores" --data-urlencode "accion=vista_previa" --data-urlencode "peso_calificacion=100" --data-urlencode "peso_puntualidad=0" \
  --data-urlencode "peso_cumplimiento=0" --data-urlencode "peso_incidencias=0" --data-urlencode "minimo_eventos=0" "${PESOS_OK[@]}")
check "Ver cómo quedaría (sin JavaScript)" "200" "${r%% *}"
contiene "Sin JavaScript: dice que todavía no se guardó" "$TMP/post.html" "todavía no se guardó"
post "r=puntaje_proveedores" --data-urlencode "accion=guardar" --data-urlencode "peso_calificacion=40" --data-urlencode "peso_puntualidad=30" \
  --data-urlencode "peso_cumplimiento=20" --data-urlencode "peso_incidencias=5" --data-urlencode "minimo_eventos=0" "${PESOS_OK[@]}" > /dev/null
check "Pesos que no suman 100: no se guarda" "0" "$(sql "SELECT count(*) FROM puntaje_proveedores_config WHERE negocio_id=$NID_A")"
get "r=puntaje_proveedores" > /dev/null
contiene "Mensaje: deben sumar 100" "$TMP/pag.html" "Los pesos deben sumar 100 %. Ahora suman 95 %."
contiene "Recuerda lo que escribió" "$TMP/pag.html" 'name="peso_incidencias" value="5"'
post "r=puntaje_proveedores" --data-urlencode "accion=guardar" --data-urlencode "peso_calificacion=100" --data-urlencode "peso_puntualidad=0" \
  --data-urlencode "peso_cumplimiento=0" --data-urlencode "peso_incidencias=0" --data-urlencode "minimo_eventos=0" --data-urlencode "tolerancia_min=30" \
  --data-urlencode "resta_leve=10" --data-urlencode "resta_media=25" --data-urlencode "resta_grave=50" --data-urlencode "tope_nivel=bueno" \
  --data-urlencode "umbral_excelente=85" --data-urlencode "umbral_bueno=70" --data-urlencode "umbral_regular=50" --data-urlencode "periodo_meses=0" > /dev/null
check "Guardado: pesos y tolerancia" "100|0|30|false" "$(sql "SELECT peso_calificacion||'|'||peso_incidencias||'|'||tolerancia_min||'|'||tope_grave FROM puntaje_proveedores_config WHERE negocio_id=$NID_A")"
check "Registro de cambios con antes y después" "1" "$(sql "SELECT count(*) FROM registro_cambios WHERE negocio_id=$NID_A AND evento_id IS NULL AND accion LIKE 'Puntaje de proveedores:%peso de la calificación: 40 % → 100 %%'")"
get "r=puntaje_proveedores" > /dev/null
contiene "Ahora es configuración propia" "$TMP/pag.html" "Configuración propia"
contiene "Cambios anteriores con quién" "$TMP/pag.html" "Dimitry Prueba"
get "r=proveedores&ver=ranking" > /dev/null
contiene "El ranking usa los pesos nuevos" "$TMP/pag.html" "calificación (estrellas) 100 %"
get "r=proveedor&id=$PDJ" > /dev/null
contiene "La ficha usa la tolerancia nueva" "$TMP/pag.html" "30 min"
contiene "La ficha muestra cuánto pesa cada parte" "$TMP/pag.html" "pesa 100 %"
post "r=puntaje_proveedores" --data-urlencode "accion=guardar" --data-urlencode "peso_calificacion=100" --data-urlencode "peso_puntualidad=0" \
  --data-urlencode "peso_cumplimiento=0" --data-urlencode "peso_incidencias=0" --data-urlencode "minimo_eventos=5" --data-urlencode "tolerancia_min=30" \
  --data-urlencode "resta_leve=10" --data-urlencode "resta_media=25" --data-urlencode "resta_grave=50" --data-urlencode "tope_nivel=bueno" \
  --data-urlencode "umbral_excelente=85" --data-urlencode "umbral_bueno=70" --data-urlencode "umbral_regular=50" --data-urlencode "periodo_meses=6" > /dev/null
get "r=proveedores&ver=ranking" > /dev/null
contiene "Mínimo de eventos: 'Sin datos suficientes'" "$TMP/pag.html" "Sin datos suficientes"
get "r=compras&nueva=1" > /dev/null
no_contiene "El selector de compras no muestra puntaje sin datos suficientes" "$TMP/pag.html" " pts ·"
post "r=puntaje_proveedores" --data-urlencode "accion=recomendados" > /dev/null
check "Volver a los recomendados" "40|30|20|10|10|0|0" "$(sql "SELECT peso_calificacion||'|'||peso_puntualidad||'|'||peso_cumplimiento||'|'||peso_incidencias||'|'||tolerancia_min||'|'||minimo_eventos||'|'||periodo_meses FROM puntaje_proveedores_config WHERE negocio_id=$NID_A")"
get "r=puntaje_proveedores" > /dev/null
contiene "Otra vez valores recomendados" "$TMP/pag.html" "pv-chip pv-chip-verde\">Valores recomendados"
post "r=puntaje_proveedores" --data-urlencode "accion=guardar" --data-urlencode "peso_calificacion=50" --data-urlencode "peso_puntualidad=30" \
  --data-urlencode "peso_cumplimiento=20" --data-urlencode "peso_incidencias=0" --data-urlencode "minimo_eventos=0" "${PESOS_OK[@]}" > /dev/null
check "Queda una configuración propia en A" "50" "$(sql "SELECT peso_calificacion FROM puntaje_proveedores_config WHERE negocio_id=$NID_A")"
entrar "$VEND" "$CL_V"
check "Vendedor: ve la explicación" "200" "$(get "r=puntaje_proveedores")"
contiene "Vendedor: solo lectura" "$TMP/pag.html" "Esto lo decide el dueño"
no_contiene "Vendedor: sin formulario" "$TMP/pag.html" "Cambiar el cálculo"
check "Vendedor: no guarda" "403" "$(post_desde "r=mi_cuenta" "r=puntaje_proveedores" --data-urlencode "accion=recomendados" | cut -d' ' -f1)"
check "Vendedor: no usa la vista previa" "403" "$(post_desde "r=mi_cuenta" "r=puntaje_proveedores&json=1" --data-urlencode "accion=vista_previa" | cut -d' ' -f1)"
entrar "$COORD" "$CL_C"
check "Coordinador: ve la explicación" "200" "$(get "r=puntaje_proveedores")"
no_contiene "Coordinador: sin formulario" "$TMP/pag.html" "Cambiar el cálculo"
check "Coordinador: no guarda" "403" "$(post_desde "r=mi_cuenta" "r=puntaje_proveedores" --data-urlencode "accion=recomendados" | cut -d' ' -f1)"
entrar "$CONS" "$CL_K"
check "Consulta: ve la explicación" "200" "$(get "r=puntaje_proveedores")"
check "Consulta: no guarda" "403" "$(post_desde "r=mi_cuenta" "r=puntaje_proveedores" --data-urlencode "accion=recomendados" | cut -d' ' -f1)"
check "Nadie más cambió la configuración de A" "50" "$(sql "SELECT peso_calificacion FROM puntaje_proveedores_config WHERE negocio_id=$NID_A")"
entrar "$EMAIL_B" "claveSegura1"
get "r=puntaje_proveedores" > /dev/null
contiene "B sigue con los valores recomendados" "$TMP/pag.html" "calificación (estrellas) 40 %"
no_contiene "B no ve los cambios de A" "$TMP/pag.html" "Cambios anteriores"
check "B no tiene configuración guardada" "0" "$(sql "SELECT count(*) FROM puntaje_proveedores_config WHERE negocio_id=$NID_B")"
