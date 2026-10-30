#!/usr/bin/env python3
"""Fix ONLY pending-card checkout blocking in a customized live Mirza bridge.
No invoice, receipt, wallet, database or service changes are performed.
"""
import os
import shutil
import subprocess
import sys
import tempfile
from datetime import datetime, timezone
from pathlib import Path

OLD = "function vpnappNewOrder(PDO $db, $u, $kind, $key) {\n    if (!is_string($key) || !preg_match('/^[0-9a-f-]{36}$/D',$key))\n        vpnappError(400,'Invalid order key');\n    // Serialize checkouts from the same Mirza user, even with different\n    // client-generated UUIDs, so two requests cannot race the pending limit.\n    $lock=$db->prepare(\"SELECT GET_LOCK(?,10)\");\n    $lock->execute(['vpnapp_account_'.$u['id']]);\n    if ((int)$lock->fetchColumn()!==1) vpnappError(409,'Checkout currently busy');\n    // Uncertain wallet/panel operations must be reconciled before another\n    // checkout, so a timeout never accidentally charges the user twice.\n    // A card receipt already submitted for manual review is different:\n    // keeping its review open must not lock all unrelated new purchases.\n    $stmt=$db->prepare(\"SELECT COUNT(*) FROM vpnapp_orders WHERE owner_id=?\n        AND (status='unknown' OR\n             (status IN ('processing','awaiting_receipt') AND created_at>?))\");\n    $stmt->execute([$u['id'],time()-86400]);\n    if ((int)$stmt->fetchColumn()>0)\n        vpnappError(409,'A previous order needs reconciliation before checkout');\n    // Limit unreviewed card receipts and rapid repeat purchases; never\n    // approve, delete or mutate any of those existing payment records.\n    $stmt=$db->prepare(\"SELECT COUNT(*) FROM vpnapp_orders\n        WHERE owner_id=? AND status='waiting' AND created_at>?\");\n    $stmt->execute([$u['id'],time()-86400]);\n    if ((int)$stmt->fetchColumn()>=3)\n        vpnappError(429,'Too many receipts awaiting review today');\n    $stmt=$db->prepare(\"SELECT COUNT(*) FROM vpnapp_orders\n        WHERE owner_id=? AND created_at>?\");\n    $stmt->execute([$u['id'],time()-86400]);\n    if ((int)$stmt->fetchColumn()>=10)\n        vpnappError(429,'Daily checkout limit reached');\n    $stmt=$db->prepare(\"INSERT INTO vpnapp_orders(external_id,owner_id,kind,status,created_at,updated_at) VALUES(?,?,?,?,?,?)\");\n    $stmt->execute([$key,$u['id'],$kind,'processing',time(),time()]);\n}\n"
NEW = "function vpnappNewOrder(PDO $db, $u, $kind, $key) {\n    if (!is_string($key) || !preg_match('/^[0-9a-f-]{36}$/D',$key))\n        vpnappError(400,'Invalid order key');\n    // Serialize checkouts from the same Mirza user, even with different\n    // client-generated UUIDs, so two requests cannot race the pending limit.\n    $lock=$db->prepare(\"SELECT GET_LOCK(?,10)\");\n    $lock->execute(['vpnapp_account_'.$u['id']]);\n    if ((int)$lock->fetchColumn()!==1) vpnappError(409,'Checkout currently busy');\n    // Preserve payment safety: uncertain wallet charges and actively\n    // processing checkouts require reconciliation. Unpaid card invoices,\n    // missing photos and receipts awaiting review must NOT lock unrelated\n    // new orders. These have no automatic balance deduction.\n    $stmt=$db->prepare(\"SELECT COUNT(*) FROM vpnapp_orders WHERE owner_id=?\n        AND ((status='unknown' AND kind IN ('wallet','renewal'))\n             OR (status='processing' AND created_at>?))\");\n    $stmt->execute([$u['id'],time()-3600]);\n    if ((int)$stmt->fetchColumn()>0)\n        vpnappError(409,'A previous order needs reconciliation before checkout');\n    // Limit unreviewed card receipts and rapid repeat purchases; never\n    // approve, delete or mutate any of those existing payment records.\n    $stmt=$db->prepare(\"SELECT COUNT(*) FROM vpnapp_orders\n        WHERE owner_id=? AND status IN ('waiting','awaiting_receipt') AND created_at>?\");\n    $stmt->execute([$u['id'],time()-86400]);\n    if ((int)$stmt->fetchColumn()>=12)\n        vpnappError(429,'Too many card orders pending; contact support to reconcile');\n    $stmt=$db->prepare(\"SELECT COUNT(*) FROM vpnapp_orders\n        WHERE owner_id=? AND created_at>?\");\n    $stmt->execute([$u['id'],time()-86400]);\n    if ((int)$stmt->fetchColumn()>=25)\n        vpnappError(429,'Daily checkout limit reached');\n    $stmt=$db->prepare(\"INSERT INTO vpnapp_orders(external_id,owner_id,kind,status,created_at,updated_at) VALUES(?,?,?,?,?,?)\");\n    $stmt->execute([$key,$u['id'],$kind,'processing',time(),time()]);\n}\n"

def patch(file: Path, backup_dir: Path):
    if not file.is_file() or file.is_symlink():
        raise RuntimeError("Invalid Mirza bridge path")
    before = file.read_bytes()
    old, new = OLD.encode(), NEW.encode()
    if before.count(new) == 1 and before.count(old) == 0:
        print("CHECKOUT_PENDING_FIX_ALREADY_INSTALLED")
        return
    if before.count(old) != 1 or before.count(new):
        raise RuntimeError("Customized checkout function differs; no live changes")
    after = before.replace(old,new,1)
    backup_dir.mkdir(parents=True, exist_ok=True, mode=0o700)
    backup=backup_dir / ("vpnapp.php.before-pending-fix-"+datetime.now(timezone.utc).strftime("%Y%m%d%H%M%S%f"))
    shutil.copy2(file,backup)
    fd,tmp=tempfile.mkstemp(prefix=".vpnapp-pending-",suffix=".php",dir=file.parent)
    try:
        with os.fdopen(fd,"wb") as w: w.write(after)
        original=file.stat()
        shutil.copymode(file,tmp)
        if os.geteuid() == 0:
            os.chown(tmp,original.st_uid,original.st_gid)
        check=subprocess.run(["php","-l",tmp],capture_output=True,text=True)
        if check.returncode:
            raise RuntimeError("Invalid PHP: "+check.stdout+check.stderr)
        os.replace(tmp,file)
        print("BACKUP:",backup)
        print("CHECKOUT_PENDING_FIX_INSTALLED")
    finally:
        if os.path.exists(tmp):os.unlink(tmp)

if __name__=="__main__":
    if len(sys.argv)!=2:
        raise SystemExit("Usage: python3 patch_vpnapp_unblock_card_orders.py /path/to/api/vpnapp.php")
    try:
        patch(Path(sys.argv[1]),Path("/root/mirza-backups"))
    except (RuntimeError,OSError,UnicodeError) as error:
        print("ABORTED; LIVE FILE UNCHANGED:",error,file=sys.stderr)
        sys.exit(1)
