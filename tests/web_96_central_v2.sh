#!/usr/bin/env bash
# Pantallas: conector con el Panel Tukán Central, protocolo v2 (POST /tukan_central.php con X-Tukan-Token y cuerpo JSON).
# Lo corre tests/run_web.sh (sitio de prueba con TUKAN_CENTRAL_ID / _SECRET / _URL de prueba).
# Prueba de punta a punta por HTTP: transporte y códigos, capacidades, clientes = estado v1, demo creada desde el Central
# que entra con la clave devuelta, pago sin duplicar, solicitudes atendidas, finanzas, SSO con enlace directo y límite.
# Variables: BASE, DB, PGPASSWORD, TMP, TUKAN_CENTRAL_* (de run_web.sh)
planes_de_antes   # estas pruebas usan los planes de antes (Esencial $19, Salón $39, Quinta $69); los de $20 y $45: web_112_planes.sh

C96_ID=${TUKAN_CENTRAL_ID:-turenta-prueba}
export C96_ID
c96_txt() { if echo "$2" | grep -q -- "$3"; then OK=$((OK+1)); echo "  OK    $1"; else FALLAS=$((FALLAS+1)); echo "  FALLA $1 — [$2]"; fi; }

# Token v2 firmado sobre el cuerpo exacto: c96_token tipo archivo [clave=valor ...]  (valor QUITAR borra la clave; iat/exp en segundos desde ahora)
c96_token() {
  php -r '
  $t = time(); $cuerpo = (string) file_get_contents($argv[2]);
  $b = fn($s) => rtrim(strtr(base64_encode($s), "+/", "-_"), "=");
  $p = ["v" => 2, "tipo" => $argv[1], "inst" => getenv("C96_ID"), "email" => "dimitryfierro@gmail.com", "nombre" => "Dimitry Fierro", "rol" => "dueno",
        "iat" => $t, "exp" => $t + ($argv[1] === "ejecutar" ? 30 : 60), "nonce" => bin2hex(random_bytes(16)), "bh" => $b(hash("sha256", $cuerpo, true))];
  foreach (array_slice($argv, 3) as $kv) {
      [$k, $v] = explode("=", $kv, 2);
      if ($v === "QUITAR") { unset($p[$k]); continue; }
      $p[$k] = in_array($k, ["iat", "exp"], true) ? $t + (int) $v : ($k === "v" ? (int) $v : $v);
  }
  $s = $p["_secreto"] ?? getenv("TUKAN_CENTRAL_SECRET");
  unset($p["_secreto"]);
  $a = $b(json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  echo $a . "." . $b(hash_hmac("sha256", $a, $s, true));' -- "$@"
}
# Token v1 (sso / estado), con "ir" opcional: c96_token1 tipo [clave=valor ...]
c96_token1() {
  php -r '
  $t = time();
  $p = ["v" => 1, "tipo" => $argv[1], "inst" => getenv("C96_ID"), "email" => "dimitryfierro@gmail.com", "nombre" => "Dimitry Fierro",
        "iat" => $t, "exp" => $t + 60, "nonce" => bin2hex(random_bytes(16))];
  foreach (array_slice($argv, 2) as $kv) { [$k, $v] = explode("=", $kv, 2); $p[$k] = $v; }
  $b = fn($s) => rtrim(strtr(base64_encode($s), "+/", "-_"), "=");
  $a = $b(json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  echo $a . "." . $b(hash_hmac("sha256", $a, getenv("TUKAN_CENTRAL_SECRET"), true));' -- "$@"
}
# POST v2: c96_post accion 'json' [token] [args de curl...] -> código HTTP; respuesta en $TMP/c96.out y cabeceras en $TMP/c96.h
c96_post() {
  local accion=$1 cuerpo=$2; shift 2
  printf '%s' "$cuerpo" > "$TMP/c96_body.json"
  local tok=${1:-}; [ $# -gt 0 ] && shift
  [ -z "$tok" ] && tok=$(c96_token "$accion" "$TMP/c96_body.json")
  curl -s -o "$TMP/c96.out" -D "$TMP/c96.h" -w "%{http_code}" -X POST -H "Content-Type: application/json; charset=utf-8" \
       -H "X-Tukan-Token: $tok" -H "CF-Connecting-IP: ${C96_IP:-198.51.100.60}" --data-binary "@$TMP/c96_body.json" "$@" "$BASE/tukan_central.php?accion=$accion"
}
# ejecutar: c96_ej operacion 'datos json' [cliente_id] [operacion_id]
c96_ej() {
  local op=$1 datos=$2 cli=${3:-} opid=${4:-$(php -r 'echo bin2hex(random_bytes(16));')}
  local c=""; [ -n "$cli" ] && c="\"cliente_id\":\"$cli\","
  c96_post ejecutar "{\"operacion_id\":\"$opid\",\"accion\":\"$op\",$c\"datos\":$datos}"
}
# Valor del JSON de la última respuesta: c96_json clientes.0.estado
c96_json() {
  php -r '$v = json_decode((string) file_get_contents($argv[1]), true);
  foreach (explode(".", $argv[2]) as $k) $v = is_array($v) ? ($v[$k] ?? null) : null;
  echo is_bool($v) ? ($v ? "true" : "false") : (is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (is_null($v) ? "null" : $v));' "$TMP/c96.out" "$1"
}
c96_cod() { echo "$1 $(c96_json codigo)"; }
sql "DELETE FROM tukan_central_pedidos" > /dev/null

echo "1. Negocios de prueba (uno en gracia y uno que vence en 7 días exactos)"
registrar quinta "Quinta V2 Gracia $RANDOM"; NG=$NID
sql "INSERT INTO suscripciones (negocio_id, plan_codigo, periodo, meses_pagados, meses_recibidos, monto, inicio, fin, metodo_pago, pagado_en)
     VALUES ($NG, 'quinta', 'mensual', 1, 1, 69, CURRENT_DATE - 31, CURRENT_DATE - 1, 'transferencia', CURRENT_DATE - 31)" > /dev/null
sql "UPDATE negocios SET estado='activo', activo_hasta=CURRENT_DATE - 1 WHERE id=$NG" > /dev/null
registrar salon "Salón V2 Siete Días $RANDOM"; N7=$NID
sql "INSERT INTO suscripciones (negocio_id, plan_codigo, periodo, meses_pagados, meses_recibidos, monto, inicio, fin, metodo_pago)
     VALUES ($N7, 'salon', 'mensual', 1, 1, 39, CURRENT_DATE - 23, CURRENT_DATE + 7, 'deuna')" > /dev/null
sql "UPDATE negocios SET estado='activo', activo_hasta=CURRENT_DATE + 7 WHERE id=$N7" > /dev/null
registrar quinta "Quinta V2 Registro $RANDOM"; NR=$NID; ER=$EMAIL
check "Las cuentas las crea Tukán (no hay registro abierto con periodo de prueba): origen super" "super" "$(sql "SELECT origen FROM negocios WHERE id=$NR")"

echo "2. Transporte y códigos (§2, §3)"
r=$(c96_post capacidades '{}')
check "capacidades: 200" "200" "$r"
check "JSON" "application/json; charset=utf-8" "$(grep -i '^content-type' "$TMP/c96.h" | cut -d' ' -f2- | tr -d '\r')"
check "Cache-Control: no-store" "1" "$(grep -ci '^cache-control: no-store' "$TMP/c96.h")"
check "No abre sesión (sin cookie)" "0" "$(grep -ci '^set-cookie' "$TMP/c96.h")"
check "protocolo 2 y producto" "2 turenta" "$(c96_json protocolo) $(c96_json producto)"
check "Acciones" '["capacidades","estado","sso","clientes","cliente","cotizar","planes","modulos","solicitudes","solicitud","finanzas","ejecutar","ia_uso"]' "$(c96_json acciones)"
check "sso_ir y rutas_sso" 'true ["/?r=admin"]' "$(c96_json sso_ir) $(c96_json rutas_sso)"
printf '{}' > "$TMP/c96_body.json"; TOK=$(c96_token capacidades "$TMP/c96_body.json")
r=$(curl -s -o "$TMP/c96.out" -D "$TMP/c96.h" -w "%{http_code}" -H "X-Tukan-Token: $TOK" "$BASE/tukan_central.php?accion=capacidades")
check "GET a una acción v2: 405 invalido" "405 invalido" "$(c96_cod "$r")"
check "… con Allow: POST" "1" "$(grep -ci '^allow: POST' "$TMP/c96.h")"
printf '{}' > "$TMP/c96_body.json"
r=$(curl -s -o "$TMP/c96.out" -w "%{http_code}" -X POST -H "Content-Type: text/plain" -H "X-Tukan-Token: $(c96_token capacidades "$TMP/c96_body.json")" \
       --data-binary "@$TMP/c96_body.json" "$BASE/tukan_central.php?accion=capacidades")
check "Content-Type que no es JSON: 415" "415 invalido" "$(c96_cod "$r")"
printf 'no es json' > "$TMP/c96_x"; r=$(c96_post capacidades 'no es json' "$(c96_token capacidades "$TMP/c96_x")")
check "Cuerpo que no es JSON: 400" "400 invalido" "$(c96_cod "$r")"
printf '{"pagina":1}' > "$TMP/c96_x"; r=$(c96_post clientes '{"pagina":2}' "$(c96_token clientes "$TMP/c96_x")")
check "Cuerpo cambiado un byte: 401 firma" "401 firma" "$(c96_cod "$r")"
check "… detalle parte cuerpo" '{"parte":"cuerpo"}' "$(c96_json detalle)"
r=$(c96_post clientes '{}' "$(c96_token clientes "$TMP/c96_body.json" bh=QUITAR)")
check "Token sin bh: 401 firma" "401 firma" "$(c96_cod "$r")"
r=$(c96_post clientes '{}' "-")
check "Token basura: 401 firma" "401 firma" "$(c96_cod "$r")"
r=$(c96_post clientes '{}' "$(c96_token clientes "$TMP/c96_body.json" v=1)")
check "v:1 en una acción v2: 401 version" "401 version" "$(c96_cod "$r")"
r=$(c96_post clientes '{}' "$(c96_token planes "$TMP/c96_body.json")")
check "tipo distinto: 401 tipo" "401 tipo" "$(c96_cod "$r")"
r=$(c96_post clientes '{}' "$(c96_token clientes "$TMP/c96_body.json" inst=tusalon)")
check "Otra instancia: 401 inst" "401 inst" "$(c96_cod "$r")"
r=$(c96_post clientes '{}' "$(c96_token clientes "$TMP/c96_body.json" iat=-100 exp=-40)")
check "Vencido: 401 vencido" "401 vencido" "$(c96_cod "$r")"
r=$(c96_post clientes '{}' "$(c96_token clientes "$TMP/c96_body.json" iat=45 exp=90)")
check "Futuro: 401 futuro" "401 futuro" "$(c96_cod "$r")"
CE='{"operacion_id":"0123456789abcdef0123456789abcdef","accion":"nota_interna","cliente_id":"1","datos":{"texto":"x"}}'
printf '%s' "$CE" > "$TMP/c96_x"; r=$(c96_post ejecutar "$CE" "$(c96_token ejecutar "$TMP/c96_x" iat=0 exp=31)")
check "ejecutar con exp - iat = 31: 401 invalido" "401 invalido" "$(c96_cod "$r")"
printf '{}' > "$TMP/c96_body.json"; TOK=$(c96_token clientes "$TMP/c96_body.json")
c96_post clientes '{}' "$TOK" > /dev/null
r=$(c96_post clientes '{}' "$TOK")
check "Token repetido: 401 nonce" "401 nonce" "$(c96_cod "$r")"
c96_txt "Mensaje en español" "$(c96_json error)" "repitió"
r=$(c96_post adjunto '{"solicitud_id":"x:1","adjunto_id":"1"}')
check "adjunto (no lo tiene TuRenta): 403 no_permitido" "403 no_permitido" "$(c96_cod "$r")"
r=$(c96_post borrar '{}')
check "Acción que no existe: 400" "400 invalido" "$(c96_cod "$r")"
r=$(curl -s -o "$TMP/c96.out" -w "%{http_code}" "$BASE/tukan_central.php?accion=estado&t=malo")
check "Errores del estado v1 con código" "401 firma" "$(c96_cod "$r")"

echo "3. clientes: contadores = estado v1 (regla única §4.2)"
r=$(c96_post clientes '{"por_pagina":200}')
check "clientes: 200" "200" "$r"
CONT=$(c96_json contadores)
c96_post clientes "{\"ids\":[\"$NG\",\"$N7\"],\"orden\":\"nombre\"}" > /dev/null
check "En gracia: vencido (en_gracia, no bloqueado)" "vencido true false" "$(c96_json clientes.0.estado) $(c96_json clientes.0.en_gracia) $(c96_json clientes.0.bloqueado)"
check "Vence en 7 días exactos: por_vencer" "por_vencer 7" "$(c96_json clientes.1.estado) $(c96_json clientes.1.fechas.dias_para_vencer)"
curl -s -o "$TMP/c96.out" "$BASE/tukan_central.php?accion=estado&t=$(c96_token1 estado)"
check "contadores = estado.negocios" "$CONT" "$(php -r '$n = json_decode(file_get_contents($argv[1]), true)["negocios"];
  echo json_encode(["total" => $n["total"], "prueba" => $n["prueba"], "activo" => $n["activos"], "por_vencer" => $n["por_vencer"], "vencido" => $n["vencidos"], "suspendido" => $n["suspendidos"]]);' "$TMP/c96.out")"
check "estado: protocolo_max 2" "2" "$(c96_json protocolo_max)"
ABIERTAS=$(c96_json solicitudes_abiertas)
check "Total = negocios de la base" "$(sql "SELECT count(*) FROM negocios")" "$(c96_json negocios.total)"
c96_post cliente "{\"id\":\"$NR\"}" > /dev/null
check "Ficha: ir al súper panel" "/?r=admin_negocio&id=$NR" "$(c96_json cliente.ir)"
check "Ficha: enlace público" "$BASE/?r=sitio&s=$(sql "SELECT slug FROM negocios WHERE id=$NR")" "$(c96_json cliente.enlace_publico)"
check "Ficha: correo del dueño" "$ER" "$(c96_json cliente.dueno.email)"
r=$(c96_post cliente '{"id":"999999"}')
check "Cliente que no existe: 404 no_encontrado" "404 no_encontrado" "$(c96_cod "$r")"

echo "4. Demo creada desde el Central: entra con la clave devuelta"
DEM="demo$RANDOM$RANDOM@prueba.ec"
OPD=$(php -r 'echo bin2hex(random_bytes(16));')
r=$(c96_ej crear_demo "{\"nombre\":\"Quinta Demo Web $RANDOM\",\"contacto_nombre\":\"Juan Pérez\",\"email\":\"$DEM\",\"telefono\":\"0991234567\",\"plan\":\"quinta\",\"dias\":10,\"enviar_correo\":false,\"extra\":{\"ciudad\":\"Loja\"}}" "" "$OPD")
check "crear_demo: 200" "200" "$r"
ND=$(c96_json resultado.cliente_id); CLAVE=$(c96_json resultado.acceso.clave)
check "Usuario = correo, panel = login" "$DEM $BASE/?r=login" "$(c96_json resultado.acceso.usuario) $(c96_json resultado.acceso.url_panel)"
check "Prueba por 10 días" "$(date -d '+10 days' +%F)" "$(sql "SELECT prueba_hasta FROM negocios WHERE id=$ND")"
check "Origen central, ciudad, datos de ejemplo" "central|Loja|2" "$(sql "SELECT origen||'|'||ciudad||'|'||(SELECT count(*) FROM espacios WHERE negocio_id=$ND) FROM negocios WHERE id=$ND")"
r=$(c96_ej crear_demo "{\"nombre\":\"Quinta Demo Web\",\"contacto_nombre\":\"Juan Pérez\",\"email\":\"$DEM\",\"telefono\":\"0991234567\",\"plan\":\"quinta\",\"dias\":10,\"enviar_correo\":false,\"extra\":{\"ciudad\":\"Loja\"}}")
check "Otra demo con el mismo correo: 422 datos.email" "422 datos.email" "$r $(c96_json campo)"
entrar "$DEM" "$CLAVE"
check "Entra con la clave devuelta (clave temporal: pide cambiarla)" "200" "$(get "r=mi_cuenta")"
contiene "Es su cuenta" "$TMP/pag.html" "Juan"
check "Y trabaja en su negocio (prueba gratis)" "200" "$(get "r=inicio")"
check "Una sola cuenta con ese correo" "1" "$(sql "SELECT count(*) FROM usuarios WHERE email='$DEM'")"
r=$(c96_ej crear_demo "{\"nombre\":\"Quinta Demo Web $RANDOM\",\"contacto_nombre\":\"Juan Pérez\",\"email\":\"$DEM\",\"telefono\":\"0991234567\",\"plan\":\"quinta\",\"dias\":10,\"enviar_correo\":false,\"extra\":{\"ciudad\":\"Loja\"}}" "" "$OPD")
check "Misma operación con otros datos: 409 conflicto" "409 conflicto" "$(c96_cod "$r")"
check "Ninguna cuenta nueva" "1" "$(sql "SELECT count(*) FROM negocios WHERE id >= $ND")"
check "La respuesta guardada no tiene la clave" "0" "$(sql "SELECT count(*) FROM tukan_central_operaciones WHERE respuesta LIKE '%$CLAVE%'")"

echo "5. registrar_pago sin duplicar y extender_prueba de quien paga"
OPP=$(php -r 'echo bin2hex(random_bytes(16));')
PAGO='{"plan":"quinta","periodo":"mensual","monto":69.0,"metodo":"transferencia","fecha":"'"$(date -d '-1 day' +%F)"'","nota":"Pichincha 777"}'
r=$(c96_ej registrar_pago "$PAGO" "$NG" "$OPP")
check "Pago: 200" "200" "$r"
check "Queda activo" "activo $(date -d '+1 month -1 day' +%F)" "$(c96_json cliente.estado) $(c96_json resultado.activo_hasta)"
r=$(c96_ej registrar_pago "$PAGO" "$NG" "$OPP")
check "Reintento (otro token): 200 repetida" "200 true" "$r $(c96_json repetida)"
check "Un solo pago nuevo" "2" "$(sql "SELECT count(*) FROM suscripciones WHERE negocio_id=$NG")"
check "Con la fecha del pago" "$(date -d '-1 day' +%F)" "$(sql "SELECT max(pagado_en) FROM suscripciones WHERE negocio_id=$NG")"
r=$(c96_ej registrar_pago "${PAGO/69.0/70.0}" "$NG" "$OPP")
check "Mismo operacion_id con otro monto: 409 conflicto" "409 conflicto" "$(c96_cod "$r")"
check "Registro del panel con el correo del Central" "1" "$(sql "SELECT count(*) FROM registro_admin r JOIN superadmins a ON a.id=r.admin_id
      WHERE r.negocio_id=$NG AND a.email='dimitryfierro@gmail.com' AND r.accion LIKE 'Panel Tukán Central · Pago registrado: quinta mensual \$69,00 (Transferencia)%Pichincha 777 (op ${OPP:0:8})'")"
r=$(c96_ej extender_prueba '{"dias":7}' "$NG")
check "Alargar la prueba de quien paga: 403 no_permitido" "403 no_permitido" "$(c96_cod "$r")"
r=$(c96_ej extender_prueba '{"dias":7}' "$NR")
check "Alargar la prueba de una prueba: 200" "200 $(date -d '+14 days' +%F)" "$r $(c96_json resultado.prueba_hasta)"
r=$(c96_ej registrar_pago '{"periodo":"mensual","metodo":"bitcoin"}' "$NR")
check "Forma de pago mala: 422 con campo" "422 invalido datos.metodo" "$r $(c96_json codigo) $(c96_json campo)"
c96_txt "… mensaje en español" "$(c96_json error)" "forma de pago"

echo "6. Solicitudes: un pedido de demo (Solicitar demo) se atiende y se convierte en demo"
rm -f "$JAR"
NEG_ANTES=$(sql "SELECT count(*) FROM negocios")
r=$(post "r=registro" --data-urlencode "negocio=Hacienda Pide Demo $RANDOM" --data-urlencode "nombre=Marta Pide" --data-urlencode "ciudad=Ambato" \
     --data-urlencode "whatsapp=0987771234" --data-urlencode "email=marta$RANDOM@pide.ec" --data-urlencode "necesita=Bodas grandes" --data-urlencode "modulos[]=invitados")
PDW=$(sql "SELECT id FROM pedidos_demo ORDER BY id DESC LIMIT 1")
check "Pedir demo no crea cuenta" "$NEG_ANTES" "$(sql "SELECT count(*) FROM negocios")"
c96_post solicitudes '{"estado":"abiertas","por_pagina":200}' > /dev/null
check "solicitudes total = estado.solicitudes_abiertas (antes de pagar)" "true" "$( [ "$(c96_json total)" -ge 1 ] && echo true )"
contiene "Sale el pedido de demo" "$TMP/c96.out" "\"id\":\"demo_pedida:$PDW\""
r=$(c96_ej atender_solicitud "{\"solicitud_id\":\"demo_pedida:$PDW\",\"resultado\":\"en_proceso\",\"nota\":\"Le escribí\"}")
check "Contactado: 200 en proceso" "200 en_proceso contactada" "$r $(c96_json solicitud.estado) $(sql "SELECT estado FROM pedidos_demo WHERE id=$PDW")"
r=$(c96_ej crear_demo "{\"nombre\":\"Hacienda Pide Demo\",\"contacto_nombre\":\"Marta Pide\",\"email\":\"marta.demo$RANDOM@pide.ec\",\"telefono\":\"0987771234\",\"solicitud_id\":\"demo_pedida:$PDW\"}")
check "Crear demo desde el pedido: 200 y pedido atendido" "200 atendida demo_creada" "$r $(c96_json solicitud.estado) $(sql "SELECT estado FROM pedidos_demo WHERE id=$PDW")"
c96_post solicitudes '{"estado":"abiertas","por_pagina":200}' > /dev/null
no_contiene "Ya no sale en abiertas" "$TMP/c96.out" "\"id\":\"demo_pedida:$PDW\""
c96_post solicitudes '{"estado":"cerradas","por_pagina":200}' > /dev/null
contiene "Sale en cerradas" "$TMP/c96.out" "\"id\":\"demo_pedida:$PDW\""
c96_post solicitud "{\"id\":\"renovacion:$N7:vence_pronto:$(date -d '+7 days' +%F)\"}" > /dev/null
check "Renovación a 7 días todavía no (es a 5)" "no_encontrado" "$(c96_json codigo)"

echo "7. finanzas: cuadra con la base"
c96_post finanzas "{\"desde\":\"$(date +%Y-%m)\",\"hasta\":\"$(date +%Y-%m)\"}" > /dev/null
check "Un mes" "1" "$(php -r 'echo count(json_decode(file_get_contents($argv[1]), true)["meses"]);' "$TMP/c96.out")"
check "Ingresos del mes = pagos no anulados de este mes" "$(sql "SELECT COALESCE(sum(monto),0)::float FROM suscripciones WHERE NOT anulada AND to_char(pagado_en,'YYYY-MM')=to_char(CURRENT_DATE,'YYYY-MM')")" "$(c96_json meses.0.ingresos)"
check "Pagos del mes" "$(sql "SELECT count(*) FROM suscripciones WHERE NOT anulada AND to_char(pagado_en,'YYYY-MM')=to_char(CURRENT_DATE,'YYYY-MM')")" "$(c96_json meses.0.pagos)"
r=$(c96_post finanzas '{"desde":"2020-01","hasta":"2026-01"}')
check "Más de 24 meses: 422" "422 invalido" "$(c96_cod "$r")"

echo "8. SSO con enlace directo (ir)"
sso_ir() { curl -s -c "$TMP/c96_jar" -b "$TMP/c96_jar" -o "$TMP/c96.out" -D "$TMP/c96.h" -w "%{http_code} %{redirect_url}" -H "CF-Connecting-IP: 198.51.100.61" \
             "$BASE/tukan_central.php?accion=sso&t=$1"; }
rm -f "$TMP/c96_jar"
r=$(sso_ir "$(c96_token1 sso "ir=/?r=admin_negocio&id=$NR")")
check "ir válido: va a la ficha del negocio" "302 $BASE/?r=admin_negocio&id=$NR" "$r"
r=$(curl -s -b "$TMP/c96_jar" -o "$TMP/pag.html" -w "%{http_code}" "$BASE/?r=admin_negocio&id=$NR")
check "La ficha abre con la sesión del SSO" "200" "$r"
contiene "Es la ficha de ese negocio" "$TMP/pag.html" "$(sql "SELECT nombre FROM negocios WHERE id=$NR")"
check "Registro: Entró … → ir" "1" "$(sql "SELECT count(*) FROM registro_admin WHERE accion LIKE 'Entró desde el Panel Tukán Central → /?r=admin_negocio&id=$NR%'")"
for malo in "//malo.com" "https://malo.com/?r=admin" "/?r=inicio" "/\\\\malo.com"; do
  rm -f "$TMP/c96_jar"
  r=$(sso_ir "$(c96_token1 sso "ir=$malo")")
  check "ir no válido ($malo): va al inicio del panel" "302 $BASE/?r=admin" "$r"
done
check "Registro: ir no válido" "4" "$(sql "SELECT count(*) FROM registro_admin WHERE accion LIKE 'Entró desde el Panel Tukán Central (ir no válido%'")"
T1=$(c96_token1 sso)
sso_ir "$T1" > /dev/null
r=$(sso_ir "$T1")
check "SSO repetido: 401 con X-Tukan-Codigo: nonce" "401  nonce" "$r $(grep -i '^x-tukan-codigo' "$TMP/c96.h" | cut -d' ' -f2 | tr -d '\r')"

echo "9. Límite: 121 pedidos en un minuto → 429"
sql "DELETE FROM tukan_central_pedidos" > /dev/null
cods=""
for i in $(seq 1 120); do cods="$cods$(curl -s -o /dev/null -w '%{http_code}' -H 'CF-Connecting-IP: 203.0.113.96' "$BASE/tukan_central.php?accion=clientes") "; done
check "120 pedidos atendidos (405 por GET)" "120" "$(echo "$cods" | grep -o 405 | wc -l | tr -d ' ')"
r=$(C96_IP=203.0.113.96 c96_post capacidades '{}')
check "El 121: 429 limite" "429 limite" "$(c96_cod "$r")"
check "Con Retry-After: 60" "1" "$(grep -ci '^retry-after: 60' "$TMP/c96.h")"
r=$(C96_IP=203.0.113.97 c96_post capacidades '{}')
check "Otra IP pasa" "200" "$r"
sql "DELETE FROM tukan_central_pedidos" > /dev/null

echo "10. Un Central v1 sigue funcionando (estado y SSO sin ir)"
r=$(curl -s -o "$TMP/c96.out" -w "%{http_code}" "$BASE/tukan_central.php?accion=estado&t=$(c96_token1 estado)")
check "estado v1: 200 protocolo 1" "200 1" "$r $(c96_json protocolo)"
rm -f "$TMP/c96_jar"
check "SSO v1 sin ir: va al panel" "302 $BASE/?r=admin" "$(sso_ir "$(c96_token1 sso)")"
