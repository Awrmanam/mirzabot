<?php

require_once dirname(__DIR__) . '/panel_service.php';

$failures = [];
$check = function ($condition, string $message) use (&$failures): void {
    if ($condition) {
        echo "[PASS] {$message}\n";
        return;
    }
    $failures[] = $message;
    echo "[FAIL] {$message}\n";
};

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "[SKIP] pdo_sqlite is required for panel CRUD integration tests.\n");
    exit(77);
}

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec(
    'CREATE TABLE marzban_panel (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code_panel TEXT,
        name_panel TEXT,
        display_name TEXT,
        normalized_name TEXT,
        active_normalized_name TEXT UNIQUE,
        emoji_key TEXT,
        custom_emoji_id TEXT,
        status TEXT,
        deleted_at TEXT,
        url_panel TEXT,
        username_panel TEXT,
        password_panel TEXT,
        agent TEXT,
        linksubx TEXT,
        secret_code TEXT,
        limit_panel TEXT,
        time_usertest TEXT,
        val_usertest TEXT,
        inboundid TEXT,
        MethodUsername TEXT,
        Methodextend TEXT,
        datelogin TEXT
    )'
);
$pdo->exec(
    'CREATE TABLE invoice (
        id_invoice TEXT PRIMARY KEY,
        panel_id INTEGER NULL,
        Service_location TEXT
    )'
);
$pdo->exec(
    'CREATE TABLE manualsell (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        panel_id INTEGER NULL,
        codepanel TEXT,
        contentrecord TEXT
    )'
);
$pdo->exec(
    'CREATE TABLE product (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        panel_id INTEGER NULL,
        Location TEXT
    )'
);

$logs = [];
$service = new PanelService($pdo, function (string $message) use (&$logs): void {
    $logs[] = $message;
});

$insertPanel = function (string $name, string $code, array $entities = []) use ($pdo, $service): int {
    $identity = $service->identityColumns($name, $entities);
    $stmt = $pdo->prepare(
        "INSERT INTO marzban_panel
         (code_panel, name_panel, display_name, normalized_name, active_normalized_name,
          emoji_key, custom_emoji_id, status, deleted_at)
         VALUES
         (:code, :name, :display, :normalized, :active_normalized, :emoji_key,
          :custom_emoji_id, 'active', NULL)"
    );
    $stmt->execute([
        ':code' => $code,
        ':name' => $identity['name_panel'],
        ':display' => $identity['display_name'],
        ':normalized' => $identity['normalized_name'],
        ':active_normalized' => $identity['active_normalized_name'],
        ':emoji_key' => $identity['emoji_key'],
        ':custom_emoji_id' => $identity['custom_emoji_id'],
    ]);
    return (int) $pdo->lastInsertId();
};

$simpleId = $insertPanel('تهران', 'simple-code');
$premiumId = $insertPanel('🚀 شیراز', 'premium-code', [[
    'type' => 'custom_emoji',
    'offset' => 0,
    'length' => 2,
    'custom_emoji_id' => '5368324170671202286',
]]);
$check($simpleId !== $premiumId, 'two distinct panels receive distinct canonical ids');

$premium = $service->findActive($premiumId);
$check($premium['display_name'] === 'شیراز', 'premium panel stores a plain display name');
$check($premium['normalized_name'] === 'شیراز', 'premium fallback glyph is excluded from identity');
$check($premium['custom_emoji_id'] === '5368324170671202286', 'Telegram custom emoji id is separate');

$mashhadId = $insertPanel('مشهد', 'mashhad-code');
try {
    $insertPanel('🚀 مشهد', 'premium-mashhad-code', [[
        'type' => 'custom_emoji',
        'offset' => 0,
        'length' => 2,
        'custom_emoji_id' => '5368324170671202286',
    ]]);
    $check(false, 'Custom Emoji entity cannot bypass normalized duplicate detection');
} catch (PanelConflictException $e) {
    $check($service->findActive($mashhadId) !== null, 'Custom Emoji entity cannot bypass normalized duplicate detection');
}

try {
    $insertPanel('  شيراز‌ ', 'duplicate-code');
    $check(false, 'normalized duplicate is rejected');
} catch (PanelConflictException $e) {
    $check(true, 'normalized duplicate is rejected');
}

$pdo->prepare('INSERT INTO invoice VALUES (?, ?, ?)')->execute(['inv-1', $premiumId, 'شیراز']);
$pdo->prepare('INSERT INTO manualsell (panel_id, codepanel, contentrecord) VALUES (?, ?, ?)')
    ->execute([$premiumId, 'premium-code', 'historical sale']);
$pdo->prepare('INSERT INTO product (panel_id, Location) VALUES (?, ?)')->execute([$premiumId, 'شیراز']);

$renamed = $service->rename($premiumId, '{emoji:new-style} Shiraz 🚀');
$check($renamed['display_name'] === 'Shiraz 🚀', 'rename operates by canonical id');
$check(
    $pdo->query("SELECT Service_location FROM invoice WHERE id_invoice = 'inv-1'")->fetchColumn() === 'شیراز',
    'rename preserves the invoice name snapshot'
);
$check(
    $pdo->query('SELECT Location FROM product WHERE panel_id = ' . $premiumId)->fetchColumn() === '{emoji:new-style} Shiraz 🚀',
    'rename updates operational products by canonical id'
);

$delete = $service->softDelete($premiumId);
$check($delete['status'] === 'deleted', 'first delete commits one soft-deleted panel');
$check($service->findActive($premiumId) === null, 'deleted panel is absent from active reads');
$check((int) $pdo->query('SELECT COUNT(*) FROM invoice')->fetchColumn() === 1, 'delete preserves invoices');
$check((int) $pdo->query('SELECT COUNT(*) FROM manualsell')->fetchColumn() === 1, 'delete preserves manual sales');
$check(
    $pdo->query('SELECT Location FROM product LIMIT 1')->fetchColumn() === '@deleted-panel:' . $premiumId,
    'delete detaches products from the reusable display-name namespace'
);

$replay = $service->softDelete($premiumId);
$check($replay['status'] === 'already_deleted', 'replayed delete is idempotent');

$replacementId = $insertPanel('{emoji:another} Shiraz 🚀', 'replacement-code');
$check($replacementId !== $premiumId, 'recreated panel gets a new canonical id');
$service->softDelete($premiumId);
$check($service->findActive($replacementId) !== null, 'old callback cannot delete a recreated panel');

$rocketId = $insertPanel('تهران 🚀', 'rocket-code');
$planeId = $insertPanel('تهران ✈️', 'plane-code');
$check($rocketId !== $planeId, 'ordinary emoji distinguishes genuinely different names');

$pdo->exec(
    "CREATE TRIGGER fail_panel_delete
     BEFORE UPDATE OF deleted_at ON marzban_panel
     WHEN OLD.id = {$simpleId}
     BEGIN
       SELECT RAISE(ABORT, 'injected delete failure');
     END"
);
try {
    $service->softDelete($simpleId);
    $check(false, 'injected delete failure propagates');
} catch (Throwable $e) {
    $check(true, 'injected delete failure propagates');
}
$check($service->findActive($simpleId) !== null, 'failed delete rolls back the panel row');
$check(count($logs) > 0, 'failed delete is logged without reporting success');

if ($failures) {
    echo "\n" . count($failures) . " panel CRUD integration test(s) failed.\n";
    exit(1);
}

echo "\nAll panel CRUD integration tests passed.\n";
