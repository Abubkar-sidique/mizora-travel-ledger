#!/usr/bin/env bash
# Stage code only. Never switches current, reloads services, copies a DB or migrates.
set -euo pipefail
mode="${1:---dry-run}"
source_root="${2:-$(cd "$(dirname "$0")/.." && pwd)}"
release_id="${3:-$(date -u +%Y%m%dT%H%M%SZ)}"
[[ "$mode" == --dry-run || "$mode" == --stage ]] || { echo 'Use --dry-run or --stage [source-root] [release-id]' >&2; exit 2; }
[[ "$release_id" =~ ^[A-Za-z0-9_-]+$ ]] || exit 2
target="/srv/mizora/releases/$release_id"
printf 'Stage allowlisted application and ops files at %s. No activation or data changes.\n' "$target"
[[ "$mode" == --stage ]] || exit 0
[[ "$EUID" == 0 ]] || { echo 'Staging requires root for read-only release ownership' >&2; exit 1; }
[[ ! -e "$target" ]] || { echo 'Release already exists' >&2; exit 1; }
umask 0022
install -d -m 0755 "$target/ledger/assets" "$target/ops"
for file in index.php ui.php xlsx.php statement.php hardening.php runtime.php .htaccess; do
    install -m 0644 "$source_root/ledger/$file" "$target/ledger/$file"
done
for file in .htaccess app.css app.js print.js validate.js logo.svg brand-plane.svg statement.css statement-preview.js statement-sky.png; do
    install -m 0644 "$source_root/ledger/assets/$file" "$target/ledger/assets/$file"
done
for file in README.md manage.php audit-host.sh backup-offsite.sh recover.sh nginx.conf.example nginx-bootstrap.conf.example trusted-proxy.conf.example php-fpm.conf.example php-fpm-umask.conf backup.env.example logrotate.example mizora-backup.service mizora-backup.timer mizora-backup-alert.service; do
    install -m 0644 "$source_root/ops/$file" "$target/ops/$file"
done
for file in index.php ui.php xlsx.php hardening.php runtime.php; do php -l "$target/ledger/$file"; done
(cd "$target" && find ledger ops -type f -print0 | sort -z | xargs -0 sha256sum > SHA256SUMS)
printf 'Staged only. Review SHA256SUMS and ops/README.md before an operator-approved activation.\n'
