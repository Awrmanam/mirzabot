<?php
/**
 * Optional app card-renewal fulfilment. Schedule once per minute only after
 * Mirza bot staging QA. Does NOT alter legacy cronbot/croncard.php.
 *
 * Existing Mirza auto-confirm first credits the user's real wallet using the
 * existing Payment_report + DirectPayment fallback. This worker then spends
 * that credit through the safe, idempotent app renewal path.
 *
 * Unknown/network outcomes are NEVER retried. Manual reconciliation required.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
if (getenv('MIRZA_VPNAPP_BRIDGE_ENABLED') !== '1' ||
    getenv('MIRZA_VPNAPP_CHECKOUT_ENABLED') !== '1') exit;

define('MIRZA_VPNAPP_LIBRARY_ONLY', true);
require_once __DIR__ . '/../api/vpnapp.php';
vpnappSetup($pdo);
$mutex=$pdo->query("SELECT GET_LOCK('mirza_vpnapp_card_renew_cron',0)")->fetchColumn();
if ((int)$mutex!==1) exit;
try {
    $stmt=$pdo->prepare("SELECT o.external_id,o.owner_id,o.result_json,p.price,p.at_updated
        FROM vpnapp_orders o JOIN Payment_report p ON p.id_order=o.payment_id
        JOIN user u ON u.id=o.owner_id
        WHERE o.kind='renewal_card' AND o.status='waiting'
          AND p.payment_Status='paid' AND u.User_Status!='block'
          AND p.at_updated IS NOT NULL
        ORDER BY o.created_at LIMIT 8");
    $stmt->execute();
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $pending) {
        // Legacy confirmation updates payment_status before finishing wallet
        // credit. This extra delay prevents racing the original bot worker.
        if (time() - strtotime($pending['at_updated']) < 180) continue;
        $details=json_decode($pending['result_json']??'null',true);
        if (!is_array($details) || !isset($details['username'],$details['panel_code'],
            $details['plan_code'])) continue;
        $u=vpnappUser($pdo,(string)$pending['owner_id']);
        $panel=vpnappPanel($pdo,$details['panel_code']);
        $plan=vpnappProduct($pdo,$u['agent'],$panel,$details['plan_code']);
        $price=(int)round((int)$plan['price_product']*(100-(int)$u['pricediscount'])/100);
        if ($price!==(int)$pending['price']) {
            // Merchant changed prices after the receipt was submitted.
            // Keep funds in wallet and require manual reconciliation.
            vpnappUpdate($pdo,$pending['external_id'],'unknown',['result'=>[
                'id'=>$pending['external_id'],'status'=>'unknown',
                'needs_review'=>true,'reason'=>'price_changed']]);
            continue;
        }
        $claim=$pdo->prepare("UPDATE vpnapp_orders SET status='processing',updated_at=?
            WHERE external_id=? AND status='waiting'");
        $claim->execute([time(),$pending['external_id']]);
        if ($claim->rowCount()!==1) continue;
        try {
            vpnappRenewWallet($pdo,$u,$pending['external_id'],$details['username'],
                $details['panel_code'],$details['plan_code'],true);
        } catch (Throwable $e) {
            vpnappUpdate($pdo,$pending['external_id'],'unknown',['result'=>[
                'id'=>$pending['external_id'],'status'=>'unknown','needs_review'=>true]]);
            error_log('[vpnapp] Card renewal requires reconciliation');
        }
    }
} finally {
    $pdo->query("SELECT RELEASE_LOCK('mirza_vpnapp_card_renew_cron')");
}
