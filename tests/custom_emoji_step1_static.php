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
$installerCommon = $read('scripts/installer/common.sh');

$check(strpos($emojiSystem, 'emoji_install.php') === false, 'emoji_system.php does not include emoji_install.php');
$check(
    strpos($emojiInstall, "defined('MIRZA_CUSTOM_EMOJI_MIGRATION')") !== false
        && strpos($emojiInstall, 'MIRZA_CUSTOM_EMOJI_MIGRATION !== true') !== false,
    'emoji_install.php requires explicit migration authorization'
);

$check(
    substr_count($installerCommon, 'php "$MIRZA_PREPARED_RELEASE/scripts/migrate_custom_emoji.php"') === 1,
    'central migration state invokes the Custom Emoji migration once'
);
$check(
    strpos($installer, 'run_migrations') !== false,
    'fresh install invokes the central migration state'
);
$check(
    strpos($installerCommon, 'MIRZA_RELEASE_SHA256 is required') !== false,
    'installer requires an immutable release checksum'
);

$sourceLines = preg_grep(
    '~github\.com/\$\{MIRZA_REPOSITORY\}/archive/\$\{MIRZA_RELEASE_REF\}\.zip~',
    preg_split('/\R/', $installerCommon)
);
$check(
    count($sourceLines) === 1,
    'installer has one release-ref archive source'
);

$forbiddenSources = [
    'premium-emoji-safe-test-20260729',
    'rebecca-support',
    'mahdiMGF2',
];
foreach ($forbiddenSources as $forbiddenSource) {
    $check(strpos($installer . $installerCommon, $forbiddenSource) === false, "installer excludes {$forbiddenSource} source references");
}

$check(
    strpos($installerCommon, 'cp -a "$source_dir"/. "$MIRZA_PREPARED_RELEASE"/') !== false
        && strpos($installerCommon, 'mv "$EXTRACTED_DIR"/*') === false,
    'installer stages a complete directory without wildcard-only moves'
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
