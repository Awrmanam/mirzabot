#!/usr/bin/env bash
# Safe Mirza recovery release: stop checkout being blocked by old receipts
# and enable queued Telegram delivery and private-image retrieval.
# No schema migration, payment approval, or full bot replacement.
set -Eeuo pipefail
APP=/var/www/mirzaprobotconfig
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ $EUID -ne 0 ]]; then echo "Run with sudo" >&2; exit 2; fi
if [[ ! -f "$APP/api/vpnapp.php" ]]; then echo "Mirza bridge not installed" >&2; exit 2; fi
# Install diagnostic tools even if subsequent strict patch checks abort.
# Those tools require no payment/order mutations.
install -d -o root -g www-data -m 0750 "$APP/scripts"
install -o root -g www-data -m 0640 "$HERE/inspect_vpnapp_receipts.php" "$APP/scripts/inspect_vpnapp_receipts.php"
python3 "$HERE/inspect_vpnapp_live_layout.py" "$APP/api/vpnapp.php"
bash "$HERE/install_vpnapp_async_receipt.sh"
python3 "$HERE/patch_vpnapp_unblock_card_orders.py" "$APP/api/vpnapp.php"
python3 "$HERE/patch_vpnapp_legacy_receipts.py" "$APP/api/vpnapp.php"
php -l "$APP/api/vpnapp.php"
php -l "$APP/scripts/vpnapp_receipt_worker.php"
install -o root -g www-data -m 0640 "$HERE/vpnapp_receipt_worker.php" "$APP/scripts/vpnapp_receipt_worker.php"
install -o root -g www-data -m 0640 "$HERE/inspect_vpnapp_receipts.php" "$APP/scripts/inspect_vpnapp_receipts.php"
for unit in php8.4-fpm php8.3-fpm php8.2-fpm php8.1-fpm; do
  if systemctl is-active --quiet "$unit"; then systemctl reload "$unit"; fi
done
systemctl enable --now mirza-vpnapp-receipt.timer
systemctl start mirza-vpnapp-receipt.service
echo "MIRZA_PAYMENT_RECOVERY_INSTALLED"
echo "Review receipt worker: journalctl -u mirza-vpnapp-receipt.service -n 50 --no-pager"
