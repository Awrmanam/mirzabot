<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "[FAILED] This migration can only run from the command line.\n";
    exit(1);
}

define('MIRZA_CUSTOM_EMOJI_MIGRATION', true);

require __DIR__ . '/../config.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "[FAILED] PDO is not available.\n");
    exit(1);
}

require __DIR__ . '/../emoji_install.php';

$result = $GLOBALS['mirza_custom_emoji_migration_result'] ?? [
    'ok' => false,
    'message' => 'Custom Emoji migration returned no result.',
];
$message = isset($result['message']) ? (string) $result['message'] : 'Unknown migration result.';

if (($result['ok'] ?? false) === true) {
    echo '[OK] ' . $message . "\n";
    exit(0);
}

fwrite(STDERR, '[FAILED] ' . $message . "\n");
exit(1);
