#!/usr/bin/env bash
# Regenera el manual de TuRenta: base de ejemplo nueva (datos_ejemplo.php), capturas reales y el PDF.
#   bash docs/manual/generar/generar.sh
# Variables opcionales: PUERTO (8241), DB (turenta_manual_prueba), DB_USER (turenta), DB_PASS (prueba),
#                       SOLO_PDF=1 (no toma capturas, solo vuelve a armar el PDF con las que ya hay),
#                       SOLO=36_ayuda,37_cotizacion_mensaje (toma solo esas capturas y deja las demás).
# Necesita: PostgreSQL (el usuario debe poder crear bases), PHP con pdo_pgsql y gd, composer (TCPDF para los QR),
#           Python 3 con playwright y pypdf, y Chromium de Playwright (PLAYWRIGHT_BROWSERS_PATH).
set -euo pipefail
AQUI=$(cd "$(dirname "$0")" && pwd)
MANUAL=$(cd "$AQUI/.." && pwd)
RAIZ=$(cd "$MANUAL/../.." && pwd)
PUERTO=${PUERTO:-8241}
export TURENTA_DB_NAME=${DB:-turenta_manual_prueba} TURENTA_DB_USER=${DB_USER:-turenta} TURENTA_DB_PASS=${DB_PASS:-prueba}
export TURENTA_URL="http://127.0.0.1:$PUERTO" TZ=America/Guayaquil PGTZ=America/Guayaquil
export PLAYWRIGHT_BROWSERS_PATH=${PLAYWRIGHT_BROWSERS_PATH:-/opt/pw-browsers}
# Sin IA ni correo de verdad: nunca /etc/tukan-ia.env ni /etc/tukan-correo.env (la Ayuda responde con las preguntas guardadas)
export TUKAN_IA_ENV=${TUKAN_IA_ENV:-/nonexistent/tukan-ia.env} TUKAN_CORREO_ENV=${TUKAN_CORREO_ENV:-/nonexistent/tukan-correo.env}
cd "$RAIZ"
if [ "${SOLO_PDF:-0}" != "1" ]; then
  case "$TURENTA_DB_NAME" in *prueba*|*manual*) ;; *) echo "Por seguridad la base debe llamarse *manual* o *prueba*"; exit 1;; esac
  [ -f vendor/autoload.php ] || composer install --no-dev --no-interaction --quiet
  TMPD=$(mktemp -d)
  export TURENTA_UPLOADS="$TMPD/uploads" TURENTA_BORRADOS="$TMPD/borrados"
  echo "== 1. Base de ejemplo nueva ($TURENTA_DB_NAME)"
  export PGPASSWORD=$TURENTA_DB_PASS
  psql -q -h 127.0.0.1 -U "$TURENTA_DB_USER" -d postgres -c "DROP DATABASE IF EXISTS $TURENTA_DB_NAME" -c "CREATE DATABASE $TURENTA_DB_NAME" 2>/dev/null
  psql -q -h 127.0.0.1 -U "$TURENTA_DB_USER" -d "$TURENTA_DB_NAME" -v ON_ERROR_STOP=1 -f db/schema.sql > /dev/null 2>&1
  for m in db/migraciones/*.sql; do
    psql -q -h 127.0.0.1 -U "$TURENTA_DB_USER" -d "$TURENTA_DB_NAME" -v ON_ERROR_STOP=1 -f "$m" > /dev/null 2>&1 || { echo "ERROR en $m"; exit 1; }
  done
  php "$AQUI/datos_ejemplo.php"
  echo "== 2. Programa en http://127.0.0.1:$PUERTO"
  php -S "127.0.0.1:$PUERTO" -t public tests/router.php > "$TMPD/servidor.log" 2>&1 &
  SERVIDOR=$!
  trap 'kill $SERVIDOR 2>/dev/null || true; rm -rf "$TMPD"' EXIT
  for _ in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$PUERTO/?r=login" && break; sleep 0.3; done
  echo "== 3. Capturas"
  [ -z "${SOLO:-}" ] && rm -f "$MANUAL"/capturas/*.png
  python3 "$AQUI/capturas.py" --base "http://127.0.0.1:$PUERTO" --salida "$MANUAL/capturas" ${SOLO:+--solo "$SOLO"}
  if grep -aq "PHP Fatal\|PHP Warning\|TuRenta: " "$TMPD/servidor.log"; then echo "Errores de PHP durante las capturas:"; grep -a "PHP Fatal\|PHP Warning\|TuRenta: " "$TMPD/servidor.log" | cut -c1-300; fi
fi
echo "== 4. PDF del manual"
python3 "$AQUI/pdf.py" "$MANUAL/manual.html" "$RAIZ/docs/Manual_TuRenta.pdf" "Manual de TuRenta"
pdfinfo "$RAIZ/docs/Manual_TuRenta.pdf" 2>/dev/null | grep -E "Pages" || true
