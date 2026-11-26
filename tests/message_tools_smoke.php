<?php
/**
 * Isolated, no-network smoke tests for Mirza message tools.
 * php8.4 tests/message_tools_smoke.php
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$calls = [];
function telegram($method, $payload = [])
{
    global $calls;
    $calls[] = ['method' => $method, 'payload' => $payload];
    return ['ok' => true, 'result' => ['message_id' => 1]];
}
require_once dirname(__DIR__) . '/mirza_message_tools.php';

function check($condition, $description)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$description}\n");
        exit(1);
    }
    echo "PASS: {$description}\n";
}

check(mmtValidDestination('@ValidChannel_1'), 'public channel destination');
check(mmtValidDestination('-1001234567890'), 'private group destination');
check(!mmtValidDestination('https://t.me/channel'), 'reject invalid destination');
check(!mmtValidDestination('123456789'), 'reject positive user destination');
check(mmtValidUrl('https://t.me/MyBot?start=buy'), 'allow HTTPS deep link');
check(!mmtValidUrl('javascript:alert(1)'), 'reject unsafe URI scheme');
check(!mmtValidUrl('http://example.org'), 'reject insecure HTTP URL');

$result = mmtSendKeyboardRemoval('123456789');
check(!empty($result['ok']), 'removal request accepted');
check(count($calls) === 2, 'removal followed by inline menu');
check($calls[0]['method'] === 'sendMessage', 'first call sends a message');
$markup = json_decode($calls[0]['payload']['reply_markup'], true);
check(($markup['remove_keyboard'] ?? false) === true, 'old reply keyboard is removed');
$menu = json_decode($calls[1]['payload']['reply_markup'], true);
$buttons = $menu['inline_keyboard'][0] ?? [];
check(($buttons[0]['callback_data'] ?? '') === 'buy', 'buy callback preserved');
check(($buttons[1]['callback_data'] ?? '') === 'account', 'wallet callback preserved');

// Additional no-network checks for channel premium-preserving inline buttons.
$public = mmpParseChannelLink('https://t.me/TestChannel_123/42');
check($public !== null && $public['chat_id'] === '@TestChannel_123'
    && $public['message_id'] === 42, 'parse public channel post link');
$private = mmpParseChannelLink('https://t.me/c/123456789/91');
check($private !== null && $private['chat_id'] === '-100123456789'
    && $private['message_id'] === 91, 'parse private channel post link');
check(mmpParseChannelLink('https://t.me/+invitehash') === null, 'reject invite links');
check(mmpParseChannelLink('https://evil.com/t.me/c/123456789/91') === null,
    'reject forged Telegram links');
check(mmpParseChannelLink('https://t.me/TestChannel_123') === null,
    'reject links without a message ID');

echo "All smoke tests passed. No Telegram requests or database writes were made.\n";
