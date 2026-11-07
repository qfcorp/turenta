#!/usr/bin/env bash
# Instalador de TuRenta (Ubuntu con PHP, PostgreSQL 16, nginx y tunel de Cloudflare).
# Uso (como el usuario normal, NO como root):
#   curl -fsSL https://raw.githubusercontent.com/qfcorp/turenta/main/deploy/instalar.sh -o ~/instalar_turenta.sh && bash ~/instalar_turenta.sh
# Se puede correr varias veces: si ya hay negocios guardados, NO borra la base.
# Los mensajes van sin tildes a proposito (se leen por SSH desde el celular).
set -u

APP="${APP:-/var/www/turenta}"
DOMINIO="${DOMINIO:-turenta.tukanec.com}"
REPO="${REPO:-https://github.com/qfcorp/turenta.git}"
PHPV="${PHPV:-$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || echo 8.3)}"
BASE="${BASE:-turenta}"
ROL="${ROL:-turenta}"
YO="$(id -un)"
cd /tmp || exit 1

if [ "$(id -u)" = "0" ]; then
  echo "AVISO: corre este instalador con tu usuario normal (no como root). Se usa sudo cuando hace falta."
fi

echo "== 1. Programas de PHP"
sudo apt-get install -y "php$PHPV-fpm" "php$PHPV-pgsql" "php$PHPV-gd" "php$PHPV-curl" "php$PHPV-mbstring" "php$PHPV-intl" "php$PHPV-xml" unzip < /dev/null > /dev/null \
  && echo "   listo (PHP $PHPV)" || echo "   AVISO: no se pudieron instalar los programas de PHP $PHPV"

echo "== 2. Bajar o actualizar TuRenta"
if [ -d "$APP/.git" ]; then
  sudo chown -R "$YO":www-data "$APP"
  git -C "$APP" pull -q && echo "   actualizado" || echo "   AVISO: no se pudo actualizar (git pull)"
else
  if sudo git clone -q "$REPO" "$APP"; then echo "   bajado"; else echo "   ERROR: no se pudo bajar $REPO"; exit 1; fi
fi
sudo chown -R "$YO":www-data "$APP"
sudo mkdir -p "$APP/uploads/espacios" "$APP/uploads/logos" "$APP/uploads/comprobantes" "$APP/uploads/firmas" "$APP/config"
sudo chown -R www-data:www-data "$APP/uploads" && sudo chmod -R 750 "$APP/uploads"
# Copias de los negocios borrados desde el Panel Tukan Central (papelera): solo las lee el programa (www-data), nunca la web
BORRADOS="$APP/respaldos/borrados"
if [ -f "$APP/config/.env" ] && grep -qE '^TURENTA_BORRADOS=.' "$APP/config/.env"; then
  BORRADOS="$(grep -E '^TURENTA_BORRADOS=' "$APP/config/.env" | head -1 | cut -d= -f2-)"
fi
sudo mkdir -p "$BORRADOS" && sudo chown -R www-data:www-data "$BORRADOS" && sudo chmod 700 "$BORRADOS" \
  && { [ "$BORRADOS" != "$APP/respaldos/borrados" ] || { sudo chown www-data:www-data "$APP/respaldos" && sudo chmod 700 "$APP/respaldos"; }; } \
  && echo "   carpeta de copias de borrados: $BORRADOS (solo www-data)" || echo "   AVISO: no se pudo preparar $BORRADOS"
sudo chown "$YO":www-data "$APP/config" && sudo chmod 750 "$APP/config"
chmod +x "$APP/deploy/respaldo.sh" "$APP/deploy/conectar_central.sh" 2>/dev/null || true

echo "== 2b. Librerias (composer: TCPDF para los PDF y los codigos QR)"
if ! command -v composer > /dev/null; then
  sudo apt-get install -y composer < /dev/null > /dev/null && echo "   composer instalado" || echo "   AVISO: no se pudo instalar composer"
fi
if command -v composer > /dev/null; then
  (cd "$APP" && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-interaction --no-progress --optimize-autoloader -q < /dev/null) \
    && echo "   librerias listas" || echo "   AVISO: composer install fallo (los PDF no funcionaran)"
fi

echo "== 3. Base de datos"
CLAVE=""
if [ -f "$APP/config/.env" ]; then   # se conserva la clave si ya estaba
  CLAVE="$(grep -E '^TURENTA_DB_PASS=' "$APP/config/.env" | head -1 | cut -d= -f2-)"
fi
[ -z "$CLAVE" ] && CLAVE="$(openssl rand -hex 16)"
sudo -u postgres psql -qc "CREATE ROLE $ROL LOGIN" < /dev/null 2>/dev/null
sudo -u postgres psql -qc "ALTER ROLE $ROL LOGIN PASSWORD '$CLAVE'" < /dev/null > /dev/null
EXISTE="$(sudo -u postgres psql -tAc "SELECT 1 FROM pg_database WHERE datname='$BASE'" < /dev/null)"
NEGOCIOS=0
if [ "$EXISTE" = "1" ]; then
  NEGOCIOS="$(sudo -u postgres psql -d "$BASE" -tAc "SELECT count(*) FROM negocios" < /dev/null 2>/dev/null || echo 0)"
  NEGOCIOS="${NEGOCIOS:-0}"
fi
if [ "$NEGOCIOS" -gt 0 ] 2>/dev/null; then
  echo "   ya hay $NEGOCIOS negocios guardados: la base NO se toca"
  sudo -u postgres psql -d "$BASE" -qc "CREATE EXTENSION IF NOT EXISTS btree_gist; CREATE EXTENSION IF NOT EXISTS pgcrypto;" < /dev/null > /dev/null
else
  sudo -u postgres dropdb --if-exists "$BASE" < /dev/null
  sudo -u postgres createdb -O "$ROL" "$BASE" < /dev/null
  # Las extensiones las crea postgres (el rol de la app no tiene permiso)
  sudo -u postgres psql -d "$BASE" -qc "CREATE EXTENSION IF NOT EXISTS btree_gist; CREATE EXTENSION IF NOT EXISTS pgcrypto;" < /dev/null > /dev/null \
    && echo "   extensiones btree_gist y pgcrypto: listas" || echo "   ERROR al crear las extensiones"
  PGPASSWORD="$CLAVE" psql -h 127.0.0.1 -U "$ROL" -d "$BASE" -q -v ON_ERROR_STOP=1 -f "$APP/db/schema.sql" < /dev/null > /dev/null \
    && echo "   base creada" || echo "   ERROR al crear las tablas"
fi

echo "== 3b. Actualizaciones de la base (migraciones)"
for m in "$APP"/db/migraciones/*.sql; do
  [ -f "$m" ] || continue
  PGPASSWORD="$CLAVE" psql -h 127.0.0.1 -U "$ROL" -d "$BASE" -q -v ON_ERROR_STOP=1 -f "$m" < /dev/null > /dev/null 2>&1 \
    && echo "   $(basename "$m"): listo" || echo "   ERROR en $(basename "$m")"
done

echo "== 4. Configuracion"
EXTRA=""
if [ -f "$APP/config/.env" ]; then   # se conservan Telegram, Payphone y la conexion con el Panel Tukan Central si ya estaban
  EXTRA="$(grep -E '^(TELEGRAM_|PAYPHONE_API_BASE=|STRIPE_API_BASE=|TUKAN_|TURENTA_ANDROID_HUELLAS=|TURENTA_GOOGLE_PLAY_COBRO=|TURENTA_ZONA=)' "$APP/config/.env" || true)"
fi
{
  echo "TURENTA_DB_HOST=127.0.0.1"
  echo "TURENTA_DB_NAME=$BASE"
  echo "TURENTA_DB_USER=$ROL"
  echo "TURENTA_DB_PASS=$CLAVE"
  echo "TURENTA_URL=https://$DOMINIO"
  echo "TURENTA_UPLOADS=$APP/uploads"
  echo "TURENTA_BORRADOS=$BORRADOS"
  [ -n "$EXTRA" ] && echo "$EXTRA"
  # Hora del programa: TuRenta es para negocios de EE.UU. (Florida). Se conserva si ya estaba.
  echo "$EXTRA" | grep -q '^TURENTA_ZONA=' || echo "TURENTA_ZONA=${ZONA:-America/New_York}"
} > "$APP/config/.env.nuevo"
mv "$APP/config/.env.nuevo" "$APP/config/.env"
chmod 640 "$APP/config/.env" && sudo chgrp www-data "$APP/config/.env" && echo "   listo"
echo "$EXTRA" | grep -q '^TELEGRAM_BOT_TOKEN=' && echo "   Telegram: se conservo la configuracion" || echo "   Telegram: sin configurar (agrega TELEGRAM_BOT_TOKEN, TELEGRAM_BOT_USERNAME y TELEGRAM_WEBHOOK_SECRET en config/.env)"
echo "$EXTRA" | grep -q '^TUKAN_CENTRAL_SECRET=' && echo "   Panel Tukan Central: se conservo la conexion" || echo "   Panel Tukan Central: sin conectar (sudo bash $APP/deploy/conectar_central.sh ID SECRETO URL)"

echo "== 5. nginx"
if command -v nginx > /dev/null; then
  sudo tee /etc/nginx/sites-available/turenta > /dev/null <<EOF
server {
    listen 80;
    server_name $DOMINIO;
    root $APP/public;
    index index.php;
    client_max_body_size 12M;

    location / {
        try_files \$uri /index.php\$is_args\$args;
    }
    location /assets/ {
        expires 7d;
        try_files \$uri =404;
    }
    location ^~ /.well-known/ {
        try_files \$uri /index.php\$is_args\$args;
    }
    location ~ /\. { deny all; }
    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php$PHPV-fpm.sock;
    }
}
EOF
  sudo ln -sf /etc/nginx/sites-available/turenta /etc/nginx/sites-enabled/turenta
  sudo nginx -t -q && sudo systemctl reload nginx && sudo systemctl restart "php$PHPV-fpm" && echo "   listo" || echo "   ERROR en la configuracion de nginx"
else
  echo "   (nginx no esta instalado: se omite)"
fi

echo "== 6. Tareas automaticas (cada hora) y respaldo (cada dia 3:25)"
# Las tareas corren como www-data (el mismo usuario de la web): asi pueden borrar las copias y los archivos de la papelera
crontab -l 2>/dev/null | grep -q "turenta/bin/tareas" && (crontab -l 2>/dev/null | grep -v "turenta/bin/tareas") | crontab -
sudo touch /var/log/turenta-tareas.log && sudo chown www-data:www-data /var/log/turenta-tareas.log
(sudo crontab -u www-data -l 2>/dev/null | grep -v "turenta/bin/tareas"; echo "5 * * * * php $APP/bin/tareas.php >> /var/log/turenta-tareas.log 2>&1") | sudo crontab -u www-data -
(sudo crontab -l 2>/dev/null | grep -v "turenta/deploy/respaldo"; echo "25 3 * * * APP=$APP $APP/deploy/respaldo.sh >> /var/log/turenta-respaldo.log 2>&1") | sudo crontab -
echo "   listo"

echo
echo "=========== RESULTADO ==========="
[ -f "$APP/config/.env" ] && echo "1) Configuracion: OK" || echo "1) Configuracion: FALTA"
echo "2) Tablas en la base: $(PGPASSWORD="$CLAVE" psql -h 127.0.0.1 -U "$ROL" -d "$BASE" -tAc "SELECT count(*) FROM information_schema.tables WHERE table_schema='public'" < /dev/null) (deben ser mas de 35)"
echo "3) Extensiones de la base: $(sudo -u postgres psql -d "$BASE" -tAc "SELECT count(*) FROM pg_extension WHERE extname IN ('btree_gist','pgcrypto')" < /dev/null) (deben ser 2)"
echo "4) Extensiones de PHP: $(php -m | grep -E -c '^(pdo_pgsql|gd|curl|mbstring)$') (deben ser 4)"
[ -f "$APP/vendor/autoload.php" ] && echo "5) Librerias (TCPDF): OK" || echo "5) Librerias (TCPDF): FALTA (corre: cd $APP && composer install --no-dev)"
if command -v nginx > /dev/null; then
  curl -s -o /dev/null -w "6) Prueba de la pagina: %{http_code} (debe ser 200)\n" -H "Host: $DOMINIO" "http://127.0.0.1/?r=login"
  curl -s -o /dev/null -w "6b) App de Android (assetlinks.json): %{http_code} (debe ser 200)\n" -H "Host: $DOMINIO" "http://127.0.0.1/.well-known/assetlinks.json"
fi
echo "7) Tareas: $(sudo -u www-data php "$APP/bin/tareas.php" < /dev/null 2>&1 | tail -1)"
echo "8) Respaldo: $(sudo APP="$APP" "$APP/deploy/respaldo.sh" < /dev/null 2>&1 | tail -1)"
echo "9) Super usuarios del panel: $(PGPASSWORD="$CLAVE" psql -h 127.0.0.1 -U "$ROL" -d "$BASE" -tAc "SELECT count(*) FROM superadmins" < /dev/null)"
grep -q '^TUKAN_CENTRAL_SECRET=' "$APP/config/.env" 2>/dev/null && echo "10) Panel Tukan Central: conectado" || echo "10) Panel Tukan Central: sin conectar"
echo "================================="
echo "Para crear o cambiar tu super usuario:  php $APP/bin/crear_admin.php"
echo "Panel Tukan: https://$DOMINIO/?r=admin_entrar"
echo "Conectar con el Panel Tukan Central:  sudo bash $APP/deploy/conectar_central.sh ID SECRETO URL"
