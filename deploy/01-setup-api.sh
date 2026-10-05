#!/usr/bin/env bash
# Run on the server as root. Creates the ERP database/role, .env, installs and migrates the Laravel API.
# Does not touch the existing indianrounds.in app, its database or its nginx config.
set -euo pipefail
cd /srv/erp/api

DBPASS=$(openssl rand -hex 24)
ADMINPASS="$(openssl rand -base64 18 | tr -d '/+=' | cut -c1-16)Aa1!"

# --- database (separate role + DB from the existing 'indianrounds' ones) ---
sudo -u postgres psql -Atc "select 1 from pg_roles where rolname='erp'" | grep -q 1 \
  || sudo -u postgres psql -v ON_ERROR_STOP=1 -c "CREATE ROLE erp LOGIN PASSWORD '${DBPASS}'"
sudo -u postgres psql -Atc "select 1 from pg_database where datname='erp_indianrounds'" | grep -q 1 \
  || sudo -u postgres psql -v ON_ERROR_STOP=1 -c "CREATE DATABASE erp_indianrounds OWNER erp"

# --- .env ---
cp -n .env.example .env
python3 - "$DBPASS" "$ADMINPASS" <<'PY'
import re, sys
dbpass, adminpass = sys.argv[1], sys.argv[2]
p = '.env'
s = open(p).read()
def setv(k, v):
    global s
    line = f'{k}={v}'
    if re.search(rf'^#?\s*{k}=', s, re.M):
        s = re.sub(rf'^#?\s*{k}=.*$', line, s, count=1, flags=re.M)
    else:
        s += '\n' + line
for k, v in {
    'APP_NAME': '"Indian Rounds ERP"', 'APP_ENV': 'production', 'APP_DEBUG': 'false',
    'APP_URL': 'https://erp.indianrounds.in', 'LOG_LEVEL': 'warning',
    'DB_CONNECTION': 'pgsql', 'DB_HOST': '127.0.0.1', 'DB_PORT': '5432',
    'DB_DATABASE': 'erp_indianrounds', 'DB_USERNAME': 'erp', 'DB_PASSWORD': dbpass,
    'SESSION_DRIVER': 'database', 'SESSION_DOMAIN': 'erp.indianrounds.in', 'SESSION_SECURE_COOKIE': 'true',
    'SANCTUM_STATEFUL_DOMAINS': 'erp.indianrounds.in', 'FRONTEND_URL': 'https://erp.indianrounds.in',
    'ADMIN_EMAIL': 'esscay2025@gmail.com', 'ADMIN_PASSWORD': adminpass,
    'CACHE_STORE': 'database', 'QUEUE_CONNECTION': 'sync',
}.items():
    setv(k, v)
open(p, 'w').write(s)
PY
chown deploy:www-data .env
chmod 640 .env

# --- dependencies, key, schema, seed (production seeder creates ONLY the admin above) ---
sudo -u deploy composer install --no-dev --optimize-autoloader --no-interaction
sudo -u deploy php artisan key:generate --force
sudo -u deploy php artisan migrate --force
sudo -u deploy php artisan db:seed --force
# force a password change at first login
sudo -u deploy php artisan tinker --execute="App\Models\User::where('email', env('ADMIN_EMAIL'))->update(['account_status' => 'password_reset_required']); echo App\Models\User::count().' user(s)';"
sudo -u deploy php artisan config:cache
sudo -u deploy php artisan route:cache

# --- permissions for php-fpm (www-data) ---
chgrp -R www-data storage bootstrap/cache
chmod -R g+rwX storage bootstrap/cache

# --- admin credentials: root-only file on the server, not printed ---
umask 077
printf 'ERP admin login\nurl:      https://erp.indianrounds.in\nemail:    esscay2025@gmail.com\npassword: %s\n(password change is forced at first login)\n' "$ADMINPASS" > /root/erp-admin-credentials.txt
echo "API setup done. Admin credentials are in /root/erp-admin-credentials.txt"
