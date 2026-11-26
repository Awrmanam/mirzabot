#!/usr/bin/env bash
# Incremental upgrade for a Mirza installation deployed without .git.
# Strictly replaces the add-on's OWN files after comparing them to the previous
# pinned release. Does not touch index.php, keyboard.php, config, cron or MySQL.
set -Eeuo pipefail
umask 077
APP=/var/www/mirzaprobotconfig
PREV=bc0f41a92b0bb4cb7982d26d67ce84e4e72bf68b
NEXT=20d8370b48eb9fbbb68e667567e4b644ad4db25b
ROOT=https://raw.githubusercontent.com/Awrmanam/mirzabot
STAGE=$(mktemp -d)
BACKUP="/root/mirza-premium-post-backup-$(date +%Y%m%d-%H%M%S)"
CHANGED=0
SUCCESS=0

cleanup() {
  local code=$?
  if [[ "$CHANGED" == 1 && "$SUCCESS" != 1 ]]; then
    echo "FAIL: restoring previous message tools..."
    cp -p "$BACKUP/mirza_message_tools.php" "$APP/mirza_message_tools.php" || true
    cp -p "$BACKUP/message_tools_smoke.php" "$APP/tests/message_tools_smoke.php" || true
    rm -f "$APP/mirza_premium_post_buttons.php"
  fi
  rm -rf "$STAGE"
  return "$code"
}
trap cleanup EXIT

[[ "$EUID" == 0 ]] || { echo "Run as root"; exit 1; }
cd "$APP"
[[ -f mirza_message_tools.php && -f tests/message_tools_smoke.php ]] ||
  { echo "STOP: original message tools are not installed"; exit 1; }
[[ ! -e mirza_premium_post_buttons.php ]] ||
  { echo "STOP: premium post addon already exists; manual verification needed"; exit 1; }
[[ -f index.php && -f config.php && -f keyboard.php ]] ||
  { echo "STOP: Mirza core files missing"; exit 1; }
for cmd in curl php8.4 cmp; do command -v "$cmd" >/dev/null; done

mkdir -p "$STAGE/tests"
echo "=== Checking local addon against previous release ==="
curl -fsSL --retry 2 --connect-timeout 12 --max-time 90 \
  "$ROOT/$PREV/mirza_message_tools.php" -o "$STAGE/old.php"
if ! cmp -s "$STAGE/old.php" "$APP/mirza_message_tools.php"; then
  echo "STOP: mirza_message_tools.php has local changes; no files changed."
  exit 1
fi

echo "=== Downloading pinned premium update ==="
for f in mirza_message_tools.php mirza_premium_post_buttons.php tests/message_tools_smoke.php; do
  curl -fsSL --retry 2 --connect-timeout 12 --max-time 90 \
    "$ROOT/$NEXT/$f" -o "$STAGE/$f"
done
echo "=== Syntax and smoke tests ==="
php8.4 -l "$STAGE/mirza_message_tools.php"
php8.4 -l "$STAGE/mirza_premium_post_buttons.php"
php8.4 -l "$STAGE/tests/message_tools_smoke.php"
php8.4 "$STAGE/tests/message_tools_smoke.php"

mkdir -p "$BACKUP"
cp -p "$APP/mirza_message_tools.php" "$BACKUP/mirza_message_tools.php"
cp -p "$APP/tests/message_tools_smoke.php" "$BACKUP/message_tools_smoke.php"
CHANGED=1
install -m 0644 "$STAGE/mirza_message_tools.php" "$APP/mirza_message_tools.php"
install -m 0644 "$STAGE/mirza_premium_post_buttons.php" "$APP/mirza_premium_post_buttons.php"
install -m 0644 "$STAGE/tests/message_tools_smoke.php" "$APP/tests/message_tools_smoke.php"
SUCCESS=1
echo "SUCCESS: premium channel post button mode installed."
echo "BACKUP: $BACKUP"
echo "In Telegram send /posttools, then tap ✨ دکمه روی پست پریمیوم کانال."
echo "No changes were made to billing, sales, database or cron jobs."
