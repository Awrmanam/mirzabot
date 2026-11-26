<?php
/**
 * Preserve custom/Premium emoji by attaching an inline URL button directly to
 * an existing ORIGINAL channel post. The post is created by a human admin,
 * not re-sent or re-rendered by the bot.
 */
function mmpParseChannelLink($value)
{
    $url = trim((string) $value);
    if (preg_match('~^https://t\.me/c/([1-9][0-9]{4,18})/([1-9][0-9]{0,12})(?:\?single)?/?$~iD', $url, $match)) {
        return ['chat_id' => '-100' . $match[1], 'message_id' => (int) $match[2], 'url' => $url];
    }
    if (preg_match('~^https://t\.me/([A-Za-z0-9_]{5,32})/([1-9][0-9]{0,12})(?:\?single)?/?$~iD', $url, $match) &&
        !in_array(strtolower($match[1]), ['share', 'joinchat'], true)) {
        return ['chat_id' => '@' . $match[1], 'message_id' => (int) $match[2], 'url' => $url];
    }
    return null;
}

function mmpCanEditPost($chatId)
{
    $chat = telegram('getChat', ['chat_id' => $chatId]);
    if (empty($chat['ok']) || ($chat['result']['type'] ?? '') !== 'channel') {
        return 'کانال پیدا نشد یا ربات به آن دسترسی ندارد.';
    }
    $bot = telegram('getMe');
    $botId = $bot['result']['id'] ?? null;
    if (empty($bot['ok']) || !$botId) return 'شناسه ربات مشخص نشد.';
    $member = telegram('getChatMember', ['chat_id' => $chatId, 'user_id' => $botId]);
    if (empty($member['ok'])) return 'دسترسی ربات در کانال قابل بررسی نیست.';
    $role = (string) ($member['result']['status'] ?? '');
    if ($role === 'creator' || ($role === 'administrator' && !empty($member['result']['can_edit_messages']))) return null;
    return 'ربات باید ادمین کانال با مجوز Edit Messages باشد.';
}

function mmpCancelButtons()
{
    return [[mmtButton('❌ لغو و بازگشت', 'mmp_cancel')]];
}

function mmpPreview($adminId, array $data)
{
    foreach (['post_url', 'chat_id', 'message_id', 'button_title', 'button_url'] as $key) {
        if (empty($data[$key])) {
            mmtReply($adminId, '❌ اطلاعات پست ناقص است.', mmpCancelButtons());
            return;
        }
    }
    mmtSaveSession($adminId, 'mmp_confirm', $data);
    mmtReply($adminId,
        "✨ پیش‌نمایش افزودن دکمه\n\nمتن، رسانه، قالب‌بندی و ایموجی پریمیومِ پست اصلی دست‌نخورده می‌مانند.\n" .
        "عنوان دکمه: {$data['button_title']}\nلینک دکمه: {$data['button_url']}\n\n" .
        "با تأیید فقط دکمه زیر پست کانال افزوده می‌شود.",
        [
            [['text' => '🔎 مشاهده پست اصلی', 'url' => $data['post_url']]],
            [['text' => $data['button_title'], 'url' => $data['button_url']]],
            [mmtButton('✅ افزودن دکمه به پست', 'mmp_apply')],
            [mmtButton('❌ انصراف', 'mmp_cancel')]
        ]
    );
}

function mmpHandleAdminUpdate($adminId, $text, $callback, $update)
{
    global $pdo, $adminrulecheck;
    if (!mmtReady() || ($adminrulecheck['rule'] ?? '') !== 'administrator') return false;
    $text = trim((string) $text);
    $callback = (string) $callback;
    $isCommand = strncmp($callback, 'mmp_', 4) === 0 ||
        $text === '/premiumbutton' || $text === '✨ دکمه روی پست پریمیوم کانال';
    $session = mmtSession($adminId);
    $stage = $session['stage'] ?? '';
    $active = strncmp($stage, 'mmp_', 4) === 0;
    if (!$isCommand && !$active) return false;

    // Allow moving to other Mirza menus instead of consuming their messages.
    if (!$isCommand && ($text === '/panel' || $text === 'panel' || $text === '/start' ||
        $text === '/posttools' || strncmp($callback, 'mmt_', 4) === 0)) {
        mmtDeleteSession($adminId);
        return false;
    }
    if (!empty($update['callback_query']['id']) && strncmp($callback, 'mmp_', 4) === 0) {
        telegram('answerCallbackQuery', ['callback_query_id' => $update['callback_query']['id']]);
    }

    try {
        if ($callback === 'mmp_cancel') {
            mmtDeleteSession($adminId);
            mmtHome($adminId);
            return true;
        }
        if ($callback === 'mmp_start' || $text === '/premiumbutton' ||
            $text === '✨ دکمه روی پست پریمیوم کانال') {
            mmtSaveSession($adminId, 'mmp_post_link');
            mmtReply($adminId,
                "✨ حفظ ایموجی پریمیوم در کانال\n\n" .
                "ابتدا پست را با اکانت خودت داخل کانال منتشر کن. سپس Copy Link همان پست را اینجا بفرست.\n" .
                "لینک عمومی: https://t.me/MyChannel/123\n" .
                "لینک خصوصی: https://t.me/c/123456789/123\n\n" .
                "میرزا باید ادمین کانال و دارای مجوز Edit Messages باشد.",
                mmpCancelButtons());
            return true;
        }
        if (!$active) return $isCommand;

        $data = $session['data'];
        if ($stage === 'mmp_post_link') {
            $post = mmpParseChannelLink($text);
            if (!$post) {
                mmtReply($adminId, '❌ لینک معتبر پست کانال ارسال کن (Copy Link).', mmpCancelButtons());
                return true;
            }
            $error = mmpCanEditPost($post['chat_id']);
            if ($error !== null) {
                mmtReply($adminId, '❌ ' . $error . "\nپس از اصلاح دسترسی لینک را دوباره بفرست.",
                    mmpCancelButtons());
                return true;
            }
            $data = ['chat_id' => $post['chat_id'], 'message_id' => $post['message_id'],
                'post_url' => $post['url']];
            mmtSaveSession($adminId, 'mmp_title', $data);
            mmtReply($adminId, 'عنوان دکمه زیر پست را بفرست؛ مثلاً 🛒 ثبت سفارش.',
                mmpCancelButtons());
            return true;
        }
        if ($stage === 'mmp_title') {
            $length = preg_match_all('/./us', $text);
            if ($text === '' || $length === false || $length > 64 || strpos($text, "\n") !== false) {
                mmtReply($adminId, '❌ عنوان دکمه باید یک خط و حداکثر ۶۴ کاراکتر باشد.',
                    mmpCancelButtons());
                return true;
            }
            $data['button_title'] = $text;
            mmtSaveSession($adminId, 'mmp_link_type', $data);
            mmtReply($adminId, 'دکمه به کجا هدایت کند؟', [
                [mmtButton('🛒 خرید میرزا', 'mmp_link_buy')],
                [mmtButton('🔗 لینک دلخواه', 'mmp_link_custom')],
                [mmtButton('❌ لغو', 'mmp_cancel')]
            ]);
            return true;
        }
        if ($stage === 'mmp_link_type') {
            if ($callback === 'mmp_link_buy') {
                $me = telegram('getMe');
                $username = (string) ($me['result']['username'] ?? '');
                if (empty($me['ok']) || !preg_match('/^[A-Za-z0-9_]{5,32}$/D', $username)) {
                    mmtReply($adminId, '❌ نام کاربری ربات دریافت نشد. دوباره تلاش کن.',
                        [[mmtButton('🔄 تلاش مجدد', 'mmp_link_buy')], [mmtButton('❌ لغو', 'mmp_cancel')]]);
                    return true;
                }
                $data['button_url'] = 'https://t.me/' . $username . '?start=buy';
                mmpPreview($adminId, $data);
                return true;
            }
            if ($callback === 'mmp_link_custom') {
                mmtSaveSession($adminId, 'mmp_custom_url', $data);
                mmtReply($adminId, '🔗 لینک HTTPS مقصد دکمه را بفرست.', mmpCancelButtons());
                return true;
            }
            mmtReply($adminId, 'یکی از گزینه‌های مقصد را انتخاب کن.', [
                [mmtButton('🛒 خرید میرزا', 'mmp_link_buy')],
                [mmtButton('🔗 لینک دلخواه', 'mmp_link_custom')],
                [mmtButton('❌ لغو', 'mmp_cancel')]
            ]);
            return true;
        }
        if ($stage === 'mmp_custom_url') {
            if (!mmtValidUrl($text)) {
                mmtReply($adminId, '❌ لینک معتبر HTTPS بفرست.', mmpCancelButtons());
                return true;
            }
            $data['button_url'] = $text;
            mmpPreview($adminId, $data);
            return true;
        }
        if ($stage === 'mmp_confirm') {
            if ($callback !== 'mmp_apply') {
                mmpPreview($adminId, $data);
                return true;
            }
            $error = mmpCanEditPost($data['chat_id']);
            if ($error !== null) {
                mmtReply($adminId, '❌ ' . $error, mmpCancelButtons());
                return true;
            }
            $claim = $pdo->prepare("UPDATE mirza_msg_sessions SET stage='mmp_applying'
                WHERE admin_id = ? AND stage = 'mmp_confirm'");
            $claim->execute([(string) $adminId]);
            if ($claim->rowCount() !== 1) return true;

            $markup = json_encode(['inline_keyboard' => [[
                ['text' => $data['button_title'], 'url' => $data['button_url']]
            ]]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $result = telegram('editMessageReplyMarkup', [
                'chat_id' => $data['chat_id'],
                'message_id' => (int) $data['message_id'],
                'reply_markup' => $markup
            ]);
            if (empty($result['ok'])) {
                mmtSaveSession($adminId, 'mmp_confirm', $data);
                mmtReply($adminId, "❌ افزودن دکمه ناموفق بود. متن پست تغییری نکرد.\n" .
                    (string) ($result['description'] ?? 'خطای ناشناخته'),
                    [[mmtButton('🔄 تلاش مجدد', 'mmp_apply')], [mmtButton('❌ لغو', 'mmp_cancel')]]);
                return true;
            }
            mmtDeleteSession($adminId);
            try {
                $log = $pdo->prepare("INSERT INTO mirza_msg_publications
                    (admin_id, destination, message_id, source_message_id, button_title, button_url)
                    VALUES (?, ?, ?, ?, ?, ?)");
                $log->execute([(string) $adminId, (string) $data['chat_id'],
                    (int) $data['message_id'], (int) $data['message_id'],
                    $data['button_title'], $data['button_url']]);
            } catch (Throwable $e) {
                error_log('[mmp] post log error: ' . $e->getMessage());
            }
            mmtReply($adminId, '✅ دکمه به پست اصلی اضافه شد، بدون تغییر متن و ایموجی‌های آن.',
                [[mmtButton('✨ پست بعدی', 'mmp_start')], [mmtButton('⬅️ ابزارها', 'mmt_home')]]);
            return true;
        }
        if ($stage === 'mmp_applying') {
            mmtReply($adminId, 'درخواست در حال پردازش است. دوباره نزن.',
                [[mmtButton('❌ لغو', 'mmp_cancel')]]);
            return true;
        }
        mmtDeleteSession($adminId);
        mmtHome($adminId);
        return true;
    } catch (Throwable $e) {
        error_log('[mmp] ' . $e->getMessage());
        mmtReply($adminId, '❌ خطای داخلی ابزار دکمه پریمیوم. لاگ را بررسی کن.',
            mmpCancelButtons());
        return true;
    }
}
