<?php
// Static invariants: this file is checked without a database or bot token.
function check($condition, $message) {
    if (!$condition) { fwrite(STDERR, "FAIL: ".$message."\n"); exit(1); }
}
$gateway = file_get_contents(__DIR__.'/../api/vpnapp.php');
$pairing = file_get_contents(__DIR__.'/../app_pairing.php');
$miniapp = file_get_contents(__DIR__.'/../api/miniapp.php');
check(strpos($gateway,'MIRZA_VPNAPP_BRIDGE_ENABLED')!==false, 'bridge flag missing');
check(strpos($gateway,'MIRZA_VPNAPP_CHECKOUT_ENABLED')!==false, 'checkout flag missing');
check(strpos($gateway,'hash_equals')!==false, 'HMAC constant-time comparison missing');
check(strpos($gateway,'vpnapp_rpc_nonces')!==false, 'replay protection missing');
check(strpos($gateway,'vpnapp_orders')!==false, 'idempotency missing');
check(strpos($gateway,'getconfigafterpay|')!==false, 'legacy paid purchase pipeline missing');
check(strpos($gateway,"'cart to cart'")!==false, 'legacy receipt channel missing');
check(strpos($gateway,"'waiting'")!==false, 'legacy auto-confirm pickup missing');
check(strpos($gateway,'SELECT id_admin FROM admin')!==false, 'authorized receipt admin fallback missing');
check(strpos($gateway,'notification_delivered')!==false, 'receipt delivery status missing');
check(strpos($gateway,"'User_Status'")!==false, 'blocked user handling missing');
check(strpos($gateway,'auth/')===false, 'unexpected public auth route');
check(strpos($gateway,'botapi.php')===false, 'must not load Telegram webhook parser');
check(strpos($pairing,'MIRZA_APP_PAIRING_ENABLED')!==false, 'pairing disabled by default');
check(strpos($miniapp,"case 'purchase':")!==false, 'Telegram Mini App entry intact');
echo "VPN app store static safety checks passed\n";
