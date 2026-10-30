<?php
/** Read-only integration audit for original Mirza bank auto-confirm and VPN app.
 * Does not call bank webhook, DirectPayment, or change any payment record.
 */
if (PHP_SAPI!=='cli') exit(1);
require_once '/var/www/mirzaprobotconfig/config.php';
if (!isset($pdo) || !($pdo instanceof PDO)) exit(2);
try {
    $config=$pdo->prepare("SELECT NamePay,ValuePay FROM PaySetting
        WHERE NamePay IN ('statuscardautoconfirm','autoconfirmcart')");
    $config->execute();$modes=[];
    foreach($config->fetchAll(PDO::FETCH_ASSOC) as $row)
        $modes[$row['NamePay']]=$row['ValuePay'];
    $bank=$modes['statuscardautoconfirm']??'unknown';
    $blind=$modes['autoconfirmcart']??'unknown';
    echo "bank_verified_mode=".($bank==='onautoconfirm'?'ON':'OFF').PHP_EOL;
    echo "unverified_timer_mode=".($blind==='onauto'?'ON':'OFF').PHP_EOL;
    echo "mode_conflict=".(($bank==='onautoconfirm'&&$blind==='onauto')?'YES':'NO').PHP_EOL;
    if ($bank!=='onautoconfirm' && $blind!=='onauto')
        echo "NOTICE: Both automatic modes are disabled".PHP_EOL;
    $cardSource='/var/www/mirzaprobotconfig/payment/card.php';
    $matcher='/var/www/mirzaprobotconfig/payment/card_auto_match.php';
    $code=is_file($cardSource)?file_get_contents($cardSource):'';
    echo "bank_callback_exists=".(is_file($cardSource)?'YES':'NO').PHP_EOL;
    echo "bank_matcher_installed=".(is_file($matcher)?'YES':'NO').PHP_EOL;
    echo "bank_round_amount_bug_present=".
        (strpos((string)$code,"substr(\$amountInteger, -3)")!==false?'YES':'NO').PHP_EOL;
    $q=$pdo->query("SELECT COUNT(*) FROM (
        SELECT price FROM Payment_report
        WHERE Payment_Method='cart to cart'
          AND payment_Status IN ('waiting','Unpaid')
        GROUP BY price HAVING COUNT(*)>1) v");
    echo "ambiguous_open_price_groups=".(int)$q->fetchColumn().PHP_EOL;
    $q=$pdo->query("SELECT
         SUM(CASE WHEN payment_Status='waiting' THEN 1 ELSE 0 END) AS waiting,
         SUM(CASE WHEN payment_Status='Unpaid' THEN 1 ELSE 0 END) AS unpaid,
         SUM(CASE WHEN payment_Status='paid' THEN 1 ELSE 0 END) AS paid,
         SUM(CASE WHEN id_invoice LIKE 'vpnapp_renew|%' AND
                  payment_Status IN ('waiting','Unpaid') THEN 1 ELSE 0 END) AS card_renewals_pending
        FROM Payment_report WHERE Payment_Method='cart to cart'");
    $c=$q->fetch(PDO::FETCH_ASSOC)?:[];
    foreach($c as $k=>$v)echo "card_".$k."=".(int)$v.PHP_EOL;
    // Payment may be correctly verified by the bank yet provisioning can
    // fail independently; report these cases for the administrator.
    $q=$pdo->query("SELECT id_invoice FROM Payment_report
        WHERE Payment_Method='cart to cart' AND payment_Status='paid'
          AND id_invoice LIKE 'getconfigafterpay|%'
        ORDER BY `time` DESC LIMIT 100");
    $service=$pdo->prepare("SELECT Status FROM invoice WHERE username=? LIMIT 1");
    $active=0; $needsReview=0;
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $payment) {
        $parts=explode('|',(string)$payment['id_invoice'],2);
        if (count($parts)!==2) continue;
        $service->execute([$parts[1]]);
        if ($service->fetchColumn()==='active') $active++;
        else $needsReview++;
    }
    echo "bank_paid_card_services_active=$active".PHP_EOL;
    echo "bank_paid_card_services_not_active=$needsReview".PHP_EOL;
    if ($needsReview>0)
        echo "WARNING: Bank-verified payments without active service require manual provisioning reconciliation".PHP_EOL;
    echo "NOTE: An ON bank mode does not confirm a receipt by photo alone. A legitimate bank notification must reach payment/card.php.".PHP_EOL;
    echo "NOTE: Same-amount open orders require manual review; a bank SMS does not identify a customer.".PHP_EOL;
    echo "NOTE: A paid bank record alone does not prove VPN service provisioning succeeded.".PHP_EOL;
} catch(Throwable $e) {
    fwrite(STDERR,"AUTO_AUDIT_ERROR: ".get_class($e)." (inspect schema/logs)".PHP_EOL);
    exit(1);
}
