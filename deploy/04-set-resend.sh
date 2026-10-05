#!/usr/bin/env bash
# Run on the server as root. Prompts (hidden) for the Resend API key and stores it only in /srv/erp/api/.env.
# Sends through Resend's SMTP relay, so password-reset emails AND supplier emails both use it.
# Prerequisite: the sending domain (indianrounds.in) is verified in Resend.
set -euo pipefail
cd /srv/erp/api

FROM="${1:-enquiry@indianrounds.in}"
read -r -s -p "Resend API key (starts with re_): " KEY; echo
case "$KEY" in re_*) ;; *) echo "That doesn't look like a Resend API key (should start with re_)"; exit 1;; esac

python3 - "$FROM" "$KEY" <<'PY'
import re, sys
frm, key = sys.argv[1], sys.argv[2]
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
    'MAIL_MAILER': 'smtp', 'MAIL_HOST': 'smtp.resend.com', 'MAIL_PORT': '587', 'MAIL_SCHEME': 'null',
    'MAIL_USERNAME': 'resend', 'MAIL_PASSWORD': '"' + key + '"',
    'MAIL_FROM_ADDRESS': frm, 'MAIL_FROM_NAME': '"Indian Rounds"',
}.items():
    setv(k, v)
open(p, 'w').write(s)
PY
chown deploy:www-data .env
chmod 640 .env
sudo -u deploy php artisan config:cache
echo "Resend configured (from ${FROM})."
