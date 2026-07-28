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

?>
