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
