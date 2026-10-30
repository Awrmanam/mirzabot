#!/usr/bin/env bash
# Recover the *confirmed* old no-gallery Mirza installation. Never delete
# invoices, orders, existing payment records or customer's real money.
set -Eeuo pipefail
APP=/var/www/mirzaprobotconfig
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [[ $EUID -ne 0 ]]; then echo "Run with sudo" >&2; exit 2; fi
if [[ ! -f "$APP/api/vpnapp.php" || ! -f "$APP/config.php" ]]; then
  echo "ABORTED: live bot layout not found" >&2; exit 2
fi
if ! id www-data >/dev/null; then
  echo "ABORTED: PHP www-data user not found" >&2; exit 2
fi

# Validate both source transformations before changing ANY live file.
python3 - "$APP/api/vpnapp.php" "$HERE" <<'PY'
import pathlib,sys
path=pathlib.Path(sys.argv[1])
sys.path.insert(0,sys.argv[2])
from patch_vpnapp_no_gallery import build
from patch_vpnapp_legacy_checkout_sql import patched
original=path.read_bytes()
build(original)
patched(original)
print("MIRZA_LEGACY_PREFLIGHT_OK")
PY

# Only now create a private directory OUTSIDE /var/www/mirzaprobotconfig.
install -d -o www-data -g www-data -m 0700 /var/www/mirza-vpnapp-receipts

# Surgical, linted, root-backed-up PHP updates. Each script aborts on any
# unexpected customization; no git pull or replacement of whole vpnapp.php.
python3 "$HERE/patch_vpnapp_no_gallery.py" "$APP/api/vpnapp.php"
python3 "$HERE/patch_vpnapp_legacy_checkout_sql.py" "$APP/api/vpnapp.php"

# Worker files are deployed only after PHP syntax succeeds.
php -l "$APP/api/vpnapp.php"
mkdir -p "$APP/scripts"
install -o root -g www-data -m 0640 "$HERE/vpnapp_receipt_worker.php" "$APP/scripts/vpnapp_receipt_worker.php"
install -o root -g www-data -m 0640 "$HERE/inspect_vpnapp_receipts.php" "$APP/scripts/inspect_vpnapp_receipts.php"
php -l "$APP/scripts/vpnapp_receipt_worker.php"

cat > /etc/systemd/system/mirza-vpnapp-receipt.service <<'SERVICE'
[Unit]
Description=Deliver previously stored Mirza app receipts to Telegram
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
User=www-data
Group=www-data
WorkingDirectory=/var/www/mirzaprobotconfig
EnvironmentFile=-/etc/mirza-vpnapp-receipts.env
ExecStart=/usr/bin/php /var/www/mirzaprobotconfig/scripts/vpnapp_receipt_worker.php
TimeoutStartSec=120
NoNewPrivileges=true
PrivateTmp=true
ProtectHome=true
SERVICE
cat > /etc/systemd/system/mirza-vpnapp-receipt.timer <<'TIMER'
[Unit]
Description=Retry Mirza app receipt photo notifications

[Timer]
OnBootSec=45s
OnUnitInactiveSec=60s
Persistent=true
Unit=mirza-vpnapp-receipt.service

[Install]
WantedBy=timers.target
TIMER
chmod 0644 /etc/systemd/system/mirza-vpnapp-receipt.service /etc/systemd/system/mirza-vpnapp-receipt.timer

for unit in php8.4-fpm php8.3-fpm php8.2-fpm php8.1-fpm; do
  if systemctl is-active --quiet "$unit"; then
    systemctl reload "$unit"
  fi
done
systemctl daemon-reload
systemctl enable --now mirza-vpnapp-receipt.timer

echo "MIRZA_LEGACY_RECOVERY_INSTALLED"
systemctl is-active mirza-vpnapp-receipt.timer
