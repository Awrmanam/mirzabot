#!/usr/bin/env python3
"""Correct only admin_snapshot joins affected by mixed MySQL collations.

Idempotent, fail-closed and reversible. It NEVER changes a database table,
payment/order state, other PHP handlers or existing bot customizations.
"""
from __future__ import annotations

import os
import shutil
import subprocess
import sys
import tempfile
from datetime import datetime, timezone
from pathlib import Path

FIXES = (
    (
        "LEFT JOIN invoice i ON i.id_invoice=o.invoice_id AND i.id_user=o.owner_id",
        "LEFT JOIN invoice i ON i.id_invoice COLLATE utf8mb4_unicode_ci = "
        "o.invoice_id COLLATE utf8mb4_unicode_ci AND i.id_user=o.owner_id",
    ),
    (
        "LEFT JOIN Payment_report p ON p.id_order=o.payment_id AND p.id_user=o.owner_id",
        "LEFT JOIN Payment_report p ON p.id_order COLLATE utf8mb4_unicode_ci = "
        "o.payment_id COLLATE utf8mb4_unicode_ci AND p.id_user=o.owner_id",
    ),
)
START = "function vpnappAdminSnapshot(PDO $db) {"
END = "if (defined('MIRZA_VPNAPP_LIBRARY_ONLY')"


def patch(path: Path, backups: Path) -> bool:
    if not path.is_file() or path.is_symlink():
        raise RuntimeError("Live Mirza PHP file not found or symlinked")
    original = path.read_bytes()
    text = original.decode("utf-8")
    start, end = text.find(START), text.find(END)
    if start < 0 or end <= start:
        raise RuntimeError("Expected admin snapshot block missing; unchanged")
    body = text[start:end]
    patched = 0
    for old, new in FIXES:
        if body.count(new) == 1 and body.count(old) == 0:
            continue
        if body.count(old) != 1 or body.count(new):
            raise RuntimeError("Unexpected snapshot JOIN; live file unchanged")
        body = body.replace(old, new, 1)
        patched += 1
    if not patched:
        print("SNAPSHOT_COLLATION_ALREADY_FIXED")
        return False
    updated = (text[:start] + body + text[end:]).encode("utf-8")
    backups.mkdir(parents=True, exist_ok=True, mode=0o700)
    backup = backups / ("vpnapp.php.before-collation-" +
        datetime.now(timezone.utc).strftime("%Y%m%d-%H%M%S-%f"))
    shutil.copy2(path, backup)
    fd, temp = tempfile.mkstemp(prefix=".vpnapp-collation-", suffix=".php", dir=path.parent)
    try:
        with os.fdopen(fd, "wb") as handle:
            handle.write(updated)
        stat = path.stat()
        shutil.copymode(path, temp)
        if os.geteuid() == 0:
            os.chown(temp, stat.st_uid, stat.st_gid)
        result = subprocess.run(["php", "-l", temp],
                                capture_output=True, text=True, check=False)
        if result.returncode:
            raise RuntimeError("PHP syntax check failed: " + result.stdout + result.stderr)
        os.replace(temp, path)
        print("BACKUP:", backup)
        print(result.stdout.strip())
        print("SNAPSHOT_COLLATION_FIXED")
        return True
    finally:
        if os.path.exists(temp):
            os.unlink(temp)


if __name__ == "__main__":
    if len(sys.argv) != 2:
        print("Usage: python3 patch_vpnapp_snapshot_collations.py /absolute/path/to/api/vpnapp.php")
        sys.exit(2)
    try:
        patch(Path(sys.argv[1]), Path("/root/mirza-backups"))
    except (RuntimeError, OSError, UnicodeError) as exc:
        print("ABORTED - LIVE FILE PRESERVED:", exc, file=sys.stderr)
        sys.exit(1)
