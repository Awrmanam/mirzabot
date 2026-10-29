#!/usr/bin/env python3
"""Replace only a known-old Mirza Telegram notifier. Preserve all other live code.

Fail closed on any divergence. Backup + lint before the atomic replacement.
The new notifier sends receipt photos to the report group and private
authorized bot admins; it never approves or changes a payment.
"""
import os
import shutil
import subprocess
import sys
import tempfile
from datetime import datetime, timezone
from pathlib import Path

OLD = "function vpnappNotify($text, $buttonId = null, $photo = null, $paymentId = null) {\n    global $pdo, $APIKEY;\n    $setting=$pdo->query(\"SELECT Channel_Report FROM setting LIMIT 1\")->fetch(PDO::FETCH_ASSOC);\n    $chatId=trim((string)($setting['Channel_Report']??''));\n    $botToken=trim((string)($APIKEY??''));\n    if ($chatId==='' || $botToken==='') {\n        error_log('[vpnapp] Telegram notice skipped: missing report group or bot token');\n        return false;\n    }\n    $topic=$pdo->prepare(\"SELECT idreport FROM topicid WHERE report='paymentreport' LIMIT 1\");\n    $topic->execute();$thread=$topic->fetchColumn();\n    $buttons=[];\n    if ($buttonId) {\n        if ($paymentId && preg_match('/^[0-9a-f]{10}$/D',(string)$paymentId)) {\n            $buttons[]=[\n                ['text'=>'✅ تأیید رسید','callback_data'=>'Confirm_pay_'.$paymentId],\n                ['text'=>'❌ رد رسید','callback_data'=>'reject_pay_'.$paymentId]\n            ];\n        }\n        $buttons[]=[['text'=>'مدیریت مشتری','callback_data'=>'manageuser_'.$buttonId],\n                    ['text'=>'مسدودسازی','callback_data'=>'blockuserfake_'.$buttonId]];\n    }\n\n    $send=function($withThread, $attachment) use\n        ($chatId,$botToken,$thread,$buttons,$text,$photo) {\n        $fields=['chat_id'=>$chatId,'parse_mode'=>'HTML'];\n        if ($withThread && $thread) $fields['message_thread_id']=$thread;\n        if ($buttons) $fields['reply_markup']=json_encode(\n            ['inline_keyboard'=>$buttons],JSON_UNESCAPED_UNICODE);\n        if ($attachment) {\n            $mime=(new finfo(FILEINFO_MIME_TYPE))->file($photo);\n            $mime=$mime==='image/png'?'image/png':'image/jpeg';\n            $filename=$mime==='image/png'?'receipt.png':'receipt.jpg';\n            $fields['photo']=new CURLFile($photo,$mime,$filename);\n            $fields['caption']=$text;\n            $method='sendPhoto';\n        } else {\n            $fields['text']=$text;\n            $method='sendMessage';\n        }\n        $ch=curl_init('https://api.telegram.org/bot'.$botToken.'/'.$method);\n        if ($ch===false) return [false,0,'curl_init'];\n        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$fields,\n            CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,\n            CURLOPT_TIMEOUT=>16]);\n        $response=curl_exec($ch);\n        $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);\n        $curlError=curl_error($ch);\n        curl_close($ch);\n        $parsed=is_string($response)?json_decode($response,true):null;\n        $ok=$status===200 && is_array($parsed) && ($parsed['ok']??false)===true;\n        $error=$curlError?:(\n            is_array($parsed)?(string)($parsed['description']??'Telegram rejected')\n            :'No Telegram JSON response');\n        return [$ok,$status,substr($error,0,220)];\n    };\n\n    [$ok,$http,$error]=$send(true,$photo!==null);\n    if ($ok) return true;\n    error_log('[vpnapp] Telegram report delivery failed: HTTP '.$http.'; '.$error);\n    // A forum topic can be deleted or inaccessible while the group still\n    // works; retry to the configured group's general chat in that case.\n    if ($thread && $http===400 &&\n        (stripos($error,'thread')!==false || stripos($error,'topic')!==false)) {\n        [$ok,$http,$error]=$send(false,$photo!==null);\n        if ($ok) {\n            error_log('[vpnapp] Telegram report sent to group without invalid topic');\n            return true;\n        }\n        error_log('[vpnapp] Telegram group fallback failed: HTTP '.$http.'; '.$error);\n    }\n    return false;\n}\n"
NEW = "function vpnappNotify($text, $buttonId = null, $photo = null, $paymentId = null) {\n    global $pdo, $APIKEY;\n    $setting=$pdo->query(\"SELECT Channel_Report FROM setting LIMIT 1\")->fetch(PDO::FETCH_ASSOC);\n    $chatId=trim((string)($setting['Channel_Report']??''));\n    $botToken=trim((string)($APIKEY??''));\n    if ($botToken==='') {\n        error_log('[vpnapp] Telegram notice skipped: missing bot token');\n        return false;\n    }\n    $topic=$pdo->prepare(\"SELECT idreport FROM topicid WHERE report='paymentreport' LIMIT 1\");\n    $topic->execute();$thread=$topic->fetchColumn();\n    $buttons=[];\n    if ($buttonId) {\n        if ($paymentId && preg_match('/^[0-9a-f]{10}$/D',(string)$paymentId)) {\n            $buttons[]=[\n                ['text'=>'✅ تأیید رسید','callback_data'=>'Confirm_pay_'.$paymentId],\n                ['text'=>'❌ رد رسید','callback_data'=>'reject_pay_'.$paymentId]\n            ];\n        }\n        $buttons[]=[['text'=>'مدیریت مشتری','callback_data'=>'manageuser_'.$buttonId],\n                    ['text'=>'مسدودسازی','callback_data'=>'blockuserfake_'.$buttonId]];\n    }\n\n    $send=function($destination, $withThread, $attachment) use\n        ($botToken,$thread,$buttons,$text,$photo) {\n        $fields=['chat_id'=>$destination,'parse_mode'=>'HTML'];\n        if ($withThread && $thread) $fields['message_thread_id']=$thread;\n        if ($buttons) $fields['reply_markup']=json_encode(\n            ['inline_keyboard'=>$buttons],JSON_UNESCAPED_UNICODE);\n        if ($attachment) {\n            $mime=(new finfo(FILEINFO_MIME_TYPE))->file($photo);\n            $mime=$mime==='image/png'?'image/png':'image/jpeg';\n            $filename=$mime==='image/png'?'receipt.png':'receipt.jpg';\n            $fields['photo']=new CURLFile($photo,$mime,$filename);\n            $fields['caption']=$text;\n            $method='sendPhoto';\n        } else {\n            $fields['text']=$text;\n            $method='sendMessage';\n        }\n        $ch=curl_init('https://api.telegram.org/bot'.$botToken.'/'.$method);\n        if ($ch===false) return [false,0,'curl_init'];\n        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$fields,\n            CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,\n            CURLOPT_TIMEOUT=>16]);\n        $response=curl_exec($ch);\n        $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);\n        $curlError=curl_error($ch);\n        curl_close($ch);\n        $parsed=is_string($response)?json_decode($response,true):null;\n        $ok=$status===200 && is_array($parsed) && ($parsed['ok']??false)===true;\n        $error=$curlError?:(\n            is_array($parsed)?(string)($parsed['description']??'Telegram rejected')\n            :'No Telegram JSON response');\n        return [$ok,$status,substr($error,0,220)];\n    };\n\n    // Keep Mirza's payment group as the original moderation destination.\n    // Send a private admin copy for *receipt photos* as well, since the\n    // operator may not monitor that group. Non-receipt notices use private\n    // admins only if the report group fails.\n    $reportSent=false;\n    if ($chatId!=='') {\n        [$ok,$http,$error]=$send($chatId,true,$photo!==null);\n        $reportSent=$ok;\n        if (!$ok) {\n            error_log('[vpnapp] Telegram report delivery failed: HTTP '.$http.'; '.$error);\n            if ($thread && $http===400 &&\n                (stripos($error,'thread')!==false || stripos($error,'topic')!==false)) {\n                [$ok,$http,$error]=$send($chatId,false,$photo!==null);\n                $reportSent=$ok;\n                if (!$ok) error_log('[vpnapp] Telegram group fallback failed: HTTP '.$http.'; '.$error);\n            }\n        }\n    } else {\n        error_log('[vpnapp] Payment report group not configured; trying admin private chats');\n    }\n    if ($reportSent && $photo===null) return true;\n\n    // Authorized bot administrators only. Receipt alert copies make sure\n    // the shop owner sees a review request even if a report topic is muted.\n    // Telegram requires these admins to have started a private bot chat.\n    $adminSent=false;\n    try {\n        $admins=$pdo->query(\"SELECT id_admin FROM admin LIMIT 10\")->fetchAll(PDO::FETCH_COLUMN);\n        foreach ($admins as $adminId) {\n            $adminId=trim((string)$adminId);\n            if (!preg_match('/^[1-9][0-9]{0,19}$/D',$adminId)) continue;\n            [$ok,$http,$error]=$send($adminId,false,$photo!==null);\n            if ($ok) {\n                $adminSent=true;\n            } else {\n                error_log('[vpnapp] Admin receipt notice failed: HTTP '.$http.'; '.$error);\n            }\n        }\n    } catch (Throwable $e) {\n        error_log('[vpnapp] Telegram admin notification could not read destinations');\n    }\n    return $reportSent || $adminSent;\n}\n"

def patch(path: Path, backups: Path) -> Path:
    if not path.is_file() or path.is_symlink():
        raise RuntimeError("Missing or unsafe live file; nothing changed")
    data = path.read_bytes()
    before, after = OLD.encode("utf-8"), NEW.encode("utf-8")
    if data.count(after) == 1:
        raise RuntimeError("Notifier already patched; nothing changed")
    if data.count(before) != 1:
        raise RuntimeError("Unknown live notifier; refused to overwrite customized code")
    backups.mkdir(parents=True, exist_ok=True, mode=0o700)
    backup = backups / ("vpnapp.php.before-telegram-" +
        datetime.now(timezone.utc).strftime("%Y%m%d-%H%M%S-%f"))
    shutil.copy2(path, backup)
    fd, tmp = tempfile.mkstemp(prefix=".vpnapp-notify-", suffix=".php", dir=path.parent)
    try:
        with os.fdopen(fd, "wb") as file:
            file.write(data.replace(before, after, 1))
        stat = path.stat()
        shutil.copymode(path, tmp)
        if os.geteuid() == 0:
            os.chown(tmp, stat.st_uid, stat.st_gid)
        check = subprocess.run(["php", "-l", tmp], capture_output=True, text=True)
        if check.returncode:
            raise RuntimeError("PHP syntax error: " + check.stderr)
        os.replace(tmp, path)
        print("NOTIFIER PATCHED; backup:", backup)
        print(check.stdout.strip())
        return backup
    finally:
        if os.path.exists(tmp):
            os.unlink(tmp)

def main():
    if len(sys.argv) != 2:
        print("Usage: sudo python3 patch_vpnapp_telegram_receipts.py /path/to/vpnapp.php")
        return 2
    try:
        patch(Path(sys.argv[1]), Path("/root/mirza-backups"))
    except (OSError, RuntimeError) as ex:
        print("ABORTED; live file not overwritten:", ex)
        return 1
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
