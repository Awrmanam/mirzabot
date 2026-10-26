<?php
/**
 * Optional, fail-closed Mirza -> Iran App enrollment.
 * The bot authenticates real Telegram senders; the phone never sends an ID.
 * Configure MIRZA_APP_API_URL and MIRZA_APP_BRIDGE_SECRET on the PHP host.
 */

function mirzaAppTryHandle($text, $chatType, $fromId, $pdo)
{
    if (!is_string($text) || !preg_match('/^\/app(?:@[a-zA-Z0-9_]+)?$/i', trim($text))) {
        return false;
    }
    if ($chatType !== 'private') {
        // Do not expose linking codes to Telegram groups.
        return true;
    }
    $api = rtrim(trim((string) getenv('MIRZA_APP_API_URL')), '/');
    $secret = (string) getenv('MIRZA_APP_BRIDGE_SECRET');
    if (!preg_match('#^https://[a-z0-9.-]+(?::443)?$#i', $api) || strlen($secret) < 32) {
        sendmessage($fromId, '⏳ اتصال اپ هنوز از سمت مدیریت فعال نشده است.', null, 'html');
        return true;
    }

    try {
        // Exactly the same order-owner column used by Mirza's /services list.
        $stmt = $pdo->prepare(
            "SELECT username, Service_location FROM invoice WHERE id_user = ? " .
            "AND LOWER(COALESCE(Status,'')) IN " .
            "('active','end_of_time','end_of_volume','sendedwarn','send_on_hold') " .
            "ORDER BY time_sell DESC LIMIT 50"
        );
        $stmt->execute([(string) $fromId]);
        $services = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = trim((string) ($row['username'] ?? ''));
            $panelCode = trim((string) ($row['Service_location'] ?? ''));
            if ($name === '' || $panelCode === '' || strlen($name) > 128 || strlen($panelCode) > 128) {
                continue;
            }
            $services[] = ['username' => $name, 'panel_code' => $panelCode];
        }

        $body = json_encode(['telegram_id' => (string) $fromId, 'services' => $services],
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $signature = hash_hmac('sha256', $timestamp . "\n" . $nonce . "\n" . $body, $secret);
        $curl = curl_init($api . '/v1/bridge/mirza/pair-code');
        if ($curl === false) {
            throw new RuntimeException('Unable to start connection');
        }
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-Mirza-Timestamp: ' . $timestamp,
                'X-Mirza-Nonce: ' . $nonce,
                'X-Mirza-Signature: ' . $signature,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if (!is_string($response) || $status !== 200) {
            if ($status === 400) {
                sendmessage($fromId,
                    '⚠️ اگر قبلاً حساب Mirza را به اپ متصل کردی، با نام کاربری و رمز خودت وارد شو. '
                    . 'برای دریافت کد مجدد هم دست‌کم یک دقیقه فاصله بده.', null, 'html');
                return true;
            }
            throw new RuntimeException('Bridge service unavailable: HTTP ' . $status);
        }
        $reply = json_decode($response, true, 8, JSON_THROW_ON_ERROR);
        $code = (string) ($reply['code'] ?? '');
        if (!preg_match('/^[A-F0-9]{6}(?:-[A-F0-9]{6}){3}$/', $code)) {
            throw new RuntimeException('Bridge returned an invalid code');
        }
        sendmessage($fromId, "🔐 <b>کد اتصال Mirza به اپ</b>\n\n<code>{$code}</code>\n\n"
            . "این کد فقط ۱۰ دقیقه اعتبار دارد و یک‌بار مصرف است.\n"
            . "آن را در بخش «اتصال به Mirza» داخل اپ وارد کن و نام کاربری و رمز مستقلی بساز.\n\n"
            . "⚠️ کد را به هیچ شخص دیگری نده.", null, 'html');
    } catch (Throwable $exc) {
        // Never log signed payloads, enrollment codes or shared secrets.
        error_log('[Mirza App Bridge] Pairing request failed: ' . get_class($exc));
        sendmessage($fromId, '❌ ارتباط با سرور اپ برقرار نشد. کمی بعد دوباره تلاش کن.', null, 'html');
    }
    return true;
}
