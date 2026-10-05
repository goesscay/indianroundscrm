#!/usr/bin/env bash
# Run on the server as root. Installs the Laravel scheduler (runs `php artisan schedule:run` every minute as the
# `deploy` user) — this is what makes `mail:poll-replies` check the supplier mailbox every minute.
# Only the ERP app's scheduler is added; the existing indianrounds.in app is not touched.
set -euo pipefail

cat > /etc/cron.d/erp-scheduler <<'EOF'
# Indian Rounds ERP - Laravel scheduler
* * * * * deploy cd /srv/erp/api && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
EOF
chmod 644 /etc/cron.d/erp-scheduler

cd /srv/erp/api
sudo -u deploy php artisan schedule:list | grep -E "mail:poll-replies" || echo "WARNING: mail:poll-replies not scheduled"
echo "Scheduler installed (/etc/cron.d/erp-scheduler)."
