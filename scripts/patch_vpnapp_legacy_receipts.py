#!/usr/bin/env python3
"""Recover older HMAC-named receipt photos without changing order/payment data."""
import os,shutil,subprocess,sys,tempfile
from pathlib import Path
from datetime import datetime,timezone

OLD = "function vpnappPrivateReceiptDir() {\n    return dirname(__DIR__,2).'/mirza-vpnapp-receipts';\n}\nfunction vpnappReceiptFile($key, $mime) {\n    if (!preg_match('/^[0-9a-f-]{36}$/D',(string)$key)) return null;\n    $ext=$mime==='image/png'?'.png':'.jpg';\n    // Same path from PHP-FPM and CLI worker; their environment variables\n    // may differ. The random 128-bit order identifier + a 0700 private dir\n    // prevent directory enumeration or public receipt exposure.\n    return vpnappPrivateReceiptDir().'/'.hash('sha256',(string)$key).$ext;\n}\nfunction vpnappPersistReceipt($key, $data, $mime) {\n    $directory=vpnappPrivateReceiptDir();\n    if (!is_dir($directory) || !is_writable($directory) || is_link($directory)) {\n        error_log('[vpnapp] Private receipt storage not configured; Telegram delivery still attempted');\n        return false;\n    }\n    $path=vpnappReceiptFile($key,$mime);\n    if (!$path) return false;\n    $temporary=tempnam($directory,'.upload-');\n    if ($temporary===false) return false;\n    try {\n        @chmod($temporary,0600);\n        if (file_put_contents($temporary,$data,LOCK_EX)!==strlen($data)) return false;\n        if (!rename($temporary,$path)) return false;\n        return true;\n    } finally {\n        if (is_file($temporary)) @unlink($temporary);\n    }\n}\nfunction vpnappPrivateReceiptExists($key) {\n    return is_file((string)vpnappReceiptFile($key,'image/jpeg')) ||\n        is_file((string)vpnappReceiptFile($key,'image/png'));\n}\nfunction vpnappAdminReceipt(PDO $db, $key) {\n    if (!preg_match('/^[0-9a-f-]{36}$/D',(string)$key))\n        vpnappError(400,'Invalid order ID');\n    $stmt=$db->prepare(\"SELECT external_id FROM vpnapp_orders WHERE external_id=?\n        AND kind IN ('card','renewal_card') LIMIT 1\");\n    $stmt->execute([$key]);\n    if (!$stmt->fetchColumn()) vpnappError(404,'Order not found');\n    foreach (['image/jpeg','image/png'] as $mime) {\n        $path=vpnappReceiptFile($key,$mime);\n        if (!$path || !is_file($path) || is_link($path)) continue;\n        $size=filesize($path);\n        if ($size===false || $size<200 || $size>1500000) continue;\n        $bytes=file_get_contents($path);\n        if (!is_string($bytes) || strlen($bytes)!==$size) continue;\n        return ['ok'=>true,'image_base64'=>base64_encode($bytes),\n                'content_type'=>$mime];\n    }\n    vpnappError(404,'Receipt image not stored; customer may resubmit it');\n}\n\n"
NEW = "function vpnappPrivateReceiptDir() {\n    return dirname(__DIR__,2).'/mirza-vpnapp-receipts';\n}\nfunction vpnappReceiptFile($key, $mime) {\n    if (!preg_match('/^[0-9a-f-]{36}$/D',(string)$key)) return null;\n    $ext=$mime==='image/png'?'.png':'.jpg';\n    // Same path from PHP-FPM and CLI worker; their environment variables\n    // may differ. The random 128-bit order identifier + a 0700 private dir\n    // prevent directory enumeration or public receipt exposure.\n    return vpnappPrivateReceiptDir().'/'.hash('sha256',(string)$key).$ext;\n}\n/**\n * Locate an image saved by either the previous HMAC-based receipt code or the\n * newer stable naming scheme. This is only used within a private 0700 folder.\n * Never expose a filesystem path to the API caller.\n */\nfunction vpnappFindReceipt($key, $mime) {\n    $preferred=vpnappReceiptFile($key,$mime);\n    if ($preferred && is_file($preferred) && !is_link($preferred)) return $preferred;\n    if (!preg_match('/^[0-9a-f-]{36}$/D',(string)$key)) return null;\n    $ext=$mime==='image/png'?'.png':'.jpg';\n    $secrets=[trim((string)getenv('MIRZA_APP_BRIDGE_SECRET')),''];\n    foreach (array_unique($secrets) as $secret) {\n        $path=vpnappPrivateReceiptDir().'/'.\n            hash_hmac('sha256',(string)$key,$secret).$ext;\n        if (is_file($path) && !is_link($path)) return $path;\n    }\n    return null;\n}\nfunction vpnappPersistReceipt($key, $data, $mime) {\n    $directory=vpnappPrivateReceiptDir();\n    if (!is_dir($directory) || !is_writable($directory) || is_link($directory)) {\n        error_log('[vpnapp] Private receipt storage not configured; Telegram delivery still attempted');\n        return false;\n    }\n    $path=vpnappReceiptFile($key,$mime);\n    if (!$path) return false;\n    $temporary=tempnam($directory,'.upload-');\n    if ($temporary===false) return false;\n    try {\n        @chmod($temporary,0600);\n        if (file_put_contents($temporary,$data,LOCK_EX)!==strlen($data)) return false;\n        if (!rename($temporary,$path)) return false;\n        return true;\n    } finally {\n        if (is_file($temporary)) @unlink($temporary);\n    }\n}\nfunction vpnappPrivateReceiptExists($key) {\n    return vpnappFindReceipt($key,'image/jpeg') !== null ||\n        vpnappFindReceipt($key,'image/png') !== null;\n}\nfunction vpnappAdminReceipt(PDO $db, $key) {\n    if (!preg_match('/^[0-9a-f-]{36}$/D',(string)$key))\n        vpnappError(400,'Invalid order ID');\n    $stmt=$db->prepare(\"SELECT external_id FROM vpnapp_orders WHERE external_id=?\n        AND kind IN ('card','renewal_card') LIMIT 1\");\n    $stmt->execute([$key]);\n    if (!$stmt->fetchColumn()) vpnappError(404,'Order not found');\n    foreach (['image/jpeg','image/png'] as $mime) {\n        $path=vpnappFindReceipt($key,$mime);\n        if (!$path) continue;\n        $size=filesize($path);\n        if ($size===false || $size<200 || $size>1500000) continue;\n        $bytes=file_get_contents($path);\n        if (!is_string($bytes) || strlen($bytes)!==$size) continue;\n        return ['ok'=>true,'image_base64'=>base64_encode($bytes),\n                'content_type'=>$mime];\n    }\n    vpnappError(404,'Receipt image not stored; customer may resubmit it');\n}\n\n"

def patch(path, backup_dir):
    path=Path(path)
    if not path.is_file() or path.is_symlink(): raise RuntimeError("Invalid file")
    original=path.read_bytes();a=OLD.encode();b=NEW.encode()
    if original.count(b)==1 and original.count(a)==0:
        print("LEGACY_RECEIPT_SUPPORT_ALREADY_INSTALLED");return
    if original.count(a)!=1 or original.count(b):
        raise RuntimeError("Unrecognized live PHP receipt storage handler; unchanged")
    after=original.replace(a,b,1)
    backup_dir=Path(backup_dir);backup_dir.mkdir(mode=0o700,parents=True,exist_ok=True)
    backup=backup_dir/("vpnapp.php.before-receipt-recovery-"+datetime.now(timezone.utc).strftime("%Y%m%d%H%M%S%f"))
    shutil.copy2(path,backup)
    fd,temp=tempfile.mkstemp(prefix=".vpnapp-recovery-",suffix=".php",dir=path.parent)
    try:
        with os.fdopen(fd,"wb") as dest:dest.write(after)
        st=path.stat();shutil.copymode(path,temp)
        if os.geteuid()==0:os.chown(temp,st.st_uid,st.st_gid)
        check=subprocess.run(["php","-l",temp],capture_output=True,text=True)
        if check.returncode:raise RuntimeError(check.stdout+check.stderr)
        os.replace(temp,path)
        print("BACKUP:",backup)
        print("LEGACY_RECEIPT_RECOVERY_INSTALLED")
    finally:
        if os.path.exists(temp):os.unlink(temp)
if __name__=="__main__":
    if len(sys.argv)!=2:raise SystemExit("Usage: sudo python3 patch_vpnapp_legacy_receipts.py /path/api/vpnapp.php")
    try:patch(sys.argv[1],"/root/mirza-backups")
    except (OSError,RuntimeError) as e:
        print("ABORTED, LIVE FILE UNCHANGED:",e,file=sys.stderr);sys.exit(1)
