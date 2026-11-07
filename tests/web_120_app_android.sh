# App de Android en Google Play (docs/GOOGLE_PLAY.md): /.well-known/assetlinks.json, privacidad pública con el borrado de la cuenta,
# modo app (?app=play deja la cookie te_app): sin precios del software, sin «Pedir cotización», sin «Pagar», sin «Renovar» y sin
# WhatsApp de ventas; lo que el local cobra a SUS clientes (cuotas, portal) sigue igual. Cuenta del revisor de Google
# (deploy/demo_google.php crear | borrar) y deploy/app_android.sh.
planes_20_45
DBENV="TURENTA_DB_NAME=$DB TURENTA_DB_USER=turenta TURENTA_DB_PASS=$PGPASSWORD"
HUELLA="39:0C:08:10:D4:40:C1:71:C4:30:C8:D0:F1:93:7B:8F:E4:37:03:25:27:2E:B2:F6:2E:4E:A6:5E:01:98:40:DC"
HUELLA_G="6D:FA:23:74:41:FF:85:59:B6:7F:3C:79:69:5E:97:98:4A:E2:DD:A8:2C:40:37:63:B3:09:D6:1D:75:33:6F:CF"
app_play() { get "r=inicio&app=play" > /dev/null; }
app_web() { get "r=inicio&app=web" > /dev/null; }

echo "1. Archivos públicos de la app"
C=$(curl -s -D "$TMP/cab.txt" -o "$TMP/pag.html" -w "%{http_code}" "$BASE/.well-known/assetlinks.json")
check "assetlinks.json responde 200" "200" "$C"
contiene "… como JSON" "$TMP/cab.txt" "application/json"
contiene "… con el paquete de la app" "$TMP/pag.html" '"package_name": "com.tukansoftware.turenta"'
contiene "… con la huella de la llave de subida" "$TMP/pag.html" "$HUELLA"
contiene "… para abrir a pantalla completa" "$TMP/pag.html" "delegate_permission/common.handle_all_urls"
no_contiene "… sin cookie de sesión" "$TMP/cab.txt" "Set-Cookie: turenta"
rm -f "$JAR"
check "privacidad.php responde 200 sin entrar" "200" "$(curl -s -o "$TMP/pag.html" -w "%{http_code}" "$BASE/privacidad.php")"
contiene "… es la política de privacidad" "$TMP/pag.html" "Política de privacidad de TuRenta"
contiene "… habla de la app de Google Play" "$TMP/pag.html" "Google Play"
contiene "… dice cómo borrar la cuenta (sección con ancla)" "$TMP/pag.html" 'id="borrar-cuenta"'
contiene "… con el correo para pedirlo" "$TMP/pag.html" "info@tukansoftware.com"
contiene "… y desde la app" "$TMP/pag.html" "Borrar mi cuenta"
contiene "… menciona la inteligencia artificial" "$TMP/pag.html" "Anthropic"
check "/?r=privacidad también" "200" "$(get "r=privacidad")"

echo "2. ?app=play deja la cookie de la app (y ?app=web la quita)"
rm -f "$JAR"
check "?app=play sin sesión manda a entrar" "302" "$(get "r=inicio&app=play")"
contiene "Cookie te_app guardada" "$JAR" "te_app"
get "r=login" > /dev/null
contiene "La pantalla de entrar sigue con Solicitar demo (no hay precios)" "$TMP/pag.html" "Solicitar demo"
get "r=login&app=web" > /dev/null
no_contiene "?app=web la quita" "$JAR" "te_app"

echo "3. Mi plan en la app: sin precios, sin cotizaciones, sin pagar ni WhatsApp de ventas"
registrar esencial "Local App Play $RANDOM"; pagar_plan esencial
sql "UPDATE negocios SET modulos_extra='paquetes' WHERE id=$NID" > /dev/null
check "En la web, Mi plan abre" "200" "$(get "r=planes")"
contiene "En la web: precio de su plan" "$TMP/pag.html" 'class="mp-precio"'
contiene "En la web: Pagar" "$TMP/pag.html" "Cuánto pagas"
contiene "En la web: Pedir cotización" "$TMP/pag.html" "Pedir cotización"
contiene "En la web: WhatsApp de ventas" "$TMP/pag.html" "wa.me/"
app_play
check "En la app, Mi plan abre" "200" "$(get "r=planes")"
contiene "La página sabe que es la app" "$TMP/pag.html" 'class="con-sesion en-app"'
contiene "Muestra el plan" "$TMP/pag.html" "Plan <strong>Básico</strong>"
contiene "Muestra los módulos a su elección" "$TMP/pag.html" "3 módulos a tu elección"
contiene "Puede elegir los módulos incluidos en su plan" "$TMP/pag.html" "Elegir este módulo"
no_contiene "Sin el precio de su plan" "$TMP/pag.html" 'class="mp-precio"'
no_contiene "Sin ningún monto en dólares" "$TMP/pag.html" '\$[0-9]'
no_contiene "Sin «Pedir cotización»" "$TMP/pag.html" "Pedir cotización"
no_contiene "Sin «Pídalo y le cotizamos»" "$TMP/pag.html" "cotizamos"
no_contiene "Sin «Precio por cotización»" "$TMP/pag.html" "Precio por cotización"
no_contiene "Sin «Cuánto pagas»" "$TMP/pag.html" "Cuánto pagas"
no_contiene "Sin botón Pagar" "$TMP/pag.html" ">Pagar<"
no_contiene "Sin otros planes" "$TMP/pag.html" 'id="mp-t-planes"'
no_contiene "Sin pagos hechos" "$TMP/pag.html" "Pagos hechos"
no_contiene "Sin WhatsApp de ventas" "$TMP/pag.html" "wa.me/"
no_contiene "Sin transferencia ni DeUna para el plan" "$TMP/pag.html" "DeUna"
get "r=mas" > /dev/null
no_contiene "Más: sin «lo que pagas»" "$TMP/pag.html" "lo que pagas"

echo "4. En la app, el servidor no acepta cotizaciones: solo elegir los módulos incluidos"
ANTES=$(sql "SELECT count(*) FROM modulos_solicitudes WHERE negocio_id=$NID")
post "r=planes" --data-urlencode "accion=pedir_plan" --data-urlencode "plan=quinta" > /dev/null
check "Pedir otro plan desde la app: no se guarda" "$ANTES" "$(sql "SELECT count(*) FROM modulos_solicitudes WHERE negocio_id=$NID")"
get "r=planes" > /dev/null
contiene "… y lo explica" "$TMP/pag.html" "Desde la app solo se eligen los módulos incluidos en tu plan"
post "r=planes" --data-urlencode "accion=pedir" --data-urlencode "modulo=asistente_ia" > /dev/null
check "Pedir el Asistente IA (fuera del plan) desde la app: no se guarda" "$ANTES" "$(sql "SELECT count(*) FROM modulos_solicitudes WHERE negocio_id=$NID")"
post "r=planes" --data-urlencode "accion=pedir" --data-urlencode "modulo=contratos" > /dev/null
check "Elegir un módulo incluido sí se guarda" "$((ANTES+1))" "$(sql "SELECT count(*) FROM modulos_solicitudes WHERE negocio_id=$NID")"
get "r=planes" > /dev/null
contiene "… y queda como pedido" "$TMP/pag.html" "Pedido enviado"

echo "5. Módulo apagado en la app: sin cotización"
check "Abre Invitados (apagado)" "403" "$(get "r=invitados")"
contiene "Dice que no está en su plan" "$TMP/pag.html" "Este módulo no está en tu plan"
no_contiene "Sin «Pedir cotización»" "$TMP/pag.html" "Pedir cotización"
no_contiene "Sin «Tukán te escribe con el valor»" "$TMP/pag.html" "con el valor"
app_web; get "r=invitados" > /dev/null
contiene "En la web sí: Pedir cotización" "$TMP/pag.html" "Pedir cotización"

echo "6. Límite de espacios y usuarios en la app: sin invitar a cambiar de plan"
app_play; get "r=espacios" > /dev/null
contiene "Dice el límite" "$TMP/pag.html" "permite 1 espacio activo"
no_contiene "Sin «cambia de plan»" "$TMP/pag.html" "cambia de plan"
app_web; get "r=espacios" > /dev/null
contiene "En la web sí" "$TMP/pag.html" "cambia de plan"

echo "7. Plan por vencer: sin «Renovar» en la app"
sql "UPDATE negocios SET activo_hasta=CURRENT_DATE + 3 WHERE id=$NID" > /dev/null
get "r=inicio" > /dev/null
contiene "En la web: Renovar" "$TMP/pag.html" ">Renovar<"
app_play; get "r=inicio" > /dev/null
contiene "En la app: avisa cuándo vence" "$TMP/pag.html" "Tu plan vence en 3 días"
no_contiene "… sin Renovar" "$TMP/pag.html" ">Renovar<"

echo "8. Demo terminada, plan vencido y cuenta pausada en la app: sin enlaces para pagar afuera"
sql "UPDATE negocios SET estado='prueba', prueba_hasta=CURRENT_DATE - 1 WHERE id=$NID" > /dev/null
get "r=prueba_terminada" > /dev/null
contiene "Demo terminada" "$TMP/pag.html" "Tu demo terminó"
no_contiene "… sin WhatsApp" "$TMP/pag.html" "wa.me/"
no_contiene "… sin «contratar»" "$TMP/pag.html" "contratar"
no_contiene "… sin pedir cotización" "$TMP/pag.html" "cotización"
contiene "… sus datos están guardados" "$TMP/pag.html" "Todo está guardado"
sql "UPDATE negocios SET estado='activo', activo_hasta=CURRENT_DATE - 40 WHERE id=$NID" > /dev/null
get "r=prueba_terminada" > /dev/null
contiene "Plan vencido" "$TMP/pag.html" "Tu plan venció"
no_contiene "… sin Renuévalo" "$TMP/pag.html" "Renuévalo"
no_contiene "… sin WhatsApp" "$TMP/pag.html" "wa.me/"
sql "UPDATE negocios SET estado='suspendido', pausa_tipo='falta_pago', pausa_motivo='Pago pendiente' WHERE id=$NID" > /dev/null
get "r=prueba_terminada" > /dev/null
contiene "Cuenta pausada" "$TMP/pag.html" "Tu cuenta está pausada"
no_contiene "… sin WhatsApp" "$TMP/pag.html" "wa.me/"
app_web; get "r=prueba_terminada" > /dev/null
contiene "En la web: WhatsApp de Tukán" "$TMP/pag.html" "wa.me/"
sql "UPDATE negocios SET estado='activo', activo_hasta=CURRENT_DATE + 30, pausa_tipo=NULL, pausa_motivo=NULL WHERE id=$NID" > /dev/null

echo "9. Lo que el local cobra a sus clientes sigue igual en la app (servicios reales, no el software)"
registrar quinta "Quinta App Cobros $RANDOM"; pagar_plan quinta
EV=$(crear_evento_sql "$(date -d '+40 days' +%F)" separado)
sql "UPDATE eventos SET total=2000 WHERE id=$EV" > /dev/null
app_play
check "Pagos del evento abre en la app" "200" "$(get "r=evento_pagos&id=$EV")"
contiene "… con el registro de pagos del cliente" "$TMP/pag.html" "Registrar un pago recibido"

echo "10. Borrar mi cuenta desde la app (Google Play lo pide)"
check "Mi cuenta abre" "200" "$(get "r=mi_cuenta")"
contiene "Sección Borrar mi cuenta" "$TMP/pag.html" 'id="borrar-cuenta"'
contiene "… con el pedido por correo" "$TMP/pag.html" "mailto:info@tukansoftware.com?subject=Borrar%20mi%20cuenta%20de%20TuRenta"
contiene "… y el enlace a la privacidad" "$TMP/pag.html" "privacidad#borrar-cuenta"

echo "11. Cuenta del revisor de Google (deploy/demo_google.php)"
env $DBENV php deploy/demo_google.php > "$TMP/demo.txt" 2>&1; check "Sin acción muestra el uso" "1" "$?"
env $DBENV php deploy/demo_google.php crear > "$TMP/demo.txt" 2>&1; check "Crear termina bien" "0" "$?"
contiene "… muestra el usuario" "$TMP/demo.txt" "Usuario:    revisor.google@tukanec.com"
CLAVE=$(grep -o 'Demo-[0-9]\{6\}' "$TMP/demo.txt" | head -1)
check "… y la contraseña (Demo- y 6 números)" "1" "$(printf '%s' "$CLAVE" | grep -c '^Demo-[0-9]\{6\}$')"
no_contiene "… sin tildes (se lee por SSH)" "$TMP/demo.txt" "á"
entrar revisor.google@tukanec.com "$CLAVE"
app_play
check "El revisor entra" "200" "$(get "r=inicio")"
contiene "… al negocio de ejemplo" "$TMP/pag.html" "Quinta Ejemplo Google Play"
check "Ve los eventos de ejemplo" "200" "$(get "r=eventos")"
contiene "… la boda de ejemplo" "$TMP/pag.html" "Boda de ejemplo"
check "Mi plan abre (demo, sin precios)" "200" "$(get "r=planes")"
no_contiene "… sin montos" "$TMP/pag.html" '\$[0-9]'
post "r=mi_cuenta&accion=clave" --data-urlencode "actual=$CLAVE" --data-urlencode "nueva=OtraClave123" --data-urlencode "repetir=OtraClave123" > /dev/null
get "r=mi_cuenta" > /dev/null
contiene "No puede cambiar su contraseña" "$TMP/pag.html" "aquí no se cambia la contraseña"
entrar revisor.google@tukanec.com "$CLAVE"
check "… y la contraseña sigue sirviendo" "200" "$(get "r=inicio")"
contiene "… (entró)" "$TMP/pag.html" "Quinta Ejemplo Google Play"
env $DBENV php deploy/demo_google.php borrar > "$TMP/demo.txt" 2>&1; check "Borrar termina bien" "0" "$?"
check "No queda el revisor" "0" "$(sql "SELECT count(*) FROM usuarios WHERE email='revisor.google@tukanec.com'")"
check "No queda el negocio de ejemplo" "0" "$(sql "SELECT count(*) FROM negocios WHERE nombre='Quinta Ejemplo Google Play'")"

echo "12. deploy/app_android.sh (revisa assetlinks y privacidad; agrega la huella de Google)"
mkdir -p "$TMP/appandroid/config"; printf 'TURENTA_DB_NAME=x\nTELEGRAM_BOT_TOKEN=y\n' > "$TMP/appandroid/config/.env"
AA="env BASE_URL_PRUEBA=1 APP=$TMP/appandroid BASE_URL=$BASE HOST=127.0.0.1 bash deploy/app_android.sh"
$AA > "$TMP/aa.txt" 2>&1; check "Sin huella: revisa y dice OK" "0" "$?"
contiene "… OK" "$TMP/aa.txt" "OK: la app de Android ya puede verificar el sitio."
$AA "12:34" > "$TMP/aa.txt" 2>&1; check "Huella mal escrita: error" "1" "$?"
no_contiene "… y no cambia config/.env" "$TMP/appandroid/config/.env" "ANDROID_HUELLAS"
$AA "$(echo "$HUELLA_G" | tr 'A-F' 'a-f')" > "$TMP/aa.txt" 2>&1
contiene "Huella de Google: queda en config/.env (en mayúsculas)" "$TMP/appandroid/config/.env" "TURENTA_ANDROID_HUELLAS=$HUELLA_G"
contiene "… sin borrar lo demás" "$TMP/appandroid/config/.env" "TELEGRAM_BOT_TOKEN=y"
$AA "$HUELLA_G" > "$TMP/aa.txt" 2>&1
contiene "La misma huella otra vez: no la repite" "$TMP/aa.txt" "Esa huella ya estaba puesta"
check "… una sola línea" "1" "$(grep -c ANDROID_HUELLAS "$TMP/appandroid/config/.env")"
contiene "El instalador conserva la huella al actualizar" "deploy/instalar.sh" "TURENTA_ANDROID_HUELLAS="
