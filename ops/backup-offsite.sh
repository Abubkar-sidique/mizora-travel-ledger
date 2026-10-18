#!/usr/bin/env bash
# Invoked only by an operator-enabled service. Does not delete backups or financial data.
set -euo pipefail
umask 0077
: "${AGE_RECIPIENT:?Configure the offline recovery public recipient}"
: "${RCLONE_TARGET:?Configure a private off-server rclone destination}"
: "${RCLONE_CONFIG:?Configure least-privilege off-server upload credentials}"
[[ "$AGE_RECIPIENT" != *REPLACE* && "$RCLONE_TARGET" != *REPLACE* ]] || exit 2
command -v age >/dev/null
command -v rclone >/dev/null
backup_dir=${MIZORA_BACKUP_DIR:-/var/backups/mizora}
database=${MIZORA_DATABASE:-/var/lib/mizora/ledger.sqlite}
php_binary=${MIZORA_PHP:-/usr/bin/php}
lock="$backup_dir/offsite.lock"
exec 9>"$lock"
flock -n 9 || { echo 'Offsite job already running' >&2; exit 1; }
root=$(cd "$(dirname "$0")/.." && pwd)
backup=$("$php_binary" "$root/ops/manage.php" backup --database="$database" --directory="$backup_dir" --public="$root/ledger")
encrypted="$backup.age"
[[ ! -e "$encrypted" && ! -e "$encrypted.partial" && ! -L "$encrypted" && ! -L "$encrypted.partial" ]] || exit 1
age -r "$AGE_RECIPIENT" -o "$encrypted.partial" "$backup"
mv -- "$encrypted.partial" "$encrypted"
(cd "$backup_dir" && sha256sum "$(basename "$encrypted")" > "$(basename "$encrypted").sha256")
rclone copyto "$encrypted" "${RCLONE_TARGET%/}/$(basename "$encrypted")" --immutable --config "$RCLONE_CONFIG"
rclone copyto "$encrypted.sha256" "${RCLONE_TARGET%/}/$(basename "$encrypted").sha256" --immutable --config "$RCLONE_CONFIG"
# Read back and hash the remote ciphertext; a successful upload alone is insufficient.
remote_hash=$(rclone cat "${RCLONE_TARGET%/}/$(basename "$encrypted")" --config "$RCLONE_CONFIG" | sha256sum | cut -d' ' -f1)
local_hash=$(sha256sum "$encrypted" | cut -d' ' -f1)
[[ "$remote_hash" == "$local_hash" ]] || { echo 'Offsite verification failed' >&2; exit 1; }
date -u +%FT%TZ > "$backup_dir/last-offsite-success"
echo 'PASS encrypted off-server backup verified'
