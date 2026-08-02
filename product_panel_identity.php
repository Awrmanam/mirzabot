<?php

function containsLiteralPremiumEmojiToken($value)
{
    return preg_match('/\{emoji:[^{}]+\}/iu', (string) $value) === 1;
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
