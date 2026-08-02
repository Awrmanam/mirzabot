<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function premiumEmojiVerificationFail($message)
{
    fwrite(STDERR, '[FAILED] ' . (string) $message . PHP_EOL);
    exit(1);
}

$rootDirectory = dirname(__DIR__);
$requiredFiles = [
    'emoji_system.php',
    'emoji_install.php',
    'scripts/migrate_custom_emoji.php',
];
foreach ($requiredFiles as $requiredFile) {
    if (!is_file($rootDirectory . '/' . $requiredFile)) {
        premiumEmojiVerificationFail('Missing Premium Emoji file: ' . $requiredFile);
    }
}

require $rootDirectory . '/config.php';
if (!defined('CUSTOM_EMOJI_ENABLED') || CUSTOM_EMOJI_ENABLED !== true) {
    premiumEmojiVerificationFail('CUSTOM_EMOJI_ENABLED does not resolve to true.');
}
if (!isset($pdo) || !($pdo instanceof PDO)) {
    premiumEmojiVerificationFail('PDO is unavailable for Premium Emoji verification.');
}

$requiredTables = [
    'styled_emojis',
    'styled_settings',
    'styled_emoji_usage',
    'styled_emoji_logs',
    'styled_admin_sessions',
    'styled_button_icons',
    'styled_text_overrides',
];
$placeholders = implode(',', array_fill(0, count($requiredTables), '?'));
$tableStatement = $pdo->prepare(
    "SELECT TABLE_NAME FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$placeholders})"
);
$tableStatement->execute($requiredTables);
$existingTables = $tableStatement->fetchAll(PDO::FETCH_COLUMN);
$missingTables = array_values(array_diff($requiredTables, $existingTables));
if ($missingTables) {
    premiumEmojiVerificationFail('Missing styled tables: ' . implode(', ', $missingTables));
}

$markerPath = $rootDirectory . '/storage/cache/custom_emoji_schema.ready';
if (!is_file($markerPath)) {
    premiumEmojiVerificationFail('Premium Emoji schema marker is missing.');
}

$botApiSource = (string) file_get_contents($rootDirectory . '/botapi.php');
$indexSource = (string) file_get_contents($rootDirectory . '/index.php');
$adminSource = (string) file_get_contents($rootDirectory . '/admin.php');
$keyboardSource = (string) file_get_contents($rootDirectory . '/keyboard.php');
if (strpos($botApiSource, "require_once __DIR__ . '/emoji_system.php'") === false
    || strpos($indexSource, 'styledHandleUpdate()') === false
    || strpos($indexSource, "require_once 'admin.php'") === false
    || trim($adminSource) === ''
    || strpos($keyboardSource, 'styledEnhanceAdminKeyboard') === false) {
    premiumEmojiVerificationFail('Premium Emoji admin integration is incomplete.');
}

require_once $rootDirectory . '/emoji_system.php';
if (!styledSystemReady()) {
    premiumEmojiVerificationFail('Premium Emoji runtime is not ready after migration.');
}

$menuLabel = styledAppearanceMenuLabel(false);
$probeKeyboard = json_encode([
    'keyboard' => [[['text' => 'Back']]],
    'resize_keyboard' => true,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$enhancedKeyboard = json_decode(styledEnhanceAdminKeyboard($probeKeyboard), true);
$menuVisible = false;
foreach ((array) ($enhancedKeyboard['keyboard'] ?? []) as $row) {
    foreach ((array) $row as $button) {
        if ((string) ($button['text'] ?? '') === $menuLabel) {
            $menuVisible = true;
            break 2;
        }
    }
}
if (!$menuVisible) {
    premiumEmojiVerificationFail('Premium Emoji admin menu is not visible.');
}

fwrite(
    STDOUT,
    '[OK] Premium Emoji files, flags, schema, runtime wiring, and admin menu are ready.' . PHP_EOL
);
exit(0);
