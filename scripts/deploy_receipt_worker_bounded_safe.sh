#!/usr/bin/env bash
# Receipts-only opt-in deployment. --check never writes.
set -Eeuo pipefail
mode="$(printf '%s' "$@" | awk '{print $1}')"
[[ -n "$mode" ]] || mode=--check
dir="$(printenv MIRZA_DIR || true)"
[[ -n "$dir" ]] || dir=/var/www/mirzaprobotconfig
file="$dir/scripts/vpnapp_receipt_worker.php"
backup_root=/root/mirza-receipt-worker-backups
old=38930780513cf3b022cade6f30116b6c6e8c971a550d67332bca1764a426c012
new=4e097cdaac5fb8a704f4edc15b9dbb0b850140bfc5d61dacfa3113e9fcd008fb
url=https://raw.githubusercontent.com/Awrmanam/mirzabot/fix/receipt-worker-bounded-batch-20261101/scripts/vpnapp_receipt_worker.php
[[ $EUID -eq 0 ]] || { echo "STOP: run as root"; exit 2; }
[[ -f "$file" ]] || { echo "STOP: worker missing"; exit 3; }
actual="$(sha256sum "$file" | awk '{print $1}')"
if [[ "$mode" == --rollback ]]; then
  backup="$(printf '%s' "$@" | awk '{print $2}')"
  [[ -f "$backup" && "$backup" == "$backup_root/"* ]] || { echo "STOP: provide a valid backup path"; exit 4; }
  [[ "$actual" == "$new" ]] || { echo "STOP: worker changed since patch"; exit 5; }
  [[ "$(sha256sum "$backup" | awk '{print $1}')" == "$old" ]] || { echo "STOP: backup hash mismatch"; exit 5; }
  php -l "$backup" >/dev/null
  stage="$(mktemp "$dir/scripts/.rollback.XXXXXXXX")"
  trap 'rm -f "$stage"' EXIT
  cp "$backup" "$stage"
  chown --reference="$file" "$stage"
  chmod --reference="$file" "$stage"
  mv -f "$stage" "$file"
  echo "ROLLED_BACK_WORKER_ONLY"
  exit 0
fi
[[ "$mode" == --check || "$mode" == --apply ]] || { echo "Use --check/--apply/--rollback PATH"; exit 2; }
echo "CURRENT_WORKER_SHA256=$actual"
if [[ "$actual" == "$new" ]]; then echo "ALREADY_PATCHED"; exit 0; fi
[[ "$actual" == "$old" ]] || { echo "STOP: unknown worker version"; exit 3; }
echo "VERIFIED_CURRENT_WORKER"
if [[ "$mode" == --check ]]; then echo "READ_ONLY_OK: nothing changed"; exit 0; fi
stage="$(mktemp "$dir/scripts/.candidate.XXXXXXXX")"
trap 'rm -f "$stage"' EXIT
curl -fLsS --connect-timeout 8 --max-time 40 "$url" -o "$stage"
[[ "$(sha256sum "$stage" | awk '{print $1}')" == "$new" ]] || { echo "STOP: candidate SHA256 mismatch"; exit 4; }
php -l "$stage" >/dev/null
mkdir -p -m 0700 "$backup_root"
backup="$backup_root/worker-$(date -u +%Y%m%dT%H%M%SZ)-$$.php.bak"
cp -p "$file" "$backup"
chmod 0600 "$backup"
[[ "$(sha256sum "$backup" | awk '{print $1}')" == "$old" ]] || { echo "STOP: backup failed"; exit 5; }
chown --reference="$file" "$stage"
chmod --reference="$file" "$stage"
mv -f "$stage" "$file"
if ! php -l "$file"; then cp -p "$backup" "$file"; echo "RESTORED_OLD_WORKER"; exit 6; fi
echo "WORKER_ONLY_PATCH_INSTALLED"
echo "BACKUP=$backup"
echo "ROLLBACK: bash scripts/deploy_receipt_worker_bounded_safe.sh --rollback $backup"
echo "No bot, API, payment, database, cron or timer changes made."
