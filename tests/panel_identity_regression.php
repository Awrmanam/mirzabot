<?php

$root = dirname(__DIR__);
$failures = [];

$check = function ($condition, $message) use (&$failures) {
    if ($condition) {
        echo "[PASS] {$message}\n";
        return;
    }

    $failures[] = $message;
    echo "[FAIL] {$message}\n";
};

$panelServicePath = $root . DIRECTORY_SEPARATOR . 'panel_service.php';
$check(is_file($panelServicePath), 'central panel identity service exists');

if (is_file($panelServicePath)) {
    require_once $panelServicePath;

    $check(
        panelNormalizeName('{emoji:premium}  تست‌ پنل ') === 'تست پنل',
        'internal Custom Emoji tokens and zero-width characters do not affect identity'
    );
    $check(
        panelNormalizeName('پنل يک') === panelNormalizeName('پنل یک'),
        'Arabic and Persian yeh/kaf variants normalize consistently'
    );
    $check(
        panelNormalizeName('TeHrAn') === 'tehran',
        'English name comparison is case-insensitive'
    );
    $check(
        panelNormalizeName('🚀 تهران') === '🚀 تهران',
        'ordinary emoji remains part of the normalized name'
    );

    $display = panelParseDisplayName('{emoji:premium} Tehran');
    $check($display['display_name'] === 'Tehran', 'display name is stored without the internal emoji token');
    $check($display['emoji_key'] === 'premium', 'Custom Emoji metadata is stored separately');

    $callback = panelCallbackData('confirm_delete', 123);
    $check($callback === 'panel:confirm_delete:123', 'panel callbacks use the canonical numeric id');
    $check(strlen($callback) <= 64, 'panel callback_data stays within Telegram 64-byte limit');
    $check(panelParseCallbackData('panel:delete:123')['panel_id'] === 123, 'callback parser validates numeric panel ids');
    $check(panelParseCallbackData('panel:delete:123oops') === null, 'malformed or replayed callback data is rejected');
}

$admin = file_get_contents($root . DIRECTORY_SEPARATOR . 'admin.php');
$keyboard = file_get_contents($root . DIRECTORY_SEPARATOR . 'keyboard.php');

$check(
    strpos($admin, "DELETE FROM marzban_panel WHERE name_panel") === false,
    'panel deletion never targets a display name'
);
$check(
    strpos($admin, 'panel:select:') !== false,
    'panel management selection uses an inline canonical-id callback'
);
$check(
    strpos($admin, 'panel:confirm_delete:') !== false,
    'panel deletion confirmation binds the canonical id'
);
$check(
    strpos($keyboard, "'callback_data' => panelCallbackData('select'") !== false,
    'management keyboard callbacks are generated from canonical ids'
);

if ($failures) {
    echo "\n" . count($failures) . " panel identity regression check(s) failed.\n";
    exit(1);
}

echo "\nAll panel identity regression checks passed.\n";
