<?php
/** Read-only report to locate old app receipt images and Telegram state.
 * Run: sudo -u www-data php scripts/inspect_vpnapp_receipts.php
 * No money, invoice, receipt or order state is ever changed.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
define('MIRZA_VPNAPP_LIBRARY_ONLY', true);
require_once __DIR__.'/../api/vpnapp.php';
if (!isset($pdo) || !($pdo instanceof PDO)) { fwrite(STDERR,"Database unavailable\n");exit(2); }

try {
    $q=$pdo->query("SELECT external_id,owner_id,payment_id,status,kind,result_json
        FROM vpnapp_orders WHERE kind IN ('card','renewal_card')
        ORDER BY updated_at DESC LIMIT 35");
    $rows=$q->fetchAll(PDO::FETCH_ASSOC);
    $missing=0;$saved=0;$delivered=0;
    foreach ($rows as $row) {
        $metadata=json_decode((string)($row['result_json']??''),true);
        if (!is_array($metadata)) $metadata=[];
        $found=vpnappPrivateReceiptExists($row['external_id']);
        if ($found) $saved++; else $missing++;
        if (!empty($metadata['receipt_notice_sent'])) $delivered++;
        $state=$pdo->prepare("SELECT payment_Status FROM Payment_report
            WHERE id_order=? AND id_user=? LIMIT 1");
        $state->execute([$row['payment_id'],$row['owner_id']]);
        $payment=(string)($state->fetchColumn()?:'unknown');
        echo implode(' | ',[
            'order='.$row['external_id'], 'user='.$row['owner_id'],
            'app='.$row['status'], 'payment='.$payment,
            'photo='.($found?'stored':'MISSING'),
            'telegram='.(!empty($metadata['receipt_notice_sent'])?'sent':'pending'),
            'tries='.(int)($metadata['receipt_send_attempts']??0)
        ]).PHP_EOL;
    }
    echo "SUMMARY card_orders=".count($rows)." stored_photos=$saved missing_photos=$missing telegram_confirmed=$delivered".PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR,"INSPECTION_ERROR: ".$e->getMessage().PHP_EOL);
    exit(1);
}
