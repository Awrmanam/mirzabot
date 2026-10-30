<?php
/**
 * Read-only audit of card receipt evidence, compatible with older Mirza
 * installations that do not yet have vpnappPrivateReceiptExists().
 *
 * Deploy ONLY this CLI file into /var/www/mirzaprobotconfig/scripts.
 * No API/checkout patch is required. Never prints image data, tokens or keys.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
require_once __DIR__.'/../config.php';
if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR,"INSPECTION_ERROR: Mirza PDO connection unavailable".PHP_EOL);exit(2);
}
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$privateDir='/var/www/mirza-vpnapp-receipts';
$seen=0; $stored=0; $notFound=0; $telegram=0;

function receiptFileState($id,$folder) {
    if (!preg_match('/^[0-9a-f-]{36}$/D',(string)$id)) return 'invalid-id';
    if (!is_dir($folder)) return 'storage-dir-missing';
    if (!is_readable($folder)) return 'storage-dir-unreadable';
    $hashes=[hash('sha256',$id)];
    // Older uploads used an HMAC; the original secret may be available
    // in PHP's environment, but avoid disclosing or logging its value.
    $secret=getenv('MIRZA_APP_BRIDGE_SECRET');
    if (is_string($secret) && $secret!=='') {
        $hashes[]=hash_hmac('sha256',$id,$secret);
    }
    $hashes[]=hash_hmac('sha256',$id,'');
    foreach(array_unique($hashes) as $digest) {
        foreach(['.jpg','.png'] as $extension) {
            $path=$folder.'/'.$digest.$extension;
            if (is_file($path) && !is_link($path)) return 'stored';
        }
    }
    return 'not-found-by-cli';
}
try {
    $query=$pdo->query("SELECT external_id,owner_id,payment_id,status,kind,result_json
      FROM vpnapp_orders WHERE kind IN ('card','renewal_card')
      ORDER BY updated_at DESC LIMIT 40");
    $payments=$pdo->prepare("SELECT payment_Status FROM Payment_report
      WHERE id_order=? AND id_user=? LIMIT 1");
    foreach($query->fetchAll(PDO::FETCH_ASSOC) as $order) {
        $seen++;
        $meta=json_decode((string)($order['result_json']??''),true);
        if (!is_array($meta)) $meta=[];
        $photo=receiptFileState((string)$order['external_id'],$privateDir);
        if ($photo==='stored') $stored++; else $notFound++;
        if (!empty($meta['receipt_notice_sent'])) $telegram++;
        $payments->execute([$order['payment_id'],$order['owner_id']]);
        $payment=$payments->fetchColumn();
        $payment=is_string($payment) ? $payment : 'unknown';
        echo implode(' | ',[
          'order='.$order['external_id'],
          'app='.$order['status'],
          'payment='.$payment,
          'photo='.$photo,
          'telegram='.(!empty($meta['receipt_notice_sent'])?'acknowledged':'unconfirmed'),
          'tries='.(int)($meta['receipt_send_attempts']??0),
        ]).PHP_EOL;
    }
    echo "SUMMARY orders=$seen photos_seen=$stored photos_unconfirmed=$notFound telegram_acknowledged=$telegram".PHP_EOL;
    if ($notFound>0) {
        echo "NOTE: photo=not-found-by-cli is NOT proof of lost funds or files. Older HMAC paths may require the web PHP environment.".PHP_EOL;
    }
} catch(Throwable $error) {
    fwrite(STDERR,"INSPECTION_ERROR: ".get_class($error).": ".
        $error->getMessage().PHP_EOL);exit(1);
}
