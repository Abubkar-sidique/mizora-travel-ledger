#!/usr/bin/env bash
# Read-only host evidence; no DB access, service reload, setup or network connections.
set -euo pipefail
printf 'UTC: '; date -u
uname -a
php -v
php --ini
php -m
if command -v nginx >/dev/null; then nginx -v; nginx -t; else echo 'BLOCKER: nginx absent'; fi
if command -v php-fpm >/dev/null; then php-fpm -t; else echo 'Use the installed versioned php-fpm binary -t'; fi
for path in /srv/mizora/current/ledger /var/lib/mizora /var/lib/mizora/ledger.sqlite /var/lib/mizora/sessions /var/backups/mizora; do
    if [[ -e "$path" ]]; then stat -c '%A %a %U:%G %n' "$path"; else echo "BLOCKER: missing $path"; fi
done
findmnt -T /var/lib/mizora || true
systemctl list-timers --all mizora-backup.timer --no-pager || true
systemctl status mizora-backup.service --no-pager || true
echo 'Review nginx -T and the active FPM pool locally; redact secrets before sharing. Check TLS/domain/proxy externally after approval.'
