#!/usr/bin/env bash
# OPTIONAL fast receipt notifications only; read-only by default.
set -Eeuo pipefail
mode="$(printf '%s' "$@" | awk '{print $1}')"
[[ -n "$mode" ]] || mode=--check
name=mirza-vpnapp-receipt.timer
override=/etc/systemd/system/mirza-vpnapp-receipt.timer.d/fast-receipts.conf
dir="$(printenv MIRZA_DIR || true)"
[[ -n "$dir" ]] || dir=/var/www/mirzaprobotconfig
worker="$dir/scripts/vpnapp_receipt_worker.php"
new=7d28c50a512be8dead9d3a11abf041bb09eefc623732a72f2012b82d7a4eb8ba
[[ $EUID -eq 0 ]] || { echo "STOP: root required"; exit 2; }
[[ -f "$worker" && "$(sha256sum "$worker" | awk '{print $1}')" == "$new" ]] || { echo "STOP: install vetted worker first"; exit 3; }
systemctl is-active "$name" >/dev/null || { echo "STOP: timer inactive"; exit 3; }
systemctl is-enabled "$name" >/dev/null || { echo "STOP: timer not enabled"; exit 3; }
systemctl show "$name" -p ActiveState -p NextElapseUSecRealtime
if [[ "$mode" == --rollback ]]; then
  [[ -f "$override" ]] || { echo "STOP: no override"; exit 3; }
  grep -Fq "# Managed by receipt-only installer" "$override" || { echo "STOP: unknown override"; exit 4; }
  rm -f "$override"
  systemctl daemon-reload
  systemctl restart "$name"
  echo "RECEIPT_ONLY_TIMER_RESTORED"
  exit 0
fi
[[ "$mode" == --check || "$mode" == --apply ]] || { echo "Use --check/--apply/--rollback"; exit 2; }
[[ ! -e "$override" ]] || { echo "STOP: custom override exists"; exit 3; }
[[ -f "/etc/systemd/system/$name" ]] || { echo "STOP: timer definition not found at expected path"; exit 3; }
grep -Eq '^OnUnitInactiveSec=60s$' "/etc/systemd/system/$name" || { echo "STOP: original timer interval unknown"; exit 4; }
if [[ "$mode" == --check ]]; then echo "READ_ONLY: proposed 10-second receipt-only timer"; exit 0; fi
mkdir -p "$(dirname "$override")"
cat > "$override" <<'UNIT'
# Managed by receipt-only installer
[Timer]
OnBootSec=
OnBootSec=10s
OnUnitInactiveSec=
OnUnitInactiveSec=10s
AccuracySec=1s
RandomizedDelaySec=0
UNIT
if ! systemctl daemon-reload || ! systemctl restart "$name"; then
  rm -f "$override"
  systemctl daemon-reload
  systemctl restart "$name" || true
  echo "STOP: override rolled back"; exit 5
fi
echo "FAST_RECEIPT_TIMER_ACTIVE; PAYMENT_CRON_UNTOUCHED"
