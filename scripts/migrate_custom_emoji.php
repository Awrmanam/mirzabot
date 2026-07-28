<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$migrationMemoryLimit = trim((string) getenv('MIRZA_MIGRATION_MEMORY_LIMIT'));
if (!preg_match('/^[1-9][0-9]*[KMG]$/i', $migrationMemoryLimit)) {
    $migrationMemoryLimit = '512M';
}
ini_set('memory_limit', strtoupper($migrationMemoryLimit));

define('MIRZA_CUSTOM_EMOJI_MIGRATION', true);

$rootDirectory = dirname(__DIR__);
require $rootDirectory . '/config.php';
require $rootDirectory . '/emoji_install.php';

$result = $GLOBALS['mirza_custom_emoji_migration_result'] ?? [
    'ok' => false,
    'message' => 'Migration did not return a result.',
];

if (empty($result['ok'])) {
    fwrite(STDERR, '[FAILED] ' . (string) ($result['message'] ?? 'Unknown migration error.') . PHP_EOL);
    exit(1);
}

require_once $rootDirectory . '/emoji_system.php';
$validatedCount = 0;
$invalidCount = 0;
try {
    $pendingRows = $pdo->query(
        "SELECT id, custom_emoji_id
         FROM styled_emojis
         WHERE custom_emoji_id <> '' AND is_valid IS NULL"
    )->fetchAll(PDO::FETCH_ASSOC);
    $validationUpdate = $pdo->prepare(
        "UPDATE styled_emojis
         SET is_valid = ?, validated_at = NOW()
         WHERE id = ?"
    );
    foreach ($pendingRows as $pendingRow) {
        $isValid = styledValidateCustomEmojiId((string) $pendingRow['custom_emoji_id']);
        $validationUpdate->execute([$isValid ? 1 : 0, (int) $pendingRow['id']]);
        $isValid ? $validatedCount++ : $invalidCount++;
    }
} catch (Throwable $validationError) {
    fwrite(STDERR, '[WARNING] Validation step: ' . $validationError->getMessage() . PHP_EOL);
}

fwrite(STDOUT, '[OK] ' . (string) $result['message'] . PHP_EOL);
fwrite(
    STDOUT,
    sprintf('[OK] Legacy validation: valid=%d, fallback=%d', $validatedCount, $invalidCount) . PHP_EOL
);
fwrite(STDOUT, 'No table, Emoji Key, Custom Emoji ID, fallback, or setting was deleted.' . PHP_EOL);
exit(0);
