#!/usr/bin/env bash
# Conecta TuRenta con el Panel Tukan Central (protocolo v1 y v2: mismo ID y mismo secreto).
# Uso (en el servidor, con sudo):
#   sudo bash /var/www/turenta/deploy/conectar_central.sh ID SECRETO URL
# Ejemplo:
#   sudo bash /var/www/turenta/deploy/conectar_central.sh turenta 3f9a...(64 letras y numeros) https://panel.tukanec.com
# Que hace:
#   1) revisa los datos  2) guarda TUKAN_CENTRAL_ID, TUKAN_CENTRAL_SECRET y TUKAN_CENTRAL_URL en config/.env
#      (las demas lineas no se tocan)  3) prepara las tablas del conector  4) recarga PHP
#   5) prueba local: pide el estado a http://127.0.0.1/tukan_central.php con un token firmado y dice OK o el error
# Se puede correr varias veces (por ejemplo, si el panel central cambia el secreto).
# Ingreso del super usuario SOLO desde el panel central: TUKAN_SOLO_CENTRAL=1 en config/.env (0 lo vuelve a prender).
# Los mensajes van sin tildes a proposito (se leen por SSH desde el celular).
set -u

APP="${APP:-/var/www/turenta}"
ENVF="$APP/config/.env"
LOCAL="${LOCAL:-http://127.0.0.1}"            # donde escucha nginx en el servidor
SITIO="${SITIO:-/etc/nginx/sites-available/turenta}"
RE_ID='^[a-z0-9_-]{2,40}$'
RE_SECRETO='^[A-Za-z0-9]{32,128}$'
RE_URL='^https?://[A-Za-z0-9._~:/?#@!$&()*+,;=%-]+$'

uso() {
  echo "Uso: sudo bash $APP/deploy/conectar_central.sh ID SECRETO URL"
  echo "Ejemplo: sudo bash $APP/deploy/conectar_central.sh turenta <secreto de 64 letras y numeros> https://panel.tukanec.com"
}

echo "== 1. Revisar los datos"
if [ "$#" -ne 3 ]; then
  echo "   ERROR: faltan datos. Son 3: ID, SECRETO y URL (los da el Panel Tukan Central)."
  uso; exit 1
fi
ID="$1"; SECRETO="$2"; URL="${3%/}"
if ! [[ "$ID" =~ $RE_ID ]]; then
  echo "   ERROR: el ID debe tener de 2 a 40 letras minusculas, numeros, - o _ (ejemplo: turenta)."; exit 1
fi
if ! [[ "$SECRETO" =~ $RE_SECRETO ]]; then
  echo "   ERROR: el SECRETO debe tener de 32 a 128 letras y numeros, sin espacios (el panel central da 64)."; exit 1
fi
if ! [[ "$URL" =~ $RE_URL ]]; then
  echo "   ERROR: la URL debe empezar con https:// (ejemplo: https://panel.tukanec.com)."; exit 1
fi
if [ "$(id -u)" != "0" ]; then
  echo "   ERROR: corre este comando con sudo:"; uso; exit 1
fi
if [ ! -f "$ENVF" ]; then
  echo "   ERROR: no existe $ENVF. Primero instala TuRenta (instalar.sh)."; exit 1
fi
if [ ! -f "$APP/public/tukan_central.php" ]; then
  echo "   ERROR: falta $APP/public/tukan_central.php. Actualiza TuRenta (corre otra vez instalar.sh)."; exit 1
fi
echo "   listo (ID $ID, panel $URL)"

echo "== 2. Guardar en config/.env"
NUEVO="$(mktemp "$APP/config/.env.XXXXXX")" || { echo "   ERROR: no se pudo escribir en $APP/config"; exit 1; }
grep -v -E '^(TUKAN_CENTRAL_ID|TUKAN_CENTRAL_SECRET|TUKAN_CENTRAL_URL)=' "$ENVF" > "$NUEVO"
if [ -s "$NUEVO" ] && [ -n "$(tail -c1 "$NUEVO")" ]; then echo >> "$NUEVO"; fi   # que termine en salto de linea
{
  echo "TUKAN_CENTRAL_ID=$ID"
  echo "TUKAN_CENTRAL_SECRET=$SECRETO"
  echo "TUKAN_CENTRAL_URL=$URL"
} >> "$NUEVO"
chown --reference="$ENVF" "$NUEVO" 2>/dev/null
chgrp www-data "$NUEVO" 2>/dev/null
chmod 640 "$NUEVO"
mv -f "$NUEVO" "$ENVF" && echo "   listo (permisos 640, grupo www-data; las demas lineas se conservaron)"
if grep -q '^TUKAN_SOLO_CENTRAL=1' "$ENVF"; then
  echo "   El super usuario entra SOLO desde el Panel Tukan (TUKAN_SOLO_CENTRAL=1)"
else
  echo "   El ingreso local del super usuario sigue prendido (para apagarlo: TUKAN_SOLO_CENTRAL=1 en $ENVF)"
fi

echo "== 3. Tablas del conector en la base"
leer() { grep -E "^$1=" "$ENVF" | head -1 | cut -d= -f2-; }
# 095 = protocolo v1 (nonces y limite); 096 = protocolo v2 (operaciones, atenciones, origen de cada negocio); 097 = pausar y papelera; 098 = modulos y pedidos de demo
if [ -f "$APP/db/migraciones/095_tukan_central.sql" ] && command -v psql > /dev/null; then
  HOSTDB="$(leer TURENTA_DB_HOST)"
  BIEN=1
  for MIG in "$APP"/db/migraciones/095_tukan_central.sql "$APP"/db/migraciones/096_tukan_central_v2.sql "$APP"/db/migraciones/097_papelera.sql "$APP"/db/migraciones/098_modulos.sql; do
    [ -f "$MIG" ] || continue
    PGPASSWORD="$(leer TURENTA_DB_PASS)" psql -h "${HOSTDB:-127.0.0.1}" -U "$(leer TURENTA_DB_USER)" \
      -d "$(leer TURENTA_DB_NAME)" -q -v ON_ERROR_STOP=1 -f "$MIG" < /dev/null > /dev/null 2>&1 || BIEN=0
  done
  [ "$BIEN" = "1" ] && echo "   listo" || echo "   AVISO: no se pudo preparar la base (corre otra vez instalar.sh)"
else
  echo "   (se omite)"
fi

echo "== 4. PHP"
RECARGADO=""
if command -v systemctl > /dev/null; then
  for s in $(systemctl list-units --type=service --no-legend 'php*-fpm.service' 2>/dev/null | awk '{print $1}'); do
    systemctl reload "$s" 2>/dev/null && RECARGADO="$RECARGADO $s"
  done
fi
[ -n "$RECARGADO" ] && echo "   recargado:$RECARGADO" || echo "   no hace falta (PHP lee config/.env en cada pedido)"

echo "== 5. Prueba local"
DOMINIO="${DOMINIO:-}"
if [ -z "$DOMINIO" ] && [ -f "$SITIO" ]; then
  DOMINIO="$(grep -m1 -E '^[[:space:]]*server_name' "$SITIO" | awk '{print $2}' | tr -d ';')"
fi
DOMINIO="${DOMINIO:-turenta.tukanec.com}"
TOKEN="$(TC_ID="$ID" TC_SECRETO="$SECRETO" php -r '
$b = fn($s) => rtrim(strtr(base64_encode($s), "+/", "-_"), "=");
$t = time();
$p = ["v" => 1, "tipo" => "estado", "inst" => getenv("TC_ID"), "email" => "autoprueba@tukanec.com", "nombre" => "Prueba local",
      "iat" => $t, "exp" => $t + 60, "nonce" => bin2hex(random_bytes(16))];
$a = $b(json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo $a . "." . $b(hash_hmac("sha256", $a, getenv("TC_SECRETO"), true));')"
if [ -z "$TOKEN" ]; then echo "   ERROR: no se pudo crear el token de prueba (falta php en la linea de comandos?)"; exit 1; fi
SALIDA="$(mktemp)"
CODIGO="$(curl -s -m 20 -o "$SALIDA" -w '%{http_code}' -H "Host: $DOMINIO" "$LOCAL/tukan_central.php?accion=estado&t=$TOKEN")"
RESULTADO="$(CODIGO="$CODIGO" php -r '
$sin = fn($s) => strtr((string) $s, ["\u{e1}"=>"a","\u{e9}"=>"e","\u{ed}"=>"i","\u{f3}"=>"o","\u{fa}"=>"u","\u{f1}"=>"n","\u{c1}"=>"A","\u{c9}"=>"E","\u{cd}"=>"I","\u{d3}"=>"O","\u{da}"=>"U","\u{d1}"=>"N","\u{fc}"=>"u","\u{bf}"=>"","\u{a1}"=>""]);
$j = json_decode((string) file_get_contents($argv[1]), true);
$c = getenv("CODIGO");
if ($c === "200" && is_array($j) && ($j["ok"] ?? false) === true) {
    echo "OK: " . $sin($j["nombre"] ?? "") . " responde al Panel Tukan Central (" . ($j["negocios"]["total"] ?? "?") . " negocios, version " . ($j["version"] ?? "?") . ")";
    exit(0);
}
$e = is_array($j) ? $sin($j["error"] ?? "") : "";
echo "ERROR: la prueba local respondio $c" . ($e !== "" ? " ($e)" : "") . ". ";
echo match ($c) {
    "000" => "No se pudo conectar a nginx en esta maquina.",
    "404" => "tukan_central.php no responde: actualiza el programa (instalar.sh) o revisa que nginx envie los .php a PHP.",
    "401" => "El ID o el SECRETO no coinciden, o la hora del servidor esta mal.",
    "429" => "Demasiadas pruebas seguidas: espera un minuto y vuelve a correr el comando.",
    "500" => "Error interno: corre otra vez instalar.sh (migraciones) y revisa el log de PHP.",
    default => "Revisa nginx y el log de PHP.",
};
exit(1);' "$SALIDA")"
FIN=$?
rm -f "$SALIDA"
echo "   $RESULTADO"
echo
if [ "$FIN" -eq 0 ]; then
  echo "Listo. En el Panel Tukan Central ya puedes ver las cifras y entrar con un clic."
else
  echo "No quedo conectado. Corrige lo de arriba y vuelve a correr:"
  echo "  sudo bash $APP/deploy/conectar_central.sh $ID <SECRETO> $URL"
fi
exit "$FIN"
