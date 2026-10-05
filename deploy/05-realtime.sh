#!/usr/bin/env bash
# Run on the server as root, AFTER the new api/ and web/ code has been uploaded to /srv/erp.
# Turns on real-time chat (Laravel Reverb) and gives PHP more workers. Does not touch the indianrounds.in site's
# own files or nginx config. (The PHP-FPM pool change gives that site more headroom too, since the pool is shared.)
set -euo pipefail

# --- 1. PHP-FPM workers: 5 -> 20 (the chat polling was queueing behind only 5 workers) ---
POOL=/etc/php/8.5/fpm/pool.d/www.conf
cp -n "$POOL" "$POOL.bak-erp"
sed -i -E 's/^pm\.max_children\s*=.*/pm.max_children = 20/; s/^pm\.start_servers\s*=.*/pm.start_servers = 4/; s/^pm\.min_spare_servers\s*=.*/pm.min_spare_servers = 3/; s/^pm\.max_spare_servers\s*=.*/pm.max_spare_servers = 8/' "$POOL"
php-fpm8.5 -t
systemctl reload php8.5-fpm

# --- 2. PHP dependencies (adds laravel/reverb) ---
cd /srv/erp/api
sudo -u deploy composer install --no-dev --optimize-autoloader --no-interaction

# --- 3. Reverb credentials (generated once, kept in the API .env) ---
python3 - <<'PY'
import re, secrets
p = '.env'
s = open(p).read()
def get(k):
    m = re.search(rf'^{k}=(.*)$', s, re.M)
    return m.group(1).strip() if m else ''
def setv(k, v):
    global s
    line = f'{k}={v}'
    if re.search(rf'^#?\s*{k}=', s, re.M):
        s = re.sub(rf'^#?\s*{k}=.*$', lambda m: line, s, count=1, flags=re.M)
    else:
        s += '\n' + line
vals = {
    'BROADCAST_CONNECTION': 'reverb',
    'REVERB_APP_ID': get('REVERB_APP_ID') or str(secrets.randbelow(900000) + 100000),
    'REVERB_APP_KEY': get('REVERB_APP_KEY') or secrets.token_hex(10),
    'REVERB_APP_SECRET': get('REVERB_APP_SECRET') or secrets.token_hex(20),
    'REVERB_HOST': '127.0.0.1', 'REVERB_PORT': '8085', 'REVERB_SCHEME': 'http',
    'REVERB_SERVER_HOST': '127.0.0.1', 'REVERB_SERVER_PORT': '8085',
}
for k, v in vals.items():
    setv(k, v)
open(p, 'w').write(s)
open('/tmp/.reverb_key', 'w').write(vals['REVERB_APP_KEY'])
PY
chown deploy:www-data .env
chmod 640 .env
sudo -u deploy php artisan config:cache
sudo -u deploy php artisan route:cache

# --- 4. Reverb service ---
install -m 644 /srv/erp/deploy/erp-reverb.service /etc/systemd/system/erp-reverb.service
systemctl daemon-reload
systemctl enable --now erp-reverb
systemctl restart erp-reverb

# --- 5. nginx: proxy only /app (WebSocket) for erp.indianrounds.in ---
install -m 644 /srv/erp/deploy/erp-reverb-nginx.conf /etc/nginx/snippets/erp-reverb.conf
VHOST=/etc/nginx/sites-available/erp.indianrounds.in
cp -n "$VHOST" "$VHOST.bak-erp"
grep -q 'snippets/erp-reverb.conf' "$VHOST" || sed -i '/root \/srv\/erp\/api\/public;/a\    include snippets/erp-reverb.conf;' "$VHOST"
nginx -t                       # stops here (set -e) if the config is invalid
systemctl reload nginx

# --- 6. Web: bake the public key into the build, rebuild, restart ---
cd /srv/erp/web
printf 'NEXT_PUBLIC_REVERB_KEY=%s\n' "$(cat /tmp/.reverb_key)" > .env.production
chown deploy:deploy .env.production
rm -f /tmp/.reverb_key
sudo -u deploy npm run build
systemctl restart erp-web

echo "Real-time chat enabled. Reverb: $(systemctl is-active erp-reverb), web: $(systemctl is-active erp-web)"
