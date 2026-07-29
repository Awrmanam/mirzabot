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

$read = function ($relativePath) use ($root) {
    $contents = file_get_contents($root . DIRECTORY_SEPARATOR . $relativePath);
    if ($contents === false) {
        throw new RuntimeException("Unable to read {$relativePath}");
    }
    return $contents;
};

$emojiSystem = $read('emoji_system.php');
$emojiInstall = $read('emoji_install.php');
$installer = $read('install.sh');

$check(strpos($emojiSystem, 'emoji_install.php') === false, 'emoji_system.php does not include emoji_install.php');
$check(
    strpos($emojiInstall, "defined('MIRZA_CUSTOM_EMOJI_MIGRATION')") !== false
        && strpos($emojiInstall, 'MIRZA_CUSTOM_EMOJI_MIGRATION !== true') !== false,
    'emoji_install.php requires explicit migration authorization'
);

$migrationCommand = 'php "$BOT_DIR/scripts/migrate_custom_emoji.php"';
$installStart = strpos($installer, 'function install_bot()');
$updateStart = strpos($installer, 'function update_bot()');
$removeStart = strpos($installer, 'function remove_bot()');
$freshInstallSection = substr($installer, $installStart, $updateStart - $installStart);
$updateSection = substr($installer, $updateStart, $removeStart - $updateStart);
$check(
    substr_count($freshInstallSection, $migrationCommand) === 1,
    'fresh install invokes the migration CLI once'
);
$check(
    substr_count($updateSection, $migrationCommand) === 1,
    'update invokes the migration CLI once'
);
$check(substr_count($installer, $migrationCommand) === 2, 'migration CLI is invoked only by fresh install and update');

$branch = 'premium-emoji-optimization-step1-20260729';
$check(
    strpos($installer, 'readonly MIRZA_SOURCE_BRANCH="' . $branch . '"') !== false,
    'install.sh pins the source branch'
);

$sourceLines = preg_grep(
    '~(?:raw\.githubusercontent\.com/Awrmanam/mirzabot|github\.com/Awrmanam/mirzabot/archive/refs/heads/)~',
    preg_split('/\R/', $installer)
);
$check(
    count($sourceLines) >= 3
        && count(array_filter($sourceLines, function ($line) {
            return strpos($line, '${MIRZA_SOURCE_BRANCH}') === false;
        })) === 0,
    'all installer source URLs use the pinned branch variable'
);

$forbiddenSources = [
    'premium-emoji-safe-test-20260729',
    'rebecca-support',
    'mahdiMGF2',
];
foreach ($forbiddenSources as $forbiddenSource) {
    $check(strpos($installer, $forbiddenSource) === false, "install.sh excludes {$forbiddenSource} source references");
}

$check(
    strpos($installer, 'cp -a "$EXTRACTED_DIR"/. "$BOT_DIR"/') !== false
        && strpos($installer, 'mv "$EXTRACTED_DIR"/*') === false,
    'install.sh uses complete-directory copy and no wildcard-only move'
);
$check(
    !preg_match('/marker|\.ready|\.installed|file_exists|is_file/i', $emojiSystem),
    'emoji_system.php has no marker-file dependency'
);
$check(
    strpos($emojiSystem, 'CUSTOM_EMOJI_ENABLED') === false,
    'emoji_system.php has no Custom Emoji feature flag'
);

$protectedFiles = [
    'botapi.php',
    'index.php',
    'keyboard.php',
    'admin.php',
    'function.php',
    'ticket_system.php',
    'ticket_install.php',
    'text.json',
];
$gitCommand = 'git -C ' . escapeshellarg($root) . ' diff --name-only HEAD -- '
    . implode(' ', array_map('escapeshellarg', $protectedFiles));
exec($gitCommand, $protectedChanges, $gitExitCode);
$check(
    $gitExitCode === 0 && count($protectedChanges) === 0,
    'protected working files are unchanged from HEAD'
);

if ($failures) {
    echo "\n" . count($failures) . " static check(s) failed.\n";
    exit(1);
}

echo "\nAll Custom Emoji Step 1 static checks passed.\n";
