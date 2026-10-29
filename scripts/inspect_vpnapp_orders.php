<?php
/**
 * Mirza app order recovery CLI. Never deletes bank evidence or invoices.
 *
 * php scripts/inspect_vpnapp_orders.php
 * sudo php scripts/inspect_vpnapp_orders.php --cancel-no-transfer <UUID> CONFIRM_NO_BANK_TRANSFER
 *
 * A cancellation is allowed ONLY for an unfulfilled card order whose invoice
 * is Unpaid, payment record is Unpaid/waiting and the operator has independently
 * confirmed NO bank transfer occurred. This retains an audit trail.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__.'/../config.php';
if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR,"Mirza PDO database connection unavailable\n"); exit(2);
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if ($argc===1 || ($argc===2 && $argv[1]==='--list')) {
    $rows=$pdo->query("SELECT o.external_id,o.owner_id,o.kind,o.status,
        o.payment_id,o.invoice_id,o.created_at,
        p.payment_Status AS payment_status,i.Status AS invoice_status
      FROM vpnapp_orders o
      LEFT JOIN Payment_report p ON p.id_order=o.payment_id AND p.id_user=o.owner_id
      LEFT JOIN invoice i ON i.id_invoice=o.invoice_id AND i.id_user=o.owner_id
      ORDER BY o.created_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        echo implode(" | ", [
            $r['external_id'], $r['owner_id'], $r['kind'], $r['status'],
            $r['payment_status']??'?', $r['invoice_status']??'?',
            date('Y-m-d H:i', (int)$r['created_at'])
        ])."\n";
    }
    exit(0);
}

if ($argc!==4 || $argv[1]!=='--cancel-no-transfer' ||
    !preg_match('/^[0-9a-f-]{36}$/D',$argv[2]) ||
    $argv[3]!=='CONFIRM_NO_BANK_TRANSFER') {
    fwrite(STDERR, "Invalid request. Nothing changed.\n"); exit(2);
}
$key=$argv[2];
$backupFile=null;
try {
    $pdo->beginTransaction();
    $stmt=$pdo->prepare("SELECT * FROM vpnapp_orders WHERE external_id=? FOR UPDATE");
    $stmt->execute([$key]);$app=$stmt->fetch(PDO::FETCH_ASSOC);
    if (!$app || !in_array($app['kind'],['card','renewal_card'],true) ||
        !in_array($app['status'],['awaiting_receipt','waiting'],true)) {
        throw new RuntimeException('Not an eligible unfulfilled card test order');
    }
    $stmt=$pdo->prepare("SELECT * FROM Payment_report WHERE id_order=?
        AND id_user=? FOR UPDATE");
    $stmt->execute([$app['payment_id'],$app['owner_id']]);$payment=$stmt->fetch(PDO::FETCH_ASSOC);
    $stmt=$pdo->prepare("SELECT * FROM invoice WHERE id_invoice=?
        AND id_user=? FOR UPDATE");
    $stmt->execute([$app['invoice_id'],$app['owner_id']]);$invoice=$stmt->fetch(PDO::FETCH_ASSOC);
    if (!$payment || !$invoice ||
        !in_array($payment['payment_Status'],['Unpaid','waiting'],true) ||
        $invoice['Status']!=='Unpaid') {
        throw new RuntimeException('Payment/invoice state is not safe to cancel');
    }
    $backups='/root/mirza-backups';
    if (!is_dir($backups) && !mkdir($backups,0700,true)) {
        throw new RuntimeException('Cannot create backup directory');
    }
    $backupFile=$backups.'/test-order-'.bin2hex(random_bytes(10)).'.json';
    $encoded=json_encode(['app'=>$app,'payment'=>$payment,'invoice'=>$invoice],
        JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
    if (file_put_contents($backupFile,$encoded,LOCK_EX)===false ||
        !chmod($backupFile,0600)) {
        throw new RuntimeException('Secure backup failed');
    }
    // Archive the old checkout and unpaid payment; do not delete any records.
    $stmt=$pdo->prepare("UPDATE vpnapp_orders SET status='rejected',
        updated_at=? WHERE external_id=? AND status IN ('awaiting_receipt','waiting')");
    $stmt->execute([time(),$key]);
    if ($stmt->rowCount()!==1) throw new RuntimeException('Concurrent order change');
    $stmt=$pdo->prepare("UPDATE Payment_report SET payment_Status='reject'
        WHERE id_order=? AND id_user=? AND payment_Status IN ('Unpaid','waiting')");
    $stmt->execute([$app['payment_id'],$app['owner_id']]);
    if ($stmt->rowCount()!==1) throw new RuntimeException('Concurrent payment change');
    $pdo->commit();
    echo "TEST ORDER CANCELLED (not deleted). Backup: ".$backupFile."\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR,"ABORTED: ".$e->getMessage()."\n");
    exit(1);
}
