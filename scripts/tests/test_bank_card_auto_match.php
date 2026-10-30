<?php
require dirname(__DIR__, 2).'/payment/card_auto_match.php';
function testAssert($value, $message) {
    if (!$value) { fwrite(STDERR, "FAIL: ".$message.PHP_EOL); exit(1); }
}
$a=['id_order'=>'a','price'=>500000,'payment_Status'=>'waiting'];
$b=['id_order'=>'b','price'=>500000,'payment_Status'=>'Unpaid'];
testAssert(mirzaBankCandidateFromRows([]) === null, 'No bank order should not approve');
testAssert(mirzaBankCandidateFromRows([$a]) === $a, 'One matching order is eligible');
testAssert(mirzaBankCandidateFromRows([$a,$b]) === null, 'Same-amount ambiguity must not auto approve');
$renew=['id_order'=>'renew','price'=>500000,'payment_Status'=>'waiting',
        'id_invoice'=>'vpnapp_renew|user'];
testAssert(mirzaBankCandidateFromRows([$renew]) === null,
           'App card renewals without deployed worker must not be miscredited to wallet');
$purchase=['id_order'=>'order','price'=>500000,'payment_Status'=>'waiting',
           'id_invoice'=>'getconfigafterpay|app_user'];
testAssert(mirzaBankCandidateFromRows([$purchase]) === $purchase,
           'Normal app purchase must remain eligible for bank-verified auto approval');
echo "BANK_CANDIDATE_TEST_OK\n";
