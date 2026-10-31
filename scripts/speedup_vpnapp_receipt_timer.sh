#!/usr/bin/env bash
# Only speed up delivery of SAVED receipt photos to Telegram.
# Does not modify Mirza payment cron, approval delay, invoices, or bot code.
set -Eeuo pipefail
TIMER=/etc/systemd/system/mirza-vpnapp-receipt.timer
OVERRIDE=/etc/systemd/system/mirza-vpnapp-receipt.timer.d/fast-delivery.conf
WORKER=/var/www/mirzaprobotconfig/scripts/vpnapp_receipt_worker.php
EXPECTED_WORKER=b3393cfe35ebe89afa3f97f3bc15f304859d7902

[[ $EUID -eq 0 ]] || { echo "STOP: run as root"; exit 2; }
[[ -f "$TIMER" && -f "$WORKER" ]] || { echo "STOP: expected timer/worker missing"; exit 3; }
[[ "$(git hash-object "$WORKER")" == "$EXPECTED_WORKER" ]] || {
  echo "STOP: Mirza receipt worker differs; no changes"; exit 3;
}
grep -Eq '^OnUnitInactiveSec=60s$' "$TIMER" || {
  echo "STOP: original receipt-only timer differs; no changes"; exit 3;
}
[[ ! -e "$OVERRIDE" ]] || {
  echo "STOP: timer already has a custom speed override; inspect it first"; exit 3;
}
[[ "${1:---check}" == "--apply" || "${1:---check}" == "--check" ]] || {
  echo "Use --check or --apply"; exit 2;
}
echo "SAFE_TIMER_MATCH: receipt notification timer only"
echo "BEFORE: $(systemctl show mirza-vpnapp-receipt.timer -p NextElapseUSecRealtime --value)"
if [[ "${1:---check}" == "--check" ]]; then
  echo "READ_ONLY_CHECK_OK. Apply only after inspecting this result."
  exit 0
fi

mkdir -p "$(dirname "$OVERRIDE")"
# Reset timer interval arrays explicitly; systemd would otherwise combine
# the old 60-second activation with the new 10-second activation.
cat > "$OVERRIDE" <<'UNIT'
[Timer]
OnBootSec=
OnBootSec=10s
OnUnitInactiveSec=
OnUnitInactiveSec=10s
AccuracySec=1s
RandomizedDelaySec=0
UNIT
chmod 0644 "$OVERRIDE"
if ! systemctl daemon-reload || ! systemctl restart mirza-vpnapp-receipt.timer; then
  rm -f "$OVERRIDE"
  systemctl daemon-reload
  systemctl restart mirza-vpnapp-receipt.timer || true
  echo "STOP: timer failed; override removed and original schedule restored"
  exit 4
fi
echo "FAST_RECEIPT_TIMER_ACTIVE"
systemctl show mirza-vpnapp-receipt.timer -p ActiveState -p NextElapseUSecRealtime
echo "No payment/auto-confirmation timers were modified."
