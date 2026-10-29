#!/usr/bin/env python3
"""Fail-closed live Mirza patch. Never replace the production VPN bridge.

Usage (after inspecting this script):
    sudo python3 scripts/patch_vpnapp_pending_orders.py /path/to/api/vpnapp.php

Only the *exact* old checkout-pending guard is changed. All customizations
outside it, Telegram settings, database and payment status stay untouched.
"""
from __future__ import annotations

import os
import shutil
import subprocess
import sys
import tempfile
from datetime import datetime, timezone
from pathlib import Path

OLD = """    $stmt=$db->prepare("SELECT COUNT(*) FROM vpnapp_orders WHERE owner_id=?
        AND (status='unknown' OR
             (status IN ('processing','awaiting_receipt','waiting') AND created_at>?))");
    $stmt->execute([$u['id'],time()-86400]);
    if ((int)$stmt->fetchColumn()>0) vpnappError(409,'A previous order is still pending');"""

NEW = """    // A paid receipt waiting for manual approval does not block a separate
    // purchase. Unknown wallet/panel results and unsubmitted receipts do.
    $stmt=$db->prepare("SELECT COUNT(*) FROM vpnapp_orders WHERE owner_id=?
        AND (status='unknown' OR
             (status IN ('processing','awaiting_receipt') AND created_at>?))");
    $stmt->execute([$u['id'],time()-86400]);
    if ((int)$stmt->fetchColumn()>0)
        vpnappError(409,'A previous order needs reconciliation before checkout');
    // Bound repeated checkout attempts without changing existing receipts.
    $stmt=$db->prepare("SELECT COUNT(*) FROM vpnapp_orders
        WHERE owner_id=? AND status='waiting' AND created_at>?");
    $stmt->execute([$u['id'],time()-86400]);
    if ((int)$stmt->fetchColumn()>=3)
        vpnappError(429,'Too many receipts awaiting review today');
    $stmt=$db->prepare("SELECT COUNT(*) FROM vpnapp_orders
        WHERE owner_id=? AND created_at>?");
    $stmt->execute([$u['id'],time()-86400]);
    if ((int)$stmt->fetchColumn()>=10)
        vpnappError(429,'Daily checkout limit reached');"""


def patch(path: Path, backup_dir: Path) -> Path:
    if not path.is_file() or path.is_symlink():
        raise RuntimeError("Refusing to modify missing/symlinked target")
    source = path.read_bytes()
    old = OLD.encode("utf-8")
    if source.count(old) != 1:
        raise RuntimeError("Expected guard not found exactly once. No change made.")
    updated = source.replace(old, NEW.encode("utf-8"), 1)
    backup_dir.mkdir(parents=True, exist_ok=True, mode=0o700)
    stamp = datetime.now(timezone.utc).strftime("%Y%m%d-%H%M%S-%f")
    backup = backup_dir / f"vpnapp.php.before-pending-fix-{stamp}"
    # Create a byte-for-byte backup and check PHP syntax BEFORE replacing live.
    shutil.copy2(path, backup)
    fd, candidate = tempfile.mkstemp(prefix=".vpnapp-check-", suffix=".php", dir=path.parent)
    try:
        with os.fdopen(fd, "wb") as out:
            out.write(updated)
        original_stat = path.stat()
        shutil.copymode(path, candidate)
        if os.geteuid() == 0:
            os.chown(candidate, original_stat.st_uid, original_stat.st_gid)
        result = subprocess.run(["php", "-l", candidate], text=True,
                                capture_output=True, check=False)
        if result.returncode != 0:
            raise RuntimeError("PHP lint failed: " + (result.stdout + result.stderr).strip())
        os.replace(candidate, path)
        print("Patched exact checkout guard; original backed up:", backup)
        print(result.stdout.strip())
        return backup
    finally:
        if os.path.exists(candidate):
            os.unlink(candidate)


def main() -> int:
    if len(sys.argv) != 2:
        print("Usage: python3 patch_vpnapp_pending_orders.py /path/to/api/vpnapp.php",
              file=sys.stderr)
        return 2
    try:
        patch(Path(sys.argv[1]).resolve(), Path("/root/mirza-backups"))
    except (OSError, RuntimeError, UnicodeError) as exc:
        print("ABORTED (live file preserved):", exc, file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
