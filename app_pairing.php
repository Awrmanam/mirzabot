<?php
/**
 * Optional /app bridge to the separate VPN client. This module is NOT part of
 * Telegram's Mirza Mini App: it never changes api/miniapp.php, bot menus, or
 * the existing payment/fulfilment workflow.
 *
 * Disabled by default. Server environment:
 *   MIRZA_APP_PAIRING_ENABLED=1
 *   MIRZA_APP_BRIDGE_SECRET=<same 32+ byte random secret as Iran API MIRZA_BRIDGE_SECRET>
 *   MIRZA_APP_BRIDGE_URL=https://me2.26arman.ir/v1/bridge/mirza/pair-code (optional)
 *
 * The only identity accepted is the verified Telegram webhook sender from
 * index.php; never take a Telegram ID from a message or HTTP query.
 */

function mirzaAppPairingEnabled()
{
    return getenv('MIRZA_APP_PAIRING_ENABLED') === '1'
        && strlen((string)getenv('MIRZA_APP_BRIDGE_SECRET')) >= 32;
}

function mirzaAppPairingServicesForUser(PDO $pdo, $telegramId)
{
    // Ownership originates in Mirza's own invoice DB, not user input.
    // Panel code is matched against the Iran server's allowlist there.
    $statement = $pdo->prepare(
        "SELECT DISTINCT i.username, p.code_panel AS panel_code
         FROM invoice AS i
         INNER JOIN marzban_panel AS p ON p.name_panel = i.Service_location
         WHERE i.id_user = :owner
           AND i.Status IN ('active','end_of_time','end_of_volume','sendedwarn','send_on_hold')
           AND i.username IS NOT NULL AND i.username != ''
         ORDER BY i.username LIMIT 50"
    );
    $statement->execute([':owner' => (string)$telegramId]);
    $services = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $record) {
        $services[] = [
            'username' => (string)$record['username'],
            'panel_code' => (string)$record['panel_code'],
        ];
    }
    return $services;
}

function mirzaAppPairingSignedRequest($telegramId, array $services, $secret, $now = null, $nonce = null)
{
    $body = json_encode([
        'telegram_id' => (string)$telegramId,
        'services' => $services,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($body === false) {
        throw new RuntimeException('Cannot encode pairing request');
    }
    $timestamp = (string)($now === null ? time() : $now);
    $randomNonce = $nonce === null ? bin2hex(random_bytes(16)) : $nonce;
    $signature = hash_hmac('sha256', $timestamp . "\n" . $randomNonce . "\n" . $body, $secret);
    return [
        'body' => $body,
        'headers' => [
            'Content-Type: application/json',
            'X-Mirza-Timestamp: ' . $timestamp,
            'X-Mirza-Nonce: ' . $randomNonce,
            'X-Mirza-Signature: ' . $signature,
        ],
    ];
}

function mirzaAppPairingSendToIran($telegramId, array $services)
{
    $secret = (string)getenv('MIRZA_APP_BRIDGE_SECRET');
    $url = trim((string)getenv('MIRZA_APP_BRIDGE_URL'));
    if ($url === '') {
        $url = 'https://me2.26arman.ir/v1/bridge/mirza/pair-code';
    }
    $parts = parse_url($url);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
        || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment'])) {
        throw new RuntimeException('Invalid bridge URL');
    }
    $request = mirzaAppPairingSignedRequest($telegramId, $services, $secret);
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Unable to contact app server');
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $request['body'],
        CURLOPT_HTTPHEADER => $request['headers'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_MAXREDIRS => 0,
    ]);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($response) || $status !== 200 || strlen($response) > 1000) {
        // Avoid logging codes, secret material, customer identity or API body.
        throw new RuntimeException('Pairing server unavailable or rejected request');
    }
    $result = json_decode($response, true);
    if (!is_array($result) || !is_string($result['code'] ?? null)
        || !preg_match('/^[A-F0-9]{6}(?:-[A-F0-9]{6}){3}$/D', $result['code'])) {
        throw new RuntimeException('Invalid pairing response');
    }
    return $result['code'];
}

function mirzaAppPairingCommand(PDO $pdo, $telegramId)
{
    if (!mirzaAppPairingEnabled()) {
        sendmessage($telegramId, 'اتصال حساب به اپ هنوز فعال نشده است. لطفاً بعداً امتحان کنید.');
        return;
    }
    try {
        $services = mirzaAppPairingServicesForUser($pdo, $telegramId);
        $code = mirzaAppPairingSendToIran($telegramId, $services);
        sendmessage(
            $telegramId,
            "🔗 <b>اتصال حساب میرزا به اپ</b>\n\n"
            . "کد یک‌بارمصرف شما:\n<code>" . $code . "</code>\n\n"
            . "این کد را داخل اپ وارد کنید. اعتبار: ۱۰ دقیقه. "
            . "کد را برای دیگران نفرستید؛ با واردکردن آن، دسترسی به حساب شما ممکن می‌شود.",
            null,
            'HTML'
        );
    } catch (Throwable $e) {
        // No authentication tokens, codes or sensitive response are logged.
        sendmessage(
            $telegramId,
            'در حال حاضر دریافت کد اتصال ممکن نیست یا درخواست جدید خیلی زود ثبت شده است. لطفاً کمی بعد دوباره امتحان کنید.'
        );
    }
}
