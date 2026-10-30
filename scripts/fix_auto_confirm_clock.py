#!/usr/bin/env python3
"""Surgical timezone fix for Mirza app card receipts. Never approves payments.

Original croncard.php explicitly interprets receipt at_updated as Asia/Tehran
local time. This fixes the app handler to write that same timezone without
changing payment statuses, older receipts, or Mirza's auto-confirm settings.
"""
import os
import shutil
import subprocess
import sys
import tempfile
from datetime import datetime,timezone
from pathlib import Path

OLD = "$update->execute([date('Y/m/d H:i:s'),$order['payment_id'],$u['id']]);"
NEW = """// Match the timezone of legacy croncard.php.
        $confirmedAt=(new DateTimeImmutable('now',
            new DateTimeZone('Asia/Tehran')))->format('Y/m/d H:i:s');
        $update->execute([$confirmedAt,$order['payment_id'],$u['id']]);"""
START = 'function vpnappSubmitReceipt('

def patch(path,backups):
    path=Path(path)
    if not path.is_file() or path.is_symlink():
        raise RuntimeError("Live Mirza bridge not found")
    before=path.read_bytes()
    data=before.decode('utf-8')
    start=data.find(START)
    if start<0 or data.find(START,start+1)>=0:
        raise RuntimeError("Receipt upload handler missing/ambiguous")
    next_fn=data.find('\nfunction ', start+len(START))
    if next_fn<0:
        raise RuntimeError("Receipt handler boundary not found")
    middle=data[start:next_fn]
    if middle.count(NEW)==1 and middle.count(OLD)==0:
        print("AUTO_CONFIRM_TIMEZONE_ALREADY_FIXED");return
    if middle.count(OLD)!=1 or middle.count(NEW):
        raise RuntimeError("Unexpected custom receipt timestamp; no changes")
    newdata=(data[:start]+middle.replace(OLD,NEW,1)+data[next_fn:]).encode()
    backups=Path(backups)
    backups.mkdir(parents=True,exist_ok=True,mode=0o700)
    backup=backups/("vpnapp.php.pre-card-autoconfirm-clock-"+
                    datetime.now(timezone.utc).strftime("%Y%m%d-%H%M%S-%f"))
    shutil.copy2(path,backup)
    descriptor,tmp=tempfile.mkstemp(prefix=".vpnapp-clock-",suffix=".php",dir=path.parent)
    try:
        with os.fdopen(descriptor,'wb') as f:f.write(newdata)
        stat=path.stat()
        shutil.copymode(path,tmp)
        if os.geteuid()==0:os.chown(tmp,stat.st_uid,stat.st_gid)
        check=subprocess.run(["php","-l",tmp],capture_output=True,text=True)
        if check.returncode:
            raise RuntimeError("PHP syntax test failed "+check.stdout+check.stderr)
        os.replace(tmp,path)
        print("BACKUP:",backup)
        print("AUTO_CONFIRM_TIMEZONE_FIXED")
    finally:
        if os.path.exists(tmp):os.unlink(tmp)

if __name__=='__main__':
    if len(sys.argv)!=2:raise SystemExit("Usage: sudo python3 fix_auto_confirm_clock.py /path/to/api/vpnapp.php")
    try:patch(sys.argv[1],'/root/mirza-backups')
    except (OSError,RuntimeError,UnicodeError) as e:
        print("ABORTED; LIVE PHP UNCHANGED:",e,file=sys.stderr)
        sys.exit(1)
