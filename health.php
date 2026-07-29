<?php

header('Content-Type: application/json; charset=UTF-8');

try {
    require __DIR__ . '/config.php';
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('PDO is unavailable');
    }
    $databaseReady = (int) $pdo->query('SELECT 1')->fetchColumn() === 1;
    $requiredFilesReady =
        is_file(__DIR__ . '/index.php')
        && is_file(__DIR__ . '/emoji_system.php')
        && is_file(__DIR__ . '/panel_service.php');

    if (!$databaseReady || !$requiredFilesReady) {
        throw new RuntimeException('application readiness check failed');
    }

    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'database' => true,
        'release' => true,
    ]);
    if (PHP_SAPI === 'cli') {
        echo "\n";
    }
} catch (Throwable $e) {
    error_log('health check failed: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode([
        'ok' => false,
        'database' => false,
        'release' => false,
    ]);
    if (PHP_SAPI === 'cli') {
        echo "\n";
        exit(1);
    }
}
