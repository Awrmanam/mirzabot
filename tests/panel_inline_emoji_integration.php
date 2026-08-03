<?php

function panelEmojiFail($message)
{
    throw new RuntimeException($message);
}

function panelEmojiAssert($condition, $message)
{
    if (!$condition) {
        panelEmojiFail($message);
    }
}

$host = getenv('MIRZA_TEST_DB_HOST') ?: '127.0.0.1';
$port = getenv('MIRZA_TEST_DB_PORT') ?: '3306';
$user = getenv('MIRZA_TEST_DB_USER') ?: 'root';
$password = getenv('MIRZA_TEST_DB_PASSWORD');
$password = $password === false ? '' : $password;
$database = 'mirza_panel_emoji_' . bin2hex(random_bytes(5));

$server = new PDO(
    "mysql:host={$host};port={$port};charset=utf8mb4",
    $user,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$server->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $pdo->exec("CREATE TABLE styled_emojis (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `key` VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
        custom_emoji_id VARCHAR(64) NOT NULL,
        fallback_emoji VARCHAR(32) NOT NULL DEFAULT '',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        is_valid TINYINT(1) NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE styled_button_icons (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        source_type VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        source_key VARCHAR(190) NOT NULL,
        icon_emoji_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        UNIQUE KEY uq_source (source_type, source_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE marzban_panel (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        code_panel VARCHAR(64) NOT NULL,
        name_panel VARCHAR(190) NOT NULL,
        type VARCHAR(40) NOT NULL,
        version_panel VARCHAR(10) NOT NULL DEFAULT '0'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE product (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name_product VARCHAR(190) NOT NULL,
        code_product VARCHAR(64) NOT NULL,
        Location VARCHAR(190) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("INSERT INTO styled_emojis
        (`key`, custom_emoji_id, fallback_emoji, is_active, is_valid) VALUES
        ('diamond', '5368324170671202286', '💎', 1, 1),
        ('lightning', '5368324170671202287', '⚡', 1, 1),
        ('inactive', '5368324170671202288', '❌', 0, 1)");

    define('CUSTOM_EMOJI_ENABLED', true);
    define('CUSTOM_EMOJI_USAGE_TRACKING', false);
    define('MIRZA_TEST_MODE', true);
    $GLOBALS['styled_test_schema_ready'] = true;

    require_once dirname(__DIR__) . '/product_panel_identity.php';
    require_once dirname(__DIR__) . '/emoji_system.php';

    $promaxInput = extractPanelEmojiToken('{emoji:diamond}  Promax');
    panelEmojiAssert($promaxInput['ok'], 'Valid leading panel token was rejected.');
    panelEmojiAssert($promaxInput['name_panel'] === 'Promax', 'Leading token removal did not normalize whitespace.');
    panelEmojiAssert($promaxInput['emoji_key'] === 'diamond', 'Leading panel emoji key was not retained.');

    $liteInput = extractPanelEmojiToken('Lite   {emoji:lightning}');
    panelEmojiAssert($liteInput['ok'], 'Valid trailing panel token was rejected.');
    panelEmojiAssert($liteInput['name_panel'] === 'Lite', 'Trailing token removal did not normalize whitespace.');
    panelEmojiAssert($liteInput['emoji_key'] === 'lightning', 'Trailing panel emoji key was not retained.');

    panelEmojiAssert(extractPanelEmojiToken('Plain پنل')['ok'], 'Plain Unicode panel name was rejected.');
    panelEmojiAssert(extractPanelEmojiToken('{emoji:unknown} Promax')['error'] === 'unknown_key', 'Unknown panel emoji was not rejected.');
    panelEmojiAssert(extractPanelEmojiToken('{emoji:inactive} Promax')['error'] === 'inactive_key', 'Inactive panel emoji was not rejected.');
    panelEmojiAssert(extractPanelEmojiToken('{emoji:diamond Promax')['error'] === 'invalid_syntax', 'Malformed panel token was not rejected.');
    panelEmojiAssert(extractPanelEmojiToken('{emoji:diamond} Promax {emoji:lightning}')['error'] === 'multiple_tokens', 'Multiple panel tokens were not rejected.');
    panelEmojiAssert(extractPanelEmojiToken('{emoji:diamond}')['error'] === 'empty_name', 'Token-only panel name was not rejected.');

    $promaxCode = generateUniquePanelCode(2);
    $stmt = $pdo->prepare("INSERT INTO marzban_panel (code_panel, name_panel, type, version_panel)
        VALUES (:code_panel, :name_panel, 'marzban', '2')");
    $stmt->execute(['code_panel' => $promaxCode, 'name_panel' => $promaxInput['name_panel']]);
    panelEmojiAssert(savePanelEmojiMapping($promaxCode, $promaxInput['emoji_key']), 'Promax panel mapping was not saved.');

    $liteCode = generateUniquePanelCode(2);
    $stmt->execute(['code_panel' => $liteCode, 'name_panel' => $liteInput['name_panel']]);
    panelEmojiAssert(savePanelEmojiMapping($liteCode, $liteInput['emoji_key']), 'Lite panel mapping was not saved.');

    $panels = $pdo->query("SELECT * FROM marzban_panel ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    panelEmojiAssert($panels[0]['name_panel'] === 'Promax', 'Promax business name contains presentation metadata.');
    panelEmojiAssert($panels[1]['name_panel'] === 'Lite', 'Lite business name contains presentation metadata.');
    foreach ($panels as $panel) {
        foreach (['name_panel', 'code_panel', 'type', 'version_panel'] as $field) {
            panelEmojiAssert(strpos($panel[$field], '{emoji:') === false, "Literal token polluted {$field}.");
        }
    }

    $mapping = $pdo->query("SELECT * FROM styled_button_icons
        WHERE source_type = 'panel' AND source_key = " . $pdo->quote($promaxCode))->fetch(PDO::FETCH_ASSOC);
    panelEmojiAssert($mapping && $mapping['icon_emoji_key'] === 'diamond', 'Panel mapping is not keyed by code_panel.');

    $GLOBALS['styled_runtime_emoji_cache']['diamond'] = [
        'key' => 'diamond',
        'custom_emoji_id' => '5368324170671202286',
        'fallback_emoji' => '💎',
        'resolved_fallback' => '💎',
        'is_active' => 1,
        'is_valid' => 1,
    ];
    $promax = resolvePanelByIdentifier($promaxCode);
    $button = buildStyledPanelButton($promax, ['callback_data' => 'location_' . $promaxCode]);
    [$telegramButton] = styledPrepareButton($button, 'test:panel-inline-emoji');
    panelEmojiAssert($telegramButton['text'] === 'Promax', 'Rendered panel button contains the literal token.');
    panelEmojiAssert(($telegramButton['icon_custom_emoji_id'] ?? '') === '5368324170671202286', 'Panel button did not render the Premium Emoji.');

    $selectedPanel = resolvePanelByIdentifier('Promax');
    panelEmojiAssert($selectedPanel && $selectedPanel['code_panel'] === $promaxCode, 'Visible Promax selection did not resolve to code_panel.');
    $pdo->prepare("INSERT INTO product (name_product, code_product, Location) VALUES ('Plan', 'product_1', :location)")
        ->execute(['location' => $selectedPanel['code_panel']]);
    panelEmojiAssert($pdo->query("SELECT Location FROM product WHERE code_product = 'product_1'")->fetchColumn() === $promaxCode, 'Product Location did not retain code_panel.');

    $pdo->prepare("UPDATE marzban_panel SET name_panel = 'Promax Renamed' WHERE code_panel = :code_panel")
        ->execute(['code_panel' => $promaxCode]);
    panelEmojiAssert(
        $pdo->query("SELECT icon_emoji_key FROM styled_button_icons WHERE source_type = 'panel' AND source_key = " . $pdo->quote($promaxCode))->fetchColumn() === 'diamond',
        'Plain panel rename detached the mapping.'
    );
    panelEmojiAssert(savePanelEmojiMapping($promaxCode, 'lightning'), 'Panel rename could not update its mapping.');
    panelEmojiAssert(
        $pdo->query("SELECT icon_emoji_key FROM styled_button_icons WHERE source_type = 'panel' AND source_key = " . $pdo->quote($promaxCode))->fetchColumn() === 'lightning',
        'Panel rename stored the wrong replacement emoji.'
    );

    $pdo->prepare("INSERT INTO styled_button_icons (source_type, source_key, icon_emoji_key) VALUES
        ('product', :promax_code, 'diamond'),
        ('marzban_panel', :promax_code, 'diamond')")
        ->execute(['promax_code' => $promaxCode]);
    $pdo->prepare("DELETE FROM marzban_panel WHERE code_panel = :code_panel")->execute(['code_panel' => $promaxCode]);
    panelEmojiAssert(deletePanelEmojiMapping($promaxCode), 'Panel mapping cleanup failed.');
    panelEmojiAssert(
        (int) $pdo->query("SELECT COUNT(*) FROM styled_button_icons WHERE source_type IN ('panel', 'marzban_panel') AND source_key = " . $pdo->quote($promaxCode))->fetchColumn() === 0,
        'Deleted panel left a panel mapping behind.'
    );
    panelEmojiAssert(
        (int) $pdo->query("SELECT COUNT(*) FROM styled_button_icons WHERE source_type = 'product' AND source_key = " . $pdo->quote($promaxCode))->fetchColumn() === 1,
        'Panel deletion removed a product mapping.'
    );
    panelEmojiAssert(
        (int) $pdo->query("SELECT COUNT(*) FROM styled_button_icons WHERE source_type = 'panel' AND source_key = " . $pdo->quote($liteCode))->fetchColumn() === 1,
        'Panel deletion removed another panel mapping.'
    );

    echo "[OK] inline panel emoji extraction, stable mapping, rendering, selection, rename, and cleanup verified.\n";
} finally {
    $server->exec("DROP DATABASE IF EXISTS `{$database}`");
}
