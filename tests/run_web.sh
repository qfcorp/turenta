#!/usr/bin/env bash
# Corre TODAS las pruebas de pantallas (tests/web_*.sh) contra un sitio de prueba que levanta solo.
# Uso: bash tests/run_web.sh            (o: bash tests/run_web.sh web_20 para una sola)
# Requiere PostgreSQL local con el usuario turenta (clave prueba) que pueda crear bases.
set -u
cd "$(dirname "$0")/.."
export DB=${DB:-turenta_web_prueba}
export PGPASSWORD=${PGPASSWORD:-prueba}
export PGTZ=America/Guayaquil
PUERTO=${PUERTO:-8199}
export BASE="http://127.0.0.1:$PUERTO"
export TMP=$(mktemp -d)
export TURENTA_UPLOADS="$TMP/uploads"
export TURENTA_BORRADOS="$TMP/borrados"   # copias de la papelera (web_97)
# Conector con el Panel Tukán Central (web_95_central.sh): datos de prueba, el sitio y las pruebas los ven igual
export TUKAN_CENTRAL_ID=turenta-prueba
export TUKAN_CENTRAL_SECRET=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef
export TUKAN_CENTRAL_URL=http://central.prueba.local
export TUKAN_SOLO_CENTRAL=0
# IA y correo de Tukán (web_110_ia.sh): archivos de prueba (sin ellos: sin IA y sin correo); nunca /etc/tukan-ia.env ni /etc/tukan-correo.env
export TUKAN_IA_ENV="$TMP/ia.env"
export TUKAN_CORREO_ENV="$TMP/correo.env"
# Base limpia
psql -h 127.0.0.1 -U turenta -d postgres -qc "DROP DATABASE IF EXISTS $DB" > /dev/null 2>&1
psql -h 127.0.0.1 -U turenta -d postgres -qc "CREATE DATABASE $DB" > /dev/null
psql -h 127.0.0.1 -U turenta -d "$DB" -q -v ON_ERROR_STOP=1 -f db/schema.sql > /dev/null || { echo "ERROR en schema.sql"; exit 1; }
for m in db/migraciones/*.sql; do [ -f "$m" ] || continue; psql -h 127.0.0.1 -U turenta -d "$DB" -q -v ON_ERROR_STOP=1 -f "$m" > /dev/null || { echo "ERROR en $m"; exit 1; }; done
# Sitio de prueba
TURENTA_DB_NAME=$DB TURENTA_DB_USER=turenta TURENTA_DB_PASS=$PGPASSWORD TURENTA_URL=$BASE \
  php -S 127.0.0.1:$PUERTO -t public tests/router.php > "$TMP/servidor.log" 2>&1 &
SERVIDOR=$!
trap 'kill $SERVIDOR 2>/dev/null' EXIT
sleep 1
OK=0; FALLAS=0
for f in tests/web_*.sh; do
  [ "$f" = "tests/web_base.sh" ] && continue
  [ -n "${1:-}" ] && [[ "$f" != *"$1"* ]] && continue
  echo; echo "==================== $(basename "$f" .sh) ===================="
  source tests/web_base.sh
  source "$f"
done
echo
echo "----------------------------------------"
echo "Pantallas — correctas: $OK  ·  Fallas: $FALLAS"
if grep -qiE "fatal|warning|deprecated|notice" "$TMP/servidor.log"; then
  echo "AVISOS DE PHP en el servidor de prueba:"; grep -iE "fatal|warning|deprecated|notice" "$TMP/servidor.log" | sort | uniq -c | head -30
  FALLAS=$((FALLAS+1))
fi
[ "$FALLAS" -eq 0 ]
