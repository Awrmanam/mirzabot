#!/usr/bin/env python3
"""Idempotent *surgical* updater for an existing customized Mirza VPN bridge.

Accepts any mix of the known-old and already-patched handler variants.
Unexpected handler code aborts without changes. No database rows are deleted,
payment status is not changed, and the entire PHP bridge is NEVER replaced.
"""
from __future__ import annotations

import os
import shutil
import subprocess
import sys
import tempfile
from datetime import datetime, timezone
from pathlib import Path

from patch_vpnapp_telegram_receipts import (
    OLD as NOTIFY_OLD, NEW as NOTIFY_NEW,
    RECEIPT_OLD, RECEIPT_NEW, ADMIN_OLD, ADMIN_NEW,
)
from patch_vpnapp_pending_orders import OLD as CHECKOUT_OLD, NEW as CHECKOUT_NEW


SECTIONS = (
    ("Telegram notifier", NOTIFY_OLD, NOTIFY_NEW),
    ("Photo receipt and retry", RECEIPT_OLD, RECEIPT_NEW),
    ("Admin sales snapshot", ADMIN_OLD, ADMIN_NEW),
    ("Safe pending checkout", CHECKOUT_OLD, CHECKOUT_NEW),
)


def patch(path: Path, backups: Path) -> dict[str, str]:
    if not path.is_file() or path.is_symlink():
        raise RuntimeError("Target missing or symlink; file was not changed")
    original = path.read_bytes()
    updated = original
    state: dict[str, str] = {}
    # Validate all four independently before touching the filesystem.
    for label, old, new in SECTIONS:
        old_bytes, new_bytes = old.encode("utf-8"), new.encode("utf-8")
        if updated.count(new_bytes) == 1 and updated.count(old_bytes) == 0:
            state[label] = "already installed"
        elif updated.count(old_bytes) == 1 and updated.count(new_bytes) == 0:
            updated = updated.replace(old_bytes, new_bytes, 1)
            state[label] = "patched"
        else:
            raise RuntimeError(label + ": unexpected live code; no changes made")
    if updated == original:
        return state

    backups.mkdir(parents=True, exist_ok=True, mode=0o700)
    backup = backups / (
        "vpnapp.php.before-safe-update-" +
        datetime.now(timezone.utc).strftime("%Y%m%d-%H%M%S-%f")
    )
    shutil.copy2(path, backup)
    descriptor, temp = tempfile.mkstemp(prefix=".vpnapp-safe-", suffix=".php", dir=path.parent)
    try:
        with os.fdopen(descriptor, "wb") as f:
            f.write(updated)
        st = path.stat()
        shutil.copymode(path, temp)
        if os.geteuid() == 0:
            os.chown(temp, st.st_uid, st.st_gid)
        check = subprocess.run(["php", "-l", temp], text=True,
                               capture_output=True, check=False)
        if check.returncode:
            raise RuntimeError("PHP syntax check failed: " + check.stdout + check.stderr)
        os.replace(temp, path)
        print("Backup created:", backup)
        return state
    finally:
        if os.path.exists(temp):
            os.unlink(temp)


def main() -> int:
    if len(sys.argv) != 2:
        print("Usage: sudo python3 patch_vpnapp_live_safe.py /absolute/path/to/api/vpnapp.php")
        return 2
    try:
        states = patch(Path(sys.argv[1]), Path("/root/mirza-backups"))
        for label, status in states.items():
            print(label + ":", status)
        print("MIRZA_SAFE_UPDATE_OK")
    except (OSError, RuntimeError) as error:
        print("ABORTED; current Mirza file preserved:", error, file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
