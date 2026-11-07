#!/usr/bin/env bash
# Pantallas: conector con el Panel Tukán Central (/tukan_central.php, protocolo v1).
# Lo corre tests/run_web.sh, que levanta el sitio con TUKAN_CENTRAL_ID / _SECRET / _URL de prueba.
# Además levanta aquí 3 sitios más en puertos libres: sin configurar (404), "solo central" y una copia para conectar_central.sh.
# Variables: BASE, DB, PGPASSWORD, TMP, TUKAN_CENTRAL_* (de run_web.sh)
planes_de_antes   # estas pruebas usan los planes de antes (Esencial $19, Salón $39, Quinta $69); los de $20 y $45: web_112_planes.sh

C95_ID=${TUKAN_CENTRAL_ID:-turenta-prueba}
C95_URL=${TUKAN_CENTRAL_URL:-http://central.prueba.local}
C95_PIDS=""
DBENV="TURENTA_DB_NAME=$DB TURENTA_DB_USER=turenta TURENTA_DB_PASS=$PGPASSWORD"
contiene_txt() { if echo "$2" | grep -q -- "$3"; then OK=$((OK+1)); echo "  OK    $1"; else FALLAS=$((FALLAS+1)); echo "  FALLA $1 — [$2]"; fi; }
no_contiene_txt() { if echo "$2" | grep -q -- "$3"; then FALLAS=$((FALLAS+1)); echo "  FALLA $1 — aparece [$3]"; else OK=$((OK+1)); echo "  OK    $1"; fi; }

# Token firmado como lo hace el panel central: c95_token tipo [clave=valor ...]
# iat y exp van como segundos desde ahora (iat=-100 exp=-40); _secreto=... firma con otro secreto
c95_token() {
  php -r '
  $t = time();
  $p = ["v" => 1, "tipo" => $argv[1], "inst" => getenv("C95_ID"), "email" => "dimitryfierro@gmail.com", "nombre" => "Dimitry Fierro",
        "iat" => $t, "exp" => $t + 60, "nonce" => bin2hex(random_bytes(16))];
  foreach (array_slice($argv, 2) as $kv) {
      [$k, $v] = explode("=", $kv, 2);
      $p[$k] = in_array($k, ["iat", "exp"], true) ? $t + (int) $v : ($k === "v" ? (int) $v : $v);
  }
  $secreto = $p["_secreto"] ?? getenv("TUKAN_CENTRAL_SECRET");
  unset($p["_secreto"]);
  $b = fn($s) => rtrim(strtr(base64_encode($s), "+/", "-_"), "=");
  $a = $b(json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  echo $a . "." . $b(hash_hmac("sha256", $a, $secreto, true));' -- "$@"
}
export C95_ID
# Pide /tukan_central.php: c95_pedir base accion token -> "codigo destino"; respuesta en $TMP/central.out
c95_pedir() {
  curl -s -b "$JAR" -c "$JAR" -o "$TMP/central.out" -w "%{http_code} %{redirect_url}" -H "CF-Connecting-IP: ${C95_IP:-198.51.100.10}" \
       "$1/tukan_central.php?accion=$2&t=$3"
}
# Valor del JSON de la última respuesta: c95_json negocios.total
c95_json() {
  php -r '$v = json_decode((string) file_get_contents($argv[1]), true);
  foreach (explode(".", $argv[2]) as $k) $v = is_array($v) ? ($v[$k] ?? null) : null;
  echo is_bool($v) ? ($v ? "true" : "false") : (is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (is_null($v) ? "null" : $v));' "$TMP/central.out" "$1"
}
c95_puerto() { php -r '$s = stream_socket_server("tcp://127.0.0.1:0"); $n = stream_socket_get_name($s, false); echo substr($n, strrpos($n, ":") + 1);'; }
c95_esperar() { for _ in $(seq 1 50); do curl -s -o /dev/null "$1/?r=login" && return 0; sleep 0.1; done; return 1; }
# Sitio de prueba extra (sin TUKAN_* del entorno): c95_servidor puerto log [VAR=valor ...]
c95_servidor() {
  local p=$1 log=$2; shift 2
  env -u TUKAN_CENTRAL_ID -u TUKAN_CENTRAL_SECRET -u TUKAN_CENTRAL_URL -u TUKAN_SOLO_CENTRAL $DBENV TURENTA_URL="http://127.0.0.1:$p" "$@" \
      php -S "127.0.0.1:$p" -t public tests/router.php > "$log" 2>&1 &
  C95_PIDS="$C95_PIDS $!"
}
trap 'kill $SERVIDOR $C95_PIDS 2>/dev/null' EXIT
limpiar_limite() { sql "DELETE FROM tukan_central_pedidos" > /dev/null; }

echo "1. Negocios de prueba para comparar con el panel"
registrar quinta "Quinta Central Uno $RANDOM"; N1=$NID; E1=$EMAIL
NOMBRE1=$(sql "SELECT nombre FROM negocios WHERE id=$N1")
sql "UPDATE negocios SET prueba_hasta=CURRENT_DATE + 2 WHERE id=$N1" > /dev/null
registrar salon "Salón Central Dos $RANDOM"; N2=$NID
sql "INSERT INTO suscripciones (negocio_id, plan_codigo, periodo, meses_pagados, meses_recibidos, monto, inicio, fin, metodo_pago)
     VALUES ($N2, 'salon', 'mensual', 1, 1, 39, CURRENT_DATE, CURRENT_DATE + 29, 'transferencia')" > /dev/null
sql "UPDATE negocios SET estado='activo', activo_hasta=CURRENT_DATE + 29 WHERE id=$N2" > /dev/null
registrar salon "Quinta Central Tres $RANDOM"; N3=$NID
NOMBRE3=$(sql "SELECT nombre FROM negocios WHERE id=$N3")
sql "UPDATE negocios SET prueba_hasta=CURRENT_DATE - 3 WHERE id=$N3" > /dev/null
sql "INSERT INTO clientes (negocio_id, nombre, telefono) VALUES ($N1, 'Cliente Muy Privado', '0981112233')" > /dev/null
check "Se crearon los 3" "3" "$(sql "SELECT count(*) FROM negocios WHERE id IN ($N1,$N2,$N3)")"

echo "2. Estado (JSON)"
limpiar_limite
JAR="$TMP/c95_estado"; rm -f "$JAR"
r=$(c95_pedir "$BASE" estado "$(c95_token estado)")
check "Token correcto: 200" "200 " "$r"
check "Es JSON" "application/json; charset=utf-8" "$(curl -s -o /dev/null -w '%{content_type}' "$BASE/tukan_central.php?accion=estado&t=$(c95_token estado)")"
check "ok = true" "true" "$(c95_json ok)"
check "protocolo = 1" "1" "$(c95_json protocolo)"
check "producto = turenta" "turenta" "$(c95_json producto)"
check "nombre = TuRenta" "TuRenta" "$(c95_json nombre)"
check "version = commit de git" "$(git rev-parse --short=7 HEAD)" "$(c95_json version)"
check "Claves del protocolo (+ las 3 del v2)" '["ok","protocolo","producto","nombre","version","generado","negocios","nuevos_30d","ingresos_mes","ingresos_mes_anterior","pruebas_vencen_7d","pagos_vencidos","extra","protocolo_max","solicitudes_abiertas","solicitudes_huella"]' \
      "$(php -r 'echo json_encode(array_keys(json_decode(file_get_contents($argv[1]), true)));' "$TMP/central.out")"
check "Claves de negocios" '["total","prueba","activos","por_vencer","vencidos","suspendidos"]' \
      "$(php -r 'echo json_encode(array_keys(json_decode(file_get_contents($argv[1]), true)["negocios"]));' "$TMP/central.out")"
check "Total de negocios = base" "$(sql "SELECT count(*) FROM negocios")" "$(c95_json negocios.total)"
contiene "La prueba que vence en 7 días aparece" "$TMP/central.out" "\"nombre\":\"$NOMBRE1\""
contiene "La prueba terminada aparece en pagos vencidos" "$TMP/central.out" "\"nombre\":\"$NOMBRE3\",\"desde\":\"$(date -d '-2 days' +%F)\""
contiene "Extra: eventos este mes" "$TMP/central.out" '"Eventos este mes":'
contiene "Extra: cotizaciones del mes" "$TMP/central.out" '"Cotizaciones del mes":'
contiene "Extra: usuarios activos" "$TMP/central.out" "\"Usuarios activos\":$(sql "SELECT count(*) FROM usuarios WHERE activo")"
no_contiene "Sin el correo del dueño" "$TMP/central.out" "$E1"
no_contiene "Sin ningún correo" "$TMP/central.out" "@"
no_contiene "Sin teléfonos" "$TMP/central.out" "0990000000"
no_contiene "Sin clientes de los negocios" "$TMP/central.out" "Cliente Muy Privado"
no_contiene "Sin el slug del negocio" "$TMP/central.out" "\"$(sql "SELECT slug FROM negocios WHERE id=$N1")\""
check "El estado no abre sesión (sin cookie)" "0" "$(curl -s -D - -o /dev/null "$BASE/tukan_central.php?accion=estado&t=$(c95_token estado)" | grep -ci '^set-cookie')"

echo "3. Entrar desde el panel central (SSO)"
limpiar_limite
sql "DELETE FROM superadmins WHERE email='dimitryfierro@gmail.com'" > /dev/null
JAR="$TMP/c95_sso"; rm -f "$JAR"
T1=$(c95_token sso)
r=$(c95_pedir "$BASE" sso "$T1")
check "Token correcto: entra y va al panel" "302 $BASE/?r=admin" "$r"
check "Crea el super usuario la primera vez" "1|true" "$(sql "SELECT count(*)||'|'||bool_and(creado_por_central) FROM superadmins WHERE email='dimitryfierro@gmail.com'")"
check "Con el nombre del token" "Dimitry Fierro" "$(sql "SELECT nombre FROM superadmins WHERE email='dimitryfierro@gmail.com'")"
AID=$(sql "SELECT id FROM superadmins WHERE email='dimitryfierro@gmail.com'")
check "Registro: Entró desde el Panel Tukán Central" "1" "$(sql "SELECT count(*) FROM registro_admin WHERE admin_id=$AID AND accion LIKE 'Entró desde el Panel Tukán Central%'")"
check "Registro: super usuario creado por el central" "1" "$(sql "SELECT count(*) FROM registro_admin WHERE admin_id=$AID AND accion='Super usuario creado por el Panel Tukán Central'")"
check "Abre el panel" "200" "$(get "r=admin")"
contiene "Botón ← Panel Tukán" "$TMP/pag.html" "← Panel Tukán</a>"
contiene "El botón lleva al panel central" "$TMP/pag.html" "href=\"$C95_URL\""
contiene "El registro del panel lo muestra" "$TMP/pag.html" "Entró desde el Panel Tukán Central"
# Las cifras del estado son las mismas del panel
c95_pedir "$BASE" estado "$(c95_token estado)" > /dev/null
pn() { grep -o "<strong>[0-9]*</strong> $1</a>" "$TMP/pag.html" | head -1 | sed 's/<strong>\([0-9]*\)<.*/\1/'; }
get "r=admin" > /dev/null
contiene "Total = panel (Negocios registrados)" "$TMP/pag.html" "<div class=\"cifra\">$(c95_json negocios.total)</div><p class=\"cifra-nombre\">Negocios registrados"
check "En demo = panel" "$(pn 'En demo')" "$(c95_json negocios.prueba)"
# Regla única del protocolo v2 (§4.2): por vencer = al día que vence en 0 a 7 días; los que están en gracia ya cuentan como vencidos
PV=$(sql "SELECT count(*) FROM negocios WHERE estado='activo' AND activo_hasta BETWEEN CURRENT_DATE AND CURRENT_DATE + 7")
check "Por vencer = al día que vence en 7 días" "$PV" "$(c95_json negocios.por_vencer)"
check "Activos = panel (Al día) − por vencer" "$(( $(pn 'Al día') - PV ))" "$(c95_json negocios.activos)"
check "Vencidos = panel (Vencido + Demo terminada + en gracia)" "$(( $(pn 'Vencido') + $(pn 'Demo terminada') + $(pn 'Venció (en gracia)') ))" "$(c95_json negocios.vencidos)"
check "Suspendidos = panel (Suspendido + Cancelado)" "$(( $(pn 'Suspendido') + $(pn 'Cancelado') ))" "$(c95_json negocios.suspendidos)"
COBRADO=$(php -r 'echo "$" . number_format((float) $argv[1], 2, ",", ".");' "$(c95_json ingresos_mes)")
contiene "Ingresos del mes = Cobrado este mes del panel ($COBRADO)" "$TMP/pag.html" "<div class=\"cifra\">$COBRADO</div><p class=\"cifra-nombre\">Cobrado este mes"
check "Ingresos del mes = suma de pagos no anulados" "$(sql "SELECT COALESCE(sum(monto),0)::float FROM suscripciones WHERE NOT anulada AND to_char(pagado_en,'YYYY-MM')=to_char(CURRENT_DATE,'YYYY-MM')")" "$(c95_json ingresos_mes)"
check "Pagos vencidos = lista Sin pagar del panel" "$(grep -o 'Sin pagar ([0-9]*)' "$TMP/pag.html" | grep -o '[0-9]*')" \
      "$(php -r 'echo count(json_decode(file_get_contents($argv[1]), true)["pagos_vencidos"]);' "$TMP/central.out")"
# Segunda vez: reutiliza el mismo super usuario
JAR="$TMP/c95_sso2"; rm -f "$JAR"
r=$(c95_pedir "$BASE" sso "$(c95_token sso nombre=Otro)")
check "Segunda vez: entra" "302 $BASE/?r=admin" "$r"
check "Reutiliza el mismo super usuario" "1|$AID" "$(sql "SELECT count(*)||'|'||min(id) FROM superadmins WHERE email='dimitryfierro@gmail.com'")"
check "Cada entrada queda registrada" "2" "$(sql "SELECT count(*) FROM registro_admin WHERE admin_id=$AID AND accion LIKE 'Entró desde el Panel Tukán Central%'")"
# El mismo enlace no sirve dos veces
JAR="$TMP/c95_sso3"; rm -f "$JAR"
r=$(c95_pedir "$BASE" sso "$T1")
check "El mismo enlace otra vez: 401" "401 " "$r"
contiene "Mensaje claro" "$TMP/central.out" "ya se usó"
contiene "Con enlace al panel central" "$TMP/central.out" "href=\"$C95_URL\""
check "Y no queda con sesión" "302" "$(get "r=admin")"
# Salir quita el botón; si entra con su clave, no aparece
JAR="$TMP/c95_sso"
r=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w "%{http_code} %{redirect_url}" "$BASE/?r=admin_salir")
check "Salir del panel" "302 $BASE/?r=admin_entrar" "$r"
ADM="local$RANDOM@tukan.ec"
env $DBENV TURENTA_ADMIN_NOMBRE="Admin Local" TURENTA_ADMIN_EMAIL="$ADM" TURENTA_ADMIN_CLAVE="ClaveLocal12345" php bin/crear_admin.php > /dev/null 2>&1
r=$(post "r=admin_entrar" --data-urlencode "email=$ADM" --data-urlencode "clave=ClaveLocal12345")
check "Con TUKAN_SOLO_CENTRAL=0 el ingreso local sigue" "302 $BASE/?r=admin" "$r"
get "r=admin" > /dev/null
no_contiene "Ingreso local: sin el botón del central" "$TMP/pag.html" "← Panel Tukán"
OUT=$(env $DBENV TUKAN_SOLO_CENTRAL=0 TURENTA_ADMIN_NOMBRE="Admin Local" TURENTA_ADMIN_EMAIL="$ADM" TURENTA_ADMIN_CLAVE="ClaveLocal12345" php bin/crear_admin.php 2>&1)
no_contiene_txt "crear_admin sin aviso cuando el ingreso local está prendido" "$OUT" "AVISO"

echo "4. Tokens rechazados"
limpiar_limite
JAR="$TMP/c95_malo"; rm -f "$JAR"
rechazo() { # nombre accion token texto
  local r; r=$(c95_pedir "$BASE" "$2" "$3")
  check "$1: 401" "401 " "$r"
  if [ "$2" = "estado" ]; then
    check "$1: JSON ok=false" "false" "$(c95_json ok)"
    contiene "$1: motivo" "$TMP/central.out" "$4"
  else
    contiene "$1: página con el motivo" "$TMP/central.out" "$4"
    contiene "$1: enlace al panel central" "$TMP/central.out" "Volver al Panel Tukán"
  fi
}
rechazo "Firma mala" sso "$(c95_token sso _secreto=otrosecretootrosecretootrosecreto12)" "firma"
rechazo "Firma mala (estado)" estado "$(c95_token estado _secreto=otrosecretootrosecretootrosecreto12)" "firma"
rechazo "Otro inst" sso "$(c95_token sso inst=tusalon)" "otra instalación"
rechazo "Otro inst (estado)" estado "$(c95_token estado inst=tusalon)" "otra instalación"
rechazo "Tipo cambiado (estado para entrar)" sso "$(c95_token estado)" "no es para esta acción"
rechazo "Tipo cambiado (sso para el estado)" estado "$(c95_token sso)" "no es para esta acción"
rechazo "Versión 2" sso "$(c95_token sso v=2)" "Versión"
rechazo "Vencido" sso "$(c95_token sso iat=-100 exp=-40)" "venció"
rechazo "Vencido (estado)" estado "$(c95_token estado iat=-100 exp=-40)" "venció"
rechazo "Futuro" sso "$(c95_token sso iat=45 exp=90)" "futura"
rechazo "Dura más de 60 s" estado "$(c95_token estado iat=0 exp=120)" "más de lo permitido"
TE=$(c95_token estado)
c95_pedir "$BASE" estado "$TE" > /dev/null
rechazo "Nonce repetido (estado)" estado "$TE" "ya se usó"
rechazo "Nonce mal formado" sso "$(c95_token sso nonce=1234)" "nonce"
rechazo "Email inválido" sso "$(c95_token sso email=no-es-correo)" "correo"
rechazo "Email inválido (estado)" estado "$(c95_token estado email=x@)" "correo"
rechazo "Sin token" sso "" "no es válido"
rechazo "Token basura" estado "abc.def.ghi" "no es válido"
check "Acción desconocida: 400" "400 " "$(c95_pedir "$BASE" borrar "$(c95_token sso)")"
check "Ningún rechazo creó super usuarios" "1" "$(sql "SELECT count(*) FROM superadmins WHERE email IN ('dimitryfierro@gmail.com','no-es-correo')")"
check "Sin sesión después de los rechazos" "302" "$(get "r=admin")"

echo "5. Límite de 30 pedidos por minuto por IP"
limpiar_limite
JAR="$TMP/c95_limite"; rm -f "$JAR"
C95_IP=203.0.113.50
cods=""
for i in $(seq 1 30); do cods="$cods$(c95_pedir "$BASE" estado "malo$i" | cut -c1-3) "; done
check "30 pedidos: todos llegan a revisar el token (401)" "30" "$(echo "$cods" | grep -o 401 | wc -l | tr -d ' ')"
check "El 31 se frena (429)" "429 " "$(c95_pedir "$BASE" estado "$(c95_token estado)")"
check "Con JSON ok=false" "false" "$(c95_json ok)"
contiene "Mensaje claro" "$TMP/central.out" "Demasiados pedidos"
check "Frena también el SSO" "429 " "$(c95_pedir "$BASE" sso "$(c95_token sso)")"
C95_IP=203.0.113.51
check "Otra IP sigue pasando" "200 " "$(c95_pedir "$BASE" estado "$(c95_token estado)")"
unset C95_IP
limpiar_limite

echo "6. Sitio sin configurar (TUKAN_CENTRAL_ID/SECRET vacíos; TUKAN_SOLO_CENTRAL=1 no apaga nada sin conector)"
P_SIN=$(c95_puerto)
c95_servidor "$P_SIN" "$TMP/servidor_sin_central.log" TUKAN_SOLO_CENTRAL=1
B_SIN="http://127.0.0.1:$P_SIN"
c95_esperar "$B_SIN"
JAR="$TMP/c95_sin"; rm -f "$JAR"
check "Estado: 404" "404 " "$(c95_pedir "$B_SIN" estado "$(c95_token estado)")"
check "SSO: 404" "404 " "$(c95_pedir "$B_SIN" sso "$(c95_token sso)")"
check "Sin acción: 404" "404" "$(curl -s -o /dev/null -w '%{http_code}' "$B_SIN/tukan_central.php")"
curl -s -o "$TMP/pag.html" "$B_SIN/?r=admin_entrar"
contiene "El ingreso local del super usuario sigue igual" "$TMP/pag.html" 'name="clave"'
no_contiene "Sin el mensaje de solo central" "$TMP/pag.html" "solo desde el Panel Tukán"
SALVO_BASE=$BASE; BASE=$B_SIN
entrar "$E1" "claveSegura1"
check "Ingreso de clientes igual" "200" "$(get "r=inicio")"
contiene "Entró el dueño" "$TMP/pag.html" "Dimitry"
BASE=$SALVO_BASE

echo "7. Solo desde el panel central (TUKAN_SOLO_CENTRAL=1)"
P_SOLO=$(c95_puerto)
c95_servidor "$P_SOLO" "$TMP/servidor_solo_central.log" TUKAN_CENTRAL_ID="$C95_ID" TUKAN_CENTRAL_SECRET="$TUKAN_CENTRAL_SECRET" TUKAN_CENTRAL_URL="$C95_URL" TUKAN_SOLO_CENTRAL=1
B_SOLO="http://127.0.0.1:$P_SOLO"
c95_esperar "$B_SOLO"
SALVO_BASE=$BASE; BASE=$B_SOLO
JAR="$TMP/c95_solo"; rm -f "$JAR"
check "Página de entrada del super usuario" "200" "$(get "r=admin_entrar")"
contiene "Mensaje: solo desde el Panel Tukán" "$TMP/pag.html" "El ingreso del super usuario es solo desde el Panel Tukán"
contiene "Con enlace al panel central" "$TMP/pag.html" "href=\"$C95_URL\""
no_contiene "Sin formulario de contraseña" "$TMP/pag.html" 'name="clave"'
get "r=login" > /dev/null; T=$(csrf)
r=$(curl -s -b "$JAR" -c "$JAR" -o "$TMP/post.html" -w "%{http_code}" --data-urlencode "csrf=$T" --data-urlencode "email=$ADM" \
     --data-urlencode "clave=ClaveLocal12345" "$BASE/?r=admin_entrar")
check "Con la clave correcta igual se rechaza (403)" "403" "$r"
check "Y no queda con sesión de super usuario" "302" "$(get "r=admin")"
check "No se registró como entrada fallida" "0" "$(sql "SELECT count(*) FROM registro_admin WHERE accion LIKE 'Entrada fallida: $ADM%'")"
r=$(c95_pedir "$BASE" sso "$(c95_token sso)")
check "Desde el panel central sí entra" "302 $BASE/?r=admin" "$r"
check "Abre el panel" "200" "$(get "r=admin")"
contiene "Con el botón ← Panel Tukán" "$TMP/pag.html" "← Panel Tukán"
entrar "$E1" "claveSegura1"
check "Ingreso de clientes igual" "200" "$(get "r=inicio")"
contiene "Entró el dueño" "$TMP/pag.html" "Dimitry"
registrar salon "Registro Con Solo Central $RANDOM"
check "Registro de negocios nuevos igual" "1" "$(sql "SELECT count(*) FROM usuarios WHERE email='$EMAIL'")"
BASE=$SALVO_BASE
OUT=$(env $DBENV TUKAN_CENTRAL_ID="$C95_ID" TUKAN_CENTRAL_SECRET="$TUKAN_CENTRAL_SECRET" TUKAN_SOLO_CENTRAL=1 TURENTA_ADMIN_NOMBRE="Admin Local" \
      TURENTA_ADMIN_EMAIL="$ADM" TURENTA_ADMIN_CLAVE="ClaveLocal12345" php bin/crear_admin.php 2>&1)
contiene_txt "crear_admin sigue funcionando (emergencia)" "$OUT" "contrasena cambiada"
contiene_txt "Y avisa cómo prender el ingreso local" "$OUT" "pon TUKAN_SOLO_CENTRAL=0"

echo "8. deploy/conectar_central.sh"
if [ "$(id -u)" != "0" ]; then
  echo "  (omitido: hace falta root para probar conectar_central.sh)"
else
  APPC="$TMP/app_central"
  mkdir -p "$APPC/config"
  cp -r app src public db deploy tests "$APPC/"
  printf 'TURENTA_DB_HOST=127.0.0.1\nTURENTA_DB_NAME=%s\nTURENTA_DB_USER=turenta\nTURENTA_DB_PASS=%s\nTELEGRAM_BOT_TOKEN=123:abc\nTUKAN_SOLO_CENTRAL=0' "$DB" "$PGPASSWORD" > "$APPC/config/.env"
  chmod 640 "$APPC/config/.env"
  P_CON=$(c95_puerto)
  env -u TUKAN_CENTRAL_ID -u TUKAN_CENTRAL_SECRET -u TUKAN_CENTRAL_URL -u TUKAN_SOLO_CENTRAL -u TURENTA_URL \
      php -S "127.0.0.1:$P_CON" -t "$APPC/public" "$APPC/tests/router.php" > "$TMP/servidor_conectar.log" 2>&1 &
  C95_PIDS="$C95_PIDS $!"
  c95_esperar "http://127.0.0.1:$P_CON"
  conectar() { APP="$APPC" LOCAL="http://127.0.0.1:$P_CON" SITIO=/no/existe DOMINIO=turenta.prueba bash deploy/conectar_central.sh "$@" 2>&1; }
  SEC1=$(openssl rand -hex 32); SEC2=$(openssl rand -hex 32)
  OUT=$(conectar); FIN=$?
  check "Sin datos: termina con error" "1" "$FIN"
  contiene_txt "Sin datos: dice cuáles faltan" "$OUT" "faltan datos"
  contiene_txt "Sin datos: muestra el uso" "$OUT" "conectar_central.sh ID SECRETO URL"
  OUT=$(conectar "TuRenta" "$SEC1" "https://panel.tukanec.com")
  contiene_txt "ID con mayúsculas: rechazado" "$OUT" "ERROR: el ID"
  OUT=$(conectar "turenta" "corto" "https://panel.tukanec.com")
  contiene_txt "Secreto corto: rechazado" "$OUT" "ERROR: el SECRETO"
  OUT=$(conectar "turenta" "$SEC1" "panel.tukanec.com")
  contiene_txt "URL sin https: rechazada" "$OUT" "ERROR: la URL"
  check "Con datos malos no se toca config/.env" "0" "$(grep -c '^TUKAN_CENTRAL' "$APPC/config/.env")"
  OUT=$(conectar "turenta-x" "$SEC1" "https://panel.tukanec.com/"); FIN=$?
  check "Datos correctos: termina bien" "0" "$FIN"
  contiene_txt "Prueba local OK" "$OUT" "OK: TuRenta responde al Panel Tukan Central"
  check "Guarda el ID" "TUKAN_CENTRAL_ID=turenta-x" "$(grep '^TUKAN_CENTRAL_ID=' "$APPC/config/.env")"
  check "Guarda el secreto" "TUKAN_CENTRAL_SECRET=$SEC1" "$(grep '^TUKAN_CENTRAL_SECRET=' "$APPC/config/.env")"
  check "Guarda la URL sin la barra final" "TUKAN_CENTRAL_URL=https://panel.tukanec.com" "$(grep '^TUKAN_CENTRAL_URL=' "$APPC/config/.env")"
  check "Conserva las demás líneas" "4" "$(grep -c -E '^(TURENTA_DB_NAME|TURENTA_DB_PASS|TELEGRAM_BOT_TOKEN|TUKAN_SOLO_CENTRAL)=' "$APPC/config/.env")"
  check "Permisos 640 y grupo www-data" "640 www-data" "$(stat -c '%a %G' "$APPC/config/.env")"
  OUT=$(conectar "turenta-x" "$SEC2" "https://panel.tukanec.com"); FIN=$?
  check "Correrlo otra vez (secreto nuevo): bien" "0" "$FIN"
  check "Una sola línea de cada variable" "1 1 1" "$(grep -c '^TUKAN_CENTRAL_ID=' "$APPC/config/.env") $(grep -c '^TUKAN_CENTRAL_SECRET=' "$APPC/config/.env") $(grep -c '^TUKAN_CENTRAL_URL=' "$APPC/config/.env")"
  check "Con el secreto nuevo" "TUKAN_CENTRAL_SECRET=$SEC2" "$(grep '^TUKAN_CENTRAL_SECRET=' "$APPC/config/.env")"
  no_contiene_txt "Mensajes sin tildes ni eñes" "$OUT" "[áéíóúñÁÉÍÓÚÑ]"
  OUT=$(APP="$APPC" LOCAL="$B_SIN" SITIO=/no/existe DOMINIO=turenta.prueba bash deploy/conectar_central.sh "turenta-x" "$SEC2" "https://panel.tukanec.com" 2>&1); FIN=$?
  check "Si el sitio no responde el conector: termina con error" "1" "$FIN"
  contiene_txt "Y lo explica" "$OUT" "ERROR: la prueba local respondio 404"
  check "El script no tiene tildes" "0" "$(LC_ALL=C grep -c -P '[^\x00-\x7F]' deploy/conectar_central.sh)"
fi

echo "9. Avisos de PHP en los sitios extra"
for f in "$TMP"/servidor_sin_central.log "$TMP"/servidor_solo_central.log "$TMP"/servidor_conectar.log; do
  [ -f "$f" ] || continue
  check "Sin avisos de PHP ($(basename "$f"))" "0" "$(grep -ciE 'fatal|warning|deprecated|notice' "$f")"
done
kill $C95_PIDS 2>/dev/null
C95_PIDS=""
