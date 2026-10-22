<?php
$root = dirname(__DIR__);

function failCheck($message)
{
    fwrite(STDERR, "[FAIL] " . $message . PHP_EOL);
    exit(1);
}

function requireContains($haystack, $needle, $message)
{
    if (strpos($haystack, $needle) === false) {
        failCheck($message . " (missing: " . $needle . ")");
    }
}

$install = file_get_contents($root . '/install.sh');
$index = file_get_contents($root . '/index.php');
$functions = file_get_contents($root . '/function.php');
$keyboard = file_get_contents($root . '/keyboard.php');
$admin = file_get_contents($root . '/admin.php');
$emoji = file_get_contents($root . '/emoji_system.php');
$botapi = file_get_contents($root . '/botapi.php');

foreach ([
    'install.sh' => $install,
    'index.php' => $index,
    'function.php' => $functions,
    'keyboard.php' => $keyboard,
    'admin.php' => $admin,
    'emoji_system.php' => $emoji,
    'botapi.php' => $botapi,
] as $name => $contents) {
    if ($contents === false) {
        failCheck("Could not read {$name}.");
    }
}

$updateStart = strpos($install, 'function update_bot()');
$updateEnd = strpos($install, '# Delete Function for Mirza Pro', $updateStart);
if ($updateStart === false || $updateEnd === false) {
    failCheck('Could not isolate update_bot().');
}
$updateFunction = substr($install, $updateStart, $updateEnd - $updateStart);

requireContains($updateFunction, '--no-tablespaces', 'Updater must create privilege-safe database backups.');
requireContains($updateFunction, 'mirza-auto-backup-', 'Updater must create an automatic pre-update backup.');
requireContains($updateFunction, 'STAGE_DIR=', 'Updater must stage updates before activation.');
requireContains($updateFunction, 'ROLLBACK_DIR=', 'Updater must retain a rollback copy.');
requireContains($updateFunction, 'cp -a "$BOT_DIR"/. "$STAGE_DIR"/', 'Updater must preserve local runtime files before overlay.');
requireContains($updateFunction, 'php -l "$php_file"', 'Updater must syntax-check staged PHP files.');
requireContains($updateFunction, 'PRODUCT_COUNT_BEFORE', 'Updater must snapshot product row count.');
requireContains($updateFunction, 'PRODUCT_COUNT_AFTER', 'Updater must verify product row count after migration.');
requireContains($updateFunction, 'PANEL_COUNT_BEFORE', 'Updater must snapshot panel row count.');
requireContains($updateFunction, 'rollback_update', 'Updater must provide automatic rollback.');
requireContains($updateFunction, 'function styledCustomEmojiEnabled', 'Updater must reject releases missing Premium Emoji support.');
requireContains($updateFunction, 'function reserveUserTrialSlot', 'Updater must reject releases missing per-panel trials.');

if (strpos($updateFunction, 'apt upgrade') !== false || strpos($updateFunction, 'apt-get upgrade') !== false) {
    failCheck('Bot updater must not upgrade operating-system packages.');
}

requireContains($emoji, 'function styledCustomEmojiEnabled', 'Premium Emoji runtime integration is missing.');
requireContains($botapi, 'styledPrepareTelegramRequest', 'Telegram custom emoji request integration is missing.');
requireContains($keyboard, 'function KeyboardProduct', 'Product keyboard renderer is missing.');
requireContains($keyboard, 'function keyboardTimeCategory', 'Product duration keyboard renderer is missing.');
requireContains($index, 'SELECT * FROM product WHERE (Location = ', 'Product location query path is missing.');
requireContains($index, "OR Location = '/all'", 'Global product location fallback is missing.');
requireContains($index, 'KeyboardProduct(', 'Product selection flow is missing.');
requireContains($functions, 'function reserveUserTrialSlot', 'Atomic per-panel trial reservation helper is missing.');
requireContains($functions, 'function getUserEligibleTrialPanels', 'Per-panel trial eligibility helper is missing.');
requireContains($functions, 'user_trial_usage', 'Per-panel trial usage storage is missing.');
requireContains($admin, '♻️ ریست تست‌های کاربر', 'Per-user trial reset control is missing.');
requireContains($admin, 'confirm_reset_all_trial_usage', 'All-user trial reset confirmation is missing.');
requireContains($keyboard, 'getUserEligibleTrialPanels(', 'Trial panel keyboard is not filtered by per-panel usage.');

if (strpos($index, "\$limit_usertest = \$userlimit['limit_usertest'] - 1;") !== false) {
    failCheck('Legacy global trial decrement is still present.');
}

echo "[OK] updater, product display, Premium Emoji, and per-panel trial invariants are present." . PHP_EOL;
