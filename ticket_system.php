<?php

/**
 * Mirza disruption ticket system.
 *
 * All user-visible content and selectable values are loaded from the database.
 * The defaults are installed by ticket_install.php and can be managed by admins.
 */

function ticketSystemReady()
{
    global $pdo;
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'tickets'");
        $ready = (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

function ticketSetting($key, $default = '')
{
    global $pdo;
    if (!ticketSystemReady()) {
        return $default;
    }
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM ticket_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? $default : $value;
    } catch (Throwable $e) {
        return $default;
    }
}

function ticketSetSetting($key, $value)
{
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO ticket_settings (setting_key, setting_value) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    return $stmt->execute([$key, (string) $value]);
}

function ticketEnabled()
{
    return ticketSystemReady() && ticketSetting('enabled', '1') === '1';
}

function ticketContentRow($key)
{
    global $pdo;
    if (!ticketSystemReady()) {
        return false;
    }
    $stmt = $pdo->prepare("SELECT * FROM ticket_content WHERE content_key = ? LIMIT 1");
    $stmt->execute([$key]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function ticketEscape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ticketLimitText($value, $maxLength)
{
    $value = trim((string) $value);
    return function_exists('mb_substr')
        ? mb_substr($value, 0, (int) $maxLength, 'UTF-8')
        : substr($value, 0, (int) $maxLength);
}

function ticketButtonText($value)
{
    return ticketLimitText(preg_replace('/\s+/u', ' ', (string) $value), 64);
}

function ticketCustomEmojiIsValid($customEmojiId)
{
    static $cache = [];
    $customEmojiId = trim((string) $customEmojiId);
    if ($customEmojiId === '' || !preg_match('/^[0-9]{10,32}$/', $customEmojiId)) {
        return false;
    }
    if (array_key_exists($customEmojiId, $cache)) {
        return $cache[$customEmojiId];
    }
    if (!function_exists('telegram')) {
        return $cache[$customEmojiId] = false;
    }
    try {
        $response = telegram('getCustomEmojiStickers', [
            'custom_emoji_ids' => json_encode([$customEmojiId]),
        ]);
        return $cache[$customEmojiId] = !empty($response['ok']) && !empty($response['result'][0]);
    } catch (Throwable $e) {
        return $cache[$customEmojiId] = false;
    }
}

function ticketRender($key, array $variables = [], $withEmoji = true)
{
    $row = ticketContentRow($key);
    if (!$row || (int) $row['enabled'] !== 1) {
        return '';
    }
    $value = (string) $row['value'];
    foreach ($variables as $name => $replacement) {
        $value = str_replace('{' . $name . '}', (string) $replacement, $value);
    }
    if (!$withEmoji) {
        return $value;
    }
    $emoji = trim((string) $row['emoji']);
    if ($emoji === '') {
        return $value;
    }
    if (ticketCustomEmojiIsValid($row['custom_emoji_id'])) {
        return '<tg-emoji emoji-id="' . ticketEscape($row['custom_emoji_id']) . '">' . ticketEscape($emoji) . '</tg-emoji> ' . $value;
    }
    return $emoji . ' ' . $value;
}

function ticketButton($key, $callbackData)
{
    $row = ticketContentRow($key);
    if (!$row || (int) $row['enabled'] !== 1) {
        return null;
    }
    $label = ticketButtonText((string) $row['emoji'] . ' ' . (string) $row['value']);
    $button = [
        'text' => $label,
        'callback_data' => $callbackData,
    ];
    if (ticketCustomEmojiIsValid($row['custom_emoji_id'])) {
        $button['icon_custom_emoji_id'] = (string) $row['custom_emoji_id'];
    }
    return $button;
}

function ticketDynamicButton($key, array $variables, $callbackData)
{
    $row = ticketContentRow($key);
    if (!$row || (int) $row['enabled'] !== 1) {
        return null;
    }
    $label = (string) $row['value'];
    foreach ($variables as $name => $replacement) {
        $label = str_replace('{' . $name . '}', (string) $replacement, $label);
    }
    $button = [
        'text' => ticketButtonText((string) $row['emoji'] . ' ' . $label),
        'callback_data' => $callbackData,
    ];
    if (ticketCustomEmojiIsValid($row['custom_emoji_id'])) {
        $button['icon_custom_emoji_id'] = (string) $row['custom_emoji_id'];
    }
    return $button;
}

function ticketButtonFromOption(array $option, $callbackData)
{
    $button = [
        'text' => ticketButtonText((string) $option['emoji'] . ' ' . (string) $option['label']),
        'callback_data' => $callbackData,
    ];
    if (ticketCustomEmojiIsValid($option['custom_emoji_id'] ?? '')) {
        $button['icon_custom_emoji_id'] = (string) $option['custom_emoji_id'];
    }
    return $button;
}

function ticketKeyboard(array $rows)
{
    $cleanRows = [];
    foreach ($rows as $row) {
        $clean = array_values(array_filter($row, function ($button) {
            return is_array($button) && !empty($button['text']);
        }));
        if ($clean) {
            $cleanRows[] = $clean;
        }
    }
    return json_encode(['inline_keyboard' => $cleanRows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function ticketEnhanceMainKeyboard($keyboardJson, $inline = false)
{
    if (!ticketEnabled()) {
        return $keyboardJson;
    }
    $keyboard = json_decode((string) $keyboardJson, true);
    if (!is_array($keyboard)) {
        return $keyboardJson;
    }
    $report = ticketContentRow('main_report');
    $mine = ticketContentRow('main_my_tickets');
    if (!$report || !$mine || (int) $report['enabled'] !== 1 || (int) $mine['enabled'] !== 1) {
        return $keyboardJson;
    }
    $container = $inline ? 'inline_keyboard' : 'keyboard';
    if (!isset($keyboard[$container]) || !is_array($keyboard[$container])) {
        $keyboard[$container] = [];
    }
    $reportLabel = ticketButtonText($report['emoji'] . ' ' . $report['value']);
    $mineLabel = ticketButtonText($mine['emoji'] . ' ' . $mine['value']);
    foreach ($keyboard[$container] as $row) {
        foreach ($row as $button) {
            if (($button['text'] ?? '') === $reportLabel || ($button['text'] ?? '') === $mineLabel) {
                return $keyboardJson;
            }
        }
    }
    $mainItems = [
        ['row' => $report, 'key' => 'main_report', 'callback' => 'ticket_new'],
        ['row' => $mine, 'key' => 'main_my_tickets', 'callback' => 'ticket_mine'],
    ];
    usort($mainItems, function ($a, $b) {
        return ((int) $a['row']['sort_order']) <=> ((int) $b['row']['sort_order']);
    });
    if ($inline) {
        $row = [];
        foreach ($mainItems as $item) {
            $row[] = ticketButton($item['key'], $item['callback']);
        }
        $keyboard[$container][] = $row;
    } else {
        $row = [];
        foreach ($mainItems as $item) {
            $button = ticketButton($item['key'], $item['callback']);
            unset($button['callback_data']);
            $row[] = $button;
        }
        $keyboard[$container][] = $row;
    }
    return json_encode($keyboard, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function ticketEnhanceAdminKeyboard($keyboardJson)
{
    if (!ticketSystemReady() || !$keyboardJson) {
        return $keyboardJson;
    }
    $keyboard = json_decode((string) $keyboardJson, true);
    $row = ticketContentRow('admin_menu');
    if (!is_array($keyboard) || !$row || (int) $row['enabled'] !== 1) {
        return $keyboardJson;
    }
    $label = ticketButtonText($row['emoji'] . ' ' . $row['value']);
    foreach (($keyboard['keyboard'] ?? []) as $buttons) {
        foreach ($buttons as $button) {
            if (($button['text'] ?? '') === $label) {
                return $keyboardJson;
            }
        }
    }
    $backRow = array_pop($keyboard['keyboard']);
    $adminButton = ticketButton('admin_menu', 'tadm_home');
    unset($adminButton['callback_data']);
    $keyboard['keyboard'][] = [$adminButton];
    if ($backRow) {
        $keyboard['keyboard'][] = $backRow;
    }
    return json_encode($keyboard, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function ticketKeyboardContainsText($keyboardJson, $text)
{
    $keyboard = json_decode((string) $keyboardJson, true);
    if (!is_array($keyboard) || $text === '') {
        return false;
    }
    foreach (['keyboard', 'inline_keyboard'] as $container) {
        foreach (($keyboard[$container] ?? []) as $row) {
            foreach ($row as $button) {
                if (($button['text'] ?? '') === $text) {
                    return true;
                }
            }
        }
    }
    return false;
}

function ticketServiceToken($invoiceId)
{
    global $pdo;
    $token = substr(hash('sha256', (string) $invoiceId), 0, 20);
    $stmt = $pdo->prepare("INSERT IGNORE INTO ticket_service_tokens (token, invoice_id) VALUES (?, ?)");
    $stmt->execute([$token, $invoiceId]);
    return $token;
}

function ticketInvoiceFromToken($token)
{
    global $pdo;
    if (!preg_match('/^[a-f0-9]{20}$/', (string) $token)) {
        return false;
    }
    $stmt = $pdo->prepare("SELECT invoice_id FROM ticket_service_tokens WHERE token = ?");
    $stmt->execute([$token]);
    return $stmt->fetchColumn();
}

function ticketAppendServiceButton($keyboard, $invoiceId)
{
    if (!ticketEnabled() || !$invoiceId) {
        return $keyboard;
    }
    $wasJson = is_string($keyboard);
    $data = $wasJson ? json_decode($keyboard, true) : $keyboard;
    if (!is_array($data)) {
        return $keyboard;
    }
    $token = ticketServiceToken($invoiceId);
    $button = ticketButton('service_report', 'ticket_service_' . $token);
    if (!$button) {
        return $keyboard;
    }
    if (!isset($data['inline_keyboard'])) {
        $data['inline_keyboard'] = [];
    }
    $data['inline_keyboard'][] = [$button];
    return $wasJson
        ? json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : $data;
}

function ticketOptions($group, $parentKey = '')
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM ticket_options
        WHERE option_group = ? AND parent_key = ? AND enabled = 1
        ORDER BY sort_order ASC, id ASC");
    $stmt->execute([$group, (string) $parentKey]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ticketOptionById($id)
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM ticket_options WHERE id = ?");
    $stmt->execute([(int) $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function ticketOptionLabel($group, $key, $parent = '')
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT label, emoji FROM ticket_options
        WHERE option_group = ? AND option_key = ? AND (? = '' OR parent_key = ?)
        ORDER BY enabled DESC, id ASC LIMIT 1");
    $stmt->execute([$group, $key, $parent, $parent]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? trim($row['emoji'] . ' ' . $row['label']) : ticketEscape($key);
}

function ticketDraft($userId)
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM ticket_drafts WHERE user_id = ?");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }
    $row['data'] = json_decode($row['data_json'], true);
    if (!is_array($row['data'])) {
        $row['data'] = [];
    }
    return $row;
}

function ticketSaveDraft($userId, $step, array $data)
{
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO ticket_drafts (user_id, current_step, data_json)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE current_step = VALUES(current_step), data_json = VALUES(data_json)");
    return $stmt->execute([
        $userId,
        $step,
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function ticketDeleteDraft($userId)
{
    global $pdo;
    $stmt = $pdo->prepare("DELETE FROM ticket_drafts WHERE user_id = ?");
    return $stmt->execute([$userId]);
}

function ticketAdminSession($adminId)
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM ticket_admin_sessions WHERE admin_id = ?");
    $stmt->execute([$adminId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }
    $row['data'] = json_decode($row['data_json'], true);
    if (!is_array($row['data'])) {
        $row['data'] = [];
    }
    return $row;
}

function ticketSaveAdminSession($adminId, $step, array $data)
{
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO ticket_admin_sessions (admin_id, current_step, data_json)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE current_step = VALUES(current_step), data_json = VALUES(data_json)");
    return $stmt->execute([
        $adminId,
        $step,
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function ticketDeleteAdminSession($adminId)
{
    global $pdo;
    $stmt = $pdo->prepare("DELETE FROM ticket_admin_sessions WHERE admin_id = ?");
    return $stmt->execute([$adminId]);
}

function ticketOwnedInvoice($invoiceId, $userId)
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM invoice WHERE id_invoice = ? AND id_user = ? LIMIT 1");
    $stmt->execute([$invoiceId, $userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function ticketUserInvoices($userId)
{
    global $pdo;
    $statuses = ['active', 'end_of_time', 'end_of_volume', 'sendedwarn', 'send_on_hold'];
    $placeholders = implode(',', array_fill(0, count($statuses), '?'));
    $stmt = $pdo->prepare("SELECT * FROM invoice WHERE id_user = ? AND Status IN ($placeholders)
        ORDER BY time_sell DESC LIMIT 50");
    $stmt->execute(array_merge([$userId], $statuses));
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ticketOpenForService($invoiceId)
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM tickets
        WHERE invoice_id = ? AND status IN ('new','in_progress','waiting_user','resolved')
        ORDER BY id DESC LIMIT 1");
    $stmt->execute([$invoiceId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function ticketByTracking($tracking)
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM tickets WHERE tracking = ? LIMIT 1");
    $stmt->execute([$tracking]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function ticketById($ticketId)
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM tickets WHERE id = ? LIMIT 1");
    $stmt->execute([(int) $ticketId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function ticketStatusLabel($status)
{
    $value = ticketRender('status_' . $status, [], false);
    return $value !== '' ? $value : ticketEscape($status);
}

function ticketSendPage($chatId, $text, $keyboard = null, $preferEdit = true)
{
    global $datain, $message_id;
    if ($preferEdit && $datain !== '' && (int) $message_id > 0) {
        $response = Editmessagetext($chatId, $message_id, $text, $keyboard, 'HTML');
        if (!empty($response['ok'])) {
            return $response;
        }
    }
    return sendmessage($chatId, $text, $keyboard, 'HTML');
}

function ticketBackCancelKeyboard($backCallback = 'ticket_mine', $includeCancel = true)
{
    $row = [];
    if ($backCallback !== '') {
        $row[] = ticketButton('back', $backCallback);
    }
    if ($includeCancel) {
        $row[] = ticketButton('cancel', 'ticket_cancel');
    }
    return ticketKeyboard([$row]);
}

function ticketShowGuide($userId, $invoiceId = '')
{
    $data = [
        'invoice_id' => $invoiceId,
        'idempotency_key' => bin2hex(random_bytes(20)),
    ];
    ticketSaveDraft($userId, 'guide', $data);
    ticketSendPage($userId, ticketRender('guide'), ticketKeyboard([
        [ticketButton('guide_confirm', 'ticket_guide_confirm')],
        [ticketButton('cancel', 'ticket_cancel')],
    ]));
}

function ticketShowServices($userId)
{
    $invoices = ticketUserInvoices($userId);
    if (!$invoices) {
        ticketSendPage($userId, ticketRender('no_services'), ticketBackCancelKeyboard('backuser'));
        return;
    }
    $rows = [];
    foreach ($invoices as $invoice) {
        $token = ticketServiceToken($invoice['id_invoice']);
        $rows[] = [ticketDynamicButton('service_item', [
            'note' => $invoice['note'] ? $invoice['note'] . ' | ' : '',
            'service' => $invoice['username'],
        ], 'ticket_choose_' . $token)];
    }
    $rows[] = [ticketButton('cancel', 'ticket_cancel')];
    ticketSendPage($userId, ticketRender('select_service'), ticketKeyboard($rows));
}

function ticketShowOptionsStep($userId, $group, $parent = '', $textKey = '', $backCallback = 'ticket_cancel')
{
    $options = ticketOptions($group, $parent);
    if (!$options && $parent !== '') {
        $options = ticketOptions($group, '');
    }
    $rows = [];
    $row = [];
    foreach ($options as $option) {
        $row[] = ticketButtonFromOption($option, 'ticket_option_' . $option['id']);
        if (count($row) === 2) {
            $rows[] = $row;
            $row = [];
        }
    }
    if ($row) {
        $rows[] = $row;
    }
    $rows[] = [ticketButton('back', $backCallback), ticketButton('cancel', 'ticket_cancel')];
    ticketSendPage($userId, ticketRender($textKey), ticketKeyboard($rows));
}

function ticketSnapshotService(array $invoice)
{
    global $ManagePanel;
    $panel = select('marzban_panel', '*', 'name_panel', $invoice['Service_location'], 'select');
    if (!is_array($panel)) {
        $panel = [];
    }
    $live = [];
    try {
        if (isset($ManagePanel) && is_object($ManagePanel)) {
            $result = $ManagePanel->DataUser($invoice['Service_location'], $invoice['username']);
            if (is_array($result) && ($result['status'] ?? '') !== 'Unsuccessful') {
                $live = $result;
            }
        }
    } catch (Throwable $e) {
        error_log('[Mirza Ticket Snapshot] ' . $e->getMessage());
    }
    $limit = isset($live['data_limit']) && is_numeric($live['data_limit']) ? (float) $live['data_limit'] : null;
    $used = isset($live['used_traffic']) && is_numeric($live['used_traffic']) ? (float) $live['used_traffic'] : null;
    $remainingBytes = ($limit !== null && $used !== null) ? max(0, $limit - $used) : null;
    $remaining = $remainingBytes !== null && function_exists('formatBytes')
        ? formatBytes($remainingBytes)
        : ($remainingBytes === null ? ticketRender('unknown', [], false) : (string) $remainingBytes);
    $expire = ticketRender('unlimited_unknown', [], false);
    if (!empty($live['expire']) && is_numeric($live['expire'])) {
        $expire = function_exists('jdate') ? jdate('Y/m/d H:i:s', (int) $live['expire']) : date('Y-m-d H:i:s', (int) $live['expire']);
    }
    $lastOnline = (string) ($live['online_at'] ?? ticketRender('unknown', [], false));
    $node = (string) ($live['node_name'] ?? $live['node'] ?? $live['node_id'] ?? $live['inbound'] ?? ticketRender('unknown', [], false));
    $snapshot = [
        'invoice' => [
            'id_invoice' => $invoice['id_invoice'],
            'username' => $invoice['username'],
            'name_product' => $invoice['name_product'] ?? '',
            'volume' => $invoice['Volume'] ?? '',
            'service_time' => $invoice['Service_time'] ?? '',
            'service_location' => $invoice['Service_location'] ?? '',
            'status' => $invoice['Status'] ?? '',
            'note' => $invoice['note'] ?? '',
        ],
        'panel' => [
            'code' => $panel['code_panel'] ?? '',
            'name' => $panel['name_panel'] ?? ($invoice['Service_location'] ?? ''),
            'type' => $panel['type'] ?? '',
        ],
        'live' => [
            'status' => $live['status'] ?? 'unknown',
            'data_limit' => $limit,
            'used_traffic' => $used,
            'remaining_bytes' => $remainingBytes,
            'remaining' => $remaining,
            'expire' => $expire,
            'last_online' => $lastOnline,
            'node' => $node,
        ],
    ];
    return [$snapshot, $panel, $node];
}

function ticketAdminRecipients()
{
    global $pdo;
    $recipients = [];
    $configured = trim((string) ticketSetting('admin_chat_id', ''));
    if ($configured !== '') {
        foreach (preg_split('/[\s,;]+/', $configured) as $id) {
            if (preg_match('/^-?[0-9]+$/', $id)) {
                $recipients[] = $id;
            }
        }
    }
    try {
        $stmt = $pdo->query("SELECT id_admin FROM admin");
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $adminId) {
            if (preg_match('/^[0-9]+$/', (string) $adminId)) {
                $recipients[] = (string) $adminId;
            }
        }
    } catch (Throwable $e) {
        error_log('[Mirza Ticket Admin Recipients] ' . $e->getMessage());
    }
    return array_values(array_unique($recipients));
}

function ticketRecordEvent($ticketId, $actorId, $actorRole, $eventType, $oldStatus = '', $newStatus = '', array $data = [])
{
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO ticket_events
        (ticket_id, actor_id, actor_role, event_type, old_status, new_status, event_data)
        VALUES (?, ?, ?, ?, ?, ?, ?)");
    return $stmt->execute([
        (int) $ticketId,
        (string) $actorId,
        $actorRole,
        $eventType,
        $oldStatus,
        $newStatus,
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function ticketAddMessage($ticketId, $senderId, $senderRole, $type, $messageText = '', $fileId = '')
{
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO ticket_messages
        (ticket_id, sender_id, sender_role, message_type, message_text, file_id)
        VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([(int) $ticketId, (string) $senderId, $senderRole, $type, $messageText, $fileId]);
    $pdo->prepare("UPDATE tickets SET updated_at = NOW() WHERE id = ?")->execute([(int) $ticketId]);
    return (int) $pdo->lastInsertId();
}

function ticketMessageLog($ticketId, $limit = 8)
{
    global $pdo;
    $limit = max(1, min(20, (int) $limit));
    $stmt = $pdo->prepare("SELECT * FROM ticket_messages WHERE ticket_id = ? ORDER BY id DESC LIMIT $limit");
    $stmt->execute([(int) $ticketId]);
    $rows = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
    if (!$rows) {
        return '—';
    }
    $lines = [];
    foreach ($rows as $row) {
        $role = ticketRender('role_' . $row['sender_role'], [], false);
        if ($row['message_type'] === 'photo') {
            $body = '🖼 ' . ticketRender('message_photo', [], false);
            if (trim((string) $row['message_text']) !== '') {
                $body .= ': ' . ticketEscape(ticketLimitText($row['message_text'], 220));
            }
        } elseif ($row['message_type'] === 'status') {
            $body = '🔄 ' . ticketEscape(ticketLimitText($row['message_text'], 220));
        } else {
            $body = ticketEscape(ticketLimitText($row['message_text'], 220));
        }
        $lines[] = '<b>' . $role . ':</b> ' . $body;
    }
    return implode("\n", $lines);
}

function ticketNotifyAdminsNew(array $ticket)
{
    $snapshot = json_decode($ticket['service_snapshot_json'], true);
    $text = ticketRender('new_ticket_admin', [
        'tracking' => ticketEscape($ticket['tracking']),
        'user_id' => ticketEscape($ticket['user_id']),
        'service' => ticketEscape($ticket['service_username']),
        'panel' => ticketEscape($ticket['panel_name']),
        'node' => ticketEscape($ticket['node_name']),
        'provider' => ticketEscape(ticketOptionLabel('provider', $ticket['provider_key'], $ticket['internet_key'])),
        'province' => ticketEscape($ticket['province']),
        'city' => ticketEscape($ticket['city']),
        'app' => ticketEscape(ticketOptionLabel('app', $ticket['app_key'], $ticket['os_key'])),
        'problem' => ticketEscape(ticketOptionLabel('problem', $ticket['problem_key'])),
    ]);
    $keyboard = ticketKeyboard([
        [[
            'text' => '🎫 ' . $ticket['tracking'],
            'callback_data' => 'tadm_view_' . $ticket['id'],
        ]],
    ]);
    foreach (ticketAdminRecipients() as $recipient) {
        sendmessage($recipient, $text, $keyboard, 'HTML');
        if ($ticket['screenshot_file_id'] !== '') {
            telegram('sendPhoto', [
                'chat_id' => $recipient,
                'photo' => $ticket['screenshot_file_id'],
                'caption' => ticketRender('view_photo', [], false) . ' ' . $ticket['tracking'],
            ]);
        }
    }
}

function ticketNotifyStatus(array $ticket)
{
    $text = ticketRender('status_changed_user', [
        'tracking' => ticketEscape($ticket['tracking']),
        'status' => ticketEscape(ticketStatusLabel($ticket['status'])),
    ]);
    sendmessage($ticket['user_id'], $text, ticketKeyboard([
        [[
            'text' => '🎫 ' . $ticket['tracking'],
            'callback_data' => 'ticket_view_' . $ticket['tracking'],
        ]],
    ]), 'HTML');
}

function ticketNotifyStatusAdmins(array $ticket)
{
    $text = ticketRender('status_changed_admin', [
        'tracking' => ticketEscape($ticket['tracking']),
        'status' => ticketEscape(ticketStatusLabel($ticket['status'])),
    ]);
    foreach (ticketAdminRecipients() as $recipient) {
        sendmessage($recipient, $text, ticketKeyboard([
            [[
                'text' => '🎫 ' . $ticket['tracking'],
                'callback_data' => 'tadm_view_' . $ticket['id'],
            ]],
        ]), 'HTML');
    }
}

function ticketSetStatus(array $ticket, $newStatus, $actorId, $actorRole, $notify = true)
{
    global $pdo;
    $allowed = ['new', 'in_progress', 'waiting_user', 'resolved', 'closed'];
    if (!in_array($newStatus, $allowed, true)) {
        return false;
    }
    $oldStatus = $ticket['status'];
    if ($oldStatus === $newStatus) {
        return true;
    }
    $reopening = $oldStatus === 'closed' && $newStatus !== 'closed';
    $startedTransaction = false;
    if ($reopening) {
        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $startedTransaction = true;
            }
            $pdo->prepare("INSERT IGNORE INTO ticket_service_locks (invoice_id) VALUES (?)")
                ->execute([$ticket['invoice_id']]);
            $pdo->prepare("SELECT invoice_id FROM ticket_service_locks WHERE invoice_id = ? FOR UPDATE")
                ->execute([$ticket['invoice_id']]);
            $stmt = $pdo->prepare("SELECT id FROM tickets WHERE invoice_id = ?
                AND id <> ? AND status IN ('new','in_progress','waiting_user','resolved') LIMIT 1");
            $stmt->execute([$ticket['invoice_id'], $ticket['id']]);
            if ($stmt->fetchColumn() !== false) {
                if ($startedTransaction) {
                    $pdo->rollBack();
                }
                return false;
            }
        } catch (Throwable $e) {
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[Mirza Ticket Reopen] ' . $e->getMessage());
            return false;
        }
    }
    $reopenHours = max(1, (int) ticketSetting('reopen_hours', '24'));
    if ($newStatus === 'closed') {
        $reopenedUntil = date('Y-m-d H:i:s', time() + ($reopenHours * 3600));
        $stmt = $pdo->prepare("UPDATE tickets SET status = 'closed', closed_at = NOW(),
            reopened_until = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$reopenedUntil, $ticket['id']]);
    } else {
        $stmt = $pdo->prepare("UPDATE tickets SET status = ?, closed_at = NULL,
            reopened_until = NULL, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$newStatus, $ticket['id']]);
    }
    ticketAddMessage($ticket['id'], $actorId, $actorRole, 'status', ticketStatusLabel($newStatus));
    ticketRecordEvent($ticket['id'], $actorId, $actorRole, 'status_changed', $oldStatus, $newStatus);
    if ($startedTransaction && $pdo->inTransaction()) {
        $pdo->commit();
    }
    $ticket['status'] = $newStatus;
    if ($notify) {
        if ($actorRole === 'user') {
            ticketNotifyStatusAdmins($ticket);
        } else {
            ticketNotifyStatus($ticket);
        }
    }
    return true;
}

function ticketCheckOutageAlert(array $ticket)
{
    global $pdo;
    $threshold = max(2, (int) ticketSetting('outage_threshold', '5'));
    $minutes = max(1, (int) ticketSetting('outage_window_minutes', '30'));
    $cutoff = date('Y-m-d H:i:s', time() - ($minutes * 60));
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tickets
        WHERE created_at >= ?
          AND problem_key = ? AND app_key = ? AND provider_key = ?
          AND province = ? AND node_name = ?");
    $stmt->execute([
        $cutoff,
        $ticket['problem_key'],
        $ticket['app_key'],
        $ticket['provider_key'],
        $ticket['province'],
        $ticket['node_name'],
    ]);
    $count = (int) $stmt->fetchColumn();
    if ($count < $threshold) {
        return;
    }
    $signatureText = implode('|', [
        $ticket['node_name'],
        $ticket['provider_key'],
        $ticket['province'],
        $ticket['app_key'],
        $ticket['problem_key'],
    ]);
    $hash = hash('sha256', $signatureText);
    $stmt = $pdo->prepare("SELECT last_alert_at FROM ticket_outage_alerts WHERE signature_hash = ?");
    $stmt->execute([$hash]);
    $lastAlert = $stmt->fetchColumn();
    if ($lastAlert !== false && strtotime($lastAlert) > time() - ($minutes * 60)) {
        return;
    }
    $stmt = $pdo->prepare("INSERT INTO ticket_outage_alerts
        (signature_hash, signature_text, report_count, last_alert_at)
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE report_count = VALUES(report_count), last_alert_at = NOW()");
    $stmt->execute([$hash, $signatureText, $count]);
    $text = ticketRender('outage_alert', [
        'minutes' => $minutes,
        'count' => $count,
        'node' => ticketEscape($ticket['node_name']),
        'provider' => ticketEscape(ticketOptionLabel('provider', $ticket['provider_key'], $ticket['internet_key'])),
        'region' => ticketEscape($ticket['province'] . '، ' . $ticket['city']),
        'app' => ticketEscape(ticketOptionLabel('app', $ticket['app_key'], $ticket['os_key'])),
        'problem' => ticketEscape(ticketOptionLabel('problem', $ticket['problem_key'])),
    ]);
    foreach (ticketAdminRecipients() as $recipient) {
        sendmessage($recipient, $text, ticketKeyboard([
            [[
                'text' => '📊 آمار اختلال',
                'callback_data' => 'tadm_stats',
            ]],
        ]), 'HTML');
    }
}

function ticketCreateFromDraft($userId, array $draftData)
{
    global $pdo;
    $invoice = ticketOwnedInvoice($draftData['invoice_id'] ?? '', $userId);
    if (!$invoice) {
        return ['ok' => false, 'reason' => 'not_authorized'];
    }
    $required = [
        'os_key', 'app_key', 'app_version', 'internet_key', 'provider_key',
        'province', 'city', 'problem_key', 'alternate_test_key', 'description', 'idempotency_key',
    ];
    foreach ($required as $key) {
        if (!isset($draftData[$key]) || trim((string) $draftData[$key]) === '') {
            return ['ok' => false, 'reason' => 'invalid_action'];
        }
    }
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT IGNORE INTO ticket_service_locks (invoice_id) VALUES (?)");
        $stmt->execute([$invoice['id_invoice']]);
        $stmt = $pdo->prepare("SELECT invoice_id FROM ticket_service_locks WHERE invoice_id = ? FOR UPDATE");
        $stmt->execute([$invoice['id_invoice']]);

        $existing = ticketOpenForService($invoice['id_invoice']);
        if ($existing) {
            $pdo->rollBack();
            return ['ok' => false, 'reason' => 'duplicate', 'ticket' => $existing];
        }

        $stmt = $pdo->prepare("SELECT * FROM tickets WHERE idempotency_key = ? LIMIT 1");
        $stmt->execute([$draftData['idempotency_key']]);
        $alreadyCreated = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($alreadyCreated) {
            $pdo->rollBack();
            return ['ok' => true, 'ticket' => $alreadyCreated, 'duplicate_submit' => true];
        }

        $cooldown = max(0, (int) ticketSetting('cooldown_seconds', '3600'));
        if ($cooldown > 0) {
            $stmt = $pdo->prepare("SELECT created_at FROM tickets WHERE invoice_id = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$invoice['id_invoice']]);
            $lastCreated = $stmt->fetchColumn();
            if ($lastCreated !== false) {
                $remaining = $cooldown - (time() - strtotime($lastCreated));
                if ($remaining > 0) {
                    $pdo->rollBack();
                    return ['ok' => false, 'reason' => 'cooldown', 'remaining' => $remaining];
                }
            }
        }

        list($snapshot, $panel, $node) = ticketSnapshotService($invoice);
        $userRecord = select('user', '*', 'id', $userId, 'select');
        $userSnapshot = [
            'id' => (string) $userId,
            'username' => is_array($userRecord) ? ($userRecord['username'] ?? '') : '',
            'number' => is_array($userRecord) ? ($userRecord['number'] ?? '') : '',
            'agent' => is_array($userRecord) ? ($userRecord['agent'] ?? '') : '',
            'registered_at' => is_array($userRecord) ? ($userRecord['register'] ?? '') : '',
        ];
        $tracking = strtoupper(substr(bin2hex(random_bytes(8)), 0, 12));
        $stmt = $pdo->prepare("INSERT INTO tickets
            (tracking, idempotency_key, user_id, invoice_id, service_username,
             panel_code, panel_name, panel_type, node_name, user_snapshot_json, service_snapshot_json,
             os_key, app_key, app_version, internet_key, provider_key, province, city,
             problem_key, alternate_test_key, screenshot_file_id, description, status)
            VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'new')");
        $stmt->execute([
            $tracking,
            $draftData['idempotency_key'],
            $userId,
            $invoice['id_invoice'],
            $invoice['username'],
            $panel['code_panel'] ?? '',
            $panel['name_panel'] ?? ($invoice['Service_location'] ?? ''),
            $panel['type'] ?? '',
            $node,
            json_encode($userSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $draftData['os_key'],
            $draftData['app_key'],
            $draftData['app_version'],
            $draftData['internet_key'],
            $draftData['provider_key'],
            $draftData['province'],
            $draftData['city'],
            $draftData['problem_key'],
            $draftData['alternate_test_key'],
            $draftData['screenshot_file_id'] ?? '',
            $draftData['description'],
        ]);
        $ticketId = (int) $pdo->lastInsertId();
        ticketAddMessage($ticketId, $userId, 'user', 'text', $draftData['description']);
        if (!empty($draftData['screenshot_file_id'])) {
            ticketAddMessage($ticketId, $userId, 'user', 'photo', '', $draftData['screenshot_file_id']);
        }
        ticketRecordEvent($ticketId, $userId, 'user', 'created', '', 'new');
        $pdo->commit();
        $ticket = ticketById($ticketId);
        ticketNotifyAdminsNew($ticket);
        ticketCheckOutageAlert($ticket);
        return ['ok' => true, 'ticket' => $ticket];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[Mirza Ticket Create] ' . $e->getMessage());
        return ['ok' => false, 'reason' => 'invalid_action'];
    }
}

function ticketSummary(array $data)
{
    return ticketRender('summary', [
        'service' => ticketEscape(($data['service_username'] ?? $data['invoice_id'] ?? '')),
        'os' => ticketEscape(ticketOptionLabel('os', $data['os_key'] ?? '')),
        'app' => ticketEscape(ticketOptionLabel('app', $data['app_key'] ?? '', $data['os_key'] ?? '')),
        'app_version' => ticketEscape($data['app_version'] ?? ''),
        'internet' => ticketEscape(ticketOptionLabel('internet', $data['internet_key'] ?? '')),
        'provider' => ticketEscape(ticketOptionLabel('provider', $data['provider_key'] ?? '', $data['internet_key'] ?? '')),
        'province' => ticketEscape($data['province'] ?? ''),
        'city' => ticketEscape($data['city'] ?? ''),
        'problem' => ticketEscape(ticketOptionLabel('problem', $data['problem_key'] ?? '')),
        'alternate' => ticketEscape(ticketOptionLabel('alternate_test', $data['alternate_test_key'] ?? '')),
        'photo' => ticketEscape(ticketRender(!empty($data['screenshot_file_id']) ? 'photo_attached' : 'photo_not_attached', [], false)),
        'description' => ticketEscape($data['description'] ?? ''),
    ]);
}

function ticketShowMine($userId)
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM tickets WHERE user_id = ? ORDER BY updated_at DESC LIMIT 30");
    $stmt->execute([$userId]);
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$tickets) {
        ticketSendPage($userId, ticketRender('my_tickets_empty'), ticketBackCancelKeyboard('backuser', false));
        return;
    }
    $rows = [];
    foreach ($tickets as $ticket) {
        $rows[] = [ticketDynamicButton('ticket_item', [
            'tracking' => $ticket['tracking'],
            'status' => ticketStatusLabel($ticket['status']),
        ], 'ticket_view_' . $ticket['tracking'])];
    }
    $rows[] = [ticketButton('back', 'backuser')];
    ticketSendPage($userId, ticketRender('my_tickets_title'), ticketKeyboard($rows));
}

function ticketShowUserDetail(array $ticket)
{
    $text = ticketRender('ticket_detail', [
        'tracking' => ticketEscape($ticket['tracking']),
        'service' => ticketEscape($ticket['service_username']),
        'problem' => ticketEscape(ticketOptionLabel('problem', $ticket['problem_key'])),
        'status' => ticketEscape(ticketStatusLabel($ticket['status'])),
        'created_at' => ticketEscape($ticket['created_at']),
        'updated_at' => ticketEscape($ticket['updated_at']),
        'messages' => ticketMessageLog($ticket['id']),
    ]);
    $rows = [];
    if ($ticket['screenshot_file_id'] !== '') {
        $rows[] = [ticketButton('view_photo', 'ticket_photo_' . $ticket['tracking'])];
    }
    if ($ticket['status'] !== 'closed') {
        $rows[] = [ticketButton('reply', 'ticket_reply_' . $ticket['tracking'])];
        $rows[] = [ticketButton('close', 'ticket_close_' . $ticket['tracking'])];
    } elseif (!empty($ticket['reopened_until']) && strtotime($ticket['reopened_until']) >= time()) {
        $rows[] = [ticketButton('reopen', 'ticket_reopen_' . $ticket['tracking'])];
    }
    $rows[] = [ticketButton('refresh', 'ticket_view_' . $ticket['tracking']), ticketButton('back', 'ticket_mine')];
    ticketSendPage($ticket['user_id'], $text, ticketKeyboard($rows));
}

function ticketAdminAllowed($userId)
{
    global $admin_ids;
    return is_array($admin_ids) && in_array($userId, $admin_ids);
}

function ticketAdminDashboard($adminId)
{
    global $pdo;
    $counts = ['new' => 0, 'in_progress' => 0, 'waiting_user' => 0];
    $stmt = $pdo->query("SELECT status, COUNT(*) total FROM tickets
        WHERE status IN ('new','in_progress','waiting_user','resolved') GROUP BY status");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $counts[$row['status']] = (int) $row['total'];
    }
    $open = array_sum($counts);
    $text = ticketRender('admin_dashboard', [
        'open' => $open,
        'new' => $counts['new'],
        'in_progress' => $counts['in_progress'],
        'waiting_user' => $counts['waiting_user'],
    ]);
    ticketSendPage($adminId, $text, ticketKeyboard([
        [ticketButton('admin_open_tickets', 'tadm_list_open'), ticketButton('admin_all_tickets', 'tadm_list_all')],
        [ticketButton('admin_statistics', 'tadm_stats'), ticketButton('admin_settings', 'tadm_settings')],
        [ticketButton('admin_content', 'tadm_content_1'), ticketButton('admin_options', 'tadm_options')],
        [ticketButton('back', 'backuser')],
    ]));
}

function ticketAdminList($adminId, $mode = 'open', $page = 1)
{
    global $pdo;
    $page = max(1, (int) $page);
    $limit = 12;
    $offset = ($page - 1) * $limit;
    if ($mode === 'open') {
        $stmt = $pdo->prepare("SELECT * FROM tickets
            WHERE status IN ('new','in_progress','waiting_user','resolved')
            ORDER BY FIELD(status,'new','waiting_user','in_progress','resolved'), updated_at DESC
            LIMIT $limit OFFSET $offset");
        $stmt->execute();
    } else {
        $stmt = $pdo->prepare("SELECT * FROM tickets ORDER BY updated_at DESC LIMIT $limit OFFSET $offset");
        $stmt->execute();
    }
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $rows = [];
    foreach ($tickets as $ticket) {
        $rows[] = [ticketDynamicButton('admin_ticket_item', [
            'tracking' => $ticket['tracking'],
            'status' => ticketStatusLabel($ticket['status']),
            'service' => $ticket['service_username'],
        ], 'tadm_view_' . $ticket['id'])];
    }
    $nav = [];
    if ($page > 1) {
        $nav[] = ['text' => '⬅️', 'callback_data' => 'tadm_list_' . $mode . '_' . ($page - 1)];
    }
    if (count($tickets) === $limit) {
        $nav[] = ['text' => '➡️', 'callback_data' => 'tadm_list_' . $mode . '_' . ($page + 1)];
    }
    if ($nav) {
        $rows[] = $nav;
    }
    $rows[] = [ticketButton('back', 'tadm_home')];
    ticketSendPage($adminId, ticketRender('admin_ticket_list'), ticketKeyboard($rows));
}

function ticketAdminDetail(array $ticket, $adminId)
{
    $snapshot = json_decode($ticket['service_snapshot_json'], true);
    if (!is_array($snapshot)) {
        $snapshot = [];
    }
    $live = $snapshot['live'] ?? [];
    $text = ticketRender('admin_ticket_detail', [
        'tracking' => ticketEscape($ticket['tracking']),
        'user_id' => ticketEscape($ticket['user_id']),
        'service' => ticketEscape($ticket['service_username']),
        'panel' => ticketEscape($ticket['panel_name']),
        'node' => ticketEscape($ticket['node_name']),
        'remaining' => ticketEscape($live['remaining'] ?? ticketRender('unknown', [], false)),
        'expire' => ticketEscape($live['expire'] ?? ticketRender('unknown', [], false)),
        'last_online' => ticketEscape($live['last_online'] ?? ticketRender('unknown', [], false)),
        'os' => ticketEscape(ticketOptionLabel('os', $ticket['os_key'])),
        'app' => ticketEscape(ticketOptionLabel('app', $ticket['app_key'], $ticket['os_key'])),
        'app_version' => ticketEscape($ticket['app_version']),
        'internet' => ticketEscape(ticketOptionLabel('internet', $ticket['internet_key'])),
        'provider' => ticketEscape(ticketOptionLabel('provider', $ticket['provider_key'], $ticket['internet_key'])),
        'province' => ticketEscape($ticket['province']),
        'city' => ticketEscape($ticket['city']),
        'problem' => ticketEscape(ticketOptionLabel('problem', $ticket['problem_key'])),
        'alternate' => ticketEscape(ticketOptionLabel('alternate_test', $ticket['alternate_test_key'])),
        'status' => ticketEscape(ticketStatusLabel($ticket['status'])),
        'description' => ticketEscape(ticketLimitText($ticket['description'], 1000)),
        'messages' => ticketMessageLog($ticket['id'], 10),
    ]);
    $rows = [];
    if ($ticket['screenshot_file_id'] !== '') {
        $rows[] = [ticketButton('view_photo', 'tadm_photo_' . $ticket['id'])];
    }
    if ($ticket['status'] !== 'closed') {
        $rows[] = [
            ticketButton('admin_reply', 'tadm_reply_' . $ticket['id']),
            ticketButton('admin_request_info', 'tadm_request_' . $ticket['id']),
        ];
        $rows[] = [ticketButton('admin_change_status', 'tadm_statuses_' . $ticket['id'])];
        $rows[] = [ticketButton('close', 'tadm_set_' . $ticket['id'] . '_closed')];
    } else {
        $rows[] = [ticketButton('reopen', 'tadm_set_' . $ticket['id'] . '_in_progress')];
    }
    $rows[] = [ticketButton('refresh', 'tadm_view_' . $ticket['id']), ticketButton('back', 'tadm_list_open')];
    ticketSendPage($adminId, $text, ticketKeyboard($rows));
}

function ticketAdminStatusPicker($adminId, $ticketId)
{
    $statuses = ['new', 'in_progress', 'waiting_user', 'resolved', 'closed'];
    $rows = [];
    foreach ($statuses as $status) {
        $row = ticketContentRow('status_' . $status);
        if ($row && (int) $row['enabled'] === 1) {
            $rows[] = [[
                'text' => ticketButtonText($row['emoji'] . ' ' . $row['value']),
                'callback_data' => 'tadm_set_' . (int) $ticketId . '_' . $status,
            ]];
        }
    }
    $rows[] = [ticketButton('back', 'tadm_view_' . (int) $ticketId)];
    ticketSendPage($adminId, ticketRender('admin_choose_status'), ticketKeyboard($rows));
}

function ticketStatsRows($column, $minutes, $limit)
{
    global $pdo;
    $allowed = [
        'node_name' => 'node_name',
        'provider_key' => 'provider_key',
        'province' => 'province',
        'region' => "CONCAT(province, '، ', city)",
        'app_key' => 'app_key',
        'problem_key' => 'problem_key',
    ];
    if (!isset($allowed[$column])) {
        return '—';
    }
    $columnSql = $allowed[$column];
    $cutoff = date('Y-m-d H:i:s', time() - ((int) $minutes * 60));
    $stmt = $pdo->prepare("SELECT $columnSql group_value, COUNT(*) total FROM tickets
        WHERE created_at >= ?
        GROUP BY $columnSql ORDER BY total DESC LIMIT " . max(1, min(30, (int) $limit)));
    $stmt->execute([$cutoff]);
    $lines = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $value = $row['group_value'];
        if ($column === 'provider_key') {
            $value = ticketOptionLabel('provider', $value);
        } elseif ($column === 'app_key') {
            $value = ticketOptionLabel('app', $value);
        } elseif ($column === 'problem_key') {
            $value = ticketOptionLabel('problem', $value);
        }
        $lines[] = ticketEscape($value ?: 'نامشخص') . ': <b>' . (int) $row['total'] . '</b>';
    }
    return $lines ? implode("\n", $lines) : '—';
}

function ticketAdminStatistics($adminId)
{
    $minutes = max(1, (int) ticketSetting('outage_window_minutes', '30'));
    $limit = max(3, (int) ticketSetting('statistics_limit', '10'));
    $text = ticketRender('admin_statistics_title', [
        'minutes' => $minutes,
        'nodes' => ticketStatsRows('node_name', $minutes, $limit),
        'providers' => ticketStatsRows('provider_key', $minutes, $limit),
        'regions' => ticketStatsRows('region', $minutes, $limit),
        'apps' => ticketStatsRows('app_key', $minutes, $limit),
        'problems' => ticketStatsRows('problem_key', $minutes, $limit),
    ]);
    ticketSendPage($adminId, $text, ticketKeyboard([
        [ticketButton('refresh', 'tadm_stats'), ticketButton('back', 'tadm_home')],
    ]));
}

function ticketAdminSettings($adminId)
{
    $text = ticketRender('admin_settings_title', [
        'enabled' => ticketRender(ticketSetting('enabled', '1') === '1' ? 'state_enabled' : 'state_disabled', [], false),
        'cooldown' => ticketEscape(ticketSetting('cooldown_seconds', '3600')),
        'reopen' => ticketEscape(ticketSetting('reopen_hours', '24')),
        'window' => ticketEscape(ticketSetting('outage_window_minutes', '30')),
        'threshold' => ticketEscape(ticketSetting('outage_threshold', '5')),
        'admin_chat_id' => ticketEscape(ticketSetting('admin_chat_id', '') ?: '—'),
    ]);
    ticketSendPage($adminId, $text, ticketKeyboard([
        [ticketButton('admin_system_toggle', 'tadm_toggle_system')],
        [ticketButton('admin_cooldown', 'tadm_setting_cooldown_seconds')],
        [ticketButton('admin_reopen_hours', 'tadm_setting_reopen_hours')],
        [ticketButton('admin_outage_window', 'tadm_setting_outage_window_minutes')],
        [ticketButton('admin_outage_threshold', 'tadm_setting_outage_threshold')],
        [ticketButton('admin_chat_id', 'tadm_setting_admin_chat_id')],
        [ticketButton('back', 'tadm_home')],
    ]));
}

function ticketAdminContentList($adminId, $page = 1)
{
    global $pdo;
    $page = max(1, (int) $page);
    $limit = 12;
    $offset = ($page - 1) * $limit;
    $stmt = $pdo->query("SELECT * FROM ticket_content ORDER BY sort_order ASC, id ASC LIMIT $limit OFFSET $offset");
    $rows = [];
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($items as $item) {
        $rows[] = [ticketDynamicButton('admin_content_item', [
            'state' => (int) $item['enabled'] === 1 ? '✅' : '⛔️',
            'key' => $item['content_key'],
        ], 'tadm_content_item_' . $item['id'])];
    }
    $nav = [];
    if ($page > 1) {
        $nav[] = ['text' => '⬅️', 'callback_data' => 'tadm_content_' . ($page - 1)];
    }
    if (count($items) === $limit) {
        $nav[] = ['text' => '➡️', 'callback_data' => 'tadm_content_' . ($page + 1)];
    }
    if ($nav) {
        $rows[] = $nav;
    }
    $rows[] = [ticketButton('admin_add_content', 'tadm_content_add')];
    $rows[] = [ticketButton('back', 'tadm_home')];
    ticketSendPage($adminId, ticketRender('admin_content_title'), ticketKeyboard($rows));
}

function ticketAdminContentDetail($adminId, $contentId)
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM ticket_content WHERE id = ?");
    $stmt->execute([(int) $contentId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$item) {
        ticketAdminContentList($adminId);
        return;
    }
    $text = ticketRender('admin_content_detail', [
        'key' => ticketEscape($item['content_key']),
        'type' => ticketEscape($item['content_type']),
        'enabled' => ticketRender((int) $item['enabled'] === 1 ? 'state_enabled' : 'state_disabled', [], false),
        'sort' => (int) $item['sort_order'],
        'emoji' => ticketEscape($item['emoji'] ?: '—'),
        'custom' => ticketEscape($item['custom_emoji_id'] ?: '—'),
        'value' => ticketEscape(ticketLimitText($item['value'], 2500)),
    ]);
    ticketSendPage($adminId, $text, ticketKeyboard([
        [ticketButton('admin_edit_value', 'tadm_content_edit_value_' . $item['id'])],
        [ticketButton('admin_edit_emoji', 'tadm_content_edit_emoji_' . $item['id']),
            ticketButton('admin_edit_custom_emoji', 'tadm_content_edit_custom_' . $item['id'])],
        [ticketButton('admin_toggle', 'tadm_content_toggle_' . $item['id'])],
        [ticketButton('admin_move_up', 'tadm_content_up_' . $item['id']),
            ticketButton('admin_move_down', 'tadm_content_down_' . $item['id'])],
        [ticketButton('admin_delete', 'tadm_content_delete_' . $item['id'])],
        [ticketButton('back', 'tadm_content_1')],
    ]));
}

function ticketAdminOptionsHome($adminId)
{
    global $pdo;
    $groups = $pdo->query("SELECT DISTINCT option_group FROM ticket_options ORDER BY option_group")->fetchAll(PDO::FETCH_COLUMN);
    $rows = [];
    foreach ($groups as $group) {
        $rows[] = [[
            'text' => '🧩 ' . $group,
            'callback_data' => 'tadm_option_group_' . $group . '_1',
        ]];
    }
    $rows[] = [ticketButton('admin_add_option', 'tadm_option_add')];
    $rows[] = [ticketButton('back', 'tadm_home')];
    ticketSendPage($adminId, ticketRender('admin_options_title'), ticketKeyboard($rows));
}

function ticketAdminOptionList($adminId, $group, $page = 1)
{
    global $pdo;
    if (!preg_match('/^[a-z0-9_]{1,30}$/', $group)) {
        ticketAdminOptionsHome($adminId);
        return;
    }
    $page = max(1, (int) $page);
    $limit = 12;
    $offset = ($page - 1) * $limit;
    $stmt = $pdo->prepare("SELECT * FROM ticket_options WHERE option_group = ?
        ORDER BY parent_key, sort_order, id LIMIT $limit OFFSET $offset");
    $stmt->execute([$group]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $rows = [];
    foreach ($items as $item) {
        $rows[] = [ticketDynamicButton('admin_option_item', [
            'state' => (int) $item['enabled'] === 1 ? '✅' : '⛔️',
            'label' => $item['label'],
            'parent' => $item['parent_key'] ? ' [' . $item['parent_key'] . ']' : '',
        ], 'tadm_option_item_' . $item['id'])];
    }
    $nav = [];
    if ($page > 1) {
        $nav[] = ['text' => '⬅️', 'callback_data' => 'tadm_option_group_' . $group . '_' . ($page - 1)];
    }
    if (count($items) === $limit) {
        $nav[] = ['text' => '➡️', 'callback_data' => 'tadm_option_group_' . $group . '_' . ($page + 1)];
    }
    if ($nav) {
        $rows[] = $nav;
    }
    $rows[] = [ticketButton('admin_add_option', 'tadm_option_add_group_' . $group)];
    $rows[] = [ticketButton('back', 'tadm_options')];
    ticketSendPage($adminId, ticketRender('admin_option_list', ['group' => ticketEscape($group)]), ticketKeyboard($rows));
}

function ticketAdminOptionDetail($adminId, $optionId)
{
    $item = ticketOptionById($optionId);
    if (!$item) {
        ticketAdminOptionsHome($adminId);
        return;
    }
    $text = ticketRender('admin_option_detail', [
        'label' => ticketEscape($item['label']),
        'key' => ticketEscape($item['option_key']),
        'group' => ticketEscape($item['option_group']),
        'parent' => ticketEscape($item['parent_key'] ?: '—'),
        'enabled' => ticketRender((int) $item['enabled'] === 1 ? 'state_enabled' : 'state_disabled', [], false),
        'sort' => (int) $item['sort_order'],
        'emoji' => ticketEscape($item['emoji'] ?: '—'),
        'custom' => ticketEscape($item['custom_emoji_id'] ?: '—'),
    ]);
    ticketSendPage($adminId, $text, ticketKeyboard([
        [ticketButton('admin_edit_value', 'tadm_option_edit_label_' . $item['id'])],
        [ticketButton('admin_edit_group', 'tadm_option_edit_group_' . $item['id']),
            ticketButton('admin_edit_parent', 'tadm_option_edit_parent_' . $item['id'])],
        [ticketButton('admin_edit_key', 'tadm_option_edit_key_' . $item['id'])],
        [ticketButton('admin_edit_emoji', 'tadm_option_edit_emoji_' . $item['id']),
            ticketButton('admin_edit_custom_emoji', 'tadm_option_edit_custom_' . $item['id'])],
        [ticketButton('admin_toggle', 'tadm_option_toggle_' . $item['id'])],
        [ticketButton('admin_move_up', 'tadm_option_up_' . $item['id']),
            ticketButton('admin_move_down', 'tadm_option_down_' . $item['id'])],
        [ticketButton('admin_delete', 'tadm_option_delete_' . $item['id'])],
        [ticketButton('back', 'tadm_option_group_' . $item['option_group'] . '_1')],
    ]));
}

function ticketAdminHandleSession(array $session)
{
    global $from_id, $text, $photoid, $caption, $pdo;
    $step = $session['current_step'];
    $data = $session['data'];

    if (strpos($step, 'setting:') === 0) {
        $key = substr($step, 8);
        $allowed = ['cooldown_seconds', 'reopen_hours', 'outage_window_minutes', 'outage_threshold', 'admin_chat_id'];
        if (!in_array($key, $allowed, true)) {
            ticketDeleteAdminSession($from_id);
            return true;
        }
        if ($key === 'admin_chat_id') {
            if ($text !== '-' && !preg_match('/^-?[0-9]+$/', $text)) {
                sendmessage($from_id, ticketRender('admin_send_chat_id'), ticketBackCancelKeyboard('tadm_settings', false), 'HTML');
                return true;
            }
            ticketSetSetting($key, $text === '-' ? '' : $text);
        } else {
            if ($text === '' || !preg_match('/^[0-9]+$/', $text)) {
                sendmessage($from_id, ticketRender('admin_send_number'), ticketBackCancelKeyboard('tadm_settings', false), 'HTML');
                return true;
            }
            ticketSetSetting($key, max(0, (int) $text));
        }
        ticketDeleteAdminSession($from_id);
        sendmessage($from_id, ticketRender('admin_saved'), null, 'HTML');
        ticketAdminSettings($from_id);
        return true;
    }

    if ($step === 'ticket_reply' || $step === 'ticket_request') {
        $ticket = ticketById($data['ticket_id'] ?? 0);
        if ($ticket && $ticket['status'] === 'closed') {
            ticketDeleteAdminSession($from_id);
            ticketAdminDetail($ticket, $from_id);
            return true;
        }
        if (!$ticket || ($text === '' && $photoid === '')) {
            sendmessage($from_id, ticketRender($step === 'ticket_request' ? 'admin_request_prompt' : 'admin_reply_prompt'),
                ticketBackCancelKeyboard($ticket ? 'tadm_view_' . $ticket['id'] : 'tadm_home', false), 'HTML');
            return true;
        }
        $message = ticketLimitText($text !== '' ? $text : $caption, 2000);
        $type = $photoid !== '' ? 'photo' : 'text';
        ticketAddMessage($ticket['id'], $from_id, 'admin', $type, $message, $photoid);
        $targetStatus = $step === 'ticket_request' ? 'waiting_user' : 'in_progress';
        ticketSetStatus($ticket, $targetStatus, $from_id, 'admin', true);
        $notification = ticketRender('new_reply_user', [
            'tracking' => ticketEscape($ticket['tracking']),
            'message' => ticketEscape($message !== '' ? $message : '🖼 تصویر'),
        ]);
        $replyKeyboard = ticketKeyboard([
            [ticketButton('reply', 'ticket_reply_' . $ticket['tracking'])],
            [[
                'text' => '🎫 ' . $ticket['tracking'],
                'callback_data' => 'ticket_view_' . $ticket['tracking'],
            ]],
        ]);
        sendmessage($ticket['user_id'], $notification, $replyKeyboard, 'HTML');
        if ($photoid !== '') {
            telegram('sendPhoto', [
                'chat_id' => $ticket['user_id'],
                'photo' => $photoid,
                'caption' => $message,
            ]);
        }
        ticketDeleteAdminSession($from_id);
        sendmessage($from_id, ticketRender('admin_reply_saved'), null, 'HTML');
        ticketAdminDetail(ticketById($ticket['id']), $from_id);
        return true;
    }

    if (strpos($step, 'content:') === 0) {
        $parts = explode(':', $step);
        $field = $parts[1] ?? '';
        $id = (int) ($data['id'] ?? 0);
        $fieldMap = ['value' => 'value', 'emoji' => 'emoji', 'custom' => 'custom_emoji_id'];
        if (!isset($fieldMap[$field]) || $text === '') {
            sendmessage($from_id, ticketRender('admin_send_value'), ticketBackCancelKeyboard('tadm_content_1', false), 'HTML');
            return true;
        }
        $value = $text === '-' ? '' : $text;
        if ($field === 'value') {
            $value = ticketLimitText($value, 3500);
        } elseif ($field === 'emoji') {
            $value = ticketLimitText($value, 32);
        } elseif ($field === 'custom') {
            $value = ticketLimitText($value, 64);
        }
        $stmt = $pdo->prepare("UPDATE ticket_content SET {$fieldMap[$field]} = ? WHERE id = ?");
        $stmt->execute([$value, $id]);
        ticketDeleteAdminSession($from_id);
        sendmessage($from_id, ticketRender('admin_saved'), null, 'HTML');
        ticketAdminContentDetail($from_id, $id);
        return true;
    }

    if (strpos($step, 'option_edit:') === 0) {
        $field = substr($step, strlen('option_edit:'));
        $id = (int) ($data['id'] ?? 0);
        $fieldMap = [
            'label' => 'label',
            'emoji' => 'emoji',
            'custom' => 'custom_emoji_id',
            'group' => 'option_group',
            'parent' => 'parent_key',
            'key' => 'option_key',
        ];
        if (!isset($fieldMap[$field]) || $text === '') {
            sendmessage($from_id, ticketRender('admin_send_value'), ticketBackCancelKeyboard('tadm_options', false), 'HTML');
            return true;
        }
        $value = $text === '-' ? '' : $text;
        if ($field === 'label') {
            $value = ticketLimitText($value, 255);
        } elseif ($field === 'emoji') {
            $value = ticketLimitText($value, 32);
        } elseif ($field === 'custom') {
            $value = ticketLimitText($value, 64);
        } elseif ($field === 'group') {
            $value = strtolower(ticketLimitText($value, 30));
            if (!preg_match('/^[a-z0-9_]{1,30}$/', $value)) {
                sendmessage($from_id, ticketRender('admin_option_group_prompt'), null, 'HTML');
                return true;
            }
        } elseif ($field === 'parent') {
            $value = $text === '-' ? '' : strtolower(ticketLimitText($text, 100));
            if ($value !== '' && !preg_match('/^[a-z0-9_]{1,100}$/', $value)) {
                sendmessage($from_id, ticketRender('admin_option_parent_prompt'), null, 'HTML');
                return true;
            }
        } elseif ($field === 'key') {
            $value = strtolower(ticketLimitText($value, 80));
            if (!preg_match('/^[a-z0-9_]{1,80}$/', $value)) {
                sendmessage($from_id, ticketRender('admin_option_key_prompt'), null, 'HTML');
                return true;
            }
        }
        try {
            $stmt = $pdo->prepare("UPDATE ticket_options SET {$fieldMap[$field]} = ? WHERE id = ?");
            $stmt->execute([$value, $id]);
        } catch (Throwable $e) {
            sendmessage($from_id, ticketRender('invalid_action'), null, 'HTML');
            return true;
        }
        ticketDeleteAdminSession($from_id);
        sendmessage($from_id, ticketRender('admin_saved'), null, 'HTML');
        ticketAdminOptionDetail($from_id, $id);
        return true;
    }

    if (strpos($step, 'option_add:') === 0) {
        $stage = substr($step, strlen('option_add:'));
        if ($text === '') {
            return true;
        }
        if ($stage === 'group') {
            $value = strtolower(trim($text));
            if (!preg_match('/^[a-z0-9_]{1,30}$/', $value)) {
                sendmessage($from_id, ticketRender('admin_option_group_prompt'), null, 'HTML');
                return true;
            }
            $data['group'] = $value;
            ticketSaveAdminSession($from_id, 'option_add:parent', $data);
            sendmessage($from_id, ticketRender('admin_option_parent_prompt'), null, 'HTML');
            return true;
        }
        if ($stage === 'parent') {
            $data['parent'] = $text === '-' ? '' : strtolower(trim($text));
            ticketSaveAdminSession($from_id, 'option_add:key', $data);
            sendmessage($from_id, ticketRender('admin_option_key_prompt'), null, 'HTML');
            return true;
        }
        if ($stage === 'key') {
            $value = strtolower(trim($text));
            if (!preg_match('/^[a-z0-9_]{1,80}$/', $value)) {
                sendmessage($from_id, ticketRender('admin_option_key_prompt'), null, 'HTML');
                return true;
            }
            $data['key'] = $value;
            ticketSaveAdminSession($from_id, 'option_add:label', $data);
            sendmessage($from_id, ticketRender('admin_option_label_prompt'), null, 'HTML');
            return true;
        }
        if ($stage === 'label') {
            try {
                $stmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM ticket_options
                    WHERE option_group = ? AND parent_key = ?");
                $stmt->execute([$data['group'], $data['parent']]);
                $sort = (int) $stmt->fetchColumn();
                $stmt = $pdo->prepare("INSERT INTO ticket_options
                    (option_group, parent_key, option_key, label, sort_order) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$data['group'], $data['parent'], $data['key'], ticketLimitText($text, 255), $sort]);
                $id = (int) $pdo->lastInsertId();
                ticketDeleteAdminSession($from_id);
                sendmessage($from_id, ticketRender('admin_option_created'), null, 'HTML');
                ticketAdminOptionDetail($from_id, $id);
            } catch (Throwable $e) {
                sendmessage($from_id, ticketRender('invalid_action'), null, 'HTML');
            }
            return true;
        }
    }

    if (strpos($step, 'content_add:') === 0) {
        $stage = substr($step, strlen('content_add:'));
        if ($text === '') {
            return true;
        }
        if ($stage === 'key') {
            $value = strtolower(trim($text));
            if (!preg_match('/^[a-z0-9_]{1,100}$/', $value)) {
                sendmessage($from_id, ticketRender('admin_content_key_prompt'), null, 'HTML');
                return true;
            }
            $data['key'] = $value;
            ticketSaveAdminSession($from_id, 'content_add:type', $data);
            sendmessage($from_id, ticketRender('admin_content_type_prompt'), null, 'HTML');
            return true;
        }
        if ($stage === 'type') {
            $value = strtolower(trim($text));
            if (!in_array($value, ['text', 'button', 'status'], true)) {
                sendmessage($from_id, ticketRender('admin_content_type_prompt'), null, 'HTML');
                return true;
            }
            $data['type'] = $value;
            ticketSaveAdminSession($from_id, 'content_add:value', $data);
            sendmessage($from_id, ticketRender('admin_content_value_prompt'), null, 'HTML');
            return true;
        }
        if ($stage === 'value') {
            try {
                $sort = (int) $pdo->query("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM ticket_content")->fetchColumn();
                $stmt = $pdo->prepare("INSERT INTO ticket_content
                    (content_key, content_type, value, sort_order) VALUES (?, ?, ?, ?)");
                $stmt->execute([$data['key'], $data['type'], ticketLimitText($text, 3500), $sort]);
                $id = (int) $pdo->lastInsertId();
                ticketDeleteAdminSession($from_id);
                sendmessage($from_id, ticketRender('admin_content_created'), null, 'HTML');
                ticketAdminContentDetail($from_id, $id);
            } catch (Throwable $e) {
                sendmessage($from_id, ticketRender('invalid_action'), null, 'HTML');
            }
            return true;
        }
    }
    return false;
}

function ticketHandleAdminUpdate()
{
    global $from_id, $text, $datain, $pdo, $keyboardadmin;
    if (!ticketAdminAllowed($from_id)) {
        return false;
    }
    $menuRow = ticketContentRow('admin_menu');
    $menuLabel = $menuRow ? ticketButtonText($menuRow['emoji'] . ' ' . $menuRow['value']) : '';
    $session = ticketAdminSession($from_id);
    if ($text !== '' && $menuLabel !== '' && $text === $menuLabel) {
        ticketDeleteAdminSession($from_id);
        ticketAdminDashboard($from_id);
        return true;
    }
    if ($session && $text !== '' && ticketKeyboardContainsText($keyboardadmin, $text)) {
        ticketDeleteAdminSession($from_id);
        return false;
    }
    if ($session && $datain !== '') {
        ticketDeleteAdminSession($from_id);
        $session = false;
    }
    if ($session && ($text !== '' || !empty($GLOBALS['photoid']))) {
        return ticketAdminHandleSession($session);
    }
    if ($datain === 'tadm_home') {
        ticketDeleteAdminSession($from_id);
        ticketAdminDashboard($from_id);
        return true;
    }
    if ($datain === 'tadm_list_open' || $datain === 'tadm_list_all') {
        ticketAdminList($from_id, substr($datain, 10));
        return true;
    }
    if (preg_match('/^tadm_list_(open|all)_([0-9]+)$/', $datain, $match)) {
        ticketAdminList($from_id, $match[1], (int) $match[2]);
        return true;
    }
    if (preg_match('/^tadm_view_([0-9]+)$/', $datain, $match)) {
        $ticket = ticketById($match[1]);
        if ($ticket) {
            ticketAdminDetail($ticket, $from_id);
        }
        return true;
    }
    if (preg_match('/^tadm_photo_([0-9]+)$/', $datain, $match)) {
        $ticket = ticketById($match[1]);
        if ($ticket && $ticket['screenshot_file_id'] !== '') {
            telegram('sendPhoto', [
                'chat_id' => $from_id,
                'photo' => $ticket['screenshot_file_id'],
                'caption' => ticketRender('view_photo', [], false) . ' ' . $ticket['tracking'],
            ]);
        }
        return true;
    }
    if (preg_match('/^tadm_(reply|request)_([0-9]+)$/', $datain, $match)) {
        $ticket = ticketById($match[2]);
        if (!$ticket || $ticket['status'] === 'closed') {
            return true;
        }
        ticketSaveAdminSession($from_id, 'ticket_' . $match[1], ['ticket_id' => $ticket['id']]);
        ticketSendPage($from_id, ticketRender($match[1] === 'request' ? 'admin_request_prompt' : 'admin_reply_prompt'),
            ticketBackCancelKeyboard('tadm_view_' . $ticket['id'], false));
        return true;
    }
    if (preg_match('/^tadm_statuses_([0-9]+)$/', $datain, $match)) {
        ticketAdminStatusPicker($from_id, $match[1]);
        return true;
    }
    if (preg_match('/^tadm_set_([0-9]+)_(new|in_progress|waiting_user|resolved|closed)$/', $datain, $match)) {
        $ticket = ticketById($match[1]);
        if ($ticket) {
            if (!ticketSetStatus($ticket, $match[2], $from_id, 'admin', true)) {
                sendmessage($from_id, ticketRender('duplicate_open'), null, 'HTML');
            }
            ticketAdminDetail(ticketById($ticket['id']), $from_id);
        }
        return true;
    }
    if ($datain === 'tadm_stats') {
        ticketAdminStatistics($from_id);
        return true;
    }
    if ($datain === 'tadm_settings') {
        ticketAdminSettings($from_id);
        return true;
    }
    if ($datain === 'tadm_toggle_system') {
        ticketSetSetting('enabled', ticketSetting('enabled', '1') === '1' ? '0' : '1');
        ticketAdminSettings($from_id);
        return true;
    }
    if (preg_match('/^tadm_setting_(cooldown_seconds|reopen_hours|outage_window_minutes|outage_threshold|admin_chat_id)$/', $datain, $match)) {
        ticketSaveAdminSession($from_id, 'setting:' . $match[1], []);
        ticketSendPage($from_id, ticketRender($match[1] === 'admin_chat_id' ? 'admin_send_chat_id' : 'admin_send_number'),
            ticketBackCancelKeyboard('tadm_settings', false));
        return true;
    }
    if (preg_match('/^tadm_content_([0-9]+)$/', $datain, $match)) {
        ticketAdminContentList($from_id, $match[1]);
        return true;
    }
    if (preg_match('/^tadm_content_item_([0-9]+)$/', $datain, $match)) {
        ticketAdminContentDetail($from_id, $match[1]);
        return true;
    }
    if ($datain === 'tadm_content_add') {
        ticketSaveAdminSession($from_id, 'content_add:key', []);
        ticketSendPage($from_id, ticketRender('admin_content_key_prompt'), ticketBackCancelKeyboard('tadm_content_1', false));
        return true;
    }
    if (preg_match('/^tadm_content_edit_(value|emoji|custom)_([0-9]+)$/', $datain, $match)) {
        ticketSaveAdminSession($from_id, 'content:' . $match[1], ['id' => (int) $match[2]]);
        ticketSendPage($from_id, ticketRender('admin_send_value'), ticketBackCancelKeyboard('tadm_content_item_' . $match[2], false));
        return true;
    }
    if (preg_match('/^tadm_content_(toggle|up|down)_([0-9]+)$/', $datain, $match)) {
        $id = (int) $match[2];
        if ($match[1] === 'toggle') {
            $pdo->prepare("UPDATE ticket_content SET enabled = IF(enabled = 1, 0, 1) WHERE id = ?")->execute([$id]);
        } else {
            $delta = $match[1] === 'up' ? -10 : 10;
            $pdo->prepare("UPDATE ticket_content SET sort_order = sort_order + ? WHERE id = ?")->execute([$delta, $id]);
        }
        ticketAdminContentDetail($from_id, $id);
        return true;
    }
    if (preg_match('/^tadm_content_delete_([0-9]+)$/', $datain, $match)) {
        ticketSendPage($from_id, ticketRender('admin_delete_confirm'), ticketKeyboard([
            [ticketButton('admin_delete', 'tadm_content_delete_ok_' . $match[1])],
            [ticketButton('back', 'tadm_content_item_' . $match[1])],
        ]));
        return true;
    }
    if (preg_match('/^tadm_content_delete_ok_([0-9]+)$/', $datain, $match)) {
        $pdo->prepare("DELETE FROM ticket_content WHERE id = ?")->execute([(int) $match[1]]);
        ticketAdminContentList($from_id, 1);
        return true;
    }
    if ($datain === 'tadm_options') {
        ticketAdminOptionsHome($from_id);
        return true;
    }
    if (preg_match('/^tadm_option_group_([a-z0-9_]{1,30})_([0-9]+)$/', $datain, $match)) {
        ticketAdminOptionList($from_id, $match[1], $match[2]);
        return true;
    }
    if (preg_match('/^tadm_option_item_([0-9]+)$/', $datain, $match)) {
        ticketAdminOptionDetail($from_id, $match[1]);
        return true;
    }
    if ($datain === 'tadm_option_add') {
        ticketSaveAdminSession($from_id, 'option_add:group', []);
        ticketSendPage($from_id, ticketRender('admin_option_group_prompt'), ticketBackCancelKeyboard('tadm_options', false));
        return true;
    }
    if (preg_match('/^tadm_option_add_group_([a-z0-9_]{1,30})$/', $datain, $match)) {
        if (!empty($match[1])) {
            ticketSaveAdminSession($from_id, 'option_add:parent', ['group' => $match[1]]);
            ticketSendPage($from_id, ticketRender('admin_option_parent_prompt'), ticketBackCancelKeyboard('tadm_options', false));
        }
        return true;
    }
    if (preg_match('/^tadm_option_edit_(label|emoji|custom|group|parent|key)_([0-9]+)$/', $datain, $match)) {
        ticketSaveAdminSession($from_id, 'option_edit:' . $match[1], ['id' => (int) $match[2]]);
        $promptMap = [
            'group' => 'admin_option_group_prompt',
            'parent' => 'admin_option_parent_prompt',
            'key' => 'admin_option_key_prompt',
        ];
        ticketSendPage($from_id, ticketRender($promptMap[$match[1]] ?? 'admin_send_value'),
            ticketBackCancelKeyboard('tadm_option_item_' . $match[2], false));
        return true;
    }
    if (preg_match('/^tadm_option_(toggle|up|down)_([0-9]+)$/', $datain, $match)) {
        $id = (int) $match[2];
        if ($match[1] === 'toggle') {
            $pdo->prepare("UPDATE ticket_options SET enabled = IF(enabled = 1, 0, 1) WHERE id = ?")->execute([$id]);
        } else {
            $delta = $match[1] === 'up' ? -10 : 10;
            $pdo->prepare("UPDATE ticket_options SET sort_order = sort_order + ? WHERE id = ?")->execute([$delta, $id]);
        }
        ticketAdminOptionDetail($from_id, $id);
        return true;
    }
    if (preg_match('/^tadm_option_delete_([0-9]+)$/', $datain, $match)) {
        ticketSendPage($from_id, ticketRender('admin_delete_confirm'), ticketKeyboard([
            [ticketButton('admin_delete', 'tadm_option_delete_ok_' . $match[1])],
            [ticketButton('back', 'tadm_option_item_' . $match[1])],
        ]));
        return true;
    }
    if (preg_match('/^tadm_option_delete_ok_([0-9]+)$/', $datain, $match)) {
        $item = ticketOptionById($match[1]);
        $pdo->prepare("DELETE FROM ticket_options WHERE id = ?")->execute([(int) $match[1]]);
        if ($item) {
            ticketAdminOptionList($from_id, $item['option_group'], 1);
        } else {
            ticketAdminOptionsHome($from_id);
        }
        return true;
    }
    return false;
}

function ticketRenderDraftStep($userId, array $draft, $target)
{
    $data = $draft['data'];
    if ($target === 'services') {
        ticketSaveDraft($userId, 'service', $data);
        ticketShowServices($userId);
        return;
    }
    if ($target === 'os') {
        ticketSaveDraft($userId, 'os', $data);
        ticketShowOptionsStep($userId, 'os', '', 'select_os',
            empty($data['invoice_id']) ? 'ticket_back_services' : 'ticket_new');
        return;
    }
    if ($target === 'app') {
        ticketSaveDraft($userId, 'app', $data);
        ticketShowOptionsStep($userId, 'app', $data['os_key'] ?? '', 'select_app', 'ticket_back_os');
        return;
    }
    if ($target === 'app_version') {
        ticketSaveDraft($userId, 'app_version', $data);
        ticketSendPage($userId, ticketRender('ask_app_version'), ticketBackCancelKeyboard('ticket_back_app'));
        return;
    }
    if ($target === 'internet') {
        ticketSaveDraft($userId, 'internet', $data);
        ticketShowOptionsStep($userId, 'internet', '', 'select_internet', 'ticket_back_app_version');
        return;
    }
    if ($target === 'provider') {
        ticketSaveDraft($userId, 'provider', $data);
        ticketShowOptionsStep($userId, 'provider', $data['internet_key'] ?? '', 'select_provider', 'ticket_back_internet');
        return;
    }
    if ($target === 'province') {
        ticketSaveDraft($userId, 'province', $data);
        ticketSendPage($userId, ticketRender('ask_province'), ticketBackCancelKeyboard('ticket_back_provider'));
        return;
    }
    if ($target === 'city') {
        ticketSaveDraft($userId, 'city', $data);
        ticketSendPage($userId, ticketRender('ask_city'), ticketBackCancelKeyboard('ticket_back_province'));
        return;
    }
    if ($target === 'problem') {
        ticketSaveDraft($userId, 'problem', $data);
        ticketShowOptionsStep($userId, 'problem', '', 'select_problem', 'ticket_back_city');
        return;
    }
    if ($target === 'alternate') {
        ticketSaveDraft($userId, 'alternate', $data);
        ticketShowOptionsStep($userId, 'alternate_test', '', 'ask_alternate', 'ticket_back_problem');
        return;
    }
    if ($target === 'photo') {
        ticketSaveDraft($userId, 'photo', $data);
        ticketSendPage($userId, ticketRender('ask_photo'), ticketKeyboard([
            [ticketButton('skip_photo', 'ticket_skip_photo')],
            [ticketButton('back', 'ticket_back_alternate'), ticketButton('cancel', 'ticket_cancel')],
        ]));
        return;
    }
    if ($target === 'description') {
        ticketSaveDraft($userId, 'description', $data);
        ticketSendPage($userId, ticketRender('ask_description'), ticketBackCancelKeyboard('ticket_back_photo'));
        return;
    }
    if ($target === 'summary') {
        ticketSaveDraft($userId, 'summary', $data);
        ticketSendPage($userId, ticketSummary($data), ticketKeyboard([
            [ticketButton('confirm', 'ticket_confirm')],
            [ticketButton('back', 'ticket_back_description'), ticketButton('cancel', 'ticket_cancel')],
        ]));
    }
}

function ticketHandleUserDraftMessage(array $draft)
{
    global $from_id, $text, $photoid, $caption;
    $step = $draft['current_step'];
    $data = $draft['data'];

    if ($step === 'ticket_reply') {
        $ticket = ticketById($data['ticket_id'] ?? 0);
        if (!$ticket || (string) $ticket['user_id'] !== (string) $from_id) {
            ticketDeleteDraft($from_id);
            sendmessage($from_id, ticketRender('not_authorized'), null, 'HTML');
            return true;
        }
        if ($ticket['status'] === 'closed') {
            ticketDeleteDraft($from_id);
            ticketShowUserDetail($ticket);
            return true;
        }
        if ($text === '' && $photoid === '') {
            sendmessage($from_id, ticketRender('reply_prompt'), ticketBackCancelKeyboard('ticket_view_' . $ticket['tracking'], false), 'HTML');
            return true;
        }
        $message = ticketLimitText($text !== '' ? $text : $caption, 2000);
        $type = $photoid !== '' ? 'photo' : 'text';
        ticketAddMessage($ticket['id'], $from_id, 'user', $type, $message, $photoid);
        if (in_array($ticket['status'], ['waiting_user', 'new', 'resolved'], true)) {
            ticketSetStatus($ticket, 'in_progress', $from_id, 'user', true);
        }
        $notice = ticketRender('new_reply_admin', [
            'tracking' => ticketEscape($ticket['tracking']),
            'message' => ticketEscape($message !== '' ? $message : '🖼 تصویر'),
        ]);
        foreach (ticketAdminRecipients() as $recipient) {
            sendmessage($recipient, $notice, ticketKeyboard([
                [[
                    'text' => '🎫 ' . $ticket['tracking'],
                    'callback_data' => 'tadm_view_' . $ticket['id'],
                ]],
            ]), 'HTML');
            if ($photoid !== '') {
                telegram('sendPhoto', [
                    'chat_id' => $recipient,
                    'photo' => $photoid,
                    'caption' => $message,
                ]);
            }
        }
        ticketDeleteDraft($from_id);
        sendmessage($from_id, ticketRender('reply_saved'), null, 'HTML');
        ticketShowUserDetail(ticketById($ticket['id']));
        return true;
    }

    if ($step === 'app_version' && $text !== '') {
        $data['app_version'] = ticketLimitText($text, 200);
        ticketSaveDraft($from_id, 'internet', $data);
        ticketRenderDraftStep($from_id, ticketDraft($from_id), 'internet');
        return true;
    }
    if ($step === 'app_version' && $text === '') {
        sendmessage($from_id, ticketRender('ask_app_version'), ticketBackCancelKeyboard('ticket_back_app'), 'HTML');
        return true;
    }
    if ($step === 'province' && $text !== '') {
        $data['province'] = ticketLimitText($text, 200);
        ticketSaveDraft($from_id, 'city', $data);
        ticketRenderDraftStep($from_id, ticketDraft($from_id), 'city');
        return true;
    }
    if ($step === 'province' && $text === '') {
        sendmessage($from_id, ticketRender('ask_province'), ticketBackCancelKeyboard('ticket_back_provider'), 'HTML');
        return true;
    }
    if ($step === 'city' && $text !== '') {
        $data['city'] = ticketLimitText($text, 200);
        ticketSaveDraft($from_id, 'problem', $data);
        ticketRenderDraftStep($from_id, ticketDraft($from_id), 'problem');
        return true;
    }
    if ($step === 'city' && $text === '') {
        sendmessage($from_id, ticketRender('ask_city'), ticketBackCancelKeyboard('ticket_back_province'), 'HTML');
        return true;
    }
    if ($step === 'photo') {
        if ($photoid === '') {
            sendmessage($from_id, ticketRender('photo_only'), ticketKeyboard([
                [ticketButton('skip_photo', 'ticket_skip_photo')],
                [ticketButton('cancel', 'ticket_cancel')],
            ]), 'HTML');
            return true;
        }
        $data['screenshot_file_id'] = $photoid;
        ticketSaveDraft($from_id, 'description', $data);
        ticketRenderDraftStep($from_id, ticketDraft($from_id), 'description');
        return true;
    }
    if ($step === 'description') {
        $description = ticketLimitText($text !== '' ? $text : $caption, 1800);
        if ($description === '') {
            sendmessage($from_id, ticketRender('description_required'), ticketBackCancelKeyboard('ticket_back_photo'), 'HTML');
            return true;
        }
        $data['description'] = $description;
        $invoice = ticketOwnedInvoice($data['invoice_id'] ?? '', $from_id);
        $data['service_username'] = $invoice['username'] ?? ($data['invoice_id'] ?? '');
        ticketSaveDraft($from_id, 'summary', $data);
        ticketRenderDraftStep($from_id, ticketDraft($from_id), 'summary');
        return true;
    }
    return false;
}

function ticketHandleUserUpdate()
{
    global $from_id, $text, $datain, $photoid, $textbotlang, $keyboard;
    if (!ticketEnabled()) {
        return false;
    }
    $reportRow = ticketContentRow('main_report');
    $mineRow = ticketContentRow('main_my_tickets');
    $reportLabel = $reportRow ? ticketButtonText($reportRow['emoji'] . ' ' . $reportRow['value']) : '';
    $mineLabel = $mineRow ? ticketButtonText($mineRow['emoji'] . ' ' . $mineRow['value']) : '';

    $navigationTexts = ['/start', 'start', '/services', '/buy', '/support', '/help', '/wallet'];
    $backText = $textbotlang['users']['backbtn'] ?? '';
    if (in_array($text, $navigationTexts, true) || $datain === 'backuser' || ($backText !== '' && $text === $backText)) {
        ticketDeleteDraft($from_id);
        return false;
    }

    if (($text !== '' && $text === $reportLabel) || $datain === 'ticket_new') {
        ticketShowGuide($from_id);
        return true;
    }
    if (($text !== '' && $text === $mineLabel) || $datain === 'ticket_mine') {
        ticketDeleteDraft($from_id);
        ticketShowMine($from_id);
        return true;
    }
    if ($text !== '' && ticketKeyboardContainsText($keyboard, $text)) {
        ticketDeleteDraft($from_id);
        return false;
    }
    if ($datain === 'ticket_cancel') {
        ticketDeleteDraft($from_id);
        ticketSendPage($from_id, ticketRender('draft_cancelled'), ticketBackCancelKeyboard('backuser', false));
        return true;
    }
    if (preg_match('/^ticket_service_([a-f0-9]{20})$/', $datain, $match)) {
        $invoiceId = ticketInvoiceFromToken($match[1]);
        if (!$invoiceId || !ticketOwnedInvoice($invoiceId, $from_id)) {
            ticketSendPage($from_id, ticketRender('not_authorized'), ticketBackCancelKeyboard('backuser', false));
            return true;
        }
        $existing = ticketOpenForService($invoiceId);
        if ($existing) {
            sendmessage($from_id, ticketRender('duplicate_open'), null, 'HTML');
            ticketShowUserDetail($existing);
            return true;
        }
        ticketShowGuide($from_id, $invoiceId);
        return true;
    }
    if (preg_match('/^disorder-([A-Za-z0-9_\\-]{1,200})$/', $datain, $match)) {
        $invoice = ticketOwnedInvoice($match[1], $from_id);
        if (!$invoice) {
            ticketSendPage($from_id, ticketRender('not_authorized'), ticketBackCancelKeyboard('backuser', false));
            return true;
        }
        $existing = ticketOpenForService($invoice['id_invoice']);
        if ($existing) {
            sendmessage($from_id, ticketRender('duplicate_open'), null, 'HTML');
            ticketShowUserDetail($existing);
            return true;
        }
        ticketShowGuide($from_id, $invoice['id_invoice']);
        return true;
    }
    if ($datain === 'ticket_guide_confirm') {
        $draft = ticketDraft($from_id);
        if (!$draft || $draft['current_step'] !== 'guide') {
            ticketSendPage($from_id, ticketRender('invalid_action'), ticketBackCancelKeyboard('ticket_new', false));
            return true;
        }
        if (!empty($draft['data']['invoice_id'])) {
            $invoice = ticketOwnedInvoice($draft['data']['invoice_id'], $from_id);
            if (!$invoice) {
                ticketDeleteDraft($from_id);
                ticketSendPage($from_id, ticketRender('not_authorized'), ticketBackCancelKeyboard('ticket_new', false));
                return true;
            }
            $draft['data']['service_username'] = $invoice['username'];
            ticketSaveDraft($from_id, 'os', $draft['data']);
            ticketRenderDraftStep($from_id, ticketDraft($from_id), 'os');
        } else {
            ticketSaveDraft($from_id, 'service', $draft['data']);
            ticketShowServices($from_id);
        }
        return true;
    }
    if (preg_match('/^ticket_choose_([a-f0-9]{20})$/', $datain, $match)) {
        $draft = ticketDraft($from_id);
        $invoiceId = ticketInvoiceFromToken($match[1]);
        $invoice = $invoiceId ? ticketOwnedInvoice($invoiceId, $from_id) : false;
        if (!$draft || !$invoice) {
            ticketSendPage($from_id, ticketRender('not_authorized'), ticketBackCancelKeyboard('ticket_new', false));
            return true;
        }
        $existing = ticketOpenForService($invoiceId);
        if ($existing) {
            ticketDeleteDraft($from_id);
            sendmessage($from_id, ticketRender('duplicate_open'), null, 'HTML');
            ticketShowUserDetail($existing);
            return true;
        }
        $draft['data']['invoice_id'] = $invoiceId;
        $draft['data']['service_username'] = $invoice['username'];
        ticketSaveDraft($from_id, 'os', $draft['data']);
        ticketRenderDraftStep($from_id, ticketDraft($from_id), 'os');
        return true;
    }
    if (preg_match('/^ticket_option_([0-9]+)$/', $datain, $match)) {
        $draft = ticketDraft($from_id);
        $option = ticketOptionById($match[1]);
        if (!$draft || !$option || (int) $option['enabled'] !== 1) {
            ticketSendPage($from_id, ticketRender('invalid_action'), ticketBackCancelKeyboard('ticket_new', false));
            return true;
        }
        $data = $draft['data'];
        $step = $draft['current_step'];
        if ($step === 'os' && $option['option_group'] === 'os') {
            $data['os_key'] = $option['option_key'];
            ticketSaveDraft($from_id, 'app', $data);
            ticketRenderDraftStep($from_id, ticketDraft($from_id), 'app');
            return true;
        }
        if ($step === 'app' && $option['option_group'] === 'app'
            && ($option['parent_key'] === '' || $option['parent_key'] === ($data['os_key'] ?? ''))) {
            $data['app_key'] = $option['option_key'];
            ticketSaveDraft($from_id, 'app_version', $data);
            ticketRenderDraftStep($from_id, ticketDraft($from_id), 'app_version');
            return true;
        }
        if ($step === 'internet' && $option['option_group'] === 'internet') {
            $data['internet_key'] = $option['option_key'];
            ticketSaveDraft($from_id, 'provider', $data);
            ticketRenderDraftStep($from_id, ticketDraft($from_id), 'provider');
            return true;
        }
        if ($step === 'provider' && $option['option_group'] === 'provider'
            && ($option['parent_key'] === '' || $option['parent_key'] === ($data['internet_key'] ?? ''))) {
            $data['provider_key'] = $option['option_key'];
            ticketSaveDraft($from_id, 'province', $data);
            ticketRenderDraftStep($from_id, ticketDraft($from_id), 'province');
            return true;
        }
        if ($step === 'problem' && $option['option_group'] === 'problem') {
            $data['problem_key'] = $option['option_key'];
            ticketSaveDraft($from_id, 'alternate', $data);
            ticketRenderDraftStep($from_id, ticketDraft($from_id), 'alternate');
            return true;
        }
        if ($step === 'alternate' && $option['option_group'] === 'alternate_test') {
            $data['alternate_test_key'] = $option['option_key'];
            ticketSaveDraft($from_id, 'photo', $data);
            ticketRenderDraftStep($from_id, ticketDraft($from_id), 'photo');
            return true;
        }
        ticketSendPage($from_id, ticketRender('invalid_action'), ticketBackCancelKeyboard('ticket_new', false));
        return true;
    }
    if ($datain === 'ticket_skip_photo') {
        $draft = ticketDraft($from_id);
        if (!$draft || $draft['current_step'] !== 'photo') {
            return true;
        }
        $draft['data']['screenshot_file_id'] = '';
        ticketSaveDraft($from_id, 'description', $draft['data']);
        ticketRenderDraftStep($from_id, ticketDraft($from_id), 'description');
        return true;
    }
    if (preg_match('/^ticket_back_(services|os|app|app_version|internet|provider|province|city|problem|alternate|photo|description)$/', $datain, $match)) {
        $draft = ticketDraft($from_id);
        if ($draft) {
            ticketRenderDraftStep($from_id, $draft, $match[1]);
        }
        return true;
    }
    if ($datain === 'ticket_confirm') {
        $draft = ticketDraft($from_id);
        if (!$draft || $draft['current_step'] !== 'summary') {
            ticketSendPage($from_id, ticketRender('invalid_action'), ticketBackCancelKeyboard('ticket_new', false));
            return true;
        }
        $result = ticketCreateFromDraft($from_id, $draft['data']);
        if (!empty($result['ok'])) {
            ticketDeleteDraft($from_id);
            $ticket = $result['ticket'];
            ticketSendPage($from_id, ticketRender('ticket_created', [
                'tracking' => ticketEscape($ticket['tracking']),
                'status' => ticketEscape(ticketStatusLabel($ticket['status'])),
            ]), ticketKeyboard([
                [[
                    'text' => '🎫 ' . $ticket['tracking'],
                    'callback_data' => 'ticket_view_' . $ticket['tracking'],
                ]],
            ]));
            return true;
        }
        if (($result['reason'] ?? '') === 'duplicate' && !empty($result['ticket'])) {
            ticketDeleteDraft($from_id);
            sendmessage($from_id, ticketRender('duplicate_open'), null, 'HTML');
            ticketShowUserDetail($result['ticket']);
            return true;
        }
        if (($result['reason'] ?? '') === 'cooldown') {
            $minutes = max(1, (int) ceil($result['remaining'] / 60));
            ticketSendPage($from_id, ticketRender('cooldown', ['minutes' => $minutes]),
                ticketBackCancelKeyboard('ticket_mine', false));
            return true;
        }
        ticketSendPage($from_id, ticketRender($result['reason'] ?? 'invalid_action'),
            ticketBackCancelKeyboard('ticket_new', false));
        return true;
    }
    if (preg_match('/^ticket_view_([A-Z0-9]{6,32})$/', $datain, $match)) {
        $ticket = ticketByTracking($match[1]);
        if (!$ticket || (string) $ticket['user_id'] !== (string) $from_id) {
            ticketSendPage($from_id, ticketRender('not_authorized'), ticketBackCancelKeyboard('ticket_mine', false));
            return true;
        }
        ticketShowUserDetail($ticket);
        return true;
    }
    if (preg_match('/^ticket_photo_([A-Z0-9]{6,32})$/', $datain, $match)) {
        $ticket = ticketByTracking($match[1]);
        if (!$ticket || (string) $ticket['user_id'] !== (string) $from_id) {
            ticketSendPage($from_id, ticketRender('not_authorized'), ticketBackCancelKeyboard('ticket_mine', false));
            return true;
        }
        if ($ticket['screenshot_file_id'] !== '') {
            telegram('sendPhoto', [
                'chat_id' => $from_id,
                'photo' => $ticket['screenshot_file_id'],
                'caption' => ticketRender('view_photo', [], false) . ' ' . $ticket['tracking'],
            ]);
        }
        return true;
    }
    if (preg_match('/^ticket_reply_([A-Z0-9]{6,32})$/', $datain, $match)) {
        $ticket = ticketByTracking($match[1]);
        if (!$ticket || (string) $ticket['user_id'] !== (string) $from_id || $ticket['status'] === 'closed') {
            ticketSendPage($from_id, ticketRender('not_authorized'), ticketBackCancelKeyboard('ticket_mine', false));
            return true;
        }
        ticketSaveDraft($from_id, 'ticket_reply', ['ticket_id' => $ticket['id']]);
        ticketSendPage($from_id, ticketRender('reply_prompt'), ticketBackCancelKeyboard('ticket_view_' . $ticket['tracking'], false));
        return true;
    }
    if (preg_match('/^ticket_close_([A-Z0-9]{6,32})$/', $datain, $match)) {
        $ticket = ticketByTracking($match[1]);
        if (!$ticket || (string) $ticket['user_id'] !== (string) $from_id) {
            ticketSendPage($from_id, ticketRender('not_authorized'), ticketBackCancelKeyboard('ticket_mine', false));
            return true;
        }
        ticketSetStatus($ticket, 'closed', $from_id, 'user', true);
        $hours = max(1, (int) ticketSetting('reopen_hours', '24'));
        sendmessage($from_id, ticketRender('ticket_closed', ['hours' => $hours]), null, 'HTML');
        ticketShowUserDetail(ticketById($ticket['id']));
        return true;
    }
    if (preg_match('/^ticket_reopen_([A-Z0-9]{6,32})$/', $datain, $match)) {
        $ticket = ticketByTracking($match[1]);
        if (!$ticket || (string) $ticket['user_id'] !== (string) $from_id) {
            ticketSendPage($from_id, ticketRender('not_authorized'), ticketBackCancelKeyboard('ticket_mine', false));
            return true;
        }
        if ($ticket['status'] !== 'closed' || empty($ticket['reopened_until']) || strtotime($ticket['reopened_until']) < time()) {
            ticketSendPage($from_id, ticketRender('reopen_expired'), ticketBackCancelKeyboard('ticket_mine', false));
            return true;
        }
        $open = ticketOpenForService($ticket['invoice_id']);
        if ($open && (int) $open['id'] !== (int) $ticket['id']) {
            sendmessage($from_id, ticketRender('duplicate_open'), null, 'HTML');
            ticketShowUserDetail($open);
            return true;
        }
        if (!ticketSetStatus($ticket, 'in_progress', $from_id, 'user', true)) {
            $open = ticketOpenForService($ticket['invoice_id']);
            sendmessage($from_id, ticketRender('duplicate_open'), null, 'HTML');
            if ($open) {
                ticketShowUserDetail($open);
            }
            return true;
        }
        sendmessage($from_id, ticketRender('ticket_reopened'), null, 'HTML');
        ticketShowUserDetail(ticketById($ticket['id']));
        return true;
    }

    $draft = ticketDraft($from_id);
    if ($draft && ($text !== '' || $photoid !== '')) {
        return ticketHandleUserDraftMessage($draft);
    }
    return false;
}

function ticketHandleUpdate()
{
    if (!ticketSystemReady()) {
        return false;
    }
    if (ticketHandleAdminUpdate()) {
        return true;
    }
    return ticketHandleUserUpdate();
}
