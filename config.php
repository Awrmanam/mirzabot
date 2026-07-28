<?php
// This variable added for high load panels which their response time is long and bot can't communicate with online panel!
// null for default settings
$request_exec_timeout = null;
$dbhost = '{database_url}';
$dbname = '{database_name}';
$usernamedb = '{username_db}';
$passworddb = '{password_db}';
$connect = mysqli_connect($dbhost, $usernamedb, $passworddb, $dbname);
if ($connect->connect_error) { die("error" . $connect->connect_error); }
mysqli_set_charset($connect, "utf8mb4");
$options = [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, ];
$dsn = "mysql:host=$dbhost;dbname=$dbname;charset=utf8mb4";
try { $pdo = new PDO($dsn, $usernamedb, $passworddb, $options); } catch (\PDOException $e) { error_log("Database connection failed: " . $e->getMessage()); }
$APIKEY = '{API_KEY}';
$adminnumber = '{admin_number}';
$domainhosts = '{domain_name}';
$usernamebot = '{username_bot}';

// Global Custom Emoji feature flag. Disabled by default.
// Enable with the CUSTOM_EMOJI_ENABLED=true environment variable or by
// defining the constant before this file is loaded.
if (!defined('CUSTOM_EMOJI_ENABLED')) {
    $customEmojiFeatureFlag = getenv('CUSTOM_EMOJI_ENABLED');
    define(
        'CUSTOM_EMOJI_ENABLED',
        $customEmojiFeatureFlag !== false
            ? filter_var($customEmojiFeatureFlag, FILTER_VALIDATE_BOOLEAN)
            : false
    );
}

// Usage statistics are intentionally disabled by default. This flag only
// controls statistics writes and never disables Custom Emoji rendering.
if (!defined('CUSTOM_EMOJI_USAGE_TRACKING')) {
    $customEmojiUsageFlag = getenv('CUSTOM_EMOJI_USAGE_TRACKING');
    define(
        'CUSTOM_EMOJI_USAGE_TRACKING',
        $customEmojiUsageFlag !== false
            ? filter_var($customEmojiUsageFlag, FILTER_VALIDATE_BOOLEAN)
            : false
    );
}

if (!defined('APP_DEBUG')) {
    $appDebugFlag = getenv('APP_DEBUG');
    define(
        'APP_DEBUG',
        $appDebugFlag !== false
            ? filter_var($appDebugFlag, FILTER_VALIDATE_BOOLEAN)
            : false
    );
}

if (!defined('MIRZA_MEMORY_LIMIT')) {
    $configuredMemoryLimit = trim((string) getenv('MIRZA_MEMORY_LIMIT'));
    if (!preg_match('/^[1-9][0-9]*[KMG]$/i', $configuredMemoryLimit)) {
        $configuredMemoryLimit = '256M';
    }
    define('MIRZA_MEMORY_LIMIT', strtoupper($configuredMemoryLimit));
}

?>
