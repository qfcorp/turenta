#!/usr/bin/env bash
# Respaldo diario de TuRenta: base de datos + archivos subidos (logos, fotos, comprobantes, firmas). Guarda 14 dias.
# Cron (todos los dias a las 3:25 de la manana):
#   25 3 * * * APP=/var/www/turenta /var/www/turenta/deploy/respaldo.sh >> /var/log/turenta-respaldo.log 2>&1
set -euo pipefail
umask 077   # solo el dueno del servidor puede leer los respaldos

APP="${APP:-/var/www/turenta}"
DESTINO="${DESTINO:-/var/backups/turenta}"
DIAS="${DIAS:-14}"

if [ ! -f "$APP/config/.env" ]; then
  echo "$(date '+%F %T') ERROR: no existe $APP/config/.env"
  exit 1
fi
# Lee los datos de conexion de config/.env
set -a
# shellcheck disable=SC1090
. <(grep -E '^TURENTA_(DB_HOST|DB_NAME|DB_USER|DB_PASS|UPLOADS)=' "$APP/config/.env")
set +a
export PGPASSWORD="${TURENTA_DB_PASS:-}"
UPLOADS="${TURENTA_UPLOADS:-$APP/uploads}"

FECHA=$(date +%Y-%m-%d_%H%M)
mkdir -p "$DESTINO"
chmod 700 "$DESTINO"

pg_dump -h "${TURENTA_DB_HOST:-127.0.0.1}" -U "${TURENTA_DB_USER:-turenta}" -Fc "${TURENTA_DB_NAME:-turenta}" > "$DESTINO/base_$FECHA.dump"
if [ -d "$UPLOADS" ]; then
  tar -czf "$DESTINO/archivos_$FECHA.tar.gz" -C "$(dirname "$UPLOADS")" "$(basename "$UPLOADS")"
fi

# Verifica que el respaldo se pueda leer
pg_restore --list "$DESTINO/base_$FECHA.dump" > /dev/null

# Borra respaldos de mas de $DIAS dias
find "$DESTINO" -name 'base_*.dump' -mtime +"$DIAS" -delete
find "$DESTINO" -name 'archivos_*.tar.gz' -mtime +"$DIAS" -delete

echo "$(date '+%F %T') respaldo OK: base $(du -h "$DESTINO/base_$FECHA.dump" | cut -f1)$([ -f "$DESTINO/archivos_$FECHA.tar.gz" ] && echo ", archivos $(du -h "$DESTINO/archivos_$FECHA.tar.gz" | cut -f1)")"
