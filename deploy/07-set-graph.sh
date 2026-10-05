#!/usr/bin/env bash
# Run on the server as root AFTER the Microsoft Entra app is registered (see api/docs/MAIL_INTEGRATION.md).
# Prompts for the tenant id, client id and (hidden) client secret, stores them ONLY in /srv/erp/api/.env,
# switches the mail transport to Microsoft Graph and verifies the connection with `mail:test-graph`.
# If the check fails it restores the previous transport so nothing breaks.
set -euo pipefail
cd /srv/erp/api

MAILBOX="${1:-enquiry@indianrounds.in}"
read -r -p "Directory (tenant) ID: " TENANT
read -r -p "Application (client) ID: " CLIENT
read -r -s -p "Client secret VALUE (hidden): " SECRET; echo
[ -n "$TENANT" ] && [ -n "$CLIENT" ] && [ -n "$SECRET" ] || { echo "All three values are required"; exit 1; }

cp .env ".env.bak-before-graph"
python3 - "$TENANT" "$CLIENT" "$SECRET" "$MAILBOX" <<'PY'
import re, sys
tenant, client, secret, mailbox = sys.argv[1:5]
p = '.env'
s = open(p).read()
def setv(k, v):
    global s
    line = f'{k}={v}'
    if re.search(rf'^#?\s*{k}=', s, re.M):
        s = re.sub(rf'^#?\s*{k}=.*$', lambda m: line, s, count=1, flags=re.M)
    else:
        s += '\n' + line
for k, v in {
    'MAIL_TRANSPORT': 'graph', 'MS_TENANT_ID': tenant, 'MS_CLIENT_ID': client,
    'MS_CLIENT_SECRET': '"' + secret.replace('"', '\\"') + '"', 'MS_MAILBOX': mailbox,
}.items():
    setv(k, v)
open(p, 'w').write(s)
PY
chown deploy:www-data .env
chmod 640 .env
sudo -u deploy php artisan config:clear >/dev/null
sudo -u deploy php artisan config:cache >/dev/null

echo "Checking the Microsoft connection..."
if sudo -u deploy php artisan mail:test-graph; then
  echo "Microsoft 365 mail is connected for ${MAILBOX}."
else
  echo "Check FAILED - restoring the previous settings."
  cp .env.bak-before-graph .env
  chown deploy:www-data .env; chmod 640 .env
  sudo -u deploy php artisan config:cache >/dev/null
  exit 1
fi
