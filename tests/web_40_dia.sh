#!/usr/bin/env bash
# Día del evento: cronograma de ejemplo y modo en vivo, personal + staff, proveedores, mobiliario,
# invitados y mesas, invitación pública + RSVP + QR, puerta + ingreso por QR, portal de los novios, PDF, CSV,
# candado del plan Salón y aislamiento entre negocios.
planes_de_antes   # estas pruebas usan los planes de antes (Esencial $19, Salón $39, Quinta $69); los de $20 y $45: web_112_planes.sh
FECHA=$(date -d '+45 days' +%Y-%m-%d)
FECHA2=$(date -d '-3 days' +%Y-%m-%d)

echo "1. Pestaña Día del evento y cronograma de ejemplo"
registrar quinta "Hacienda Día $RANDOM"
NID_A=$NID; EMAIL_A=$EMAIL
EV=$(crear_evento_sql "$FECHA" separado)
sql "UPDATE eventos SET hora_inicio='18:00', hora_fin='02:00', invitados=150, menu_elegido='Lomo fino con salsa de champiñones', detalles_cliente='Colores blanco y dorado' WHERE id=$EV" > /dev/null
check "Día del evento abre" "200" "$(get "r=evento_dia&id=$EV")"
contiene "Estado vacío útil del cronograma" "$TMP/pag.html" "Cargar cronograma de ejemplo"
contiene "Pestañas del evento" "$TMP/pag.html" "Día del evento"
r=$(post_desde "r=evento_dia&id=$EV" "r=evento_dia&id=$EV" --data-urlencode "accion=ejemplo" --data-urlencode "plantilla=boda" --data-urlencode "ver=cronograma")
check "Cargar ejemplo redirige" "302" "${r%% *}"
check "11 momentos de boda" "11" "$(sql "SELECT count(*) FROM cronograma WHERE evento_id=$EV")"
check "Empieza a las 18:00" "18:00:00" "$(sql "SELECT hora FROM cronograma WHERE evento_id=$EV ORDER BY orden LIMIT 1")"
get "r=evento_dia&id=$EV" > /dev/null
contiene "Muestra el vals" "$TMP/pag.html" "Vals"
contiene "Muestra la hora loca" "$TMP/pag.html" "Hora loca"
contiene "Enlace del modo en vivo" "$TMP/pag.html" "r=vivo&amp;t="
contiene "WhatsApp al DJ" "$TMP/pag.html" "WhatsApp al DJ"
contiene "Confirmación antes de quitar" "$TMP/pag.html" "data-confirmar"
post_desde "r=evento_dia&id=$EV" "r=evento_dia&id=$EV" --data-urlencode "accion=bloque_agregar" --data-urlencode "hora=19:00" \
  --data-urlencode "duracion_min=5" --data-urlencode "actividad=Lanzamiento del ramo" --data-urlencode "responsable=DJ" > /dev/null
check "Agregar un momento" "1" "$(sql "SELECT count(*) FROM cronograma WHERE evento_id=$EV AND actividad='Lanzamiento del ramo'")"
B=$(sql "SELECT id FROM cronograma WHERE evento_id=$EV AND actividad='Lanzamiento del ramo'")
post_desde "r=evento_dia&id=$EV" "r=evento_dia&id=$EV" --data-urlencode "accion=bloque_editar" --data-urlencode "bloque_id=$B" --data-urlencode "hora=19:05" \
  --data-urlencode "duracion_min=8" --data-urlencode "actividad=Ramo y liga" > /dev/null
check "Editar un momento" "Ramo y liga" "$(sql "SELECT actividad FROM cronograma WHERE id=$B")"
O1=$(sql "SELECT orden FROM cronograma WHERE id=$B")
post_desde "r=evento_dia&id=$EV" "r=evento_dia&id=$EV" --data-urlencode "accion=bloque_subir" --data-urlencode "bloque_id=$B" > /dev/null
check "Subir un momento" "$((O1-1))" "$(sql "SELECT orden FROM cronograma WHERE id=$B")"
post_desde "r=evento_dia&id=$EV" "r=evento_dia&id=$EV" --data-urlencode "accion=bloque_borrar" --data-urlencode "bloque_id=$B" > /dev/null
check "Borrar un momento" "0" "$(sql "SELECT count(*) FROM cronograma WHERE id=$B")"
r=$(post_desde "r=evento_dia&id=$EV" "r=evento_dia&id=$EV" --data-urlencode "accion=bloque_agregar" --data-urlencode "hora=99:00" --data-urlencode "actividad=X")
get "r=evento_dia&id=$EV" > /dev/null
contiene "Hora mala: mensaje claro" "$TMP/pag.html" "Revisa la hora"

echo "2. Modo en vivo"
TV=$(sql "SELECT token_vivo FROM eventos WHERE id=$EV")
rm -f "$TMP/jar_vivo"; JAR_A=$JAR; JAR="$TMP/jar_vivo"
check "Vivo abre sin sesión" "200" "$(get "r=vivo&t=$TV")"
contiene "Todavía no empieza" "$TMP/pag.html" "Todavía no empieza"
contiene "Botón para empezar la recepción" "$TMP/pag.html" "Empezar: Recepción de invitados"
r=$(post "r=vivo&t=$TV" --data-urlencode "accion=siguiente")
check "Empezar siguiente redirige" "302" "${r%% *}"
check "Recepción empezó" "1" "$(sql "SELECT count(*) FROM cronograma WHERE evento_id=$EV AND inicio_real IS NOT NULL")"
get "r=vivo&t=$TV" > /dev/null
contiene "Ahora: recepción" "$TMP/pag.html" "Recepción de invitados"
contiene "Sigue: ceremonia" "$TMP/pag.html" "Empezar: Ceremonia"
post "r=vivo&t=$TV" --data-urlencode "accion=siguiente" > /dev/null
check "Avanza al segundo bloque" "Ceremonia" "$(sql "SELECT actividad FROM cronograma WHERE evento_id=$EV AND inicio_real IS NOT NULL AND fin_real IS NULL")"
check "La recepción quedó terminada" "1" "$(sql "SELECT count(*) FROM cronograma WHERE evento_id=$EV AND fin_real IS NOT NULL")"
check "JSON del modo en vivo" "200" "$(get "r=vivo&t=$TV&json=1")"
contiene "JSON con el bloque actual" "$TMP/pag.html" '"actividad":"Ceremonia"'
contiene "JSON con el atraso" "$TMP/pag.html" '"atraso":'
VER=$(grep -o '"version":"[a-f0-9]*"' "$TMP/pag.html" | cut -d'"' -f4)
r=$(post "r=vivo&t=$TV" --data-urlencode "accion=siguiente" --data-urlencode "version=viejo")
get "r=vivo&t=$TV" > /dev/null
contiene "Si otro avanzó, no se avanza dos veces" "$TMP/pag.html" "Otra persona acaba de actualizar"
post "r=vivo&t=$TV" --data-urlencode "accion=deshacer" --data-urlencode "version=$VER" > /dev/null
check "Deshacer vuelve a la recepción" "Recepción de invitados" "$(sql "SELECT actividad FROM cronograma WHERE evento_id=$EV AND inicio_real IS NOT NULL AND fin_real IS NULL")"
check "Enlace de vivo falso: 404" "404" "$(get "r=vivo&t=abcdef0123456789abcdef0123456789")"
JAR=$JAR_A

echo "3. Personal: asignar, sugerencia de meseros y confirmar desde staff"
check "Personal abre" "200" "$(get "r=personal")"
contiene "Estado vacío de personal" "$TMP/pag.html" "Todavía no tienes personal"
r=$(post "r=personal" --data-urlencode "accion=crear" --data-urlencode "nombre=Carlos Mesero" --data-urlencode "rol=mesero" \
  --data-urlencode "pago_evento=35,50" --data-urlencode "telefono=0981111111")
P1=$(sql "SELECT id FROM personal WHERE negocio_id=$NID AND nombre='Carlos Mesero'")
check "Persona creada con tarifa" "35.50" "$(sql "SELECT pago_evento FROM personal WHERE id=$P1")"
post "r=personal" --data-urlencode "accion=crear" --data-urlencode "nombre=María Capitana" --data-urlencode "rol=capitan" --data-urlencode "pago_evento=50" > /dev/null
post "r=personal" --data-urlencode "accion=crear" --data-urlencode "nombre=Luis Libre" --data-urlencode "rol=mesero" --data-urlencode "pago_evento=30" > /dev/null
post "r=personal" --data-urlencode "accion=crear" --data-urlencode "nombre=X" > /dev/null
get "r=personal" > /dev/null
contiene "Nombre muy corto: error claro" "$TMP/pag.html" "Escribe el nombre completo"
check "Ficha de la persona" "200" "$(get "r=personal&id=$P1")"
contiene "Enlace staff en la ficha" "$TMP/pag.html" "r=staff&amp;t="
contiene "WhatsApp con su enlace" "$TMP/pag.html" "wa.me/593981111111"
get "r=evento_dia&id=$EV&ver=personal" > /dev/null
contiene "Aviso amarillo: faltan meseros" "$TMP/pag.html" "dia-aviso-amarillo"
contiene "Sugiere 10 meseros para 150 invitados" "$TMP/pag.html" "se sugieren <strong>10 meseros</strong>"
post_desde "r=evento_dia&id=$EV&ver=personal" "r=evento_dia&id=$EV" --data-urlencode "accion=asignar" --data-urlencode "personal_id=$P1" --data-urlencode "ver=personal" > /dev/null
check "Asignado con su tarifa" "35.50" "$(sql "SELECT pago FROM evento_personal WHERE evento_id=$EV AND personal_id=$P1")"
check "Entra 2 horas antes" "16:00:00" "$(sql "SELECT hora_entrada FROM evento_personal WHERE evento_id=$EV AND personal_id=$P1")"
post_desde "r=evento_dia&id=$EV&ver=personal" "r=evento_dia&id=$EV" --data-urlencode "accion=asignar" --data-urlencode "personal_id=$P1" --data-urlencode "ver=personal" > /dev/null
check "Sin duplicados" "1" "$(sql "SELECT count(*) FROM evento_personal WHERE evento_id=$EV AND personal_id=$P1")"
get "r=evento_dia&id=$EV&ver=personal" > /dev/null
contiene "Mensaje: ya está asignado" "$TMP/pag.html" "ya está asignado a este evento"
contiene "WhatsApp para pedir confirmación con su enlace" "$TMP/pag.html" "r%3Dstaff%26t%3D"
post_desde "r=evento_dia&id=$EV&ver=personal" "r=evento_dia&id=$EV" --data-urlencode "accion=meseros_auto" --data-urlencode "ver=personal" > /dev/null
check "Asignar meseros que faltan (solo hay 1 libre)" "2" "$(sql "SELECT count(*) FROM evento_personal WHERE evento_id=$EV")"
A1=$(sql "SELECT id FROM evento_personal WHERE evento_id=$EV AND personal_id=$P1")
post_desde "r=evento_dia&id=$EV&ver=personal" "r=evento_dia&id=$EV" --data-urlencode "accion=marcar" --data-urlencode "asig_id=$A1" \
  --data-urlencode "campo=pagado" --data-urlencode "valor=1" --data-urlencode "ver=personal" > /dev/null
check "Marcar pagado" "t" "$(sql "SELECT pagado FROM evento_personal WHERE id=$A1")"
get "r=evento_dia&id=$EV&ver=personal" > /dev/null
contiene "Total a pagar al personal" "$TMP/pag.html" "65,50"
TS=$(sql "SELECT token FROM personal WHERE id=$P1")
JAR="$TMP/jar_staff"; rm -f "$JAR"
check "Staff abre sin sesión" "200" "$(get "r=staff&t=$TS")"
contiene "Saluda a la persona" "$TMP/pag.html" "Hola, Carlos"
contiene "Muestra su evento" "$TMP/pag.html" "Boda de prueba"
contiene "Botón para confirmar" "$TMP/pag.html" "Sí, voy"
contiene "Mapa del lugar" "$TMP/pag.html" "google.com/maps"
post "r=staff&t=$TS" --data-urlencode "asig_id=$A1" --data-urlencode "respuesta=si" > /dev/null
check "Confirmó desde staff" "t" "$(sql "SELECT confirmado FROM evento_personal WHERE id=$A1")"
get "r=staff&t=$TS" > /dev/null
contiene "Staff muestra la confirmación" "$TMP/pag.html" "Confirmaste que vas"
A2=$(sql "SELECT id FROM evento_personal WHERE evento_id=$EV AND personal_id<>$P1 LIMIT 1")
post "r=staff&t=$TS" --data-urlencode "asig_id=$A2" --data-urlencode "respuesta=si" > /dev/null
check "No confirma por otra persona" "f" "$(sql "SELECT confirmado FROM evento_personal WHERE id=$A2")"
check "Staff con enlace falso: 404" "404" "$(get "r=staff&t=0000000000000000000000000000000000000000000000ff")"
JAR=$JAR_A

echo "4. Proveedores y mobiliario"
check "Proveedores abre" "200" "$(get "r=proveedores")"
post "r=proveedores" --data-urlencode "accion=crear" --data-urlencode "nombre=DJ Fiesta Total" --data-urlencode "servicio=musica" --data-urlencode "telefono=0993334444" > /dev/null
PR=$(sql "SELECT id FROM proveedores WHERE negocio_id=$NID AND nombre='DJ Fiesta Total'")
check "Proveedor creado" "musica" "$(sql "SELECT servicio FROM proveedores WHERE id=$PR")"
post_desde "r=evento_dia&id=$EV&ver=proveedores" "r=evento_dia&id=$EV" --data-urlencode "accion=prov_agregar" --data-urlencode "origen_prov=lista" \
  --data-urlencode "proveedor_id=$PR" --data-urlencode "hora_llegada=15:00" --data-urlencode "ver=proveedores" > /dev/null
post_desde "r=evento_dia&id=$EV&ver=proveedores" "r=evento_dia&id=$EV" --data-urlencode "accion=prov_agregar" --data-urlencode "origen_prov=cliente" \
  --data-urlencode "nombre=Florería de la tía" --data-urlencode "servicio=decoracion" --data-urlencode "hora_llegada=13:00" --data-urlencode "ver=proveedores" > /dev/null
check "Dos proveedores en el evento" "2" "$(sql "SELECT count(*) FROM evento_proveedores WHERE evento_id=$EV")"
check "El del cliente queda marcado" "t" "$(sql "SELECT lo_trae_cliente FROM evento_proveedores WHERE evento_id=$EV AND nombre='Florería de la tía'")"
get "r=evento_dia&id=$EV&ver=proveedores" > /dev/null
contiene "WhatsApp al proveedor con hora de llegada" "$TMP/pag.html" "wa.me/593993334444"
contiene "Hora de llegada visible" "$TMP/pag.html" "15:00"
check "Mobiliario abre" "200" "$(get "r=mobiliario")"
contiene "Inventario de ejemplo" "$TMP/pag.html" "Silla Tiffany"
SILLA=$(sql "SELECT id FROM mobiliario WHERE negocio_id=$NID AND nombre='Silla Tiffany'")
COPA=$(sql "SELECT id FROM mobiliario WHERE negocio_id=$NID AND nombre='Copa de vino'")
post_desde "r=evento_dia&id=$EV&ver=mobiliario" "r=evento_dia&id=$EV" --data-urlencode "accion=mobiliario" --data-urlencode "cant[$SILLA]=200" \
  --data-urlencode "cant[$COPA]=150" --data-urlencode "ver=mobiliario" > /dev/null
check "Mobiliario reservado" "200" "$(sql "SELECT cantidad FROM evento_mobiliario WHERE evento_id=$EV AND mobiliario_id=$SILLA")"
EV2=$(crear_evento_sql "$FECHA" consulta)   # mismo día, otro horario: se confirma por SQL
sql "UPDATE eventos SET estado='confirmado' WHERE id=$EV2" > /dev/null
post_desde "r=evento_dia&id=$EV2&ver=mobiliario" "r=evento_dia&id=$EV2" --data-urlencode "accion=mobiliario" --data-urlencode "cant[$SILLA]=100" --data-urlencode "ver=mobiliario" > /dev/null
get "r=evento_dia&id=$EV2&ver=mobiliario" > /dev/null
contiene "Otro evento el mismo día: avisa que faltan 50" "$TMP/pag.html" "faltan 50 Silla Tiffany"
contiene "Libres ese día descuenta el otro evento" "$TMP/pag.html" "200 en otro evento"
EVP=$(crear_evento_sql "$FECHA2" realizado)
post_desde "r=evento_dia&id=$EVP&ver=mobiliario" "r=evento_dia&id=$EVP" --data-urlencode "accion=mobiliario" --data-urlencode "cant[$COPA]=100" --data-urlencode "ver=mobiliario" > /dev/null
post_desde "r=evento_dia&id=$EVP&ver=mobiliario" "r=evento_dia&id=$EVP" --data-urlencode "accion=danados" --data-urlencode "danados[$COPA]=5" --data-urlencode "ver=mobiliario" > /dev/null
get "r=evento_dia&id=$EVP&ver=mobiliario" > /dev/null
contiene "Daños: 5 copas x \$4 = \$20" "$TMP/pag.html" '\$20,00'
contiene "Enlace a Pagos para retener de la garantía" "$TMP/pag.html" "r=evento_pagos&amp;id=$EVP"
check "Montaje y menú" "200" "$(get "r=evento_dia&id=$EV&ver=detalles")"
contiene "Menú elegido" "$TMP/pag.html" "Lomo fino"
contiene "Enlace para editarlo en el resumen" "$TMP/pag.html" "r=evento&amp;id=$EV"

echo "5. Boda en vivo"
check "Sección boda en vivo" "200" "$(get "r=evento_dia&id=$EV&ver=vivo")"
contiene "Botón pedir a QF Producciones" "$TMP/pag.html" "Pedir transmisión a QF Producciones"
r=$(post_desde "r=evento_dia&id=$EV&ver=vivo" "r=evento_dia&id=$EV" --data-urlencode "accion=pedir_transmision" --data-urlencode "ver=vivo")
check "Pedir transmisión abre WhatsApp de QF" "302 https://wa.me/593983662181" "$(echo "$r" | cut -d'?' -f1)"
check "Queda solicitada" "solicitada" "$(sql "SELECT transmision_estado FROM eventos WHERE id=$EV")"
post_desde "r=evento_dia&id=$EV&ver=vivo" "r=evento_dia&id=$EV" --data-urlencode "accion=transmision" --data-urlencode "transmision_url=https://youtube.com/live/boda123" \
  --data-urlencode "transmision_estado=confirmada" --data-urlencode "ver=vivo" > /dev/null
check "Enlace de transmisión guardado" "https://youtube.com/live/boda123" "$(sql "SELECT transmision_url FROM eventos WHERE id=$EV")"

echo "6. Invitados, lista pegada y mesas"
check "Invitados abre" "200" "$(get "r=invitados&id=$EV")"
contiene "Lista vacía con ayuda" "$TMP/pag.html" "La lista está vacía"
contiene "Enlace del modo puerta" "$TMP/pag.html" "r=puerta&amp;t="
post_desde "r=invitados&id=$EV" "r=invitados&id=$EV" --data-urlencode "accion=agregar" --data-urlencode "nombre=Ana Pérez" --data-urlencode "pases=2" \
  --data-urlencode "telefono=0991234567" --data-urlencode "grupo=Familia de la novia" > /dev/null
LISTA=$(printf 'Luis Mora; 0987654321; 3; Amigos\nTía Rosa\nCarlos Ruiz\t0998887777\t4\tTrabajo\nana perez')
post_desde "r=invitados&id=$EV" "r=invitados&id=$EV" --data-urlencode "accion=importar" --data-urlencode "lista=$LISTA" > /dev/null
check "4 invitados (no repite a Ana)" "4" "$(sql "SELECT count(*) FROM invitados WHERE evento_id=$EV")"
check "10 pases" "10" "$(sql "SELECT sum(pases) FROM invitados WHERE evento_id=$EV")"
get "r=invitados&id=$EV" > /dev/null
contiene "Avisa que uno ya estaba" "$TMP/pag.html" "ya estaban en la lista"
contiene "Botón Invitar por WhatsApp" "$TMP/pag.html" "Invitar"
contiene "Agrupa por grupo" "$TMP/pag.html" "Familia de la novia"
ANA=$(sql "SELECT id FROM invitados WHERE evento_id=$EV AND nombre='Ana Pérez'")
TANA=$(sql "SELECT token FROM invitados WHERE id=$ANA")
r=$(post_desde "r=invitados&id=$EV" "r=invitados&id=$EV" --data-urlencode "accion=enviar_wa" --data-urlencode "invitado_id=$ANA")
check "Invitar abre WhatsApp de Ana" "302 https://wa.me/593991234567" "$(echo "$r" | cut -d'?' -f1)"
check "Queda marcada como enviada" "1" "$(sql "SELECT count(*) FROM invitados WHERE id=$ANA AND invitacion_enviada IS NOT NULL")"
post_desde "r=invitados&id=$EV&ver=mesas" "r=invitados&id=$EV" --data-urlencode "accion=mesas_crear" --data-urlencode "cantidad=3" --data-urlencode "puestos=4" --data-urlencode "ver=mesas" > /dev/null
check "3 mesas creadas" "3" "$(sql "SELECT count(*) FROM mesas WHERE evento_id=$EV")"
M1=$(sql "SELECT id FROM mesas WHERE evento_id=$EV ORDER BY orden LIMIT 1")
CARLOS=$(sql "SELECT id FROM invitados WHERE evento_id=$EV AND nombre='Carlos Ruiz'")
post_desde "r=invitados&id=$EV&ver=mesas" "r=invitados&id=$EV" --data-urlencode "accion=mesa" --data-urlencode "invitado_id=$ANA" --data-urlencode "mesa_id=$M1" --data-urlencode "ver=mesas" > /dev/null
post_desde "r=invitados&id=$EV&ver=mesas" "r=invitados&id=$EV" --data-urlencode "accion=mesa" --data-urlencode "invitado_id=$CARLOS" --data-urlencode "mesa_id=$M1" --data-urlencode "ver=mesas" > /dev/null
check "Ana y Carlos en la mesa 1" "2" "$(sql "SELECT count(*) FROM invitados WHERE mesa_id=$M1")"
get "r=invitados&id=$EV&ver=mesas" > /dev/null
contiene "Avisa que la mesa se pasa" "$TMP/pag.html" "la mesa se pasa: 6 personas para 4 puestos"
contiene "Mesa marcada en rojo" "$TMP/pag.html" "dia-mesa-pasa"
post_desde "r=invitados&id=$EV&ver=mesas" "r=invitados&id=$EV" --data-urlencode "accion=mesa_editar" --data-urlencode "mesa_id=$M1" \
  --data-urlencode "nombre=Mesa de honor" --data-urlencode "capacidad=8" --data-urlencode "ver=mesas" > /dev/null
check "Renombrar mesa" "Mesa de honor" "$(sql "SELECT nombre FROM mesas WHERE id=$M1")"
check "CSV para Excel" "200" "$(curl -s -b "$JAR" -o "$TMP/inv.csv" -w '%{http_code}' "$BASE/?r=invitados&id=$EV&csv=1")"
check "CSV con BOM" "efbbbf" "$(head -c 3 "$TMP/inv.csv" | od -An -tx1 | tr -d ' \n')"
contiene "CSV separado con ;" "$TMP/inv.csv" "Nombre;Celular;Grupo;Pases"
contiene "CSV con la mesa" "$TMP/inv.csv" "Mesa de honor"

echo "7. Invitación pública, confirmar y QR"
JAR="$TMP/jar_invitado"; rm -f "$JAR"
check "Invitación abre sin sesión" "200" "$(get "r=invitacion&t=$TANA")"
contiene "Nombre del evento" "$TMP/pag.html" "Boda de prueba"
contiene "Para quién es" "$TMP/pag.html" "Ana Pérez"
contiene "Fecha larga" "$TMP/pag.html" "de $(date -d "$FECHA" +%Y)"
contiene "Mapa del negocio" "$TMP/pag.html" "Cómo llegar"
contiene "Ver en vivo (transmisión)" "$TMP/pag.html" "youtube.com/live/boda123"
contiene "Pregunta cuántos van" "$TMP/pag.html" "¿Cuántos van?"
r=$(post "r=invitacion&t=$TANA" --data-urlencode "respuesta=si" --data-urlencode "cuantos=3")
get "r=invitacion&t=$TANA" > /dev/null
contiene "No confirma más que sus pases" "$TMP/pag.html" "para 2 personas"
post "r=invitacion&t=$TANA" --data-urlencode "respuesta=si" --data-urlencode "cuantos=2" --data-urlencode "restricciones=Vegetariana" > /dev/null
check "Ana confirmó 2" "confirmado 2" "$(sql "SELECT estado || ' ' || confirmados FROM invitados WHERE id=$ANA")"
get "r=invitacion&t=$TANA" > /dev/null
contiene "Después muestra el QR" "$TMP/pag.html" "<svg"
contiene "Muestra este código en la entrada" "$TMP/pag.html" "Muestra este código en la entrada"
check "QR en SVG por la misma ruta" "200" "$(curl -s -o "$TMP/qr.svg" -w '%{http_code}' "$BASE/?r=invitacion&t=$TANA&qr=1")"
check "Tipo de archivo del QR" "image/svg+xml; charset=utf-8" "$(curl -s -o /dev/null -w '%{content_type}' "$BASE/?r=invitacion&t=$TANA&qr=1")"
check "SVG bien formado" "ok" "$(php -r 'echo simplexml_load_file($argv[1]) !== false ? "ok" : "malo";' "$TMP/qr.svg")"
if python3 -c "import cv2" 2>/dev/null; then
  python3 -I - "$TMP/qr.svg" "$TMP/qr.png" <<'PY' 2>/dev/null
import sys, re
svg = open(sys.argv[1]).read()
vb = int(re.search(r'viewBox="0 0 (\d+)', svg).group(1))
from PIL import Image, ImageDraw
k = 8; img = Image.new('L', (vb*k, vb*k), 255); d = ImageDraw.Draw(img)
for x, y, w in re.findall(r'M(\d+) (\d+)h(\d+)', svg):
    x, y, w = int(x), int(y), int(w); d.rectangle([x*k, y*k, (x+w)*k-1, (y+1)*k-1], fill=0)
img.save(sys.argv[2])
PY
  QRTXT=$(python3 -I -c "import cv2,sys; print(cv2.QRCodeDetector().detectAndDecode(cv2.imread(sys.argv[1]))[0])" "$TMP/qr.png" 2>/dev/null)
  check "El QR se lee y lleva al ingreso del invitado" "$BASE/?r=ingreso&t=$TANA" "$QRTXT"
fi
ROSA=$(sql "SELECT token FROM invitados WHERE evento_id=$EV AND nombre='Tía Rosa'")
post "r=invitacion&t=$ROSA" --data-urlencode "respuesta=no" > /dev/null
check "No podré asistir" "no_asiste" "$(sql "SELECT estado FROM invitados WHERE token='$ROSA'")"
check "Invitación falsa: 404" "404" "$(get "r=invitacion&t=0123456789abcdef0123456789abcdef")"

echo "8. Ingreso por QR: sin sesión de puerta muestra el QR"
check "Ingreso sin permiso abre" "200" "$(get "r=ingreso&t=$TANA")"
contiene "Muestra el QR al invitado" "$TMP/pag.html" "Muestra este código en la entrada"
check "Sin permiso NO registra el ingreso" "0" "$(sql "SELECT ingresaron FROM invitados WHERE id=$ANA")"

echo "9. Modo puerta + ingreso por QR con sesión de puerta"
TP=$(sql "SELECT token_puerta FROM eventos WHERE id=$EV")
JAR="$TMP/jar_puerta"; rm -f "$JAR"
check "Puerta abre sin sesión" "200" "$(get "r=puerta&t=$TP")"
contiene "Contador ingresados/esperados" "$TMP/pag.html" "data-dia-ingresaron"
check "Buscar en la puerta (sin tildes)" "200" "$(get "r=puerta&t=$TP&q=tia%20rosa")"
contiene "Encuentra a Tía Rosa" "$TMP/pag.html" "Tía Rosa"
check "JSON de la puerta" "200" "$(get "r=puerta&t=$TP&json=1")"
contiene "JSON con ingresaron" "$TMP/pag.html" '"ingresaron":0'
check "Escanear el QR con sesión de puerta" "200" "$(get "r=ingreso&t=$TANA")"
contiene "Pregunta cuántos entran (tiene 2 pases)" "$TMP/pag.html" "¿Cuántos entran ahora?"
r=$(post "r=ingreso&t=$TANA" --data-urlencode "cuantos=2")
get "r=ingreso&t=$TANA" > /dev/null
contiene "Pantalla verde" "$TMP/pag.html" "dia-verde"
contiene "Con la mesa" "$TMP/pag.html" "Mesa de honor"
check "Ingresaron 2" "2" "$(sql "SELECT ingresaron FROM invitados WHERE id=$ANA")"
get "r=ingreso&t=$TANA" > /dev/null
contiene "Escanear otra vez: pantalla roja" "$TMP/pag.html" "dia-rojo"
contiene "Avisa que ya ingresó completo" "$TMP/pag.html" "Ya ingresó completo"
check "No pasa de los pases" "2" "$(sql "SELECT ingresaron FROM invitados WHERE id=$ANA")"
LUIS=$(sql "SELECT id FROM invitados WHERE evento_id=$EV AND nombre='Luis Mora'")
r=$(post "r=puerta&t=$TP" --data-urlencode "invitado_id=$LUIS" --data-urlencode "cuantos=2" --data-urlencode "q=luis")
get "r=puerta&t=$TP&q=luis" > /dev/null
contiene "Marcar ingreso desde la puerta" "$TMP/pag.html" "Entraron 2"
check "Luis: 2 de 3" "2" "$(sql "SELECT ingresaron FROM invitados WHERE id=$LUIS")"
post "r=puerta&t=$TP" --data-urlencode "invitado_id=$LUIS" --data-urlencode "cuantos=5" > /dev/null
check "Desde la puerta tampoco pasa de los pases" "2" "$(sql "SELECT ingresaron FROM invitados WHERE id=$LUIS")"
get "r=puerta&t=$TP" > /dev/null
contiene "Mensaje: solo le queda 1" "$TMP/pag.html" "solo tiene 1 pase"
OTRO=$(sql "INSERT INTO invitados (evento_id, nombre) VALUES ($EV2, 'De otro evento') RETURNING id" | head -1)
post "r=puerta&t=$TP" --data-urlencode "invitado_id=$OTRO" --data-urlencode "cuantos=1" > /dev/null
check "La puerta no registra invitados de otro evento" "0" "$(sql "SELECT ingresaron FROM invitados WHERE id=$OTRO")"
check "Puerta falsa: 404" "404" "$(get "r=puerta&t=abcdef0123456789abcdef0123456789")"
JAR=$JAR_A
get "r=invitados&id=$EV" > /dev/null
contiene "Contador de ingresaron en la lista" "$TMP/pag.html" "Ingresaron"

echo "10. Portal de invitados de los novios"
TC=$(sql "SELECT token_cliente FROM eventos WHERE id=$EV")
JAR="$TMP/jar_novios"; rm -f "$JAR"
check "Portal de invitados abre sin sesión" "200" "$(get "r=portal_invitados&t=$TC")"
contiene "Con la lista" "$TMP/pag.html" "Carlos Ruiz"
contiene "Enlace de regreso al portal" "$TMP/pag.html" "r=portal&amp;t=$TC"
contiene "Color del negocio" "$TMP/pag.html" "--color-negocio"
post "r=portal_invitados&t=$TC" --data-urlencode "accion=agregar" --data-urlencode "nombre=Primo Juan" --data-urlencode "pases=2" > /dev/null
check "Los novios agregan invitados" "1" "$(sql "SELECT count(*) FROM invitados WHERE evento_id=$EV AND nombre='Primo Juan'")"
post "r=portal_invitados&t=$TC" --data-urlencode "accion=importar" --data-urlencode "lista=$(printf 'Amiga Sofía, 2\nCompadre Pepe')" > /dev/null
check "Los novios pegan su lista" "7" "$(sql "SELECT count(*) FROM invitados WHERE evento_id=$EV")"
PJ=$(sql "SELECT id FROM invitados WHERE evento_id=$EV AND nombre='Primo Juan'")
post "r=portal_invitados&t=$TC" --data-urlencode "accion=editar" --data-urlencode "invitado_id=$PJ" --data-urlencode "nombre=Primo Juan" \
  --data-urlencode "pases=2" --data-urlencode "estado=confirmado" --data-urlencode "confirmados=2" > /dev/null
check "Los novios no cambian la respuesta del invitado" "pendiente" "$(sql "SELECT estado FROM invitados WHERE id=$PJ")"
post "r=portal_invitados&t=$TC" --data-urlencode "accion=borrar" --data-urlencode "invitado_id=$OTRO" > /dev/null
check "No borran invitados de otro evento" "1" "$(sql "SELECT count(*) FROM invitados WHERE id=$OTRO")"
r=$(post "r=portal_invitados&t=$TC" --data-urlencode "accion=enviar_wa" --data-urlencode "invitado_id=$PJ")
check "Compartir invitación por WhatsApp" "302 https://wa.me/" "$(echo "$r" | cut -d'?' -f1)"
get "r=portal_invitados&t=$TC&ver=mesas" > /dev/null
contiene "Ven las mesas" "$TMP/pag.html" "Mesa de honor"
check "Portal con token falso: 404" "404" "$(get "r=portal_invitados&t=0123456789abcdef0123456789abcdef0123456789abcdef")"
JAR=$JAR_A

echo "11. PDF"
for tipo in hoja invitados mesas cronograma; do
  check "PDF $tipo: 200" "200" "$(curl -s -b "$JAR" -o "$TMP/$tipo.pdf" -w '%{http_code}' "$BASE/?r=pdf&tipo=$tipo&id=$EV")"
  check "PDF $tipo: application/pdf" "application/pdf" "$(curl -s -b "$JAR" -o /dev/null -w '%{content_type}' "$BASE/?r=pdf&tipo=$tipo&id=$EV")"
  check "PDF $tipo: es un PDF" "%PDF" "$(head -c 4 "$TMP/$tipo.pdf")"
done
if command -v pdftotext > /dev/null; then
  pdftotext "$TMP/hoja.pdf" "$TMP/hoja.txt"
  contiene "Hoja: cronograma" "$TMP/hoja.txt" "Recepción de invitados"
  contiene "Hoja: personal" "$TMP/hoja.txt" "Carlos Mesero"
  contiene "Hoja: proveedores" "$TMP/hoja.txt" "DJ Fiesta Total"
  contiene "Hoja: mobiliario" "$TMP/hoja.txt" "Silla Tiffany"
  contiene "Hoja: menú" "$TMP/hoja.txt" "Lomo fino"
  contiene "Hoja: restricciones de comida" "$TMP/hoja.txt" "Vegetariana"
  no_contiene "Hoja: sin precios del cliente" "$TMP/hoja.txt" "Total a pagar"
  pdftotext "$TMP/invitados.pdf" "$TMP/inv.txt"
  contiene "PDF invitados por grupo" "$TMP/inv.txt" "Familia de la novia"
  pdftotext "$TMP/mesas.pdf" "$TMP/mesas.txt"
  contiene "PDF mesas: mesa de honor" "$TMP/mesas.txt" "Mesa de honor"
fi
check "PDF de evento ajeno: no se ve" "0" "$(curl -s -b "$JAR" "$BASE/?r=pdf&tipo=hoja&id=999999" | head -c 4 | grep -c '%PDF')"

echo "12. Plan Salón pagado: los módulos del día del evento están apagados (Anexo B)"
registrar salon "Salón Candado $RANDOM"
pagar_plan salon
EVS=$(crear_evento_sql "$FECHA" separado)
check "Día del evento sin el módulo: 403" "403" "$(get "r=evento_dia&id=$EVS")"
contiene "Explica que no está en el plan" "$TMP/pag.html" "Este módulo no está en tu plan"
contiene "… con la cabecera del evento" "$TMP/pag.html" "evento-cabeza"
check "Invitados sin el módulo: 403" "403" "$(get "r=invitados&id=$EVS")"
contiene "Módulo apagado en Invitados" "$TMP/pag.html" "Invitados con QR"
check "Mobiliario sin el módulo: 403" "403" "$(get "r=mobiliario")"
contiene "Módulo apagado en Mobiliario" "$TMP/pag.html" "Este módulo no está en tu plan"
check "Personal es del día del evento: 403" "403" "$(get "r=personal")"
get "r=evento&id=$EVS" > /dev/null
no_contiene "Sin pestaña Día del evento" "$TMP/pag.html" "r=evento_dia&amp;id=$EVS"
no_contiene "Sin pestaña Invitados" "$TMP/pag.html" "r=invitados&amp;id=$EVS"
TPS=$(sql "SELECT token_puerta FROM eventos WHERE id=$EVS")
check "Puerta pública sin el módulo: no disponible (403)" "403" "$(get "r=puerta&t=$TPS")"
contiene "… dice no disponible" "$TMP/pag.html" "No disponible"
check "PDF hoja sin el módulo: no sale" "0" "$(curl -s -b "$JAR" "$BASE/?r=pdf&tipo=hoja&id=$EVS" | head -c 4 | grep -c '%PDF')"

echo "13. Otro negocio no ve nada"
registrar quinta "Quinta Intrusa $RANDOM"
check "Día del evento ajeno: 404" "404" "$(get "r=evento_dia&id=$EV")"
no_contiene "No ve el cronograma ajeno" "$TMP/pag.html" "Recepción de invitados"
check "Invitados ajenos: 404" "404" "$(get "r=invitados&id=$EV")"
no_contiene "No ve los invitados ajenos" "$TMP/pag.html" "Carlos Ruiz"
check "CSV ajeno: 404" "404" "$(curl -s -b "$JAR" -o "$TMP/ajeno.csv" -w '%{http_code}' "$BASE/?r=invitados&id=$EV&csv=1")"
check "Persona ajena: 404" "404" "$(get "r=personal&id=$P1")"
check "Proveedor ajeno: 404" "404" "$(get "r=proveedores&id=$PR")"
check "Mobiliario ajeno: 404" "404" "$(get "r=mobiliario&id=$SILLA")"
post_desde "r=personal" "r=evento_dia&id=$EV" --data-urlencode "accion=bloque_borrar" --data-urlencode "bloque_id=$(sql "SELECT id FROM cronograma WHERE evento_id=$EV LIMIT 1")" > /dev/null
check "No borra el cronograma ajeno" "11" "$(sql "SELECT count(*) FROM cronograma WHERE evento_id=$EV")"
post_desde "r=personal" "r=evento_dia&id=$EV" --data-urlencode "accion=asignar" --data-urlencode "personal_id=$P1" > /dev/null
check "No asigna en eventos ajenos" "2" "$(sql "SELECT count(*) FROM evento_personal WHERE evento_id=$EV")"
check "No ve el PDF ajeno" "0" "$(curl -s -b "$JAR" "$BASE/?r=pdf&tipo=hoja&id=$EV" | head -c 4 | grep -c '%PDF')"
check "El usuario del negocio intruso no registra ingresos ajenos" "200" "$(get "r=ingreso&t=$(sql "SELECT token FROM invitados WHERE id=$LUIS")")"
check "Ingreso ajeno no cuenta" "2" "$(sql "SELECT ingresaron FROM invitados WHERE id=$LUIS")"
