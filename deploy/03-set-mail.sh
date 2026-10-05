#!/usr/bin/env bash
# Run on the server as root. Prompts (hidden) for the mailbox password and stores it only in /srv/erp/api/.env.
# Mail is sent through Microsoft 365 (the indianrounds.in MX/SPF point at Outlook):
#   smtp.office365.com:587 STARTTLS, authenticated as the mailbox itself.
set -euo pipefail
cd /srv/erp/api

MAILBOX="${1:-enquiry@indianrounds.in}"
read -r -s -p "Password for ${MAILBOX}: " MAILPASS; echo
[ -n "$MAILPASS" ] || { echo "empty password, aborting"; exit 1; }

python3 - "$MAILBOX" "$MAILPASS" <<'PY'
import re, sys
mailbox, pw = sys.argv[1], sys.argv[2]
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
    'MAIL_MAILER': 'smtp', 'MAIL_HOST': 'smtp.office365.com', 'MAIL_PORT': '587', 'MAIL_SCHEME': 'null',
    'MAIL_USERNAME': mailbox, 'MAIL_PASSWORD': '"' + pw.replace('\\', '\\\\').replace('"', '\\"') + '"',
    'MAIL_FROM_ADDRESS': mailbox, 'MAIL_FROM_NAME': '"Indian Rounds"',
}.items():
    setv(k, v)
open(p, 'w').write(s)
PY
chown deploy:www-data .env
chmod 640 .env
sudo -u deploy php artisan config:cache
echo "Mail configured for ${MAILBOX}. Test with: sudo -u deploy php artisan tinker --execute=\"Mail::raw('test', fn(\$m) => \$m->to('YOUR@EMAIL')->subject('ERP mail test'));\""
