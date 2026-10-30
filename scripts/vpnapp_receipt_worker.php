<?php
/** Private, idempotent Telegram photo delivery worker for mobile receipts.
 * Run as PHP-FPM's filesystem user every minute:
 *   * * * * * www-data /usr/bin/php /var/www/mirzaprobotconfig/scripts/vpnapp_receipt_worker.php
 * NEVER run via the web. No bank balance, payment approval or invoice writes.
 */
if (PHP_SAPI!=='cli') { http_response_code(403); exit(1); }
define('MIRZA_VPNAPP_LIBRARY_ONLY', true);
require_once __DIR__.'/../api/vpnapp.php';

$lockPath=sys_get_temp_dir().'/mirza-vpnapp-receipt-delivery.lock';
$lock=fopen($lockPath,'c');
if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) { exit(0); }
$now=time();
$delivered=0;
try {
    $orders=$pdo->query("SELECT external_id,owner_id,payment_id,result_json
        FROM vpnapp_orders WHERE status='waiting'
        ORDER BY updated_at DESC LIMIT 2000")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($orders as $order) {
        if ($delivered>=1) break; // Bound worst-case Telegram retry latency per systemd run.
        $stored=json_decode((string)($order['result_json']??''),true);
        if (!is_array($stored)) $stored=[];
        if (!empty($stored['receipt_notice_sent'])) continue;
        $tries=(int)($stored['receipt_send_attempts']??0);
        $last=(int)($stored['receipt_last_attempt_at']??0);
        if ($tries>=8 || ($last>0 && $now-$last<min(3600,30*(2**min($tries,6))))) continue;
        $key=(string)$order['external_id'];
        $image=null;
        foreach (['image/jpeg','image/png'] as $mime) {
            $path=vpnappFindReceipt($key,$mime);
            if ($path) {
                $image=$path;
                break;
            }
        }
        if ($image===null) continue;
        $payment=(string)($order['payment_id']??'');
        $owner=(string)$order['owner_id'];
        // A Telegram moderator may already have approved/rejected the receipt.
        $check=$pdo->prepare("SELECT payment_Status FROM Payment_report
            WHERE id_order=? AND id_user=? LIMIT 1");
        $check->execute([$payment,$owner]);
        if ($check->fetchColumn()!=='waiting') continue;

        $pdo->beginTransaction();
        try {
            $q=$pdo->prepare("SELECT result_json,status FROM vpnapp_orders
                WHERE external_id=? AND owner_id=? FOR UPDATE");
            $q->execute([$key,$owner]);$current=$q->fetch(PDO::FETCH_ASSOC);
            if (!$current || $current['status']!=='waiting') {
                $pdo->rollBack();continue;
            }
            $meta=json_decode((string)($current['result_json']??''),true);
            if (!is_array($meta)) $meta=[];
            $previous=(int)($meta['receipt_send_attempts']??0);
            $previousAt=(int)($meta['receipt_last_attempt_at']??0);
            if (!empty($meta['receipt_notice_sent']) || $previous>=8 ||
                ($previousAt>0 && time()-$previousAt<min(3600,30*(2**min($previous,6))))) {
                $pdo->rollBack();continue;
            }
            $meta['receipt_last_attempt_at']=time();
            $meta['receipt_send_attempts']=$previous+1;
            $pdo->prepare("UPDATE vpnapp_orders SET result_json=?,updated_at=?
                WHERE external_id=? AND owner_id=?")->execute([
                json_encode($meta,JSON_UNESCAPED_UNICODE),time(),$key,$owner]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        $delivered++;
        $message='🧾 رسید کارت‌به‌کارت از اپ VPN'."\n".
            'کاربر: '.htmlspecialchars($owner,ENT_QUOTES,'UTF-8')."\n".
            'پیگیری: '.htmlspecialchars($payment,ENT_QUOTES,'UTF-8')."\n".
            'وضعیت: در انتظار بررسی';
        $sent=vpnappNotify($message,$owner,$image,$payment);
        if ($sent) {
            $pdo->beginTransaction();
            try {
                $q=$pdo->prepare("SELECT result_json FROM vpnapp_orders
                    WHERE external_id=? AND owner_id=? FOR UPDATE");
                $q->execute([$key,$owner]);$meta=json_decode((string)$q->fetchColumn(),true);
                if (!is_array($meta)) $meta=[];
                $meta['receipt_notice_sent']=true;
                $pdo->prepare("UPDATE vpnapp_orders SET result_json=?,updated_at=?
                    WHERE external_id=? AND owner_id=?")->execute([
                    json_encode($meta,JSON_UNESCAPED_UNICODE),time(),$key,$owner]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
        } else {
            error_log('[vpnapp] queued receipt Telegram delivery failed; will retry: '.$key);
        }
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[vpnapp] receipt delivery worker failed: '.get_class($e).' '.$e->getMessage());
    exit(1);
} finally {
    flock($lock,LOCK_UN);
    fclose($lock);
}
