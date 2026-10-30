<?php
/** Read-only Mirza card auto-confirmation diagnostic. NEVER confirms payments.
 * Run: sudo -u www-data php /tmp/mirza-auto-check/scripts/diagnose_card_autoconfirm.php
 */
if (PHP_SAPI!=='cli') { http_response_code(403); exit(1); }
require_once __DIR__.'/../config.php';
if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR,"ERROR: PDO unavailable".PHP_EOL);exit(2);
}
try {
    $values=[];
    $q=$pdo->prepare("SELECT NamePay,ValuePay FROM PaySetting WHERE NamePay IN
        ('statuscardautoconfirm','autoconfirmcart') LIMIT 2");
    $q->execute();
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row)
        $values[$row['NamePay']]=$row['ValuePay'];
    $verified=$values['statuscardautoconfirm']??'NOT_SET';
    $blind=$values['autoconfirmcart']??'NOT_SET';
    $config=$pdo->query("SELECT timeauto_not_verify FROM setting LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $delay=(int)($config['timeauto_not_verify']??0);
    echo "bank_notification_verification=".($verified==='onautoconfirm'?'ON':'OFF').PHP_EOL;
    echo "timer_based_unverified_confirmation=".($blind==='onauto'?'ON':'OFF').PHP_EOL;
    echo "configured_delay_minutes=".$delay.PHP_EOL;
    echo "php_default_timezone=".date_default_timezone_get().PHP_EOL;
    echo "cron_timezone=Asia/Tehran".PHP_EOL;
    echo "php_now=".date('Y-m-d H:i:s').PHP_EOL;
    echo "tehran_now=".(new DateTimeImmutable('now',
        new DateTimeZone('Asia/Tehran')))->format('Y-m-d H:i:s').PHP_EOL;
    if ($verified==='onautoconfirm' && $blind==='onauto')
        echo "WARNING: BOTH MODES ON; legacy cron skips while bank matching is ON".PHP_EOL;
    if ($delay>=60)
        echo "WARNING: Delay >=60 minutes; legacy cron only considers receipts younger than 60 minutes".PHP_EOL;

    $q=$pdo->query("SELECT payment_Status,Payment_Method,bottype,at_updated
        FROM Payment_report
        WHERE payment_Status='waiting' AND
            Payment_Method IN ('cart to cart','arze digital offline')
        ORDER BY at_updated DESC LIMIT 100");
    $c=['eligible_now'=>0, 'over_60_minutes'=>0, 'no_receipt_time'=>0,
        'blocked_by_bottype'=>0, 'waiting_for_delay'=>0, 'future_timestamp'=>0];
    $tz=new DateTimeZone('Asia/Tehran');
    $now=new DateTimeImmutable('now',$tz);
    $samples=0;
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($row['bottype']!==null) { $c['blocked_by_bottype']++;continue; }
        if (empty($row['at_updated'])) { $c['no_receipt_time']++;continue; }
        try { $updated=DateTimeImmutable::createFromFormat('!Y/m/d H:i:s',(string)$row['at_updated'],$tz); }
        catch(Throwable $e) { $updated=false; }
        if (!$updated) { $c['no_receipt_time']++;continue; }
        $age=$now->getTimestamp()-$updated->getTimestamp();
        if ($age>=3600) $c['over_60_minutes']++;
        elseif ($age<0) $c['future_timestamp']++;
        elseif ($age<=$delay*60) $c['waiting_for_delay']++;
        else $c['eligible_now']++;
    }
    echo "waiting_cards_by_reason=".json_encode($c,JSON_UNESCAPED_UNICODE).PHP_EOL;
    echo "NOTE: eligible_now is a time/filter check, not proof a bank transfer occurred.".PHP_EOL;
    echo "NOTE: the timer needs a live cron job; verify with crontab -l.".PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR,"AUTO_CONFIRM_DIAGNOSTIC_ERROR: ".
        get_class($e)." ".$e->getMessage().PHP_EOL);exit(1);
}
