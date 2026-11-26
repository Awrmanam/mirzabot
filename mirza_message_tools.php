<?php
/**
 * Mirza message tools: publish NEW channel/group messages with URL buttons,
 * and remove persistent reply keyboards left by a previous bot implementation.
 *
 * No edits to existing messages, purchases, payments, panels or user state.
 * Requires mirza_message_tools_install.php to be run once on deployment.
 */

function mmtReady()
{
    global $pdo;
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'mirza_msg_sessions'");
        $ready = (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

function mmtSetting($name, $fallback = '')
{
    global $pdo;
    if (!mmtReady()) return $fallback;
    $stmt = $pdo->prepare("SELECT value FROM mirza_msg_settings WHERE name = ?");
    $stmt->execute([$name]);
    $value = $stmt->fetchColumn();
    return $value === false ? $fallback : (string) $value;
}

function mmtSetSetting($name, $value)
{
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO mirza_msg_settings (name, value) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE value = VALUES(value)");
    $stmt->execute([$name, (string) $value]);
}

function mmtKeyboard(array $rows)
{
    return json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function mmtButton($title, $action)
{
    return ['text' => $title, 'callback_data' => $action];
}

function mmtReply($chatId, $text, array $rows = [])
{
    $payload = ['chat_id' => $chatId, 'text' => $text];
    if ($rows) $payload['reply_markup'] = mmtKeyboard($rows);
    return telegram('sendMessage', $payload);
}

function mmtHome($chatId)
{
    return mmtReply($chatId,
        "🧰 ابزار پیام‌های میرزا\n\nپست جدید را همراه یک دکمه لینک‌دار منتشر کن یا کیبورد باقی‌مانده از ربات قبلی را پاکسازی کن.",
        [
            [mmtButton('📢 انتشار پست جدید با دکمه', 'mmt_post_new')],
            [mmtButton('🧹 پاکسازی کیبورد قدیمی', 'mmt_cleanup_panel')],
            [mmtButton('⬅️ مدیریت اصلی', 'admin')]
        ]
    );
}

function mmtSession($adminId)
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT stage, payload FROM mirza_msg_sessions WHERE admin_id = ?");
    $stmt->execute([(string) $adminId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $data = json_decode((string) $row['payload'], true);
    return ['stage' => (string) $row['stage'], 'data' => is_array($data) ? $data : []];
}

function mmtSaveSession($adminId, $stage, array $data = [])
{
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO mirza_msg_sessions (admin_id, stage, payload)
        VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE stage = VALUES(stage),
        payload = VALUES(payload), updated_at = NOW()");
    $stmt->execute([(string) $adminId, $stage, json_encode($data, JSON_UNESCAPED_UNICODE)]);
}

function mmtDeleteSession($adminId)
{
    global $pdo;
    $stmt = $pdo->prepare("DELETE FROM mirza_msg_sessions WHERE admin_id = ?");
    $stmt->execute([(string) $adminId]);
}

function mmtCleanupButtons($isAdmin = false)
{
    if ($isAdmin) return [[mmtButton('🛠 پنل مدیریت', 'admin')]];
    return [
        [mmtButton('🛒 خرید سرویس', 'buy'), mmtButton('👛 کیف پول', 'account')]
    ];
}

function mmtSendKeyboardRemoval($userId, $isAdmin = false)
{
    $removed = telegram('sendMessage', [
        'chat_id' => $userId,
        'text' => "✅ منوی قدیمی این گفتگو پاکسازی شد.",
        'reply_markup' => json_encode(['remove_keyboard' => true], JSON_UNESCAPED_UNICODE)
    ]);
    if (empty($removed['ok'])) return $removed;
    $menu = mmtReply($userId, 'از دکمه‌های جدید زیر استفاده کن:', mmtCleanupButtons($isAdmin));
    if (empty($menu['ok'])) {
        error_log('[mirza_message_tools] Cleanup succeeded, inline menu failed for user ' . $userId .
            ': ' . ($menu['description'] ?? 'unknown'));
    }
    return $removed;
}

/** Claim a single user once. Staff and contact-sharing flows are excluded. */
function mmtCleanupOnInteraction($userId, array $user, array $setting)
{
    global $pdo;
    if (!mmtReady() || ($setting['inlinebtnmain'] ?? '') !== 'oninline') return;
    if (($user['step'] ?? '') === 'get_number') return;
    if (($setting['get_number'] ?? '') === 'onAuthenticationphone' &&
        ($user['number'] ?? '') === 'none') return;

    try {
        $stmt = $pdo->prepare("INSERT IGNORE INTO mirza_msg_keyboard_cleanup
            (user_id, status) VALUES (?, 'pending')");
        $stmt->execute([(string) $userId]);

        $stmt = $pdo->prepare("UPDATE mirza_msg_keyboard_cleanup SET status = 'sending',
            attempts = attempts + 1 WHERE user_id = ? AND status = 'pending'");
        $stmt->execute([(string) $userId]);
        if ($stmt->rowCount() !== 1) return;

        $result = mmtSendKeyboardRemoval($userId);
        $status = !empty($result['ok']) ? 'done' : 'failed';
        $error = substr((string) ($result['description'] ?? ''), 0, 255);
        $stmt = $pdo->prepare("UPDATE mirza_msg_keyboard_cleanup
            SET status = ?, last_error = ? WHERE user_id = ?");
        $stmt->execute([$status, $error, (string) $userId]);
    } catch (Throwable $e) {
        error_log('[mirza_message_tools] Interactive cleanup error: ' . $e->getMessage());
    }
}

/** Safe, slow, opt-in CLI batch; run via flock once per minute. */
function mmtBulkRunOnce($limit = 12)
{
    global $pdo;
    if (!mmtReady() || mmtSetting('cleanup_running') !== '1') return ['state' => 'paused'];
    $setting = $pdo->query("SELECT inlinebtnmain FROM setting LIMIT 1")->fetchColumn();
    if ($setting !== 'oninline') return ['state' => 'inline_menu_disabled'];

    $limit = max(1, min(15, (int) $limit));
    // Recover interrupted claims; a previous request may have been sent before a crash.
    $pdo->exec("UPDATE mirza_msg_keyboard_cleanup SET status = 'pending'
        WHERE status = 'sending' AND updated_at < NOW() - INTERVAL 20 MINUTE");

    $stmt = $pdo->query("SELECT user_id FROM mirza_msg_keyboard_cleanup
        WHERE status = 'pending' ORDER BY created_at, user_id LIMIT {$limit}");
    $candidates = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $sent = 0;
    $failed = 0;
    $adminStmt = $pdo->prepare("SELECT COUNT(*) FROM admin WHERE id_admin = ?");
    $claim = $pdo->prepare("UPDATE mirza_msg_keyboard_cleanup SET status = 'sending',
        attempts = attempts + 1 WHERE user_id = ? AND status = 'pending'");
    $finish = $pdo->prepare("UPDATE mirza_msg_keyboard_cleanup SET status = ?,
        last_error = ? WHERE user_id = ?");

    foreach ($candidates as $userId) {
        $claim->execute([(string) $userId]);
        if ($claim->rowCount() !== 1) continue;

        $adminStmt->execute([(string) $userId]);
        if ((int) $adminStmt->fetchColumn() > 0) {
            $finish->execute(['skipped', 'admin', (string) $userId]);
            continue;
        }

        $result = mmtSendKeyboardRemoval($userId);
        if (!empty($result['ok'])) {
            $finish->execute(['done', '', (string) $userId]);
            $sent++;
        } elseif ((int) ($result['error_code'] ?? 0) === 429) {
            $finish->execute(['pending', 'Telegram rate limit', (string) $userId]);
            break;
        } else {
            $finish->execute(['failed', substr((string) ($result['description'] ?? 'unknown'), 0, 255), (string) $userId]);
            $failed++;
        }
    }

    $pending = (int) $pdo->query("SELECT COUNT(*) FROM mirza_msg_keyboard_cleanup
        WHERE status = 'pending' OR status = 'sending'")->fetchColumn();
    if ($pending === 0) mmtSetSetting('cleanup_running', '0');
    return ['state' => $pending ? 'running' : 'completed', 'sent' => $sent,
        'failed' => $failed, 'remaining' => $pending];
}

function mmtCleanupPanel($chatId)
{
    global $pdo;
    $stmt = $pdo->query("SELECT status, COUNT(*) AS total
        FROM mirza_msg_keyboard_cleanup GROUP BY status");
    $counts = ['pending' => 0, 'sending' => 0, 'done' => 0,
        'failed' => 0, 'skipped' => 0];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $counts[$row['status']] = (int) $row['total'];
    }
    $running = mmtSetting('cleanup_running') === '1' ? 'فعال' : 'متوقف';
    return mmtReply($chatId, "🧹 پاکسازی کیبورد قدیمی\n\n" .
        "وضعیت: {$running}\nپاکسازی‌شده: {$counts['done']}\n" .
        "در صف: {$counts['pending']}\nدر حال پردازش: {$counts['sending']}\n" .
        "ناموفق: {$counts['failed']}\n\n" .
        "ادمین‌ها از پاکسازی گروهی مستثنا هستند. بدون تأیید شما پیامی به کل کاربران ارسال نمی‌شود.",
        [
            [mmtButton('🧪 تست روی حساب خودم', 'mmt_cleanup_self')],
            [mmtButton('▶️ شروع پاکسازی گروهی', 'mmt_cleanup_confirm')],
            [mmtButton('⏸ توقف ارسال گروهی', 'mmt_cleanup_pause')],
            [mmtButton('🔄 تلاش دوباره برای ناموفق‌ها', 'mmt_cleanup_retry')],
            [mmtButton('⬅️ ابزار پیام‌ها', 'mmt_home')]
        ]);
}

function mmtValidDestination($value)
{
    $value = trim((string) $value);
    return preg_match('/^@[A-Za-z0-9_]{5,32}$/D', $value) === 1 ||
        preg_match('/^-[1-9][0-9]{4,19}$/D', $value) === 1;
}

function mmtValidUrl($value)
{
    return strlen($value) <= 2048 && preg_match('~^https://\S+$~iD', $value) === 1 &&
        filter_var($value, FILTER_VALIDATE_URL) !== false;
}

function mmtPostCancelRows()
{
    return [[mmtButton('❌ لغو و بازگشت', 'mmt_cancel')]];
}

function mmtPostPreview($adminId, array $data)
{
    if (empty($data['source']) || empty($data['destination']) ||
        empty($data['button_title']) || empty($data['button_url'])) {
        return mmtReply($adminId, '❌ اطلاعات پست ناقص است. دوباره شروع کن.',
            [[mmtButton('🔄 شروع مجدد', 'mmt_post_new')]]);
    }
    $keyboard = ['inline_keyboard' => [[
        ['text' => $data['button_title'], 'url' => $data['button_url']]
    ]]];
    $result = telegram('copyMessage', [
        'chat_id' => $adminId,
        'from_chat_id' => $adminId,
        'message_id' => (int) $data['source'],
        'reply_markup' => json_encode($keyboard, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    ]);
    if (empty($result['ok'])) {
        mmtReply($adminId, '❌ پیش‌نمایش ساخته نشد: ' .
            (string) ($result['description'] ?? 'خطای نامشخص'),
            [[mmtButton('🔄 شروع مجدد', 'mmt_post_new')]]);
        return false;
    }
    mmtSaveSession($adminId, 'post_confirm', $data);
    mmtReply($adminId,
        "👆 پیش‌نمایش پست\n\nمقصد: {$data['destination']}\nدکمه: {$data['button_title']}\n" .
        "لینک: {$data['button_url']}\n\nفقط پس از تأیید، پیام در مقصد منتشر می‌شود.",
        [[mmtButton('✅ انتشار در مقصد', 'mmt_post_publish')],
         [mmtButton('❌ لغو', 'mmt_cancel')]]);
    return true;
}

function mmtHandleAdminUpdate($adminId, $text, $callback, $update)
{
    global $pdo, $adminrulecheck;
    if (!mmtReady() || ($adminrulecheck['rule'] ?? '') !== 'administrator') return false;
    $text = is_string($text) ? trim($text) : '';
    $callback = is_string($callback) ? $callback : '';
    $isToolsCommand = strncmp($callback, 'mmt_', 4) === 0;
    $isTextTrigger = in_array($text, [
        '📢 انتشار پست با دکمه', '🧹 پاکسازی کیبورد قدیمی', '/posttools'
    ], true);
    $session = mmtSession($adminId);
    if (!$isToolsCommand && !$isTextTrigger && !$session) return false;
    if (!$isToolsCommand && !$isTextTrigger && in_array($text, [
        '/start', '/panel', 'panel'
    ], true)) {
        mmtDeleteSession($adminId);
        return false;
    }
    if ($isToolsCommand && !empty($update['callback_query']['id'])) {
        telegram('answerCallbackQuery', ['callback_query_id' => $update['callback_query']['id']]);
    }

    try {
        if ($callback === 'mmt_cancel' || $callback === 'mmt_home' || $text === '/posttools') {
            mmtDeleteSession($adminId);
            mmtHome($adminId);
            return true;
        }
        if ($text === '🧹 پاکسازی کیبورد قدیمی' || $callback === 'mmt_cleanup_panel') {
            mmtDeleteSession($adminId);
            mmtCleanupPanel($adminId);
            return true;
        }
        if ($callback === 'mmt_cleanup_self') {
            $result = mmtSendKeyboardRemoval($adminId, true);
            if (empty($result['ok'])) {
                mmtReply($adminId, '❌ پاکسازی آزمایشی ناموفق: ' .
                    (string) ($result['description'] ?? 'unknown'));
            }
            mmtCleanupPanel($adminId);
            return true;
        }
        if ($callback === 'mmt_cleanup_confirm') {
            mmtSaveSession($adminId, 'cleanup_confirm');
            $count = (int) $pdo->query("SELECT COUNT(*) FROM user u
                LEFT JOIN admin a ON a.id_admin = u.id
                WHERE a.id_admin IS NULL AND u.id REGEXP '^[0-9]+$'")->fetchColumn();
            mmtReply($adminId,
                "⚠️ تأیید پاکسازی گروهی\n\nتا {$count} حساب مشتری در پایگاه داده وجود دارد. " .
                "برای افرادی که قبلاً پاکسازی شده‌اند پیام تکراری ارسال نمی‌شود. " .
                "پس از تأیید، ارسال تدریجی از طریق Cron شروع می‌شود.",
                [[mmtButton('✅ تأیید و شروع', 'mmt_cleanup_start')],
                 [mmtButton('❌ انصراف', 'mmt_cleanup_panel')]]);
            return true;
        }
        if ($callback === 'mmt_cleanup_start') {
            $confirm = mmtSession($adminId);
            if (!$confirm || $confirm['stage'] !== 'cleanup_confirm') {
                mmtReply($adminId, '⚠️ برای شروع ارسال گروهی، ابتدا تأیید جدید بگیر.',
                    [[mmtButton('🧹 پاکسازی', 'mmt_cleanup_panel')]]);
                return true;
            }
            mmtDeleteSession($adminId);
            $pdo->exec("INSERT IGNORE INTO mirza_msg_keyboard_cleanup (user_id, status)
                SELECT CAST(u.id AS CHAR), 'pending' FROM user u
                LEFT JOIN admin a ON a.id_admin = u.id
                WHERE a.id_admin IS NULL AND u.id REGEXP '^[0-9]+$'");
            mmtSetSetting('cleanup_running', '1');
            mmtReply($adminId, '✅ صف پاکسازی ساخته شد. ارسال مرحله‌ای با Cron فعال می‌شود.');
            mmtCleanupPanel($adminId);
            return true;
        }
        if ($callback === 'mmt_cleanup_pause') {
            mmtSetSetting('cleanup_running', '0');
            mmtCleanupPanel($adminId);
            return true;
        }
        if ($callback === 'mmt_cleanup_retry') {
            $pdo->exec("UPDATE mirza_msg_keyboard_cleanup SET status = 'pending'
                WHERE status = 'failed'");
            mmtSetSetting('cleanup_running', '1');
            mmtReply($adminId, '🔄 ارسال مجدد برای حساب‌های ناموفق در صف قرار گرفت.');
            mmtCleanupPanel($adminId);
            return true;
        }
        if ($text === '📢 انتشار پست با دکمه' || $callback === 'mmt_post_new') {
            mmtSaveSession($adminId, 'post_source');
            mmtReply($adminId,
                "📢 انتشار پست جدید\n\nپیامی که می‌خواهی منتشر شود را همین‌جا بفرست یا فوروارد کن.\n" .
                "متن، عکس، ویدئو، فایل، صوت و انیمیشن قابل پشتیبانی هستند.\n" .
                "برای آلبوم چندعکسی، هر پیام به‌صورت جداگانه قابل انتشار است.",
                mmtPostCancelRows());
            return true;
        }

        $session = mmtSession($adminId);
        if (!$session) return $isToolsCommand;
        $stage = $session['stage'];
        $data = $session['data'];

        if ($stage === 'post_source') {
            $message = $update['message'] ?? null;
            if (!is_array($message) || empty($message['message_id'])) {
                mmtReply($adminId, '❌ یک پیام، تصویر یا فایل جدید برای پست بفرست.', mmtPostCancelRows());
                return true;
            }
            $supported = false;
            foreach (['text', 'photo', 'video', 'animation', 'document', 'audio', 'voice'] as $kind) {
                if (isset($message[$kind])) { $supported = true; break; }
            }
            if (!$supported) {
                mmtReply($adminId, '❌ این نوع پیام پشتیبانی نمی‌شود. متن یا رسانه معمولی ارسال کن.',
                    mmtPostCancelRows());
                return true;
            }
            $data['source'] = (int) $message['message_id'];
            mmtSaveSession($adminId, 'post_destination', $data);
            mmtReply($adminId,
                "✅ محتوای پست دریافت شد.\n\nشناسه کانال یا گروه مقصد را بفرست:\n" .
                "@YourChannel یا -1001234567890\n\nربات باید در مقصد اجازه انتشار داشته باشد.",
                mmtPostCancelRows());
            return true;
        }
        if ($stage === 'post_destination') {
            if (!mmtValidDestination($text)) {
                mmtReply($adminId, '❌ مقصد نامعتبر است. @username یا شناسه عددی منفی گروه/کانال را بفرست.',
                    mmtPostCancelRows());
                return true;
            }
            $data['destination'] = $text;
            mmtSaveSession($adminId, 'post_title', $data);
            mmtReply($adminId, 'عنوان دکمه را ارسال کن؛ مثلاً 🛒 ثبت سفارش.',
                mmtPostCancelRows());
            return true;
        }
        if ($stage === 'post_title') {
            $length = preg_match_all('/./us', $text);
            if ($text === '' || $length === false || $length > 64 || strpos($text, "\n") !== false) {
                mmtReply($adminId, '❌ عنوان دکمه باید یک خط و حداکثر ۶۴ کاراکتر باشد.',
                    mmtPostCancelRows());
                return true;
            }
            $data['button_title'] = $text;
            mmtSaveSession($adminId, 'post_link_type', $data);
            mmtReply($adminId, 'مقصد دکمه را انتخاب کن:',
                [[mmtButton('🛒 ورود به خرید میرزا', 'mmt_link_buy')],
                 [mmtButton('🔗 لینک دلخواه', 'mmt_link_custom')],
                 [mmtButton('❌ لغو', 'mmt_cancel')]]);
            return true;
        }
        if ($stage === 'post_link_type') {
            if ($callback === 'mmt_link_buy') {
                $me = telegram('getMe');
                $username = (string) ($me['result']['username'] ?? '');
                if (empty($me['ok']) || !preg_match('/^[A-Za-z0-9_]{5,32}$/D', $username)) {
                    mmtReply($adminId, '❌ نام کاربری ربات قابل دریافت نیست. دوباره تلاش کن.',
                        [[mmtButton('🔄 تلاش دوباره', 'mmt_link_buy')],
                         [mmtButton('❌ لغو', 'mmt_cancel')]]);
                    return true;
                }
                $data['button_url'] = 'https://t.me/' . $username . '?start=buy';
                mmtPostPreview($adminId, $data);
                return true;
            }
            if ($callback === 'mmt_link_custom') {
                mmtSaveSession($adminId, 'post_url', $data);
                mmtReply($adminId, '🔗 لینک HTTPS مقصد دکمه را ارسال کن (مثلاً https://t.me/YourBot).',
                    mmtPostCancelRows());
                return true;
            }
            mmtReply($adminId, 'یکی از دو گزینه مقصد دکمه را انتخاب کن.',
                [[mmtButton('🛒 خرید میرزا', 'mmt_link_buy')],
                 [mmtButton('🔗 لینک دلخواه', 'mmt_link_custom')],
                 [mmtButton('❌ لغو', 'mmt_cancel')]]);
            return true;
        }
        if ($stage === 'post_url') {
            if (!mmtValidUrl($text)) {
                mmtReply($adminId, '❌ لینک باید معتبر و با https:// شروع شود.',
                    mmtPostCancelRows());
                return true;
            }
            $data['button_url'] = $text;
            mmtPostPreview($adminId, $data);
            return true;
        }
        if ($stage === 'post_confirm') {
            if ($callback !== 'mmt_post_publish') {
                mmtReply($adminId, 'انتشار را تأیید کن یا لغو کن.',
                    [[mmtButton('✅ انتشار', 'mmt_post_publish')],
                     [mmtButton('❌ لغو', 'mmt_cancel')]]);
                return true;
            }
            $claim = $pdo->prepare("UPDATE mirza_msg_sessions SET stage = 'post_publishing'
                WHERE admin_id = ? AND stage = 'post_confirm'");
            $claim->execute([(string) $adminId]);
            if ($claim->rowCount() !== 1) return true; // Prevent double-post on double taps.

            $markup = json_encode(['inline_keyboard' => [[
                ['text' => $data['button_title'], 'url' => $data['button_url']]
            ]]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $result = telegram('copyMessage', [
                'chat_id' => $data['destination'],
                'from_chat_id' => $adminId,
                'message_id' => (int) $data['source'],
                'reply_markup' => $markup
            ]);
            if (empty($result['ok'])) {
                mmtSaveSession($adminId, 'post_confirm', $data);
                mmtReply($adminId, '❌ انتشار انجام نشد: ' .
                    (string) ($result['description'] ?? 'unknown') .
                    "\nمجوز ارسال ربات و شناسه مقصد را بررسی کن.",
                    [[mmtButton('🔄 تلاش مجدد', 'mmt_post_publish')],
                     [mmtButton('❌ لغو', 'mmt_cancel')]]);
                return true;
            }
            // Telegram has accepted the post. Never retry automatically after this point.
            mmtDeleteSession($adminId);
            try {
                $stmt = $pdo->prepare("INSERT INTO mirza_msg_publications
                    (admin_id, destination, message_id, source_message_id, button_title, button_url)
                    VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([(string) $adminId, $data['destination'],
                    (int) $result['result']['message_id'], (int) $data['source'],
                    $data['button_title'], $data['button_url']]);
            } catch (Throwable $e) {
                error_log('[mirza_message_tools] Publication log failed: ' . $e->getMessage());
            }
            mmtReply($adminId, '✅ پست همراه دکمه با موفقیت منتشر شد.',
                [[mmtButton('📢 انتشار پست بعدی', 'mmt_post_new')],
                 [mmtButton('⬅️ ابزار پیام‌ها', 'mmt_home')]]);
            return true;
        }
        if ($stage === 'post_publishing') {
            mmtReply($adminId, '⏳ درخواست انتشار در حال پردازش است. برای جلوگیری از پست تکراری دوباره نزن.',
                [[mmtButton('❌ لغو و شروع مجدد', 'mmt_cancel')]]);
            return true;
        }
        mmtDeleteSession($adminId);
        mmtHome($adminId);
        return true;
    } catch (Throwable $e) {
        error_log('[mirza_message_tools] Admin action error: ' . $e->getMessage());
        mmtReply($adminId, '❌ خطای داخلی ابزار پیام‌ها. جزئیات در لاگ سرور ثبت شد.',
            [[mmtButton('❌ لغو', 'mmt_cancel')]]);
        return true;
    }
}
