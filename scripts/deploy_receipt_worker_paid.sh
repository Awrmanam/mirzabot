#!/usr/bin/env bash
# Patch ONLY the private receipt notification worker; never bot checkout,
# payment approval, subscription, API, or any other cron.
set -Eeuo pipefail
LIVE=/var/www/mirzaprobotconfig/scripts/vpnapp_receipt_worker.php
BRANCH=fix/receipt-worker-paid-compat-20261030
EXPECTED_OLD=ac266404275c372845da6b496432608c88de59fd
EXPECTED_NEW=b3393cfe35ebe89afa3f97f3bc15f304859d7902

[[ ${EUID} -eq 0 ]] || { echo 'STOP: run as root'; exit 2; }
[[ -f "$LIVE" ]] || { echo "STOP: Mirza worker missing"; exit 2; }
actual=$(git hash-object "$LIVE")
if [[ "$actual" == "$EXPECTED_NEW" ]]; then
  echo "WORKER_ALREADY_UPDATED"
  exit 0
fi
[[ "$actual" == "$EXPECTED_OLD" ]] || {
  echo "STOP: custom Mirza worker differs from the tested original: $actual"
  echo "No file changed; inspect differences before applying a patch."
  exit 3
}
if [[ "${1:---check}" != "--apply" ]]; then
  echo "CHECK_OK: one worker-file patch; paid orders still report the receipt."
  echo "To apply rerun with --apply. No other files or cron entries change."
  exit 0
fi
tmp=$(mktemp)
trap 'rm -f "$tmp"' EXIT
curl -fLsS --connect-timeout 8 --max-time 35 \
  "https://raw.githubusercontent.com/Awrmanam/mirzabot/$BRANCH/scripts/vpnapp_receipt_worker.php" \
  -o "$tmp"
[[ $(git hash-object "$tmp") == "$EXPECTED_NEW" ]] || {
  echo "STOP: downloaded file hash differs from reviewed version"; exit 4;
}
php -l "$tmp"
backup="$LIVE.pre-paid-report-$(date -u +%Y%m%dT%H%M%SZ).bak"
cp -p "$LIVE" "$backup"
cp "$tmp" "$LIVE"
php -l "$LIVE"
echo "MIRZA_WORKER_UPDATED"
echo "BACKUP: $backup"
echo "Existing systemd timer continues unchanged."
