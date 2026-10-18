#!/usr/bin/env bash
# Restore only to a NEW offline file. Never replaces the active database.
set -euo pipefail
umask 0077
[[ $# == 4 ]] || { echo 'Usage: recover.sh BACKUP.sqlite.age AGE_IDENTITY NEW_PRIVATE_DB PUBLIC_ROOT' >&2; exit 2; }
cipher=$1; identity=$2; destination=$3; public=$4
[[ -f "$cipher" && -f "$cipher.sha256" && -f "$identity" && ! -e "$destination" && ! -L "$destination" ]] || exit 1
parent=$(realpath "$(dirname "$destination")")
public=$(realpath "$public")
[[ "$parent/" != "$public/"* ]] || { echo 'Recovery destination must be private' >&2; exit 1; }
(cd "$(dirname "$cipher")" && sha256sum -c "$(basename "$cipher").sha256")
temporary_dir=$(mktemp -d "$parent/recovery-XXXXXXXX")
temporary="$temporary_dir/recovery.sqlite"
age -d -i "$identity" -o "$temporary" "$cipher"
(cd "$temporary_dir" && sha256sum "$(basename "$temporary")" > "$(basename "$temporary").sha256")
root=$(cd "$(dirname "$0")/.." && pwd)
"${MIZORA_PHP:-php}" "$root/ops/manage.php" restore-check --backup="$temporary" --destination="$destination" --public="$public"
echo 'Offline restored file verified. Review before any approved maintenance/activation.'
# Temporary plaintext is intentionally retained privately for inspection. Apply reviewed retention manually.
