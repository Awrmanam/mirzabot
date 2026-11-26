#!/usr/bin/env bash
# Targeted green-button/admin-menu fix for Mirza deployed without Git metadata.
# Refuses to overwrite locally customized addon files; never touches payments or DB.
set -Eeuo pipefail
umask 077
APP=/var/www/mirzaprobotconfig
OLD=14f2e654a40d19403b248991768602109addce32
NEW=889f134c3d861f055b5da327f6fccb8785c7388b
ROOT=https://raw.githubusercontent.com/Awrmanam/mirzabot
WORK=$(mktemp -d)
BACKUP="/root/mirza-green-post-backup-$(date +%Y%m%d-%H%M%S)"
CHANGED=0
SUCCESS=0
finish() {
  code=$?
  if [[ "$CHANGED" == 1 && "$SUCCESS" != 1 ]]; then
    echo "ERROR: Attempting rollback from $BACKUP" >&2
    for f in keyboard.php mirza_message_tools.php mirza_premium_post_buttons.php; do
      cp -p "$BACKUP/$f" "$APP/$f" || true
    done
    if [[ -e "$BACKUP/message_tools_smoke.php" ]]; then
      cp -p "$BACKUP/message_tools_smoke.php" "$APP/tests/message_tools_smoke.php" || true
    fi
  fi
  rm -rf "$WORK"
  return "$code"
}
trap finish EXIT

[[ "$EUID" == 0 ]] || { echo "STOP: Run as root"; exit 1; }
cd "$APP" || { echo "STOP: Mirza path not found"; exit 1; }
for f in keyboard.php mirza_message_tools.php mirza_premium_post_buttons.php \
  tests/message_tools_smoke.php; do
  [[ -f "$f" ]] || { echo "STOP: Missing $f"; exit 1; }
done
for cmd in curl php8.4 python3 cmp; do command -v "$cmd" >/dev/null; done

mkdir -p "$WORK/tests" "$WORK/old"
for f in mirza_message_tools.php mirza_premium_post_buttons.php tests/message_tools_smoke.php; do
  echo "Fetching and checking: $f"
  curl -fsSL --retry 2 --connect-timeout 12 --max-time 90 \
    "$ROOT/$OLD/$f" -o "$WORK/old/$(basename "$f")"
  if ! cmp -s "$WORK/old/$(basename "$f")" "$APP/$f"; then
    echo "STOP: $f differs from previous release. No changes were made."
    exit 1
  fi
  curl -fsSL --retry 2 --connect-timeout 12 --max-time 90 \
    "$ROOT/$NEW/$f" -o "$WORK/$f"
done

echo "Preparing safe patch for admin keyboard..."
python3 - "$APP/keyboard.php" "$WORK/keyboard.php" <<'PY'
from pathlib import Path
import sys
source, target = map(Path, sys.argv[1:])
content = source.read_bytes()
eol = b'\r\n' if b'\r\n' in content else b'\n'
old = "        [['text' => \"📢 انتشار پست با دکمه\"], ['text' => \"🧹 پاکسازی کیبورد قدیمی\"]],".encode()
new = (
    "        [['text' => \"📢 انتشار پست با دکمه\"], ['text' => \"✨ دکمه روی پست پریمیوم کانال\"]],"
    + eol.decode()
    + "        [['text' => \"🧹 پاکسازی کیبورد قدیمی\"]],"
).encode()
if content.count(old) != 1:
    sys.exit("STOP: admin keyboard differs from expected. No changes were made.")
target.write_bytes(content.replace(old, new, 1))
print("Admin keyboard patch prepared; all other keyboard.php content preserved.")
PY

echo "Running PHP lint and smoke tests without Telegram calls..."
for f in keyboard.php mirza_message_tools.php mirza_premium_post_buttons.php \
  tests/message_tools_smoke.php; do
  php8.4 -l "$WORK/$f"
done
php8.4 "$WORK/tests/message_tools_smoke.php"

echo "Back up four files before replacing..."
mkdir -p "$BACKUP"
cp -p "$APP/keyboard.php" "$BACKUP/keyboard.php"
cp -p "$APP/mirza_message_tools.php" "$BACKUP/mirza_message_tools.php"
cp -p "$APP/mirza_premium_post_buttons.php" "$BACKUP/mirza_premium_post_buttons.php"
cp -p "$APP/tests/message_tools_smoke.php" "$BACKUP/message_tools_smoke.php"

CHANGED=1
cp "$WORK/keyboard.php" "$APP/keyboard.php"
cp "$WORK/mirza_message_tools.php" "$APP/mirza_message_tools.php"
cp "$WORK/mirza_premium_post_buttons.php" "$APP/mirza_premium_post_buttons.php"
cp "$WORK/tests/message_tools_smoke.php" "$APP/tests/message_tools_smoke.php"

echo "Post-deployment PHP lint..."
for f in keyboard.php mirza_message_tools.php mirza_premium_post_buttons.php \
  tests/message_tools_smoke.php; do
  php8.4 -l "$APP/$f"
done
SUCCESS=1
echo "SUCCESS: Green Telegram post buttons + admin menu entry installed."
echo "BACKUP: $BACKUP"
echo "Open /panel to refresh the admin reply keyboard. New published buttons use style=success."
echo "Old posts are not modified; no database, billing, cron or payment changes."
