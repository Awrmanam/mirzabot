<?php

// Timeout for panels with slow response times.
// Keep null to use the default timeout.
$request_exec_timeout = null;

// Database configuration
$dbhost = '{database_url}';
$dbname = '{database_name} null to use the default timeout.
$request_exec_timeout = null;

// Database';
$usernamedb = '{username_db}';
$passworddb = '{password_db}';

// MySQLi connection
$connect = mysqli_connect($dbhost, $usernamedb, $passworddb, $dbname);

if ($connect === false) {
    die('Database connection error: ' . mysqli_connect_error());
}

mysqli_set_charset($connect, 'utf8mb4');

// PDO connection
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

$dsn = "mysql:host={$dbhost};dbname={$dbname};charset=utf8mb4";
$pdo = null;

try {
    $pdo = new PDO($dsn, $usernamedb, $passworddb, $options);
} catch (PDOException $e) {
    error_log('Database connection failed: ' . $e->getMessage());
}

// Bot configuration
$APIKEY = '{API_KEY}';
$adminnumber = '{admin_number}';
$domainhosts = '{domain_name}';
$usernamebot = '{username_bot}';

// Custom Emoji is enabled by default.
// It can be disabled through CUSTOM_EMOJI_ENABLED=false.
$customEmojiFlag = getenv('CUSTOM_EMOJI_ENABLED');

if (!defined('CUSTOM_EMOJI_ENABLED')) {
    define(
        'CUSTOM_EMOJI_ENABLED',
        $customEmojiFlag !== false
            ? filter_var($customEmojiFlag, FILTER_VALIDATE_BOOLEAN)
            : true
    );
}

// Usage tracking is disabled by default.
$customEmojiUsageFlag = getenv('CUSTOM_EMOJI_USAGE_TRACKING');

if (!defined('CUSTOM_EMOJI_USAGE_TRACKING')) {
    define(
        'CUSTOM_EMOJI_USAGE_TRACKING',
        $customEmojiUsageFlag !== false
            ? filter_var($customEmojiUsageFlag, FILTER_VALIDATE_BOOLEAN)
            : false
    );
}

// Debug mode is disabled by default.
$appDebugFlag = getenv('APP_DEBUG');

if (!defined('APP_DEBUG')) {
    define(
        'APP_DEBUG',
        $appDebugFlag !== false
            ? filter_var($appDebugFlag, FILTER_VALIDATE_BOOLEAN)
            : false
    );
}

// PHP memory limit
$configuredMemoryLimit = trim((string) getenv('MIRZA_MEMORY_LIMIT'));

if (!preg_match('/^[1-9][0-9]*[KMG]$/i', $configuredMemoryLimit)) {
    $configuredMemoryLimit = '256M';
}

if (!defined('MIRZA_MEMORY_LIMIT')) {
    define('MIRZA_MEMORY_LIMIT', strtoupper($configuredMemoryLimit));
}

ini_set('memory_limit', MIRZA_MEMORY_LIMIT);
