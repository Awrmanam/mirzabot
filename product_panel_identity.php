<?php

function containsLiteralPremiumEmojiToken($value)
{
    return preg_match('/\{emoji:[^{}]+\}/iu', (string) $value) === 1;
}

function normalizeProductBusinessName($value)
{
    $value = preg_replace('/[\p{Z}\s]+/u', ' ', (string) $value);
    return trim((string) $value);
}

function validateProductEmojiKey($emojiKey)
{
    global $pdo;

    $emojiKey = trim((string) $emojiKey);
    if (!preg_match('/^[a-z0-9_]{1,100}$/', $emojiKey)) {
        return ['ok' => false, 'error' => 'invalid_syntax', 'emoji_key' => $emojiKey];
    }

    try {
        $stmt = $pdo->prepare("SELECT `key`, is_active FROM styled_emojis WHERE `key` = :emoji_key LIMIT 2");
        $stmt->execute(['emoji_key' => $emojiKey]);
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'emoji_system_unavailable', 'emoji_key' => $emojiKey];
    }

    if (count($matches) !== 1) {
        return ['ok' => false, 'error' => 'unknown_key', 'emoji_key' => $emojiKey];
    }
    if ((int) $matches[0]['is_active'] !== 1) {
        return ['ok' => false, 'error' => 'inactive_key', 'emoji_key' => $emojiKey];
    }

    return ['ok' => true, 'error' => '', 'emoji_key' => (string) $matches[0]['key']];
}

function extractProductEmojiToken($value)
{
    $value = (string) $value;
    $markerCount = preg_match_all('/\{\s*emoji\s*:/iu', $value, $markerMatches);

    if ($markerCount === 0) {
        if (preg_match('/emoji\s*:/iu', $value)) {
            return ['ok' => false, 'error' => 'invalid_syntax', 'name_product' => '', 'emoji_key' => ''];
        }
        return ['ok' => true, 'error' => '', 'name_product' => $value, 'emoji_key' => ''];
    }
    if ($markerCount > 1) {
        return ['ok' => false, 'error' => 'multiple_tokens', 'name_product' => '', 'emoji_key' => ''];
    }

    if (preg_match_all('/\{emoji:([a-z0-9_]{1,100})\}/u', $value, $validMatches) !== 1) {
        return ['ok' => false, 'error' => 'invalid_syntax', 'name_product' => '', 'emoji_key' => ''];
    }

    $validation = validateProductEmojiKey($validMatches[1][0]);
    if (!$validation['ok']) {
        return array_merge($validation, ['name_product' => '']);
    }

    $plainName = preg_replace('/\{emoji:[a-z0-9_]{1,100}\}/u', '', $value, 1);
    $plainName = normalizeProductBusinessName($plainName);
    if ($plainName === '') {
        return ['ok' => false, 'error' => 'empty_name', 'name_product' => '', 'emoji_key' => $validation['emoji_key']];
    }

    return [
        'ok' => true,
        'error' => '',
        'name_product' => $plainName,
        'emoji_key' => $validation['emoji_key'],
    ];
}

function productEmojiValidationMessage(array $result)
{
    $emojiKey = htmlspecialchars((string) ($result['emoji_key'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    switch ($result['error'] ?? '') {
        case 'multiple_tokens':
            return '❌ فعلاً فقط یک توکن ایموجی پریمیوم برای هر محصول قابل استفاده است.';
        case 'unknown_key':
            return "❌ کلید ایموجی <code>{$emojiKey}</code> در کتابخانه ایموجی‌ها وجود ندارد.";
        case 'inactive_key':
            return "❌ ایموجی <code>{$emojiKey}</code> غیرفعال است و قابل استفاده نیست.";
        case 'empty_name':
            return '❌ نام محصول پس از حذف توکن ایموجی خالی است.';
        case 'emoji_system_unavailable':
            return '❌ سامانه ایموجی پریمیوم در دسترس نیست؛ محصول ذخیره نشد.';
        default:
            return '❌ قالب توکن ایموجی نامعتبر است. قالب صحیح: <code>{emoji:key}</code>';
    }
}

function extractPanelEmojiToken($value)
{
    $result = extractProductEmojiToken($value);
    $result['name_panel'] = (string) ($result['name_product'] ?? '');
    unset($result['name_product']);
    return $result;
}

function panelEmojiValidationMessage(array $result)
{
    $emojiKey = htmlspecialchars((string) ($result['emoji_key'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    switch ($result['error'] ?? '') {
        case 'multiple_tokens':
            return '❌ فعلاً فقط یک توکن ایموجی پریمیوم برای هر پنل قابل استفاده است.';
        case 'unknown_key':
            return "❌ کلید ایموجی <code>{$emojiKey}</code> در کتابخانه ایموجی‌ها وجود ندارد.";
        case 'inactive_key':
            return "❌ ایموجی <code>{$emojiKey}</code> غیرفعال است و قابل استفاده نیست.";
        case 'empty_name':
            return '❌ نام پنل پس از حذف توکن ایموجی خالی است.';
        case 'emoji_system_unavailable':
            return '❌ سامانه ایموجی پریمیوم در دسترس نیست؛ پنل ذخیره نشد.';
        default:
            return '❌ قالب توکن ایموجی نامعتبر است. قالب صحیح: <code>{emoji:key}</code>';
    }
}

function savePanelEmojiMapping($codePanel, $emojiKey)
{
    global $pdo;

    $codePanel = trim((string) $codePanel);
    $validation = validateProductEmojiKey($emojiKey);
    if ($codePanel === '' || !$validation['ok']) {
        return false;
    }

    $stmt = $pdo->prepare("INSERT INTO styled_button_icons
        (source_type, source_key, icon_emoji_key) VALUES ('panel', :source_key, :emoji_key)
        ON DUPLICATE KEY UPDATE icon_emoji_key = VALUES(icon_emoji_key)");
    $saved = $stmt->execute([
        'source_key' => $codePanel,
        'emoji_key' => $validation['emoji_key'],
    ]);
    unset($GLOBALS['styled_runtime_source_icon_map']['panel:' . $codePanel]);
    return $saved;
}

function deletePanelEmojiMapping($codePanel)
{
    global $pdo;

    $codePanel = trim((string) $codePanel);
    if ($codePanel === '') {
        return true;
    }

    $stmt = $pdo->prepare("DELETE FROM styled_button_icons
        WHERE source_type IN ('panel', 'marzban_panel') AND source_key = :source_key");
    $deleted = $stmt->execute(['source_key' => $codePanel]);
    unset(
        $GLOBALS['styled_runtime_source_icon_map']['panel:' . $codePanel],
        $GLOBALS['styled_runtime_source_icon_map']['marzban_panel:' . $codePanel]
    );
    return $deleted;
}

function deleteCanonicalPanelEmojiMapping($codePanel)
{
    global $pdo;

    $codePanel = trim((string) $codePanel);
    if ($codePanel === '') {
        return true;
    }

    $stmt = $pdo->prepare("DELETE FROM styled_button_icons
        WHERE source_type = 'panel' AND source_key = :source_key");
    $deleted = $stmt->execute(['source_key' => $codePanel]);
    unset($GLOBALS['styled_runtime_source_icon_map']['panel:' . $codePanel]);
    return $deleted;
}

function panelShortCodeSuffix($codePanel, $length = 6)
{
    $codePanel = trim((string) $codePanel);
    $safeCode = preg_replace('/[^A-Za-z0-9_-]/', '', $codePanel);
    if ($safeCode === '') {
        $safeCode = substr(hash('sha256', $codePanel), 0, max(4, (int) $length));
    }
    return substr($safeCode, -max(4, (int) $length));
}

function panelAdminSelectionLabel(array $panel)
{
    $namePanel = normalizePanelLookupLabel($panel['name_panel'] ?? '');
    return $namePanel . ' • ' . panelShortCodeSuffix($panel['code_panel'] ?? '');
}

function panelAdminCallbackData($callbackPrefix, $codePanel)
{
    $codePanel = trim((string) $codePanel);
    if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', $codePanel)) {
        return null;
    }
    $callbackData = (string) $callbackPrefix . $codePanel;
    return strlen($callbackData) <= 64 ? $callbackData : null;
}

function panelAdminSelectionDescriptor(array $panel, $callbackPrefix)
{
    $callbackData = panelAdminCallbackData($callbackPrefix, $panel['code_panel'] ?? '');
    if ($callbackData === null) {
        return null;
    }
    return [
        'text' => panelAdminSelectionLabel($panel),
        'callback_data' => $callbackData,
    ];
}

function panelDeletionDebugLog($event, array $context = [])
{
    $debugEnabled = (defined('APP_DEBUG') && APP_DEBUG)
        || (defined('MIRZA_TEST_MODE') && MIRZA_TEST_MODE);
    if (!$debugEnabled) {
        return;
    }

    $allowedKeys = ['callback_data', 'resolved_code_panel', 'stored_deletion_state', 'affected_rows'];
    $safeContext = [];
    foreach ($allowedKeys as $key) {
        if (array_key_exists($key, $context)) {
            $safeContext[$key] = is_scalar($context[$key]) ? (string) $context[$key] : '';
        }
    }
    error_log('[Panel Delete Debug] ' . preg_replace('/[^a-z0-9_.-]/i', '', (string) $event)
        . ' ' . json_encode($safeContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function generateUniquePanelCode($bytes = 2)
{
    global $pdo;

    $bytes = max(2, (int) $bytes);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM marzban_panel WHERE code_panel = :code_panel");
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $codePanel = bin2hex(random_bytes($bytes));
        $stmt->execute(['code_panel' => $codePanel]);
        if ((int) $stmt->fetchColumn() === 0) {
            return $codePanel;
        }
    }

    throw new RuntimeException('Unable to allocate a unique panel code.');
}

function resolvePanelDeletionSelection($storedValue)
{
    global $pdo;

    $storedValue = trim((string) $storedValue);
    if ($storedValue === '') {
        return null;
    }

    foreach (['code_panel', 'name_panel'] as $column) {
        $stmt = $pdo->prepare("SELECT * FROM marzban_panel WHERE {$column} = :panel_value LIMIT 2");
        $stmt->execute(['panel_value' => $storedValue]);
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($matches) === 1) {
            $matches[0]['resolved_from'] = $column;
            return $matches[0];
        }
        if (count($matches) > 1) {
            return null;
        }
    }

    return null;
}

function resolveAdminPanelState(array $user)
{
    foreach (['Processing_value_one', 'Processing_value'] as $field) {
        $storedValue = trim((string) ($user[$field] ?? ''));
        if ($storedValue === '') {
            continue;
        }
        $panel = resolvePanelDeletionSelection($storedValue);
        if ($panel) {
            return $panel;
        }
    }
    return null;
}

function panelDeletionPreviewByCode($codePanel)
{
    global $pdo;

    $panel = resolvePanelDeletionSelection($codePanel);
    if (!$panel || $panel['resolved_from'] !== 'code_panel') {
        return null;
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM product
        WHERE Location = :panel_code OR Location = :panel_name");
    $stmt->execute([
        'panel_code' => $panel['code_panel'],
        'panel_name' => $panel['name_panel'],
    ]);
    $panel['product_count'] = (int) $stmt->fetchColumn();
    return $panel;
}

function deletePanelByCode($codePanel)
{
    global $pdo;

    $codePanel = trim((string) $codePanel);
    if ($codePanel === '') {
        return ['status' => 'not_found', 'panel' => null, 'products' => [], 'affected_rows' => 0];
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT * FROM marzban_panel WHERE code_panel = :code_panel LIMIT 2 FOR UPDATE");
        $stmt->execute(['code_panel' => $codePanel]);
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($matches) === 0) {
            $pdo->rollBack();
            return ['status' => 'not_found', 'panel' => null, 'products' => [], 'affected_rows' => 0];
        }
        if (count($matches) > 1) {
            $pdo->rollBack();
            return ['status' => 'integrity_error', 'panel' => null, 'products' => [], 'affected_rows' => 0];
        }
        $panel = $matches[0];

        $stmt = $pdo->prepare("SELECT id, name_product, code_product FROM product
            WHERE Location = :panel_code OR Location = :panel_name ORDER BY id");
        $stmt->execute([
            'panel_code' => $panel['code_panel'],
            'panel_name' => $panel['name_panel'],
        ]);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($products) {
            $pdo->rollBack();
            return ['status' => 'has_products', 'panel' => $panel, 'products' => $products, 'affected_rows' => 0];
        }

        $stmt = $pdo->prepare("DELETE FROM marzban_panel WHERE code_panel = :code_panel");
        $stmt->execute(['code_panel' => $panel['code_panel']]);
        $affectedRows = $stmt->rowCount();
        panelDeletionDebugLog('delete_execute', [
            'resolved_code_panel' => $panel['code_panel'],
            'affected_rows' => $affectedRows,
        ]);
        if ($affectedRows === 0) {
            $pdo->rollBack();
            return ['status' => 'not_found', 'panel' => $panel, 'products' => [], 'affected_rows' => 0];
        }
        if ($affectedRows > 1) {
            $pdo->rollBack();
            return ['status' => 'integrity_error', 'panel' => $panel, 'products' => [], 'affected_rows' => $affectedRows];
        }
        if (!deleteCanonicalPanelEmojiMapping($panel['code_panel'])) {
            throw new RuntimeException('Panel emoji mapping cleanup failed.');
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM marzban_panel WHERE code_panel = :code_panel");
        $stmt->execute(['code_panel' => $panel['code_panel']]);
        if ((int) $stmt->fetchColumn() !== 0) {
            $pdo->rollBack();
            return ['status' => 'integrity_error', 'panel' => $panel, 'products' => [], 'affected_rows' => $affectedRows];
        }
        $pdo->commit();
        return ['status' => 'deleted', 'panel' => $panel, 'products' => [], 'affected_rows' => $affectedRows];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['status' => 'failed', 'panel' => null, 'products' => [], 'affected_rows' => 0];
    }
}

function saveProductEmojiMapping($codeProduct, $emojiKey)
{
    global $pdo;

    $codeProduct = trim((string) $codeProduct);
    $validation = validateProductEmojiKey($emojiKey);
    if ($codeProduct === '' || !$validation['ok']) {
        return false;
    }

    $stmt = $pdo->prepare("INSERT INTO styled_button_icons
        (source_type, source_key, icon_emoji_key) VALUES ('product', :source_key, :emoji_key)
        ON DUPLICATE KEY UPDATE icon_emoji_key = VALUES(icon_emoji_key)");
    $saved = $stmt->execute([
        'source_key' => $codeProduct,
        'emoji_key' => $validation['emoji_key'],
    ]);
    unset($GLOBALS['styled_runtime_source_icon_map']['product:' . $codeProduct]);
    return $saved;
}

function deleteProductEmojiMapping($codeProduct)
{
    global $pdo;

    $codeProduct = trim((string) $codeProduct);
    if ($codeProduct === '') {
        return true;
    }

    $stmt = $pdo->prepare("DELETE FROM styled_button_icons
        WHERE source_type = 'product' AND source_key = :source_key");
    $deleted = $stmt->execute(['source_key' => $codeProduct]);
    unset($GLOBALS['styled_runtime_source_icon_map']['product:' . $codeProduct]);
    return $deleted;
}

function cleanupProductEmojiMappingAfterDelete($codeProduct)
{
    global $pdo;

    $codeProduct = trim((string) $codeProduct);
    if ($codeProduct === '') {
        return true;
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM product WHERE code_product = :code_product");
    $stmt->execute(['code_product' => $codeProduct]);
    if ((int) $stmt->fetchColumn() > 0) {
        return true;
    }

    return deleteProductEmojiMapping($codeProduct);
}

function generateUniqueProductCode($bytes = 2)
{
    global $pdo;

    $bytes = max(2, (int) $bytes);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM product WHERE code_product = :code_product");
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $codeProduct = bin2hex(random_bytes($bytes));
        $stmt->execute(['code_product' => $codeProduct]);
        if ((int) $stmt->fetchColumn() === 0) {
            return $codeProduct;
        }
    }

    throw new RuntimeException('Unable to allocate a unique product code.');
}

function normalizePanelLookupLabel($value)
{
    $value = preg_replace('/\{emoji:[^{}]+\}\s*/iu', '', (string) $value);
    if (class_exists('Normalizer')) {
        $normalized = Normalizer::normalize($value, Normalizer::FORM_C);
        if (is_string($normalized)) {
            $value = $normalized;
        }
    }
    $value = preg_replace('/[\p{Z}\s\x{200B}\x{200C}\x{200D}\x{FEFF}]+/u', ' ', $value);
    return trim((string) $value);
}

function resolvePanelByIdentifier($identifier)
{
    global $pdo;

    $identifier = trim((string) $identifier);
    if ($identifier === '' || $identifier === '/all') {
        return null;
    }

    foreach (['code_panel', 'name_panel'] as $column) {
        $stmt = $pdo->prepare("SELECT * FROM marzban_panel WHERE {$column} = :identifier LIMIT 2");
        $stmt->execute(['identifier' => $identifier]);
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($matches) === 1) {
            return $matches[0];
        }
        if (count($matches) > 1) {
            return null;
        }
    }

    $visibleIdentifier = normalizePanelLookupLabel($identifier);
    if ($visibleIdentifier === '') {
        return null;
    }

    $stmt = $pdo->query("SELECT * FROM marzban_panel ORDER BY code_panel");
    $visibleMatches = [];
    while ($panel = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (normalizePanelLookupLabel($panel['name_panel'] ?? '') === $visibleIdentifier) {
            $visibleMatches[] = $panel;
            if (count($visibleMatches) > 1) {
                return null;
            }
        }
    }

    return $visibleMatches[0] ?? null;
}

function productPanelLocationValues($identifier)
{
    $identifier = trim((string) $identifier);
    if ($identifier === '/all') {
        return [
            'panel' => null,
            'code_panel' => '/all',
            'name_panel' => '/all',
        ];
    }

    $panel = resolvePanelByIdentifier($identifier);
    if (!$panel) {
        return [
            'panel' => null,
            'code_panel' => $identifier,
            'name_panel' => $identifier,
        ];
    }

    return [
        'panel' => $panel,
        'code_panel' => (string) $panel['code_panel'],
        'name_panel' => (string) $panel['name_panel'],
    ];
}

function productLocationMatchesPanel($location, $panelIdentifier)
{
    $location = trim((string) $location);
    if ($location === '/all') {
        return true;
    }

    $values = productPanelLocationValues($panelIdentifier);
    if ($location === $values['code_panel'] || $location === $values['name_panel']) {
        return true;
    }

    return normalizePanelLookupLabel($location) === normalizePanelLookupLabel($values['name_panel']);
}

function productLocationSqlCondition($panelIdentifier, $column = 'Location', $includeAll = true)
{
    global $pdo;

    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', (string) $column)) {
        throw new InvalidArgumentException('Invalid product location column.');
    }

    $values = productPanelLocationValues($panelIdentifier);
    $parts = [
        $column . ' = ' . $pdo->quote($values['code_panel']),
        $column . ' = ' . $pdo->quote($values['name_panel']),
    ];
    if ($includeAll) {
        $parts[] = $column . " = '/all'";
    }

    return '(' . implode(' OR ', array_values(array_unique($parts))) . ')';
}

function ProductPanelSelectionKeyboard($callbackPrefix, $includeAll = true, $backCallback = 'admin')
{
    global $pdo;

    $stmt = $pdo->query("SELECT code_panel, name_panel FROM marzban_panel ORDER BY id");
    $rows = [];
    while ($panel = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $action = ['callback_data' => $callbackPrefix . $panel['code_panel']];
        $button = function_exists('buildStyledPanelButton')
            ? buildStyledPanelButton($panel, $action)
            : array_merge(['text' => normalizePanelLookupLabel($panel['name_panel'])], $action);
        $rows[] = [$button];
    }
    if ($includeAll) {
        $rows[] = [[
            'text' => 'همه پنل‌ها',
            'callback_data' => $callbackPrefix . 'all',
        ]];
    }
    $rows[] = [[
        'text' => '▶️ بازگشت',
        'callback_data' => $backCallback,
    ]];

    return json_encode(
        ['inline_keyboard' => $rows],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
}
