<?php

function mirzaEnforceTelegramWebhookSecret(string $expectedSecret): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    if ($expectedSecret === '') {
        http_response_code(503);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'webhook_not_configured']);
        exit;
    }

    $providedSecret = (string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
    if ($providedSecret === '' || !hash_equals($expectedSecret, $providedSecret)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'forbidden']);
        exit;
    }
}
