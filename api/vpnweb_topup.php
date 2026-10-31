<?php
/**
 * Mirza-authoritative manual wallet top-ups for non-Telegram customers.
 *
 * No Telegram API, bank callback, cron/DirectPayment, or external balance.
 * A receipt is only evidence uploaded by the customer, never verification.
 * The Payment_report row uses a PRIVATE state vpnweb_review_pending,
 * never legacy Unpaid/waiting, because payment/card.php matches those solely
 * by amount and could otherwise auto-credit an unrelated incoming bank SMS.
 * Approval requires an authenticated internal administrator who has checked
 * the independent bank statement and supplies the UNIQUE bank transaction ref.
 * No global web routes: call through the already signed vpnapp.php bridge only.
 */
function vpnwebTopupEnabled() {
    if (getenv('MIRZA_VPNWEB_TOPUP_ENABLED') !== '1')
        vpnappError(503,'Manual wallet recharge disabled');
}
function vpnwebTopupOwner($u) {
    vpnwebTopupEnabled();
    if (!isset($u['id']) || !is_string($u['id']) ||
        !preg_match('/^web_[0-9a-f]{32}$/D',$u['id']))
        vpnappError(403,'Independent web account required');
}
function vpnwebTopupKey($id) {
    if (!is_string($id) || !preg_match('/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/D',$id))
        vpnappError(400,'Invalid idempotency key');
    return $id;
}
function vpnwebTopupPublic($row) {
    $status=(string)$row['state'];
    return [
      'id'=>(string)$row['external_id'],
      'amount_toman'=>(int)$row['amount_toman'],
      'status'=>$status,
      'receipt_stored'=>in_array($status,['awaiting_manual_review','approved','rejected'],true),
      'payment_verified'=>$status==='approved',
    ];
}
function vpnwebTopupOwned(PDO $db,$u,$id) {
    $get=$db->prepare('SELECT * FROM vpnweb_topups WHERE external_id=? AND owner_id=?');
    $get->execute([$id,$u['id']]);
    $row=$get->fetch(PDO::FETCH_ASSOC);
    if (!$row) vpnappError(404,'Top-up not found');
    return $row;
}
function vpnwebTopupCreate(PDO $db,$u,$id,$amount) {
    vpnwebTopupOwner($u);
    vpnwebTopupKey($id);
    if (!is_int($amount) || $amount<10000 || $amount>2000000)
        vpnappError(400,'Invalid top-up amount');
    if (!vpnwebIdentitySchemaReady($db))
        vpnappError(503,'Web identity migration required');
    $db->beginTransaction();
    try {
        $lock=$db->prepare('SELECT Balance FROM user WHERE id=? AND User_Status!=? FOR UPDATE');
        $lock->execute([$u['id'],'block']);
        if ($lock->fetchColumn()===false) throw new RuntimeException('Account unavailable');
        $stmt=$db->prepare('SELECT * FROM vpnweb_topups WHERE external_id=? FOR UPDATE');
        $stmt->execute([$id]);
        $existing=$stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            if ($existing['owner_id']!==$u['id'] || (int)$existing['amount_toman']!==$amount)
                throw new RuntimeException('Idempotency key conflict');
            $db->commit();
            return vpnwebTopupStatus($db,$u,$id);
        }
        // Protect against repeated pending requests under changing UUIDs.
        $pending=$db->prepare("SELECT COUNT(*) FROM vpnweb_topups WHERE owner_id=?
             AND state IN ('awaiting_receipt','awaiting_manual_review') AND created_at>?");
        $pending->execute([$u['id'],time()-86400]);
        if ((int)$pending->fetchColumn()>=3) throw new RuntimeException('Too many pending top-ups');
        $card=$db->query('SELECT cardnumber,namecard FROM card_number ORDER BY RAND() LIMIT 1')
                 ->fetch(PDO::FETCH_ASSOC);
        if (!$card || !preg_match('/^[0-9]{16}$/D',(string)$card['cardnumber']))
            throw new RuntimeException('Bank transfer details not configured');
        $paymentId=bin2hex(random_bytes(10));
        $db->prepare("INSERT INTO Payment_report
           (id_user,id_order,time,price,payment_Status,Payment_Method,bottype,id_invoice)
           VALUES (?,?,?,?, 'vpnweb_review_pending','vpnweb_manual_wallet_topup','vpnweb_manual',NULL)")
           ->execute([$u['id'],$paymentId,date('Y/m/d H:i:s'),$amount]);
        $db->prepare("INSERT INTO vpnweb_topups
           (external_id,owner_id,amount_toman,payment_id,card_number,card_holder,state,created_at,updated_at)
           VALUES (?,?,?,?,?,?,'awaiting_receipt',?,?)")
           ->execute([$id,$u['id'],$amount,$paymentId,$card['cardnumber'],
                      $card['namecard'],time(),time()]);
        $db->commit();
        return ['id'=>$id,'amount_toman'=>$amount,'status'=>'awaiting_receipt',
                'card_number'=>$card['cardnumber'],'card_holder'=>$card['namecard'],
                'receipt_stored'=>false,'payment_verified'=>false];
    } catch(Throwable $e) {
        if($db->inTransaction()) $db->rollBack();
        error_log('[vpnweb] topup create rejected: '.get_class($e));
        vpnappError(409,'Top-up request unavailable or already exists');
    }
}
function vpnwebTopupStatus(PDO $db,$u,$id) {
    vpnwebTopupOwner($u);vpnwebTopupKey($id);
    $row=vpnwebTopupOwned($db,$u,$id);
    $out=vpnwebTopupPublic($row);
    if ($row['state']==='awaiting_receipt') {
        $out['card_number']=$row['card_number'];
        $out['card_holder']=$row['card_holder'];
    }
    return $out;
}
function vpnwebTopupReceipt(PDO $db,$u,$id,$encoded) {
    vpnwebTopupOwner($u);vpnwebTopupKey($id);
    if (!is_string($encoded)||strlen($encoded)>2100000)
        vpnappError(413,'Receipt too large');
    $bytes=base64_decode($encoded,true);
    if ($bytes===false||strlen($bytes)<200||strlen($bytes)>1500000)
        vpnappError(415,'Invalid image');
    $mime=(new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
    if (!in_array($mime,['image/png','image/jpeg'],true))
        vpnappError(415,'Only JPEG or PNG');
    // Require a decoded valid image, not simply a file with image MIME header.
    $meta=@getimagesizefromstring($bytes);
    if (!$meta||$meta['mime']!==$mime)
        vpnappError(415,'Invalid image');
    $db->beginTransaction();
    try {
        $stmt=$db->prepare('SELECT * FROM vpnweb_topups WHERE external_id=? AND owner_id=? FOR UPDATE');
        $stmt->execute([$id,$u['id']]);
        $topup=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!$topup) { $db->rollBack(); vpnappError(404,'Unknown top-up'); }
        if (in_array($topup['state'],['awaiting_manual_review','approved'],true)) {
            // Acknowledgement may have been lost after durable Mirza storage,
            // even after an authorized admin approved. Never replace/credit.
            $db->commit();return vpnwebTopupPublic($topup);
        }
        if ($topup['state']!=='awaiting_receipt') {
            $db->rollBack();vpnappError(409,'Top-up cannot accept new receipt');
        }
        if (!vpnappPersistReceipt($id,$bytes,$mime))
            throw new RuntimeException('Private receipt directory unavailable');
        $db->prepare("UPDATE vpnweb_topups SET state='awaiting_manual_review',
            receipt_mime=?,receipt_sha256=?,updated_at=? WHERE external_id=?")
            ->execute([$mime,hash('sha256',$bytes),time(),$id]);
        $db->prepare("UPDATE Payment_report SET at_updated=?
            WHERE id_order=? AND id_user=? AND payment_Status='vpnweb_review_pending'
            AND bottype='vpnweb_manual' AND Payment_Method='vpnweb_manual_wallet_topup'")
            ->execute([date('Y-m-d H:i:s'),$topup['payment_id'],$u['id']]);
        $db->commit();
        return ['id'=>$id,'status'=>'awaiting_manual_review','receipt_stored'=>true,
                'payment_verified'=>false];
    } catch(Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[vpnweb] receipt persistence failed: '.get_class($e));
        vpnappError(503,'Receipt could not be accepted; retry with same id');
    }
}
function vpnwebTopupApprove(PDO $db,$id,$bankReference,$reviewer) {
    vpnwebTopupEnabled();
    if (getenv('MIRZA_VPNWEB_MANUAL_APPROVAL_ENABLED')!=='1')
        vpnappError(503,'Manual approval disabled');
    vpnwebTopupKey($id);
    if (!is_string($bankReference) ||
        !preg_match('/^[A-Za-z0-9_.:-]{8,100}$/D',$bankReference) ||
        !is_string($reviewer) || !preg_match('/^[A-Za-z0-9_-]{3,64}$/D',$reviewer))
        vpnappError(400,'Bank reference and reviewer are required');
    // The authenticated administrator MUST have independently verified this
    // transaction exists in the merchant's bank statement; code cannot prove it.
    $db->beginTransaction();
    try {
        $get=$db->prepare('SELECT * FROM vpnweb_topups WHERE external_id=? FOR UPDATE');
        $get->execute([$id]);$row=$get->fetch(PDO::FETCH_ASSOC);
        if (!$row) { $db->rollBack();vpnappError(404,'Unknown top-up'); }
        if ($row['state']==='approved') {
            if ($row['bank_reference']!==$bankReference)
                throw new RuntimeException('Approved bank reference mismatch');
            $db->commit();return vpnwebTopupPublic($row);
        }
        if ($row['state']!=='awaiting_manual_review' ||
            !is_string($row['receipt_sha256']) || strlen($row['receipt_sha256'])!==64)
            throw new RuntimeException('Receipt not awaiting review');
        $payment=$db->prepare("UPDATE Payment_report SET payment_Status='paid',
            dec_not_confirmed='Manually reconciled to bank statement'
            WHERE id_order=? AND id_user=? AND payment_Status='vpnweb_review_pending'
            AND Payment_Method='vpnweb_manual_wallet_topup' AND bottype='vpnweb_manual'");
        $payment->execute([$row['payment_id'],$row['owner_id']]);
        if ($payment->rowCount()!==1)
            throw new RuntimeException('Payment status changed; no credit granted');
        $credit=$db->prepare('UPDATE user SET Balance=Balance+? WHERE id=? AND User_Status!=?');
        $credit->execute([(int)$row['amount_toman'],$row['owner_id'],'block']);
        if ($credit->rowCount()!==1)
            throw new RuntimeException('Wallet owner unavailable');
        // bank_reference UNIQUE protects reusing one statement transfer for
        // multiple user accounts or UUIDs. Transaction rollback undoes credit.
        $db->prepare("UPDATE vpnweb_topups SET state='approved',bank_reference=?,
            approved_by=?,updated_at=? WHERE external_id=? AND state='awaiting_manual_review'")
           ->execute([$bankReference,$reviewer,time(),$id]);
        $db->commit();
        return ['id'=>$id,'status'=>'approved','payment_verified'=>true,
                'amount_toman'=>(int)$row['amount_toman']];
    } catch(Throwable $e) {
        if($db->inTransaction()) $db->rollBack();
        error_log('[vpnweb] manual approval failed: '.get_class($e));
        vpnappError(409,'Manual approval rejected; verify statement and status');
    }
}
function vpnwebTopupPending(PDO $db) {
    vpnwebTopupEnabled();
    $rows=$db->query("SELECT external_id,amount_toman,created_at,state,
         owner_id,receipt_sha256 FROM vpnweb_topups
         WHERE state='awaiting_manual_review' ORDER BY created_at ASC LIMIT 50")
         ->fetchAll(PDO::FETCH_ASSOC);
    return ['items'=>$rows];
}
