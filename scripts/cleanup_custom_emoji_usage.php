<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$rootDirectory = dirname(__DIR__);
require $rootDirectory . '/config.php';

$retentionDays = isset($argv[1]) ? max(1, (int) $argv[1]) : 90;
$batchSize = isset($argv[2]) ? max(1, min(5000, (int) $argv[2])) : 500;
$cutoff = gmdate('Y-m-d H:i:s', time() - ($retentionDays * 86400));

try {
    $stmt = $pdo->prepare(
        "DELETE FROM styled_emoji_usage
         WHERE last_seen_at < ?
         ORDER BY last_seen_at
         LIMIT {$batchSize}"
    );
    $stmt->execute([$cutoff]);
    fwrite(STDOUT, '[OK] Deleted ' . $stmt->rowCount() . ' expired usage rows.' . PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[FAILED] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
