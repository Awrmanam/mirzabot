<?php
/**
 * CLI-only one-batch cleanup worker. Install as a low-rate cron job with flock.
 * This only sends a one-time ReplyKeyboardRemove and new inline menu to customers.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/botapi.php';
require_once dirname(__DIR__) . '/mirza_message_tools.php';

try {
    $result = mmtBulkRunOnce(12);
    echo date('c') . ' ' . json_encode($result, JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $e) {
    error_log('[mirza_message_tools] Cron failed: ' . $e->getMessage());
    fwrite(STDERR, "ERROR: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
