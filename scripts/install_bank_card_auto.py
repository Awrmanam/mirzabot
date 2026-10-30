#!/usr/bin/env python3
"""Install a narrowly scoped, bank-verified legacy Mirza card auto-confirm fix.

No modifications to PaySetting, croncard.php, Telegram callbacks, invoice
schemas, wallet balances, Payment_report, or existing customer data.
Only patches the bank webhook logic after strict exact-match preflight.
"""
import os
import shutil
import subprocess
import sys
import tempfile
from datetime import datetime,timezone
from pathlib import Path

OLD_TAIL="if (is_numeric($amountInteger) && substr($amountInteger, -3) === '000')return;\nif(isset($amountInteger) && $amountInteger !== NULL){\n    $datauser = mysqli_fetch_assoc(mysqli_query($connect, \"SELECT * FROM Payment_report WHERE price = '$amountInteger' AND (payment_Status = 'Unpaid' OR payment_Status = 'waiting')\"));\n    $order_id = $datauser['id_order'];\n    $Payment_report = mysqli_fetch_assoc(mysqli_query($connect, \"SELECT * FROM Payment_report WHERE id_order = '$order_id' LIMIT 1\"));\n    if(!isset($Payment_report['price']) || $Payment_report['price'] == null)return;\n    $Balance_id = mysqli_fetch_assoc(mysqli_query($connect, \"SELECT * FROM user WHERE id = '{$Payment_report['id_user']}' LIMIT 1\"));\n    $textbotlang = languagechange('../text.json');\n\n    if ($Payment_report['payment_Status'] == \"paid\" || $Payment_report['payment_Status'] == \"reject\") {\n        telegram('answerCallbackQuery', array(\n            'callback_query_id' => $callback_query_id,\n            'text' => $textbotlang['Admin']['Payment']['reviewedpayment'],\n            'show_alert' => true,\n            'cache_time' => 5,\n        ));\n        return;}\n        DirectPayment($order_id,\"../images.jpg\");\n        update(\"Payment_report\",\"payment_Status\",\"paid\",'id_order',$order_id);\n    $balanceformatsell = number_format(mysqli_fetch_assoc(mysqli_query($connect, \"SELECT (Balance) FROM user WHERE id = '{$Payment_report['id_user']}' LIMIT 1\"))['Balance'], 0);\n    $paymentreports = select(\"topicid\",\"idreport\",\"report\",\"paymentreport\",\"select\")['idreport'];\n    $text_report = \"یک رسید توسط ربات  تایید شد\n\nاطلاعات :\n💰 مبلغ پرداخت : {$Payment_report['price']}\n👤  آیدی عددی کاربر : {$Balance_id['id']} \n👤 نام کاربری کاربر : @{$Balance_id['username']} \nموجودی کاربر : $balanceformatsell تومان\nکد پیگیری پرداخت : $order_id\";\n    if (strlen($setting['Channel_Report']) > 0) {\n        telegram('sendmessage',[\n        'chat_id' => $setting['Channel_Report'],\n        'message_thread_id' => $paymentreports,\n        'text' => $text_report,\n        'parse_mode' => \"HTML\"\n        ]);\n    }\n}\n}"
NEW_TAIL="// A valid bank deposit for a round plan price (e.g. 500000 tomans)\n// MUST NOT be ignored. The old \"ends with 000\" guard silently dropped it.\nif (isset($amountInteger) && is_numeric($amountInteger) &&\n    (int)$amountInteger > 0 && (float)$amountInteger == (int)$amountInteger) {\n    $amount=(int)$amountInteger;\n    // Serialize bank SMS webhooks by amount; older duplicate notifications\n    // cannot cause another side effect after payment_Status becomes paid.\n    $lockName='mirza_bank_auto_'.hash('sha256',(string)$amount);\n    $guard=$pdo->prepare(\"SELECT GET_LOCK(?,5)\");\n    $guard->execute([$lockName]);\n    if ((int)$guard->fetchColumn() !== 1) {\n        error_log('[Mirza bank auto] Payment amount is already being checked');\n        return;\n    }\n    try {\n        $Payment_report=mirzaFindUniqueBankCardPayment($pdo,$amount);\n        if (!$Payment_report) return;\n        $order_id=(string)$Payment_report['id_order'];\n        $Balance_id=select('user','*','id',$Payment_report['id_user'],'select');\n        if (!$Balance_id) return;\n        $textbotlang=languagechange('../text.json');\n\n        // Claim exactly once BEFORE creating the VPN service or charging a\n        // wallet. A second webhook or admin callback sees \"paid\" and stops.\n        // Bank transfer evidence has already been checked by the inbound\n        // trusted SMS notification handler above.\n        $claim=$pdo->prepare(\"UPDATE Payment_report\n            SET payment_Status='paid',\n                dec_not_confirmed='تأیید با اعلان تراکنش بانکی'\n            WHERE id_order=? AND payment_Status IN ('waiting','Unpaid')\n              AND Payment_Method='cart to cart'\");\n        $claim->execute([$order_id]);\n        if ($claim->rowCount() !== 1) return;\n\n        try {\n            DirectPayment($order_id,\"../images.jpg\");\n        } catch (Throwable $error) {\n            // Never blindly re-run provisioning or balance credit after an\n            // external-panel exception: operations may be partially complete.\n            error_log('[Mirza bank auto] Claimed payment needs MANUAL provisioning review; exception type: '\n                .get_class($error));\n            return;\n        }\n    $balanceformatsell = number_format(mysqli_fetch_assoc(mysqli_query($connect, \"SELECT (Balance) FROM user WHERE id = '{$Payment_report['id_user']}' LIMIT 1\"))['Balance'], 0);\n    $paymentreports = select(\"topicid\",\"idreport\",\"report\",\"paymentreport\",\"select\")['idreport'];\n    $text_report = \"یک رسید توسط ربات  تایید شد\n\nاطلاعات :\n💰 مبلغ پرداخت : {$Payment_report['price']}\n👤  آیدی عددی کاربر : {$Balance_id['id']} \n👤 نام کاربری کاربر : @{$Balance_id['username']} \nموجودی کاربر : $balanceformatsell تومان\nکد پیگیری پرداخت : $order_id\";\n    if (strlen($setting['Channel_Report']) > 0) {\n        telegram('sendmessage',[\n        'chat_id' => $setting['Channel_Report'],\n        'message_thread_id' => $paymentreports,\n        'text' => $text_report,\n        'parse_mode' => \"HTML\"\n        ]);\n    }\n    } finally {\n        $release=$pdo->prepare(\"SELECT RELEASE_LOCK(?)\");\n        $release->execute([$lockName]);\n    }\n}\n}"
REQUIRE="require_once __DIR__ . '/card_auto_match.php';\n"
ANCHOR="require_once __DIR__ . '/../keyboard.php';\n"

def planned(raw):
    data=raw.decode('utf-8')
    if data.count(REQUIRE)==1 and data.endswith(NEW_TAIL):
        return raw,False
    if data.count(ANCHOR)!=1 or data.count(REQUIRE)!=0:
        raise RuntimeError("Legacy bank import layout changed; no write")
    if not data.endswith(OLD_TAIL):
        raise RuntimeError("Legacy bank approval function customized; no write")
    # Preserve ALL other existing bank SMS patterns and configuration.
    data=data.replace(ANCHOR,ANCHOR+REQUIRE,1)
    data=data[:-len(OLD_TAIL)] + NEW_TAIL
    return data.encode('utf-8'),True

def update(target, helper_src):
    target=Path(target)
    helper_src=Path(helper_src)
    if not target.is_file() or target.is_symlink() or not helper_src.is_file():
        raise RuntimeError("Bank callback/helper paths invalid")
    new,changed=planned(target.read_bytes())
    parent=target.parent
    helper_target=parent/'card_auto_match.php'
    if helper_target.is_symlink():raise RuntimeError("Helper path is symlink")
    if helper_target.exists() and helper_target.read_bytes()!=helper_src.read_bytes():
        raise RuntimeError("Live bank matcher already customized; no changes")
    if not changed and helper_target.exists():
        print("MIRZA_BANK_AUTO_ALREADY_FIXED")
        return
    # Validate both PHP files without running any payment.
    for content in (new,helper_src.read_bytes()):
        fd,tmp=tempfile.mkstemp(prefix=".mirza-bank-lint-",suffix=".php",dir=parent)
        try:
            with os.fdopen(fd,'wb') as f:f.write(content)
            ck=subprocess.run(['php','-l',tmp],capture_output=True,text=True)
            if ck.returncode:raise RuntimeError("PHP lint error "+ck.stdout+ck.stderr)
        finally:
            if os.path.exists(tmp):os.unlink(tmp)
    if changed:
        backups=Path('/root/mirza-backups')
        backups.mkdir(parents=True,exist_ok=True,mode=0o700)
        b=backups/("card.php.before-safe-bank-auto-"+
             datetime.now(timezone.utc).strftime("%Y%m%d-%H%M%S-%f"))
        shutil.copy2(target,b)
        print("BACKUP:",b)
    if not helper_target.exists():
        shutil.copyfile(helper_src,helper_target)
        os.chmod(helper_target,0o644)
    if changed:
        fd,tmp=tempfile.mkstemp(prefix=".mirza-bank-",suffix=".php",dir=parent)
        try:
            with os.fdopen(fd,'wb') as out:out.write(new)
            st=target.stat()
            shutil.copymode(target,tmp)
            if os.geteuid()==0:os.chown(tmp,st.st_uid,st.st_gid)
            os.replace(tmp,target)
        finally:
            if os.path.exists(tmp):os.unlink(tmp)
    print("MIRZA_BANK_AUTO_SAFE_FIX_INSTALLED")

if __name__=='__main__':
    if len(sys.argv)!=3:
        sys.exit("Usage: sudo python3 install_bank_card_auto.py /live/payment/card.php /clone/payment/card_auto_match.php")
    try:update(sys.argv[1],sys.argv[2])
    except (RuntimeError,OSError,UnicodeError) as error:
        print("ABORTED, original bank callback preserved:",error,file=sys.stderr)
        sys.exit(1)
