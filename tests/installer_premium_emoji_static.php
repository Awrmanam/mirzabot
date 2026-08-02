<?php

function installerStaticAssert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, '[FAIL] ' . $message . PHP_EOL);
        exit(1);
    }
}

$root = dirname(__DIR__);
$installer = (string) file_get_contents($root . '/install.sh');
$migration = (string) file_get_contents($root . '/emoji_install.php');
$index = (string) file_get_contents($root . '/index.php');
$botApi = (string) file_get_contents($root . '/botapi.php');
$keyboard = (string) file_get_contents($root . '/keyboard.php');

foreach ([
    'emoji_system.php',
    'emoji_install.php',
    'scripts/migrate_custom_emoji.php',
    'scripts/verify_custom_emoji_install.php',
] as $requiredFile) {
    installerStaticAssert(is_file($root . '/' . $requiredFile), 'Missing package file: ' . $requiredFile);
    installerStaticAssert(
        strpos($installer, '"' . $requiredFile . '"') !== false,
        'Installer does not verify package file: ' . $requiredFile
    );
}

installerStaticAssert(
    strpos($installer, 'MIRZA_SOURCE_BRANCH="fix/installer-premium-emoji-activation"') !== false
        && strpos($installer, 'ZIP_URL="$MIRZA_SOURCE_ARCHIVE_URL"') !== false
        && strpos($installer, 'rebecca-support.zip') === false,
    'Installer package source does not point to the Premium Emoji integration branch.'
);

foreach ([
    "if (!defined('CUSTOM_EMOJI_ENABLED')) {\n    define('CUSTOM_EMOJI_ENABLED', true);\n}",
    "if (!defined('CUSTOM_EMOJI_USAGE_TRACKING')) {\n    define('CUSTOM_EMOJI_USAGE_TRACKING', false);\n}",
    "if (!defined('APP_DEBUG')) {\n    define('APP_DEBUG', false);\n}",
    "if (!defined('MIRZA_MEMORY_LIMIT')) {\n    define('MIRZA_MEMORY_LIMIT', '256M');\n}",
] as $guardedDefinition) {
    installerStaticAssert(
        strpos(str_replace("\r\n", "\n", $installer), $guardedDefinition) !== false,
        'Fresh config template is missing a guarded runtime definition.'
    );
}

$tableSetupPosition = strpos($installer, 'url="https://${YOUR_DOMAIN}/table.php"');
$migrationPosition = strpos($installer, 'install_premium_emoji_schema "$BOT_DIR"');
installerStaticAssert(
    $tableSetupPosition !== false
        && $migrationPosition !== false
        && $migrationPosition > $tableSetupPosition,
    'Premium Emoji migration is not ordered after the main table setup.'
);

installerStaticAssert(
    substr_count($installer, 'for module in mysqli pdo_mysql curl mbstring; do') >= 2
        && strpos($installer, "extension_loaded('\$module')") !== false
        && strpos($installer, 'php${php_version}-mysql') !== false
        && strpos($installer, 'php${php_version}-curl') !== false
        && strpos($installer, 'php${php_version}-mbstring') !== false
        && strpos($installer, "Required PHP module '") !== false,
    'Missing-module installation or clear failure handling is absent.'
);

installerStaticAssert(
    strpos($migration, 'SHOW TABLES LIKE ?') === false
        && strpos($migration, 'information_schema.TABLES') !== false,
    'Migration still uses incompatible prepared SHOW TABLES LIKE syntax.'
);
installerStaticAssert(
    strpos($botApi, "require_once __DIR__ . '/emoji_system.php'") !== false
        && strpos($index, 'styledHandleUpdate()') !== false
        && strpos($index, "require_once 'admin.php'") !== false
        && strpos($keyboard, 'styledEnhanceAdminKeyboard') !== false,
    'Premium Emoji admin handler/menu wiring is incomplete.'
);

$updateStart = strpos($installer, 'function update_bot()');
$updateEnd = strpos($installer, 'function remove_bot()', $updateStart);
$updateBlock = substr($installer, $updateStart, $updateEnd - $updateStart);
installerStaticAssert(
    strpos($updateBlock, 'cp "$CONFIG_PATH" "$TEMP_CONFIG"') !== false
        && strpos($updateBlock, 'mv "$TEMP_CONFIG" "$CONFIG_PATH"') !== false,
    'Update flow no longer preserves the existing config.php.'
);

fwrite(STDOUT, '[OK] Premium Emoji installer static checks passed.' . PHP_EOL);
exit(0);
