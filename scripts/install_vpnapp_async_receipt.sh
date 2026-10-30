#!/usr/bin/env bash
# One-time safe Mirza receipt-queue deployment. Run as root after cloning
# fix/async-receipt-telegram-20261030. Never overwrites the whole bot bridge.
set -Eeuo pipefail

APP=/var/www/mirzaprobotconfig
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [[ ${EUID} -ne 0 ]]; then
  echo "Run this installer with sudo" >&2
  exit 2
fi
if [[ ! -f "$APP/api/vpnapp.php" ]] || [[ ! -f "$APP/config.php" ]]; then
  echo "Live Mirza installation not found; no changes made" >&2
  exit 2
fi
if ! id www-data >/dev/null 2>&1; then
  echo "PHP-FPM www-data user not found; no changes made" >&2
  exit 2
fi
install -d -m 0700 -o www-data -g www-data /var/www/mirza-vpnapp-receipts
python3 "$HERE/patch_vpnapp_async_receipt.py" "$APP/api/vpnapp.php"
install -D -o root -g www-data -m 0640     "$HERE/vpnapp_receipt_worker.php" "$APP/scripts/vpnapp_receipt_worker.php"
php -l "$APP/api/vpnapp.php"
php -l "$APP/scripts/vpnapp_receipt_worker.php"
# Reload PHP opcode cache so the signed bridge immediately uses the new queue.
for unit in php8.4-fpm php8.3-fpm php8.2-fpm php8.1-fpm; do
  if systemctl is-active --quiet "$unit"; then
    systemctl reload "$unit"
  fi
done

# Do not require a cron daemon; systemd serializes periodic delivery.
cat > /etc/systemd/system/mirza-vpnapp-receipt.service <<EOF
[Unit]
Description=Mirza queued VPN app receipt Telegram notifications
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
User=www-data
Group=www-data
WorkingDirectory=$APP
EnvironmentFile=-/etc/mirza-vpnapp-receipts.env
ExecStart=/usr/bin/php $APP/scripts/vpnapp_receipt_worker.php
TimeoutStartSec=240
NoNewPrivileges=true
PrivateTmp=true
ProtectHome=true
EOF
cat > /etc/systemd/system/mirza-vpnapp-receipt.timer <<'EOF'
[Unit]
Description=Run Mirza receipt delivery every minute

[Timer]
OnBootSec=45s
OnUnitInactiveSec=60s
Persistent=true
Unit=mirza-vpnapp-receipt.service

[Install]
WantedBy=timers.target
EOF
chmod 0644 /etc/systemd/system/mirza-vpnapp-receipt.service     /etc/systemd/system/mirza-vpnapp-receipt.timer
systemctl daemon-reload
systemctl enable --now mirza-vpnapp-receipt.timer
echo "MIRZA_ASYNC_RECEIPT_INSTALLED"
systemctl status mirza-vpnapp-receipt.timer --no-pager -l || true
