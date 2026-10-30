#!/usr/bin/env python3
"""Small fail-closed hotfix for legacy Mirza checkout SQL only.

Allows card orders awaiting receipt/photos to coexist with new card checkout.
Does NOT change invoices, wallets, pending payments, or Telegram moderation.
No full PHP function replacement. Preserves custom logic byte-for-byte
outside three exact known SQL predicates. Supports old and patched layouts.
"""
import os, re, shutil, subprocess, sys, tempfile
from pathlib import Path
from datetime import datetime,timezone

START = "function vpnappNewOrder("
NEXT = re.compile(r"(?m)^function [a-zA-Z_][a-zA-Z_0-9]*\(")
OLD_HARD_BLOCK = re.compile(
    r"AND\s*\(\s*status\s*=\s*'unknown'\s+OR\s*"
    r"\(\s*status\s+IN\s*\(\s*'processing'\s*,\s*'awaiting_receipt'"
    r"(?:\s*,\s*'waiting')?\s*\)\s+AND\s+created_at\s*>\s*\?\s*\)\s*\)"
)
NEW_HARD_BLOCK = (
    "AND ((status='unknown' AND kind NOT IN ('card','renewal_card'))"
    " OR (status='processing' AND created_at>?))"
)
PENDING_CAP = re.compile(
    r"WHERE owner_id=\?\s+AND status='waiting'\s+AND created_at>\?"
)
OLD_PENDING_ERROR = "Too many receipts awaiting review today"
UPDATED_PENDING_ERROR = "Too many card orders pending; contact support to reconcile"
OLD_DAILY_ERROR = "Daily checkout limit reached"


def patched(raw: bytes) -> tuple[bytes, str]:
    data = raw.decode('utf-8')
    i = data.find(START)
    if i < 0 or data.find(START, i+1) >= 0:
        raise RuntimeError("checkout function missing or ambiguous")
    m = NEXT.search(data, i+len(START))
    if not m:
        raise RuntimeError("checkout function end missing")
    j = m.start()
    original = data[i:j]
    if "SELECT GET_LOCK" not in original or "INSERT INTO vpnapp_orders" not in original:
        raise RuntimeError("unexpected checkout body, refusing changes")
    body=original
    hard=OLD_HARD_BLOCK.findall(body)
    if len(hard)==1:
        body,qty=OLD_HARD_BLOCK.subn(NEW_HARD_BLOCK,body,count=1)
        if qty != 1: raise RuntimeError("hard guard match failed")
        # Each legacy hard-block query has two placeholders and exactly
        # one subsequent PDO execute([owner_id, time-86400]).
        mtime=re.search(
            r"(\$stmt->execute\(\[\$u\['id'\],\s*time\(\)\s*-\s*)86400(\s*\]\);)",
            body
        )
        if not mtime:
            raise RuntimeError("hard-block age guard differs; no changes")
        body=body[:mtime.start(0)]+mtime.group(1)+"3600"+mtime.group(2)+body[mtime.end(0):]
    elif NEW_HARD_BLOCK not in body and "kind IN ('wallet','renewal')" not in body:
        raise RuntimeError("legacy/updated hard checkout predicate not recognized")
    if OLD_PENDING_ERROR in body:
        if body.count(OLD_PENDING_ERROR)!=1 or len(PENDING_CAP.findall(body))!=1:
            raise RuntimeError("legacy receipt pending limit unrecognized")
        body=PENDING_CAP.sub("WHERE owner_id=? AND status IN ('waiting','awaiting_receipt') AND created_at>?",body,count=1)
        body=body.replace(">=3)\n        vpnappError(429,'"+OLD_PENDING_ERROR+"'",
                          ">=12)\n        vpnappError(429,'"+UPDATED_PENDING_ERROR+"'",1)
        if OLD_PENDING_ERROR in body:
            raise RuntimeError("legacy receipt limit syntax differs; no changes")
    elif UPDATED_PENDING_ERROR not in body and "status='waiting'" in body:
        raise RuntimeError("receipt pending guard customized; no changes")
    if ">=10)\n        vpnappError(429,'"+OLD_DAILY_ERROR+"'" in body:
        body=body.replace(">=10)\n        vpnappError(429,'"+OLD_DAILY_ERROR+"'",
                          ">=25)\n        vpnappError(429,'"+OLD_DAILY_ERROR+"'",1)
    if body==original:
        return raw, 'already compatible'
    return (data[:i]+body+data[j:]).encode(), 'card checkout guard updated'


def patch(file: Path, folder: Path):
    if not file.is_file() or file.is_symlink():
        raise RuntimeError("Invalid live bridge path")
    old=file.read_bytes()
    new,message=patched(old)
    if new==old:
        print("CHECKOUT_GUARD_ALREADY_COMPATIBLE");return
    folder.mkdir(parents=True,exist_ok=True,mode=0o700)
    backup=folder/("vpnapp.php.before-legacy-checkout-"+datetime.now(timezone.utc).strftime("%Y%m%d-%H%M%S-%f"))
    shutil.copy2(file,backup)
    fd,tmp=tempfile.mkstemp(prefix=".vpnapp-guard-",suffix=".php",dir=file.parent)
    try:
        with os.fdopen(fd,"wb") as f: f.write(new)
        st=file.stat()
        shutil.copymode(file,tmp)
        if os.geteuid()==0: os.chown(tmp,st.st_uid,st.st_gid)
        result=subprocess.run(["php","-l",tmp],capture_output=True,text=True)
        if result.returncode:
            raise RuntimeError("PHP lint failed "+result.stdout+result.stderr)
        os.replace(tmp,file)
        print("BACKUP:",backup)
        print("CHECKOUT_LEGACY_COMPAT_FIXED")
    finally:
        if os.path.exists(tmp):os.unlink(tmp)


if __name__=="__main__":
    if len(sys.argv)!=2:
        print("Usage: sudo python3 patch_vpnapp_legacy_checkout_sql.py /absolute/path/api/vpnapp.php")
        sys.exit(2)
    try:
        patch(Path(sys.argv[1]),Path("/root/mirza-backups"))
    except (OSError,RuntimeError,UnicodeError) as e:
        print("ABORTED: file unchanged:",e,file=sys.stderr)
        sys.exit(1)
