#!/usr/bin/env bash
# Pantallas: Panel Tukán Central v2, Anexo A (pausar, papelera, restaurar, purga) de punta a punta por HTTP.
# Lo corre tests/run_web.sh (sitio de prueba con TUKAN_CENTRAL_* de prueba y TURENTA_BORRADOS en una carpeta temporal).
# Cliente pausado: "Tu cuenta está pausada" con el motivo (genérico en abuso) y el WhatsApp de Tukán; páginas públicas y
# portales "temporalmente no disponible" (503). Borrado: no entra, sus páginas dan 404, sale del Panel Tukán (filtro Papelera),
# se restaura desde el panel y la tarea de cada hora lo borra de verdad a los 30 días.
# Variables: BASE, DB, PGPASSWORD, TMP, TUKAN_CENTRAL_*, TURENTA_BORRADOS (de run_web.sh)
planes_de_antes   # estas pruebas usan los planes de antes (Esencial $19, Salón $39, Quinta $69); los de $20 y $45: web_112_planes.sh

C97_ID=${TUKAN_CENTRAL_ID:-turenta-prueba}
export C97_ID
c97_token() {   # c97_token tipo archivo [rol]
  php -r '
  $t = time(); $cuerpo = (string) file_get_contents($argv[2]);
  $b = fn($s) => rtrim(strtr(base64_encode($s), "+/", "-_"), "=");
  $p = ["v" => 2, "tipo" => $argv[1], "inst" => getenv("C97_ID"), "email" => "dimitryfierro@gmail.com", "nombre" => "Dimitry Fierro", "rol" => $argv[3] ?? "dueno",
        "iat" => $t, "exp" => $t + ($argv[1] === "ejecutar" ? 30 : 60), "nonce" => bin2hex(random_bytes(16)), "bh" => $b(hash("sha256", $cuerpo, true))];
  $a = $b(json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  echo $a . "." . $b(hash_hmac("sha256", $a, getenv("TUKAN_CENTRAL_SECRET"), true));' -- "$@"
}
c97_sso_token() {
  php -r '
  $t = time(); $b = fn($s) => rtrim(strtr(base64_encode($s), "+/", "-_"), "=");
  $p = ["v" => 1, "tipo" => "sso", "inst" => getenv("C97_ID"), "email" => "dimitryfierro@gmail.com", "nombre" => "Dimitry Fierro",
        "iat" => $t, "exp" => $t + 60, "nonce" => bin2hex(random_bytes(16)), "ir" => $argv[1]];
  $a = $b(json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  echo $a . "." . $b(hash_hmac("sha256", $a, getenv("TUKAN_CENTRAL_SECRET"), true));' -- "$1"
}
c97_post() {   # c97_post accion 'json' [rol] -> código HTTP; respuesta en $TMP/c97.out
  printf '%s' "$2" > "$TMP/c97_body.json"
  curl -s -o "$TMP/c97.out" -w "%{http_code}" -X POST -H "Content-Type: application/json; charset=utf-8" \
       -H "X-Tukan-Token: $(c97_token "$1" "$TMP/c97_body.json" "${3:-dueno}")" -H "CF-Connecting-IP: 198.51.100.97" \
       --data-binary "@$TMP/c97_body.json" "$BASE/tukan_central.php?accion=$1"
}
c97_ej() {   # c97_ej operacion 'datos json' cliente_id [rol]
  c97_post ejecutar "{\"operacion_id\":\"$(php -r 'echo bin2hex(random_bytes(16));')\",\"accion\":\"$1\",\"cliente_id\":\"$3\",\"datos\":$2}" "${4:-dueno}"
}
c97_json() {
  php -r '$v = json_decode((string) file_get_contents($argv[1]), true);
  foreach (explode(".", $argv[2]) as $k) $v = is_array($v) ? ($v[$k] ?? null) : null;
  echo is_bool($v) ? ($v ? "true" : "false") : (is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (is_null($v) ? "null" : $v));' "$TMP/c97.out" "$1"
}
c97_codigo() { curl -s -o "$TMP/c97_pag.html" -w "%{http_code}" "$BASE/?$1"; }
sql "DELETE FROM tukan_central_pedidos" > /dev/null

echo "1. Negocios de prueba (con sitio, portal del cliente y un evento)"
registrar quinta "Quinta Web Papelera $RANDOM"; NA=$NID; EA=$EMAIL; JAR_A="$TMP/jar_a"; cp "$JAR" "$JAR_A"
NOMBRE_A=$(sql "SELECT nombre FROM negocios WHERE id=$NA"); SLUG_A=$(sql "SELECT slug FROM negocios WHERE id=$NA")
EV_A=$(crear_evento_sql "$(date -d '+30 days' +%F)" confirmado); TOK_A=$(sql "SELECT token_cliente FROM eventos WHERE id=$EV_A")
registrar salon "Salón Web Vecino $RANDOM"; NB=$NID; SLUG_B=$(sql "SELECT slug FROM negocios WHERE id=$NB")
check "Sitio del negocio: 200" "200" "$(c97_codigo "r=sitio&s=$SLUG_A")"
check "Portal del cliente: 200" "200" "$(c97_codigo "r=portal&t=$TOK_A")"

echo "2. Pausar (falta de pago): pantalla del cliente y páginas públicas"
check "pausar sin motivo: 422" "422 datos.motivo" "$(c97_ej pausar '{"tipo":"falta_pago"}' "$NA") $(c97_json campo)"
check "pausar: 200" "200" "$(c97_ej pausar '{"tipo":"falta_pago","motivo":"Debe la mensualidad de octubre"}' "$NA")"
check "pausar: en la base" "suspendido|falta_pago|Debe la mensualidad de octubre" "$(sql "SELECT estado||'|'||pausa_tipo||'|'||pausa_motivo FROM negocios WHERE id=$NA")"
r=$(curl -s -b "$JAR_A" -c "$JAR_A" -o "$TMP/c97_pag.html" -w "%{http_code} %{redirect_url}" "$BASE/?r=inicio")
check "El dueño pausado: va a su pantalla de cuenta" "302 $BASE/?r=prueba_terminada" "$r"
curl -s -b "$JAR_A" -c "$JAR_A" -o "$TMP/c97_pag.html" "$BASE/?r=prueba_terminada"
contiene "Pantalla: Tu cuenta está pausada" "$TMP/c97_pag.html" "Tu cuenta está pausada"
contiene "Pantalla: con el motivo" "$TMP/c97_pag.html" "Debe la mensualidad de octubre"
contiene "Pantalla: WhatsApp de Tukán" "$TMP/c97_pag.html" "https://wa.me/593983662181"
check "Sitio del pausado: 503" "503" "$(c97_codigo "r=sitio&s=$SLUG_A")"
contiene "Sitio: temporalmente no disponible" "$TMP/c97_pag.html" "Temporalmente no disponible"
no_contiene "Sitio: sin el nombre del negocio" "$TMP/c97_pag.html" "$NOMBRE_A"
check "Portal del cliente del pausado: 503" "503" "$(c97_codigo "r=portal&t=$TOK_A")"
contiene "Portal: temporalmente no disponible" "$TMP/c97_pag.html" "temporalmente no disponible"
check "Disponibilidad pública (JSON): 503" "503" "$(c97_codigo "r=disponible&s=$SLUG_A&fecha=$(date -d '+5 days' +%F)")"
contiene "… en JSON" "$TMP/c97_pag.html" '"disponible":false'
check "El vecino no se toca: 200" "200" "$(c97_codigo "r=sitio&s=$SLUG_B")"
c97_ej pausar '{"tipo":"abuso","motivo":"Mandó spam desde el portal"}' "$NA" > /dev/null
curl -s -b "$JAR_A" -c "$JAR_A" -o "$TMP/c97_pag.html" "$BASE/?r=prueba_terminada"
contiene "Abuso: sigue pausada" "$TMP/c97_pag.html" "Tu cuenta está pausada"
no_contiene "Abuso: el motivo no se muestra" "$TMP/c97_pag.html" "spam"
contiene "Abuso: texto genérico" "$TMP/c97_pag.html" "pausó tu cuenta temporalmente"
check "reactivar: 200" "200" "$(c97_ej reactivar '{}' "$NA")"
check "Reactivado: sitio 200" "200" "$(c97_codigo "r=sitio&s=$SLUG_A")"
check "Reactivado: entra al inicio" "200" "$(curl -s -b "$JAR_A" -c "$JAR_A" -o /dev/null -w "%{http_code}" "$BASE/?r=inicio")"

echo "3. Borrar (papelera)"
check "Borrar con rol soporte: 403" "403 no_permitido" "$(c97_ej borrar_cliente "{\"confirmar\":\"$NOMBRE_A\",\"motivo\":\"Cerró\"}" "$NA" soporte) $(c97_json codigo)"
check "Borrar con el nombre mal: 422" "422 datos.confirmar" "$(c97_ej borrar_cliente '{"confirmar":"otro","motivo":"Cerró"}' "$NA") $(c97_json campo)"
check "Borrar: 200" "200" "$(c97_ej borrar_cliente "{\"confirmar\":\"$NOMBRE_A\",\"motivo\":\"Cerró el local\"}" "$NA")"
COPIA=$(c97_json copia)
check "Borrar: borrar_definitivo_el = hoy + 30" "$(date -d '+30 days' +%F)" "$(c97_json borrar_definitivo_el)"
check "Borrar: la copia existe, en la carpeta de borrados, con permisos 600" "1 1 600" \
      "$([ -f "$COPIA" ] && echo 1 || echo 0) $([[ "$COPIA" == "$TURENTA_BORRADOS/"* ]] && echo 1 || echo 0) $(stat -c %a "$COPIA" 2>/dev/null)"
check "Borrar: la copia se lee (gzip JSON) y es de ese negocio" "$NA" "$(php -r 'echo json_decode(gzdecode(file_get_contents($argv[1])), true)["negocio_id"];' "$COPIA")"
r=$(curl -s -o "$TMP/c97_web.bin" -w "%{http_code}" "$BASE/respaldos/borrados/$(basename "$COPIA")")
check "Borrar: la copia no se puede bajar desde la web (está fuera de public/)" "no" "$([ "$r" = "200" ] || head -c2 "$TMP/c97_web.bin" | od -An -tx1 | grep -q '1f 8b' && echo si || echo no)"
r=$(curl -s -b "$JAR_A" -c "$JAR_A" -o /dev/null -w "%{http_code} %{redirect_url}" "$BASE/?r=inicio")
check "La sesión abierta del borrado ya no sirve: va al ingreso" "302 $BASE/?r=login" "$r"
entrar "$EA" "claveSegura1"
check "No puede entrar con su clave" "1" "$(grep -c 'El correo o la contraseña no coinciden' "$TMP/post.html")"
check "Sitio del borrado: 404" "404" "$(c97_codigo "r=sitio&s=$SLUG_A")"
no_contiene "Sitio del borrado: sin su nombre" "$TMP/c97_pag.html" "$NOMBRE_A"
check "Portal del cliente del borrado: 404" "404" "$(c97_codigo "r=portal&t=$TOK_A")"
check "El vecino sigue: 200" "200" "$(c97_codigo "r=sitio&s=$SLUG_B")"
c97_post clientes '{"por_pagina":200}' > /dev/null
check "clientes: no sale" "0" "$(c97_json clientes | grep -c "\"id\":\"$NA\"")"
check "clientes con filtros.estado = papelera: sale" "200 1" "$(c97_post clientes '{"filtros":{"estado":"papelera"}}') $(c97_json clientes | grep -c "\"id\":\"$NA\"")"
check "… con papelera.motivo" "Cerró el local" "$(c97_json clientes.0.papelera.motivo)"
check "Otra operación sobre el borrado: 409 en_papelera" "409 en_papelera" "$(c97_ej nota_interna '{"texto":"x"}' "$NA") $(c97_json detalle.motivo)"

echo "4. Panel Tukán propio: filtro Papelera y restaurar"
JAR_S="$TMP/jar_super"; rm -f "$JAR_S"
curl -s -c "$JAR_S" -b "$JAR_S" -o /dev/null -H "CF-Connecting-IP: 198.51.100.98" "$BASE/tukan_central.php?accion=sso&t=$(c97_sso_token '/?r=admin_negocios')"
curl -s -b "$JAR_S" -c "$JAR_S" -o "$TMP/c97_pag.html" "$BASE/?r=admin_negocios"
no_contiene "Lista del panel: no sale" "$TMP/c97_pag.html" "$NOMBRE_A"
contiene "Lista del panel: opción Papelera" "$TMP/c97_pag.html" 'value="papelera"'
curl -s -b "$JAR_S" -c "$JAR_S" -o "$TMP/c97_pag.html" "$BASE/?r=admin_negocios&f=papelera"
contiene "Filtro Papelera: sale" "$TMP/c97_pag.html" "$NOMBRE_A"
contiene "Filtro Papelera: con la fecha del borrado definitivo" "$TMP/c97_pag.html" "se borra el $(date -d '+30 days' +%d/%m/%Y)"
curl -s -b "$JAR_S" -c "$JAR_S" -o "$TMP/c97_pag.html" "$BASE/?r=admin_negocio&id=$NA"
contiene "Ficha del panel: En la papelera" "$TMP/c97_pag.html" "En la papelera"
contiene "Ficha del panel: motivo" "$TMP/c97_pag.html" "Cerró el local"
T=$(grep -o 'name="csrf" value="[^"]*"' "$TMP/c97_pag.html" | head -1 | sed 's/.*value="//;s/"$//')
r=$(curl -s -b "$JAR_S" -c "$JAR_S" -o /dev/null -w "%{http_code}" --data-urlencode "csrf=$T" "$BASE/?r=admin_negocio&id=$NA&accion=restaurar")
check "Restaurar desde el panel" "302 " "$r $(sql "SELECT eliminado_en FROM negocios WHERE id=$NA")"
check "Restaurado: sitio 200 y entra" "200" "$(c97_codigo "r=sitio&s=$SLUG_A")"
entrar "$EA" "claveSegura1"
check "Restaurado: puede entrar" "200" "$(get "r=inicio")"
check "Restaurado: auditoría del panel" "1" "$(sql "SELECT count(*) FROM registro_admin WHERE negocio_id=$NA AND accion='Restaurado de la papelera'")"

echo "5. Purga a los 30 días con la tarea de cada hora (bin/tareas.php con otra fecha)"
c97_ej borrar_cliente "{\"confirmar\":\"$NA\",\"motivo\":\"Ahora sí\"}" "$NA" > /dev/null
COPIA2=$(c97_json copia)
salida=$(TURENTA_DB_NAME=$DB TURENTA_DB_USER=turenta TURENTA_DB_PASS=$PGPASSWORD php bin/tareas.php "$(date -d '+29 days' '+%F %H:%M')" 2>&1)
check "Tarea a los 29 días: no lo borra" "1" "$(sql "SELECT count(*) FROM negocios WHERE id=$NA")"
salida=$(TURENTA_DB_NAME=$DB TURENTA_DB_USER=turenta TURENTA_DB_PASS=$PGPASSWORD php bin/tareas.php "$(date -d '+31 days' '+%F %H:%M')" 2>&1)
check "Tarea a los 31 días: borrado de verdad" "0 0 0" \
      "$(sql "SELECT count(*) FROM negocios WHERE id=$NA") $(sql "SELECT count(*) FROM eventos WHERE negocio_id=$NA") $(sql "SELECT count(*) FROM usuarios WHERE email='$EA'")"
c97_txt() { if echo "$2" | grep -q -- "$3"; then OK=$((OK+1)); echo "  OK    $1"; else FALLAS=$((FALLAS+1)); echo "  FALLA $1 — [$2]"; fi; }
c97_txt "La tarea lo dice en su resumen" "$salida" "papelera: borrados 1"
check "La copia se conserva" "1" "$([ -f "$COPIA2" ] && echo 1 || echo 0)"
check "El vecino sigue" "1 200" "$(sql "SELECT count(*) FROM negocios WHERE id=$NB") $(c97_codigo "r=sitio&s=$SLUG_B")"
check "Después de la purga: la ficha da 404" "404" "$(c97_post cliente "{\"id\":\"$NA\"}")"
