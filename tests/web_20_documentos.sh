# Documentos: catálogo, paquetes, cotización (editor, envío, página pública, aceptar), contrato (generar, enviar, firmar),
# verificación, plantillas y permisos — de punta a punta.
planes_de_antes   # estas pruebas usan los planes de antes (Esencial $19, Salón $39, Quinta $69); los de $20 y $45: web_112_planes.sh
FUT=$(date -d "+100 days" +%Y-%m-%d)
FIRMA=$(php -r '$i=imagecreatetruecolor(400,150);imagefill($i,0,0,imagecolorallocate($i,255,255,255));$n=imagecolorallocate($i,10,10,40);imagesetthickness($i,4);for($k=0;$k<6;$k++)imageline($i,20+$k*50,120-($k%2)*80,70+$k*50,40+($k%2)*80,$n);ob_start();imagepng($i);echo "data:image/png;base64,".base64_encode(ob_get_clean());')

echo "1. Catálogo"
registrar quinta "Hacienda Documentos $RANDOM"
NID_D=$NID; EMAIL_D=$EMAIL
check "Catálogo abre" "200" "$(get "r=catalogo")"
contiene "Lista los productos de ejemplo" "$TMP/pag.html" "Menú tres tiempos"
contiene "El dueño ve el margen" "$TMP/pag.html" "Margen"
contiene "Subir precios en bloque" "$TMP/pag.html" "Subir precios en bloque"
r=$(post_desde "r=catalogo" "r=catalogo&accion=guardar" --data-urlencode "nombre=Cena de gala" --data-urlencode "categoria=menu" \
     --data-urlencode "unidad=persona" --data-urlencode "precio=32,50" --data-urlencode "costo=15" --data-urlencode "con_iva=1" --data-urlencode "descripcion=Cuatro tiempos")
PROD=$(sql "SELECT id FROM productos WHERE negocio_id=$NID AND nombre='Cena de gala'")
check "Crear producto redirige al catálogo" "302" "${r%% *}"
check "Producto guardado con precio 32,50" "32.50" "$(sql "SELECT precio FROM productos WHERE id=$PROD")"
check "Costo guardado" "15.00" "$(sql "SELECT costo FROM productos WHERE id=$PROD")"
get "r=catalogo&cat=menu" > /dev/null
contiene "Filtro por categoría muestra el nuevo" "$TMP/pag.html" "Cena de gala"
no_contiene "Filtro por categoría oculta otras" "$TMP/pag.html" "Animadores, accesorios"
contiene "Margen calculado (53,8 %)" "$TMP/pag.html" "Margen 53,8 %"
get "r=catalogo&q=gala" > /dev/null
contiene "Búsqueda encuentra" "$TMP/pag.html" "Cena de gala"
r=$(post_desde "r=catalogo" "r=catalogo&accion=guardar" --data-urlencode "nombre=" --data-urlencode "categoria=menu" --data-urlencode "unidad=persona" --data-urlencode "precio=1")
get "r=catalogo&nuevo=1" > /dev/null
contiene "Producto sin nombre: error claro" "$TMP/pag.html" "Escribe el nombre"
ANTES=$(sql "SELECT precio FROM productos WHERE negocio_id=$NID AND nombre='Menú buffet'")
r=$(post_desde "r=catalogo" "r=catalogo&accion=subir_precios" --data-urlencode "pct=5" --data-urlencode "categoria_bloque=menu" --data-urlencode "paquetes=1")
check "Subir 5 % al menú" "$(php -r "echo number_format(round($ANTES*1.05,2),2,'.','');")" "$(sql "SELECT precio FROM productos WHERE negocio_id=$NID AND nombre='Menú buffet'")"
get "r=catalogo" > /dev/null
contiene "Aviso de precios subidos" "$TMP/pag.html" "Subiste 5 %"
r=$(post_desde "r=catalogo" "r=catalogo&accion=desactivar" --data-urlencode "producto_id=$PROD")
check "Desactivar producto" "f" "$(sql "SELECT activo FROM productos WHERE id=$PROD")"
r=$(post_desde "r=catalogo" "r=catalogo&accion=activar" --data-urlencode "producto_id=$PROD")
check "Activar producto" "t" "$(sql "SELECT activo FROM productos WHERE id=$PROD")"

echo "2. Paquetes"
check "Paquetes abre" "200" "$(get "r=paquetes")"
contiene "Lista los paquetes de ejemplo" "$TMP/pag.html" "Paquete Boda Oro"
contiene "Precio por persona en la tarjeta" "$TMP/pag.html" "por persona"
TORTA=$(sql "SELECT id FROM productos WHERE negocio_id=$NID AND nombre='Torta de bodas'")
DJ=$(sql "SELECT id FROM productos WHERE negocio_id=$NID AND nombre LIKE 'DJ%'")
r=$(post_desde "r=paquetes&nuevo=1" "r=paquetes&accion=guardar" --data-urlencode "nombre=Paquete Gala Web" --data-urlencode "tipo_evento=boda" \
     --data-urlencode "minimo_invitados=80" --data-urlencode "descripcion=Cena de gala, torta y DJ" \
     --data-urlencode "items[0][producto_id]=$PROD" --data-urlencode "items[0][cantidad]=1" --data-urlencode "items[0][precio]=30" \
     --data-urlencode "items[1][producto_id]=$TORTA" --data-urlencode "items[1][cantidad]=1" --data-urlencode "items[1][precio]=" \
     --data-urlencode "items[2][producto_id]=$DJ" --data-urlencode "items[2][cantidad]=1" --data-urlencode "items[2][precio]=600" \
     --data-urlencode "items[3][producto_id]=" --data-urlencode "items[3][cantidad]=1")
PAQ=$(sql "SELECT id FROM paquetes WHERE negocio_id=$NID AND nombre='Paquete Gala Web'")
check "Paquete creado lleva al simulador" "302 $BASE/?r=paquetes&sim=$PAQ" "$r"
check "Paquete con 3 productos" "3" "$(sql "SELECT count(*) FROM paquete_items WHERE paquete_id=$PAQ")"
check "Simulador con 120 invitados" "200" "$(get "r=paquetes&sim=$PAQ&n=120")"
contiene "Simulador muestra el total" "$TMP/pag.html" "Total con IVA"
contiene "Simulador por persona" "$TMP/pag.html" "Por persona"
get "r=paquetes&sim=$PAQ&n=20" > /dev/null
contiene "Simulador avisa el mínimo" "$TMP/pag.html" "se calcula como si fueran 80"
r=$(post_desde "r=paquetes" "r=paquetes&accion=duplicar" --data-urlencode "paquete_id=$PAQ")
check "Duplicar paquete" "1" "$(sql "SELECT count(*) FROM paquetes WHERE negocio_id=$NID AND nombre='Copia de Paquete Gala Web'")"
r=$(post_desde "r=paquetes&nuevo=1" "r=paquetes&accion=guardar" --data-urlencode "nombre=Vacío")
get "r=paquetes&nuevo=1" > /dev/null
contiene "Paquete sin productos: error claro" "$TMP/pag.html" "Agrega al menos un producto"

echo "3. Cotizar con un paquete y editar"
EV=$(crear_evento_sql "$FUT" consulta)
check "Pestaña cotización sin cotizaciones" "200" "$(get "r=cotizacion&id=$EV")"
contiene "Estado vacío útil" "$TMP/pag.html" "todavía no tiene cotización"
r=$(post_desde "r=cotizacion&id=$EV" "r=cotizacion&id=$EV&accion=crear" --data-urlencode "modo=paquete" --data-urlencode "paquete_id=$PAQ")
COT=$(sql "SELECT id FROM cotizaciones WHERE evento_id=$EV ORDER BY id DESC LIMIT 1")
check "Crear cotización desde paquete" "302 $BASE/?r=cotizacion&id=$EV&c=$COT" "$r"
check "Cotización con las 3 líneas" "3" "$(sql "SELECT count(*) FROM cotizacion_items WHERE cotizacion_id=$COT")"
check "Por persona x 120 invitados" "120.00" "$(sql "SELECT cantidad FROM cotizacion_items WHERE cotizacion_id=$COT AND producto_id=$PROD")"
get "r=cotizacion&id=$EV&c=$COT" > /dev/null
contiene "Editor con totales en vivo" "$TMP/pag.html" "data-doc-editor"
contiene "Buscador del catálogo" "$TMP/pag.html" "data-doc-buscador"
contiene "El dueño ve la ganancia" "$TMP/pag.html" "Ganancia"
contiene "Enlace para el cliente" "$TMP/pag.html" "r=cot&amp;t="
# Guardar: cambiar el precio del DJ, agregar una línea libre, descuento de 100 y 130 invitados
L1=$(sql "SELECT id FROM cotizacion_items WHERE cotizacion_id=$COT AND producto_id=$PROD")
L2=$(sql "SELECT id FROM cotizacion_items WHERE cotizacion_id=$COT AND producto_id=$TORTA")
L3=$(sql "SELECT id FROM cotizacion_items WHERE cotizacion_id=$COT AND producto_id=$DJ")
P2=$(sql "SELECT precio_unitario FROM cotizacion_items WHERE id=$L2")
guardar_cot() { # extra...
  post_desde "r=cotizacion&id=$EV&c=$COT" "r=cotizacion&id=$EV&accion=guardar" --data-urlencode "cotizacion_id=$COT" \
     --data-urlencode "invitados=130" --data-urlencode "valida_hasta=$(date -d '+15 days' +%Y-%m-%d)" --data-urlencode "descuento=100" \
     --data-urlencode "notas=Incluye degustación" --data-urlencode "condiciones=Pago de reserva 30 %" \
     --data-urlencode "lineas[i$L1][id]=$L1" --data-urlencode "lineas[i$L1][producto_id]=$PROD" --data-urlencode "lineas[i$L1][categoria]=menu" \
     --data-urlencode "lineas[i$L1][descripcion]=Cena de gala" --data-urlencode "lineas[i$L1][cantidad]=120" --data-urlencode "lineas[i$L1][unidad]=persona" \
     --data-urlencode "lineas[i$L1][precio_unitario]=30,00" --data-urlencode "lineas[i$L1][con_iva]=1" \
     --data-urlencode "lineas[i$L2][id]=$L2" --data-urlencode "lineas[i$L2][producto_id]=$TORTA" --data-urlencode "lineas[i$L2][categoria]=menu" \
     --data-urlencode "lineas[i$L2][descripcion]=Torta de bodas" --data-urlencode "lineas[i$L2][cantidad]=1" --data-urlencode "lineas[i$L2][unidad]=evento" \
     --data-urlencode "lineas[i$L2][precio_unitario]=$P2" --data-urlencode "lineas[i$L2][con_iva]=1" \
     --data-urlencode "lineas[i$L3][id]=$L3" --data-urlencode "lineas[i$L3][producto_id]=$DJ" --data-urlencode "lineas[i$L3][categoria]=musica" \
     --data-urlencode "lineas[i$L3][descripcion]=DJ 6 horas" --data-urlencode "lineas[i$L3][cantidad]=1" --data-urlencode "lineas[i$L3][unidad]=evento" \
     --data-urlencode "lineas[i$L3][precio_unitario]=550" --data-urlencode "lineas[i$L3][con_iva]=1" \
     --data-urlencode "lineas[n0][categoria]=otros" --data-urlencode "lineas[n0][descripcion]=Permiso municipal" --data-urlencode "lineas[n0][cantidad]=1" \
     --data-urlencode "lineas[n0][unidad]=evento" --data-urlencode "lineas[n0][precio_unitario]=80" \
     "$@"
}
r=$(guardar_cot --data-urlencode "despues=")
check "Guardar vuelve a la cotización" "302 $BASE/?r=cotizacion&id=$EV&c=$COT" "$r"
check "4 líneas (con la línea libre)" "4" "$(sql "SELECT count(*) FROM cotizacion_items WHERE cotizacion_id=$COT")"
check "Invitados 130: la línea por persona se recalcula" "130.00" "$(sql "SELECT cantidad FROM cotizacion_items WHERE cotizacion_id=$COT AND producto_id=$PROD")"
check "Precio del DJ cambiado" "550.00" "$(sql "SELECT precio_unitario FROM cotizacion_items WHERE cotizacion_id=$COT AND producto_id=$DJ")"
check "Línea libre sin IVA" "f" "$(sql "SELECT con_iva FROM cotizacion_items WHERE cotizacion_id=$COT AND descripcion='Permiso municipal'")"
check "Descuento 100" "100.00" "$(sql "SELECT descuento FROM cotizaciones WHERE id=$COT")"
ESPERADO=$(php -r "require 'src/Cotizaciones.php'; \$ci=130*30+$P2+550; \$t=TuRenta\Cotizaciones::calcularTotales(\$ci,80,100,15); echo number_format(\$t['total'],2,'.','');")
check "Total calculado igual que la fórmula" "$ESPERADO" "$(sql "SELECT total FROM cotizaciones WHERE id=$COT")"
check "Costo guardado para la ganancia (menú)" "15.00" "$(sql "SELECT costo_unitario FROM cotizacion_items WHERE cotizacion_id=$COT AND producto_id=$PROD")"
r=$(guardar_cot --data-urlencode "quitar=n0")
check "Quitar la línea libre" "3" "$(sql "SELECT count(*) FROM cotizacion_items WHERE cotizacion_id=$COT")"
r=$(guardar_cot --data-urlencode "mover=i$L3:arriba")
check "Subir el DJ un lugar" "DJ 6 horas" "$(sql "SELECT descripcion FROM cotizacion_items WHERE cotizacion_id=$COT ORDER BY orden LIMIT 1 OFFSET 1")"
r=$(post_desde "r=cotizacion&id=$EV&c=$COT" "r=cotizacion&id=$EV&accion=agregar" --data-urlencode "cotizacion_id=$COT" --data-urlencode "producto_id=$TORTA")
check "Agregar del catálogo" "5" "$(sql "SELECT count(*) FROM cotizacion_items WHERE cotizacion_id=$COT")"
r=$(guardar_cot --data-urlencode "despues=" --data-urlencode "descuento=-5")
get "r=cotizacion&id=$EV&c=$COT" > /dev/null
contiene "Descuento negativo: error claro" "$TMP/pag.html" "no puede ser negativo"

echo "4. Enviar y PDF"
r=$(guardar_cot --data-urlencode "despues=whatsapp")
check "Guardar y enviar por WhatsApp abre WhatsApp" "302 https://wa.me/593991234567" "$(echo "$r" | cut -d'?' -f1)"
check "Cotización enviada" "enviada" "$(sql "SELECT estado FROM cotizaciones WHERE id=$COT")"
check "El evento pasa a cotizado" "cotizado" "$(sql "SELECT estado FROM eventos WHERE id=$EV")"
code=$(curl -s -b "$JAR" -o "$TMP/cot.pdf" -w "%{http_code} %{content_type}" "$BASE/?r=pdf&tipo=cotizacion&id=$COT")
check "PDF de la cotización 200 y application/pdf" "200 application/pdf" "$code"
check "El PDF empieza con %PDF" "%PDF" "$(head -c 4 "$TMP/cot.pdf")"
if command -v pdftotext > /dev/null; then
  pdftotext -layout "$TMP/cot.pdf" "$TMP/cot.txt"
  CLIENTE=$(sql "SELECT c.nombre FROM eventos e JOIN clientes c ON c.id=e.cliente_id WHERE e.id=$EV")
  contiene "PDF con el nombre del cliente" "$TMP/cot.txt" "$CLIENTE"
  contiene "PDF con la línea del DJ" "$TMP/cot.txt" "DJ 6 horas"
  contiene "PDF con precio por persona" "$TMP/cot.txt" "Precio por persona"
fi
check "PDF de una cotización de otro negocio: no" "200" "$(get "r=pdf&tipo=cotizacion&id=999999")"
contiene "Mensaje claro" "$TMP/pag.html" "No se encontró la cotización"

echo "5. Página pública de la cotización"
TOK=$(sql "SELECT token FROM cotizaciones WHERE id=$COT")
JAR_DUENO=$JAR; JAR="$TMP/cookies_cliente_$$"; rm -f "$JAR"
check "Cotización pública abre sin sesión" "200" "$(get "r=cot&t=$TOK")"
contiene "Con la marca del negocio" "$TMP/pag.html" "Hacienda Documentos"
contiene "Precio por persona" "$TMP/pag.html" "Por persona"
contiene "Detalle por categoría" "$TMP/pag.html" "Música y sonido"
contiene "Botón aceptar" "$TMP/pag.html" "Aceptar cotización"
contiene "Tengo dudas por WhatsApp" "$TMP/pag.html" "Tengo dudas"
no_contiene "El cliente no ve la ganancia" "$TMP/pag.html" "Ganancia"
code=$(curl -s -o "$TMP/cotpub.pdf" -w "%{http_code} %{content_type}" "$BASE/?r=cot&t=$TOK&pdf=1")
check "PDF público 200 application/pdf" "200 application/pdf" "$code"
check "Token falso: mensaje" "404" "$(get "r=cot&t=abc123")"
contiene "Mensaje de enlace inválido" "$TMP/pag.html" "no existe o está mal copiado"
r=$(post_desde "r=cot&t=$TOK" "r=cot&t=$TOK" --data-urlencode "nombre=Cliente Web")
get "r=cot&t=$TOK" > /dev/null
contiene "Sin la casilla no se acepta" "$TMP/pag.html" "Marca la casilla"
r=$(post_desde "r=cot&t=$TOK" "r=cot&t=$TOK" --data-urlencode "nombre=Cliente Web Aceptó" --data-urlencode "acepto=1")
check "Aceptar desde el enlace" "aceptada" "$(sql "SELECT estado FROM cotizaciones WHERE id=$COT")"
check "Quién aceptó" "Cliente Web Aceptó" "$(sql "SELECT aceptada_por FROM cotizaciones WHERE id=$COT")"
check "Se guardó la IP" "127.0.0.1" "$(sql "SELECT aceptada_ip FROM cotizaciones WHERE id=$COT")"
check "Evento cotizado" "cotizado" "$(sql "SELECT estado FROM eventos WHERE id=$EV")"
check "El evento queda con el valor" "$(sql "SELECT total FROM cotizaciones WHERE id=$COT")" "$(sql "SELECT total FROM eventos WHERE id=$EV")"
check "Y con su plan de cuotas" "3" "$(sql "SELECT count(*) FROM cuotas WHERE evento_id=$EV")"
check "Aviso al negocio" "1" "$(sql "SELECT count(*) FROM notificaciones WHERE evento_id=$EV AND texto LIKE '%aceptó la cotización%'")"
get "r=cot&t=$TOK" > /dev/null
contiene "Ya aceptada: muestra el estado" "$TMP/pag.html" "Cotización aceptada"
contiene "Enlace al portal del cliente" "$TMP/pag.html" "r=portal&amp;t="
JAR=$JAR_DUENO

echo "6. Versiones: nueva versión, comparar, vencida"
r=$(post_desde "r=cotizacion&id=$EV" "r=cotizacion&id=$EV&accion=crear" --data-urlencode "modo=copia")
COT2=$(sql "SELECT id FROM cotizaciones WHERE evento_id=$EV ORDER BY id DESC LIMIT 1")
check "Nueva versión (copia)" "2" "$(sql "SELECT version FROM cotizaciones WHERE id=$COT2")"
check "La copia tiene las mismas líneas" "$(sql "SELECT count(*) FROM cotizacion_items WHERE cotizacion_id=$COT")" "$(sql "SELECT count(*) FROM cotizacion_items WHERE cotizacion_id=$COT2")"
check "Comparar versiones" "200" "$(get "r=cotizacion&id=$EV&c=$COT2&comparar=1")"
contiene "Tabla de comparación" "$TMP/pag.html" "Comparar versiones"
contiene "Columna versión 2" "$TMP/pag.html" "Versión 2"
sql "UPDATE cotizaciones SET valida_hasta = CURRENT_DATE - 1 WHERE id=$COT2" > /dev/null
r=$(post_desde "r=cotizacion&id=$EV&c=$COT2" "r=cotizacion&id=$EV&accion=guardar" --data-urlencode "cotizacion_id=$COT2" --data-urlencode "despues=enviar" \
    --data-urlencode "invitados=130" --data-urlencode "valida_hasta=$(date -d '-1 days' +%Y-%m-%d)" --data-urlencode "descuento=0" \
    --data-urlencode "lineas[a][categoria]=otros" --data-urlencode "lineas[a][descripcion]=Algo" --data-urlencode "lineas[a][cantidad]=1" --data-urlencode "lineas[a][unidad]=evento" --data-urlencode "lineas[a][precio_unitario]=10")
TOK2=$(sql "SELECT token FROM cotizaciones WHERE id=$COT2")
JAR="$TMP/cookies_cliente2_$$"; rm -f "$JAR"
get "r=cot&t=$TOK2" > /dev/null
contiene "Vencida: aviso con WhatsApp para pedir otra" "$TMP/pag.html" "Pedir una actualizada"
no_contiene "Vencida: sin botón aceptar" "$TMP/pag.html" "Aceptar cotización"
JAR=$JAR_DUENO
sql "UPDATE cotizaciones SET valida_hasta = CURRENT_DATE + 10 WHERE id=$COT2" > /dev/null
r=$(post_desde "r=cotizacion&id=$EV" "r=cotizacion&id=$EV&accion=crear" --data-urlencode "modo=copia")
COT3=$(sql "SELECT id FROM cotizaciones WHERE evento_id=$EV ORDER BY id DESC LIMIT 1")
check "La versión 2 queda reemplazada por la 3" "reemplazada" "$(sql "SELECT estado FROM cotizaciones WHERE id=$COT2")"
JAR="$TMP/cookies_cliente3_$$"; rm -f "$JAR"
get "r=cot&t=$TOK2" > /dev/null
contiene "Reemplazada: aviso" "$TMP/pag.html" "fue actualizada"
JAR=$JAR_DUENO
r=$(post_desde "r=cotizacion&id=$EV&c=$COT3" "r=cotizacion&id=$EV&accion=rechazar" --data-urlencode "cotizacion_id=$COT3" --data-urlencode "motivo=Prefiere la primera")
check "Rechazar la versión 3" "rechazada" "$(sql "SELECT estado FROM cotizaciones WHERE id=$COT3")"
check "La aceptada sigue aceptada" "aceptada" "$(sql "SELECT estado FROM cotizaciones WHERE id=$COT")"

echo "7. Aceptar en nombre del cliente (otro evento)"
EV2=$(crear_evento_sql "$(date -d '+130 days' +%Y-%m-%d)" consulta)
r=$(post_desde "r=cotizacion&id=$EV2" "r=cotizacion&id=$EV2&accion=crear" --data-urlencode "modo=vacia")
C2=$(sql "SELECT id FROM cotizaciones WHERE evento_id=$EV2")
check "Cotización en blanco con el alquiler del espacio" "1" "$(sql "SELECT count(*) FROM cotizacion_items WHERE cotizacion_id=$C2 AND descripcion LIKE 'Alquiler de%'")"
r=$(post_desde "r=cotizacion&id=$EV2&c=$C2" "r=cotizacion&id=$EV2&accion=aceptar" --data-urlencode "cotizacion_id=$C2" --data-urlencode "nombre=Cliente Teléfono")
check "Sin confirmar no se acepta" "borrador" "$(sql "SELECT estado FROM cotizaciones WHERE id=$C2")"
r=$(post_desde "r=cotizacion&id=$EV2&c=$C2" "r=cotizacion&id=$EV2&accion=aceptar" --data-urlencode "cotizacion_id=$C2" --data-urlencode "nombre=Cliente Teléfono" --data-urlencode "confirmo=1")
check "Aceptada en nombre del cliente" "aceptada" "$(sql "SELECT estado FROM cotizaciones WHERE id=$C2")"
check "Evento 2 con valor" "1" "$(sql "SELECT (total > 0)::int FROM eventos WHERE id=$EV2")"

echo "8. Contrato: generar, editar, enviar"
check "Pestaña contrato" "200" "$(get "r=contrato&id=$EV")"
contiene "Explica el flujo" "$TMP/pag.html" "El cliente firma en su celular"
contiene "Botón generar" "$TMP/pag.html" "Generar contrato"
PLANT=$(sql "SELECT id FROM plantillas_contrato WHERE negocio_id=$NID AND predeterminada")
r=$(post_desde "r=contrato&id=$EV" "r=contrato&id=$EV&accion=generar" --data-urlencode "plantilla_id=$PLANT")
K=$(sql "SELECT id FROM contratos WHERE evento_id=$EV ORDER BY id DESC LIMIT 1")
check "Generar lleva al contrato" "302 $BASE/?r=contrato&id=$EV&k=$K" "$r"
check "Contrato en borrador" "borrador" "$(sql "SELECT estado FROM contratos WHERE id=$K")"
get "r=contrato&id=$EV&k=$K" > /dev/null
contiene "Texto editable" "$TMP/pag.html" "Revisa el texto"
TEXTO=$(sql "SELECT texto FROM contratos WHERE id=$K")
r=$(post_desde "r=contrato&id=$EV&k=$K" "r=contrato&id=$EV&accion=editar" --data-urlencode "contrato_id=$K" --data-urlencode "texto=$TEXTO
CLÁUSULA ESPECIAL: el cliente puede traer su propio fotógrafo.")
check "Texto editado" "1" "$(sql "SELECT count(*) FROM contratos WHERE id=$K AND texto LIKE '%CLÁUSULA ESPECIAL%'")"
check "La huella se recalcula" "t" "$(sql "SELECT hash_sha256 = encode(sha256(convert_to(texto,'UTF8')),'hex') FROM contratos WHERE id=$K")"
code=$(curl -s -b "$JAR" -o "$TMP/k.pdf" -w "%{http_code} %{content_type}" "$BASE/?r=pdf&tipo=contrato&id=$K")
check "PDF del contrato (borrador) 200 application/pdf" "200 application/pdf" "$code"
r=$(post_desde "r=contrato&id=$EV&k=$K" "r=contrato&id=$EV&accion=enviar_wa" --data-urlencode "contrato_id=$K")
check "Enviar por WhatsApp abre WhatsApp" "302 https://wa.me/593991234567" "$(echo "$r" | cut -d'?' -f1)"
check "Contrato enviado" "enviado" "$(sql "SELECT estado FROM contratos WHERE id=$K")"
get "r=contrato&id=$EV&k=$K" > /dev/null
contiene "Enlace de firma" "$TMP/pag.html" "r=firmar&amp;t="
r=$(post_desde "r=contrato&id=$EV&k=$K" "r=contrato&id=$EV&accion=editar" --data-urlencode "contrato_id=$K" --data-urlencode "texto=$TEXTO cambiado")
get "r=contrato&id=$EV&k=$K" > /dev/null
contiene "Enviado: el texto ya no se cambia" "$TMP/pag.html" "Solo se puede cambiar un contrato en borrador"

echo "9. Firma en la página pública"
KTOK=$(sql "SELECT token FROM contratos WHERE id=$K")
KCOD=$(sql "SELECT codigo_verificacion FROM contratos WHERE id=$K")
# La reserva se paga antes de firmar: al firmar, el evento queda confirmado
RES=$(sql "SELECT monto FROM cuotas WHERE evento_id=$EV ORDER BY numero LIMIT 1")
sql "INSERT INTO pagos (negocio_id, evento_id, monto, metodo, estado) VALUES ($NID, $EV, $RES, 'transferencia', 'confirmado'); UPDATE cuotas SET pagado = monto WHERE evento_id=$EV AND numero=1; UPDATE eventos SET estado='separado' WHERE id=$EV; UPDATE evento_espacios SET bloquea=true WHERE evento_id=$EV" > /dev/null
JAR="$TMP/cookies_firma_$$"; rm -f "$JAR"
check "Página de firma abre sin sesión" "200" "$(get "r=firmar&t=$KTOK")"
contiene "Muestra el texto del contrato" "$TMP/pag.html" "texto-contrato"
contiene "Recuadro para firmar con el dedo" "$TMP/pag.html" "firma-caja"
contiene "Casilla Leí y acepto" "$TMP/pag.html" "Leí y acepto"
contiene "Con la marca del negocio" "$TMP/pag.html" "Hacienda Documentos"
r=$(post_desde "r=firmar&t=$KTOK" "r=firmar&t=$KTOK" --data-urlencode "nombre=Cliente Web Firma" --data-urlencode "identificacion=1712345678" --data-urlencode "firma=$FIRMA")
contiene "Sin aceptar: error claro" "$TMP/post.html" "Marca la casilla"
r=$(post_desde "r=firmar&t=$KTOK" "r=firmar&t=$KTOK" --data-urlencode "nombre=Cliente Web Firma" --data-urlencode "identificacion=123" --data-urlencode "firma=$FIRMA" --data-urlencode "acepto=1")
contiene "Cédula mal escrita: error claro" "$TMP/post.html" "cédula"
contiene "Conserva el nombre escrito" "$TMP/post.html" "Cliente Web Firma"
r=$(post_desde "r=firmar&t=$KTOK" "r=firmar&t=$KTOK" --data-urlencode "nombre=Cliente Web Firma" --data-urlencode "identificacion=1712345678" --data-urlencode "firma=" --data-urlencode "acepto=1")
contiene "Sin firma: pide dibujarla" "$TMP/post.html" "Dibuja tu firma"
r=$(post_desde "r=firmar&t=$KTOK" "r=firmar&t=$KTOK" --data-urlencode "nombre=Cliente Web Firma" --data-urlencode "identificacion=1712345678" --data-urlencode "firma=$FIRMA" --data-urlencode "acepto=1")
check "Firmar redirige a la confirmación" "302 $BASE/?r=firmar&t=$KTOK" "$r"
check "Contrato firmado" "firmado" "$(sql "SELECT estado FROM contratos WHERE id=$K")"
check "Firmante guardado" "Cliente Web Firma" "$(sql "SELECT firmante_nombre FROM contratos WHERE id=$K")"
check "Reserva pagada + firma: evento CONFIRMADO" "confirmado" "$(sql "SELECT estado FROM eventos WHERE id=$EV")"
get "r=firmar&t=$KTOK" > /dev/null
contiene "Confirmación de firmado" "$TMP/pag.html" "Contrato firmado"
contiene "Descargar el PDF firmado" "$TMP/pag.html" "Descargar mi contrato firmado"
code=$(curl -s -o "$TMP/kf.pdf" -w "%{http_code} %{content_type}" "$BASE/?r=firmar&t=$KTOK&pdf=1")
check "PDF firmado 200 application/pdf" "200 application/pdf" "$code"
if command -v pdftotext > /dev/null; then
  pdftotext -layout "$TMP/kf.pdf" "$TMP/kf.txt"
  contiene "PDF firmado con el firmante" "$TMP/kf.txt" "Cliente Web Firma"
  contiene "PDF firmado con el código" "$TMP/kf.txt" "$KCOD"
  contiene "PDF firmado con la cláusula editada" "$TMP/kf.txt" "CLÁUSULA ESPECIAL"
fi
r=$(post_desde "r=firmar&t=$KTOK" "r=firmar&t=$KTOK" --data-urlencode "nombre=Otra Persona" --data-urlencode "identificacion=1712345678" --data-urlencode "firma=$FIRMA" --data-urlencode "acepto=1")
check "No se firma dos veces" "Cliente Web Firma" "$(sql "SELECT firmante_nombre FROM contratos WHERE id=$K")"
check "Token de firma falso" "404" "$(get "r=firmar&t=noexiste")"
contiene "Mensaje de enlace inválido" "$TMP/pag.html" "no existe o está mal copiado"

echo "10. Verificar"
check "Verificar abre sin código" "200" "$(get "r=verificar")"
contiene "Formulario para escribir el código" "$TMP/pag.html" "Código de verificación"
check "Verificar con el código" "200" "$(get "r=verificar&c=$KCOD")"
contiene "Auténtico y firmado" "$TMP/pag.html" "Contrato auténtico y firmado"
contiene "Íntegro sí" "$TMP/pag.html" "Sí, el texto no fue cambiado"
contiene "Firmado por" "$TMP/pag.html" "Cliente Web Firma"
contiene "Negocio" "$TMP/pag.html" "Hacienda Documentos"
no_contiene "Cédula enmascarada" "$TMP/pag.html" "1712345678"
check "Código en minúsculas también sirve" "200" "$(get "r=verificar&c=$(echo "$KCOD" | tr 'A-Z' 'a-z')")"
check "Código que no existe" "404" "$(get "r=verificar&c=ABCDEF123456")"
contiene "Mensaje de no encontrado" "$TMP/pag.html" "No encontramos ese código"
sql "UPDATE contratos SET texto = texto || ' x' WHERE id=$K" > /dev/null
get "r=verificar&c=$KCOD" > /dev/null
contiene "Texto alterado: lo detecta" "$TMP/pag.html" "El texto NO coincide"
sql "UPDATE contratos SET texto = left(texto, length(texto) - 2) WHERE id=$K" > /dev/null
JAR=$JAR_DUENO
get "r=contrato&id=$EV" > /dev/null
contiene "Contrato firmado: imagen de la firma" "$TMP/pag.html" "r=archivo&amp;tipo=firma&amp;id=$K"
contiene "Contrato firmado: código" "$TMP/pag.html" "$KCOD"
check "Imagen de la firma 200" "200" "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$BASE/?r=archivo&tipo=firma&id=$K")"

echo "11. Anular y contrato nuevo"
r=$(post_desde "r=contrato&id=$EV" "r=contrato&id=$EV&accion=anular" --data-urlencode "contrato_id=$K" --data-urlencode "motivo=")
check "Anular pide motivo" "firmado" "$(sql "SELECT estado FROM contratos WHERE id=$K")"
r=$(post_desde "r=contrato&id=$EV" "r=contrato&id=$EV&accion=anular" --data-urlencode "contrato_id=$K" --data-urlencode "motivo=Cambio de fecha")
check "Contrato anulado" "anulado" "$(sql "SELECT estado FROM contratos WHERE id=$K")"
get "r=contrato&id=$EV" > /dev/null
contiene "Ofrece preparar uno nuevo" "$TMP/pag.html" "Preparar un contrato nuevo"
contiene "Historial de contratos" "$TMP/pag.html" "Contratos de este evento"
JAR="$TMP/cookies_firma2_$$"; rm -f "$JAR"
get "r=firmar&t=$KTOK" > /dev/null
contiene "Enlace de un contrato anulado: aviso" "$TMP/pag.html" "fue reemplazado"
get "r=verificar&c=$KCOD" > /dev/null
contiene "Verificar un anulado" "$TMP/pag.html" "Contrato anulado"
JAR=$JAR_DUENO

echo "12. Plantillas de contrato"
check "Plantillas abre" "200" "$(get "r=contrato")"
contiene "Lista la plantilla de ejemplo" "$TMP/pag.html" "Contrato general"
check "Editar plantilla" "200" "$(get "r=contrato&plantilla=$PLANT")"
contiene "Ayuda de variables" "$TMP/pag.html" "{{total_letras}}"
r=$(post_desde "r=contrato&plantilla=nueva" "r=contrato&accion=guardar_plantilla" --data-urlencode "nombre=Contrato quince" \
     --data-urlencode "texto=CONTRATO PARA QUINCE AÑOS

En {{ciudad}}, el cliente {{cliente}} contrata el evento {{evento}} para el {{fecha}} por {{total}}. Texto suficientemente largo.")
P2ID=$(sql "SELECT id FROM plantillas_contrato WHERE negocio_id=$NID AND nombre='Contrato quince'")
check "Plantilla nueva guardada" "302 $BASE/?r=contrato&plantilla=$P2ID" "$r"
r=$(post_desde "r=contrato&plantilla=nueva" "r=contrato&accion=guardar_plantilla" --data-urlencode "nombre=Mala" \
     --data-urlencode "texto=Este contrato tiene una variable que no existe: {{inventada}} y es largo para pasar el mínimo de letras.")
get "r=contrato&plantilla=nueva" > /dev/null
contiene "Variable inválida: error claro" "$TMP/pag.html" "Estas variables no existen"
contiene "Conserva lo escrito" "$TMP/pag.html" "{{inventada}}"
check "No se guardó la mala" "0" "$(sql "SELECT count(*) FROM plantillas_contrato WHERE negocio_id=$NID AND nombre='Mala'")"
r=$(post_desde "r=contrato" "r=contrato&accion=predeterminada" --data-urlencode "plantilla_id=$P2ID")
check "Nueva predeterminada" "$P2ID" "$(sql "SELECT id FROM plantillas_contrato WHERE negocio_id=$NID AND predeterminada")"
check "Vista previa con datos de ejemplo" "200" "$(get "r=contrato&previa=$P2ID")"
contiene "Vista previa reemplaza variables" "$TMP/pag.html" "Ana Lucía Torres Vega"
r=$(post_desde "r=contrato" "r=contrato&accion=restaurar" --data-urlencode "plantilla_id=$P2ID")
check "Restaurar la de fábrica" "1" "$(sql "SELECT count(*) FROM plantillas_contrato WHERE id=$P2ID AND texto LIKE 'CONTRATO DE PRESTACIÓN%'")"
r=$(post_desde "r=contrato" "r=contrato&accion=borrar_plantilla" --data-urlencode "plantilla_id=$P2ID")
check "La predeterminada no se borra" "1" "$(sql "SELECT count(*) FROM plantillas_contrato WHERE id=$P2ID")"
r=$(post_desde "r=contrato" "r=contrato&accion=borrar_plantilla" --data-urlencode "plantilla_id=$PLANT")
check "Otra plantilla sí se borra" "0" "$(sql "SELECT count(*) FROM plantillas_contrato WHERE id=$PLANT")"

echo "13. Permisos"
HASH=$(php -r 'echo password_hash("claveSegura1", PASSWORD_DEFAULT);')
COORD="coord$RANDOM@prueba.ec"; VEND="vend$RANDOM@prueba.ec"; CONS="cons$RANDOM@prueba.ec"
sql "INSERT INTO usuarios (negocio_id, nombre, email, password_hash, rol) VALUES ($NID, 'Coordinadora', '$COORD', '$HASH', 'coordinador'), ($NID, 'Vendedor', '$VEND', '$HASH', 'vendedor'), ($NID, 'Solo mira', '$CONS', '$HASH', 'consulta')" > /dev/null
entrar "$COORD" "claveSegura1"
check "Coordinador no abre cotizaciones" "302 $BASE/?r=inicio" "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code} %{redirect_url}' "$BASE/?r=cotizacion&id=$EV")"
check "Coordinador no abre contratos" "302 $BASE/?r=inicio" "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code} %{redirect_url}' "$BASE/?r=contrato&id=$EV")"
check "Coordinador no abre el catálogo" "302 $BASE/?r=inicio" "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code} %{redirect_url}' "$BASE/?r=catalogo")"
get "r=pdf&tipo=cotizacion&id=$COT" > /dev/null
contiene "Coordinador no baja el PDF de la cotización" "$TMP/pag.html" "dueño o el vendedor"
entrar "$VEND" "claveSegura1"
check "Vendedor abre la cotización" "200" "$(get "r=cotizacion&id=$EV2&c=$C2")"
no_contiene "Vendedor no ve la ganancia" "$TMP/pag.html" "Ganancia"
get "r=catalogo" > /dev/null
no_contiene "Vendedor no ve costos en el catálogo" "$TMP/pag.html" "Costo "
no_contiene "Vendedor no sube precios en bloque" "$TMP/pag.html" "Subir precios en bloque"
r=$(post_desde "r=catalogo" "r=catalogo&accion=guardar" --data-urlencode "producto_id=$PROD" --data-urlencode "nombre=Cena de gala" --data-urlencode "categoria=menu" \
     --data-urlencode "unidad=persona" --data-urlencode "precio=33" --data-urlencode "costo=1" --data-urlencode "con_iva=1")
check "Vendedor cambia el precio" "33.00" "$(sql "SELECT precio FROM productos WHERE id=$PROD")"
check "Pero no el costo" "15.00" "$(sql "SELECT costo FROM productos WHERE id=$PROD")"
r=$(post_desde "r=catalogo" "r=catalogo&accion=subir_precios" --data-urlencode "pct=50")
check "Vendedor no puede subir precios en bloque" "403" "${r%% *}"
check "Vendedor no abre las plantillas" "403" "$(get "r=contrato")"
entrar "$CONS" "claveSegura1"
check "Consulta mira la cotización" "200" "$(get "r=cotizacion&id=$EV")"
no_contiene "Consulta no ve el editor" "$TMP/pag.html" "data-doc-editor"
r=$(post_desde "r=mi_cuenta" "r=cotizacion&id=$EV&accion=crear" --data-urlencode "modo=copia")
check "Consulta no crea cotizaciones" "403" "${r%% *}"
registrar salon "Otro Salón $RANDOM"
check "Otro negocio no ve la cotización ajena" "404" "$(get "r=cotizacion&id=$EV")"
check "Otro negocio no ve el contrato ajeno" "404" "$(get "r=contrato&id=$EV")"
get "r=pdf&tipo=contrato&id=$K" > /dev/null
contiene "Otro negocio no baja el PDF ajeno" "$TMP/pag.html" "No se encontró el contrato"
