#!/usr/bin/env bash
# Safely install only Mirza message tools on non-Git production.
set -Eeuo pipefail
umask 077

APP=/var/www/mirzaprobotconfig
REV=bc0f41a92b0bb4cb7982d26d67ce84e4e72bf68b
BASE="https://raw.githubusercontent.com/Awrmanam/mirzabot/$REV"
BACKUP="/root/mirza-message-tools-backup-$(date +%Y%m%d-%H%M%S)"
STAGE="$(mktemp -d)"
CHANGED=0
SUCCESS=0
cleanup() {
  code=$?
  if [[ "$CHANGED" -eq 1 && "$SUCCESS" -ne 1 ]]; then
    echo "ERROR: restoring original index.php and keyboard.php..." >&2
    cp -p "$BACKUP/index.php" "$APP/index.php" || true
    cp -p "$BACKUP/keyboard.php" "$APP/keyboard.php" || true
    rm -f "$APP/mirza_message_tools.php" "$APP/mirza_message_tools_install.php" \
      "$APP/cronbot/mirza_message_tools_cleanup.php" "$APP/tests/message_tools_smoke.php"
  fi
  rm -rf "$STAGE"
  return "$code"
}
trap cleanup EXIT

if [[ "$EUID" -ne 0 ]]; then echo "Run as root"; exit 1; fi
cd "$APP" || { echo "Missing $APP"; exit 1; }
[[ -f index.php && -f keyboard.php && -f config.php && -f botapi.php ]] ||
  { echo "Missing Mirza core files"; exit 1; }
if [[ -d .git ]]; then
  echo "Use Git-based update for a Git checkout"; exit 1
fi
for f in mirza_message_tools.php mirza_message_tools_install.php \
  cronbot/mirza_message_tools_cleanup.php tests/message_tools_smoke.php; do
  [[ ! -e "$APP/$f" ]] ||
    { echo "STOP: $f already exists. Manual review required."; exit 1; }
done
for cmd in curl php8.4 mysqldump python3; do command -v "$cmd" >/dev/null; done

echo "=== Download only four addon files, pinned commit ==="
mkdir -p "$STAGE/cronbot" "$STAGE/tests"
for f in mirza_message_tools.php mirza_message_tools_install.php \
  cronbot/mirza_message_tools_cleanup.php tests/message_tools_smoke.php; do
  curl -fsSL --retry 2 --connect-timeout 12 --max-time 90 "$BASE/$f" -o "$STAGE/$f"
done

echo "=== Patch copies, preserving local customization ==="
python3 - "$APP" "$STAGE" <<'PY'
from pathlib import Path
import sys
app, stage = map(Path, sys.argv[1:])
patches = {
  "index.php": [
    (
      "require_once 'ticket_system.php';\nrequire_once 'keyboard.php';",
      "require_once 'ticket_system.php';\nrequire_once __DIR__ . '/mirza_message_tools.php';\nrequire_once 'keyboard.php';"
    ),
    (
      '$admin_ids = select("admin", "id_admin", null, null, "FETCH_COLUMN");\n'
      'if (!is_array($admin_ids)) {\n    $admin_ids = [];\n}',
      '$admin_ids = select("admin", "id_admin", null, null, "FETCH_COLUMN");\n'
      'if (!is_array($admin_ids)) {\n    $admin_ids = [];\n}\n'
      "// Optional one-time legacy keyboard removal for private customers, never for admins.\n"
      "if ($Chat_type === 'private' && !in_array((string) $from_id, array_map('strval', $admin_ids), true)) {\n"
      "    mmtCleanupOnInteraction($from_id, $user, $setting);\n}"
    ),
    (
      'if (ticketHandleUpdate()) {\n    return;\n}\nif ($text == "/start"',
      'if (ticketHandleUpdate()) {\n    return;\n}\n'
      "// Separate admin-only post publisher and cleanup controls.\n"
      "if ($Chat_type === 'private' && in_array((string) $from_id, array_map('strval', $admin_ids), true)\n"
      "    && mmtHandleAdminUpdate($from_id, $text, $datain, $update)) {\n"
      "    return;\n}\n"
      'if ($text == "/start"'
    )
  ],
  "keyboard.php": [
    (
      '        [[\'text\' => "💎 مالی"]],\n        [[\'text\' => "🤙 بخش پشتیبانی"]',
      '        [[\'text\' => "💎 مالی"]],\n'
      '        [[\'text\' => "📢 انتشار پست با دکمه"], [\'text\' => "🧹 پاکسازی کیبورد قدیمی"]],\n'
      '        [[\'text\' => "🤙 بخش پشتیبانی"]'
    )
  ]
}
for filename, changes in patches.items():
    raw = (app / filename).read_bytes()
    newline = '\r\n' if b'\r\n' in raw else '\n'
    source = raw.decode('utf-8')
    for before, after in changes:
        old = before.replace('\n', newline)
        new = after.replace('\n', newline)
        if source.count(old) != 1:
            sys.exit(f"STOP: Cannot safely patch {filename}; anchor differs: {before[:55]}")
        source = source.replace(old, new, 1)
    (stage / filename).write_bytes(source.encode('utf-8'))
    print(f"Prepared {filename}: original={len(raw)}, patched={len(source.encode('utf-8'))}")
PY

echo "=== Syntax checks (staged files) ==="
for f in index.php keyboard.php mirza_message_tools.php mirza_message_tools_install.php \
  cronbot/mirza_message_tools_cleanup.php tests/message_tools_smoke.php; do
  php8.4 -l "$STAGE/$f"
done
php8.4 "$STAGE/tests/message_tools_smoke.php"

echo "=== Backup core files and full database ==="
mkdir -p "$BACKUP"
cp -p index.php keyboard.php "$BACKUP/"
mysqldump --single-transaction --quick mirzaprobot | gzip > "$BACKUP/database.sql.gz"
test -s "$BACKUP/database.sql.gz"

echo "=== Install selective changes ==="
CHANGED=1
mkdir -p "$APP/tests"
cp "$STAGE/index.php" "$APP/index.php"
cp "$STAGE/keyboard.php" "$APP/keyboard.php"
for f in mirza_message_tools.php mirza_message_tools_install.php \
  cronbot/mirza_message_tools_cleanup.php tests/message_tools_smoke.php; do
  install -m 0644 "$STAGE/$f" "$APP/$f"
done
php8.4 mirza_message_tools_install.php

SUCCESS=1
echo "SUCCESS: selective addon installed. Backup: $BACKUP"
echo "Existing payments, panels, wallets, and config.php were not replaced."
echo "Batch cleanup remains OFF until explicit opt-in."
