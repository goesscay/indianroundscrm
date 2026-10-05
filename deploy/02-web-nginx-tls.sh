#!/usr/bin/env bash
# Run on the server as root AFTER 01-setup-api.sh. Does not modify the existing indianrounds.in site.
set -euo pipefail

cd /srv/erp/web
sudo -u deploy npm ci --no-audit --no-fund
sudo -u deploy npm run build

install -m 644 /srv/erp/deploy/erp-web.service /etc/systemd/system/erp-web.service
systemctl daemon-reload
systemctl enable --now erp-web

install -m 644 /srv/erp/deploy/erp.indianrounds.in.conf /etc/nginx/sites-available/erp.indianrounds.in
ln -sf /etc/nginx/sites-available/erp.indianrounds.in /etc/nginx/sites-enabled/erp.indianrounds.in
nginx -t                      # stops here (set -e) if the config is invalid
systemctl reload nginx        # reload, not restart: the existing site keeps serving

# TLS via the server's existing certbot account (DNS for erp.indianrounds.in already points here)
certbot --nginx -d erp.indianrounds.in --redirect --non-interactive
curl -sI https://erp.indianrounds.in/login | head -3
