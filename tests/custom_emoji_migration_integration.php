<?php

function integrationAssert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function runPhpScript($scriptPath)
{
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, $scriptPath],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start PHP migration process.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0) {
        throw new RuntimeException(
            'PHP script failed: ' . $scriptPath . PHP_EOL . $stdout . $stderr
        );
    }
    return $stdout;
}

function removeIntegrationDirectory($directory)
{
    if (!is_dir($directory)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($directory);
}

foreach (['mysqli', 'pdo_mysql', 'curl', 'mbstring'] as $module) {
    integrationAssert(extension_loaded($module), 'Required test PHP module is missing: ' . $module);
}

$host = getenv('MIRZA_TEST_DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('MIRZA_TEST_DB_PORT') ?: 3306);
$user = getenv('MIRZA_TEST_DB_USER') ?: 'root';
$password = getenv('MIRZA_TEST_DB_PASSWORD');
$password = $password === false ? '' : $password;
$database = 'mirza_emoji_test_' . strtolower(bin2hex(random_bytes(6)));
$root = dirname(__DIR__);
$temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mirza-emoji-' . bin2hex(random_bytes(6));

$serverPdo = new PDO(
    "mysql:host={$host};port={$port};charset=utf8mb4",
    $user,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

try {
    $serverPdo->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    mkdir($temporaryRoot . '/scripts', 0777, true);
    foreach ([
        'emoji_install.php',
        'emoji_system.php',
        'botapi.php',
        'index.php',
        'admin.php',
        'keyboard.php',
    ] as $file) {
        copy($root . '/' . $file, $temporaryRoot . '/' . $file);
    }
    copy(
        $root . '/scripts/migrate_custom_emoji.php',
        $temporaryRoot . '/scripts/migrate_custom_emoji.php'
    );
    copy(
        $root . '/scripts/verify_custom_emoji_install.php',
        $temporaryRoot . '/scripts/verify_custom_emoji_install.php'
    );
    file_put_contents($temporaryRoot . '/text.json', '{}');

    $config = <<<'PHP'
<?php
$dbhost = getenv('MIRZA_TEST_DB_HOST') ?: '127.0.0.1';
$dbname = getenv('MIRZA_TEST_DB_NAME');
$usernamedb = getenv('MIRZA_TEST_DB_USER') ?: 'root';
$passworddb = getenv('MIRZA_TEST_DB_PASSWORD') ?: '';
$dbport = (int) (getenv('MIRZA_TEST_DB_PORT') ?: 3306);
$connect = mysqli_connect($dbhost, $usernamedb, $passworddb, $dbname, $dbport);
if (!$connect) {
    throw new RuntimeException('Test mysqli connection failed.');
}
mysqli_set_charset($connect, 'utf8mb4');
$pdo = new PDO(
    "mysql:host={$dbhost};port={$dbport};dbname={$dbname};charset=utf8mb4",
    $usernamedb,
    $passworddb,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
if (!defined('CUSTOM_EMOJI_ENABLED')) {
    define('CUSTOM_EMOJI_ENABLED', true);
}
if (!defined('CUSTOM_EMOJI_USAGE_TRACKING')) {
    define('CUSTOM_EMOJI_USAGE_TRACKING', false);
}
if (!defined('APP_DEBUG')) {
    define('APP_DEBUG', false);
}
if (!defined('MIRZA_MEMORY_LIMIT')) {
    define('MIRZA_MEMORY_LIMIT', '256M');
}
PHP;
    file_put_contents($temporaryRoot . '/config.php', $config);
    putenv('MIRZA_TEST_DB_NAME=' . $database);

    $firstOutput = runPhpScript($temporaryRoot . '/scripts/migrate_custom_emoji.php');
    integrationAssert(strpos($firstOutput, '[OK]') !== false, 'First migration did not report success.');

    $databasePdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $requiredTables = [
        'styled_emojis',
        'styled_settings',
        'styled_emoji_usage',
        'styled_emoji_logs',
        'styled_admin_sessions',
        'styled_button_icons',
        'styled_text_overrides',
    ];
    $actualTables = $databasePdo->query(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'styled\\_%' ORDER BY TABLE_NAME"
    )->fetchAll(PDO::FETCH_COLUMN);
    sort($requiredTables);
    sort($actualTables);
    integrationAssert($actualTables === $requiredTables, 'Migration did not create the expected styled_* tables.');

    $firstState = [
        'emoji_count' => (int) $databasePdo->query('SELECT COUNT(*) FROM styled_emojis')->fetchColumn(),
        'settings_count' => (int) $databasePdo->query('SELECT COUNT(*) FROM styled_settings')->fetchColumn(),
        'schema_version' => (string) $databasePdo->query(
            "SELECT setting_value FROM styled_settings WHERE setting_key = 'schema_version'"
        )->fetchColumn(),
    ];
    integrationAssert($firstState['schema_version'] === '2', 'Schema version did not reach 2.');

    $secondOutput = runPhpScript($temporaryRoot . '/scripts/migrate_custom_emoji.php');
    integrationAssert(strpos($secondOutput, '[OK]') !== false, 'Second migration did not report success.');
    $secondState = [
        'emoji_count' => (int) $databasePdo->query('SELECT COUNT(*) FROM styled_emojis')->fetchColumn(),
        'settings_count' => (int) $databasePdo->query('SELECT COUNT(*) FROM styled_settings')->fetchColumn(),
        'schema_version' => (string) $databasePdo->query(
            "SELECT setting_value FROM styled_settings WHERE setting_key = 'schema_version'"
        )->fetchColumn(),
    ];
    integrationAssert($secondState === $firstState, 'Second migration changed idempotent schema state.');

    $verificationOutput = runPhpScript($temporaryRoot . '/scripts/verify_custom_emoji_install.php');
    integrationAssert(
        strpos($verificationOutput, 'admin menu are ready') !== false,
        'Post-install verifier did not confirm the Premium Emoji admin menu.'
    );

    fwrite(
        STDOUT,
        '[OK] Clean migration, second-run idempotency, styled_* tables, and admin menu verified.' . PHP_EOL
    );
} finally {
    $serverPdo->exec("DROP DATABASE IF EXISTS `{$database}`");
    removeIntegrationDirectory($temporaryRoot);
}
