#!/usr/bin/env bash
# App de Android "TuRenta" (Google Play): revisa que /.well-known/assetlinks.json y la politica de privacidad respondan,
# y si le pasas la huella de "Firma de apps de Google Play" (Play Console, despues de la primera subida), la agrega.
# Uso en el servidor (qfcorp, usuario qf), despues de actualizar con el instalador:
#   sudo bash /var/www/turenta/deploy/app_android.sh                 (solo revisa)
#   sudo bash /var/www/turenta/deploy/app_android.sh HUELLA_DE_GOOGLE (agrega la huella y revisa)
# La huella queda en config/.env como TURENTA_ANDROID_HUELLAS (el instalador la conserva). La de nuestra llave de subida va siempre.
# Se puede correr varias veces. Para pruebas (sin sudo): BASE_URL_PRUEBA=1 APP=/otra BASE_URL=http://127.0.0.1:8501 HOST=otro bash ...
set -u
APP=${APP:-/var/www/turenta}
APP=${APP%/}
ENVF="$APP/config/.env"
HOST=${HOST:-turenta.tukanec.com}
BASE_URL=${BASE_URL:-http://127.0.0.1}
PAQUETE="com.tukansoftware.turenta"
if [ -z "${BASE_URL_PRUEBA:-}" ] && [ "$(id -u)" -ne 0 ]; then echo "Correlo con sudo:  sudo bash $0 ${1:-}"; exit 1; fi
if [ ! -f "$ENVF" ]; then echo "Falta $ENVF. Primero instala o actualiza TuRenta (instalar.sh)."; exit 1; fi

if [ -n "${1:-}" ]; then
  H=$(printf '%s' "$1" | tr 'a-f' 'A-F' | tr -d ' ')
  if ! printf '%s' "$H" | grep -qE '^([0-9A-F]{2}:){31}[0-9A-F]{2}$'; then
    echo "ERROR: la huella debe tener 32 pares de letras o numeros separados por dos puntos (como 39:0C:08:...:DC). No se cambio nada."
    exit 1
  fi
  ACTUAL=$(grep -E '^TURENTA_ANDROID_HUELLAS=' "$ENVF" | tail -1 | cut -d= -f2-)
  if printf '%s' ",$ACTUAL," | grep -q ",$H,"; then
    echo "Esa huella ya estaba puesta. No se cambio nada."
  else
    NUEVA=$( [ -n "$ACTUAL" ] && echo "$ACTUAL,$H" || echo "$H" )
    TMPF=$(mktemp "$APP/config/.env.XXXXXX") || { echo "ERROR: no se pudo escribir en $APP/config"; exit 1; }
    grep -v -E '^TURENTA_ANDROID_HUELLAS=' "$ENVF" > "$TMPF"
    if [ -s "$TMPF" ] && [ -n "$(tail -c1 "$TMPF")" ]; then echo >> "$TMPF"; fi
    echo "TURENTA_ANDROID_HUELLAS=$NUEVA" >> "$TMPF"
    chmod --reference="$ENVF" "$TMPF" 2>/dev/null; chown --reference="$ENVF" "$TMPF" 2>/dev/null
    mv "$TMPF" "$ENVF"
    echo "Listo: huella de Google agregada en config/.env"
  fi
fi

# Prueba: el archivo debe salir como JSON con el paquete de la app (y la huella, si se paso), y la privacidad con 200
RESP=$(curl -sk -D - -H "Host: $HOST" "$BASE_URL/.well-known/assetlinks.json")
CODIGO=$(printf '%s' "$RESP" | head -1 | awk '{print $2}')
TIPO=$(printf '%s' "$RESP" | grep -i '^content-type:' | head -1 | tr -d '\r')
echo "Prueba de /.well-known/assetlinks.json -> $CODIGO ($TIPO)"
printf '%s' "$RESP" | sed '1,/^\r\{0,1\}$/d'
echo
PRIV=$(curl -sk -o /dev/null -w '%{http_code}' -H "Host: $HOST" "$BASE_URL/privacidad.php")
echo "Prueba de /privacidad.php -> $PRIV"
if [ "$CODIGO" = "200" ] && printf '%s' "$TIPO" | grep -qi 'application/json' && printf '%s' "$RESP" | grep -q "$PAQUETE" \
   && { [ -z "${1:-}" ] || printf '%s' "$RESP" | grep -qi "$(printf '%s' "$1" | tr -d ' ')"; } && [ "$PRIV" = "200" ]; then
  echo "OK: la app de Android ya puede verificar el sitio."
else
  echo "ALGO FALLO: debe salir 200, application/json, el texto con $PAQUETE (y la huella nueva) y la privacidad con 200. Mandame todo lo que salio arriba."
  exit 1
fi
