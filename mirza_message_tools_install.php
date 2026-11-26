<?php
/**
 * Additive schema for message publishing + old Telegram reply keyboard cleanup.
 * Run ONLY from CLI: php8.4 mirza_message_tools_install.php
 * Does not alter Mirza payment, product, user or admin tables.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
require_once __DIR__ . '/config.php';

$statements = [
    "CREATE TABLE IF NOT EXISTS mirza_msg_sessions (
        admin_id VARCHAR(64) NOT NULL PRIMARY KEY,
        stage VARCHAR(40) NOT NULL,
        payload TEXT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS mirza_msg_keyboard_cleanup (
        user_id VARCHAR(64) NOT NULL PRIMARY KEY,
        status VARCHAR(16) NOT NULL DEFAULT 'pending',
        attempts INT UNSIGNED NOT NULL DEFAULT 0,
        last_error VARCHAR(255) NOT NULL DEFAULT '',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_cleanup_status (status, updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS mirza_msg_settings (
        name VARCHAR(64) NOT NULL PRIMARY KEY,
        value VARCHAR(255) NOT NULL DEFAULT ''
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS mirza_msg_publications (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        admin_id VARCHAR(64) NOT NULL,
        destination VARCHAR(100) NOT NULL,
        message_id BIGINT NOT NULL,
        source_message_id BIGINT NOT NULL,
        button_title VARCHAR(255) NOT NULL,
        button_url VARCHAR(2048) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_pub_admin (admin_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
];

foreach ($statements as $sql) {
    $pdo->exec($sql);
}
$pdo->exec("INSERT IGNORE INTO mirza_msg_settings (name, value) VALUES ('cleanup_running', '0')");
echo "OK: message tools schema is installed (4 additive tables).\n";
