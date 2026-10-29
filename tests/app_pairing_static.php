<?php
require_once __DIR__ . '/../app_pairing.php';
function assertPair($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "Pairing test failed: " . $message . "\n");
        exit(1);
    }
}
putenv('MIRZA_APP_PAIRING_ENABLED=0');
putenv('MIRZA_APP_BRIDGE_SECRET=' . str_repeat('S', 40));
assertPair(mirzaAppPairingEnabled() === false, 'feature must be disabled by default');
putenv('MIRZA_APP_PAIRING_ENABLED=1');
assertPair(mirzaAppPairingEnabled() === true, 'feature toggle should enable adapter');
$services = [['username' => 'my_service', 'panel_code' => 'test_panel']];
$request = mirzaAppPairingSignedRequest('12345678', $services, str_repeat('S', 40),
    1760000000, str_repeat('a', 32));
$headers = implode("\n", $request['headers']);
$expected = hash_hmac('sha256',
    '1760000000' . "\n" . str_repeat('a', 32) . "\n" . $request['body'],
    str_repeat('S', 40));
assertPair(strpos($headers, 'X-Mirza-Signature: ' . $expected) !== false,
    'signature must match the Iran bridge contract');
assertPair(json_decode($request['body'], true)['telegram_id'] === '12345678',
    'actual webhook account ID must be passed');
assertPair(strpos($request['body'], str_repeat('S', 40)) === false,
    'secret must never be in the request body');
assertPair(strpos(file_get_contents(__DIR__ . '/../index.php'),
    "require_once 'app_pairing.php'") !== false, '/app hook is missing');
echo "Mirza /app signature and isolated hook checks passed\n";
