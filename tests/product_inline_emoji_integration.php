<?php

function inlineEmojiFail($message)
{
    throw new RuntimeException($message);
}

function inlineEmojiAssert($condition, $message)
{
    if (!$condition) {
        inlineEmojiFail($message);
    }
}

$host = getenv('MIRZA_TEST_DB_HOST') ?: '127.0.0.1';
$port = getenv('MIRZA_TEST_DB_PORT') ?: '3306';
$user = getenv('MIRZA_TEST_DB_USER') ?: 'root';
$password = getenv('MIRZA_TEST_DB_PASSWORD');
$password = $password === false ? '' : $password;
$database = 'mirza_product_emoji_' . bin2hex(random_bytes(5));

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
    $pdo->exec("CREATE TABLE product (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name_product VARCHAR(190) NOT NULL,
        code_product VARCHAR(64) NOT NULL,
        Location VARCHAR(190) NOT NULL,
        agent VARCHAR(10) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("INSERT INTO styled_emojis
        (`key`, custom_emoji_id, fallback_emoji, is_active, is_valid) VALUES
        ('diamond', '5368324170671202286', '💎', 1, 1),
        ('inactive', '5368324170671202287', '⚡', 0, 1)");

    define('CUSTOM_EMOJI_ENABLED', true);
    define('CUSTOM_EMOJI_USAGE_TRACKING', false);
    define('MIRZA_TEST_MODE', true);
    $GLOBALS['styled_test_schema_ready'] = true;

    require_once dirname(__DIR__) . '/product_panel_identity.php';
    require_once dirname(__DIR__) . '/emoji_system.php';

    $valid = extractProductEmojiToken('10 گیگ {emoji:diamond} | ماهانه');
    inlineEmojiAssert($valid['ok'], 'Valid inline product emoji token was rejected.');
    inlineEmojiAssert($valid['name_product'] === '10 گیگ | ماهانه', 'Token removal did not normalize whitespace.');
    inlineEmojiAssert($valid['emoji_key'] === 'diamond', 'Valid emoji key was not retained.');

    inlineEmojiAssert(
        extractProductEmojiToken('10 گیگ {emoji:unknown} | ماهانه')['error'] === 'unknown_key',
        'Unknown emoji key was not rejected.'
    );
    inlineEmojiAssert(
        extractProductEmojiToken('10 گیگ {emoji:diamond | ماهانه')['error'] === 'invalid_syntax',
        'Invalid token syntax was not rejected.'
    );
    inlineEmojiAssert(
        extractProductEmojiToken('{emoji:diamond} 10 گیگ {emoji:diamond}')['error'] === 'multiple_tokens',
        'Multiple product emoji tokens were not rejected.'
    );
    inlineEmojiAssert(
        extractProductEmojiToken('10 گیگ {emoji:inactive}')['error'] === 'inactive_key',
        'Inactive emoji key was not rejected.'
    );

    $plain = extractProductEmojiToken('20 گیگ | ماهانه');
    inlineEmojiAssert($plain['ok'] && $plain['emoji_key'] === '', 'Product without a token did not remain supported.');

    $codeProduct = generateUniqueProductCode(2);
    $stmt = $pdo->prepare("INSERT INTO product (name_product, code_product, Location, agent)
        VALUES (:name_product, :code_product, :location, 'f')");
    $stmt->execute([
        'name_product' => $valid['name_product'],
        'code_product' => $codeProduct,
        'location' => 'panel_promax',
    ]);
    inlineEmojiAssert(saveProductEmojiMapping($codeProduct, $valid['emoji_key']), 'Emoji mapping was not saved.');

    $product = $pdo->query("SELECT * FROM product WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    inlineEmojiAssert($product['name_product'] === '10 گیگ | ماهانه', 'Business product name contains the token.');
    foreach (['name_product', 'code_product', 'Location'] as $field) {
        inlineEmojiAssert(strpos($product[$field], '{emoji:') === false, "Literal token polluted {$field}.");
    }
    $mapping = $pdo->query("SELECT * FROM styled_button_icons WHERE source_type = 'product'")
        ->fetch(PDO::FETCH_ASSOC);
    inlineEmojiAssert($mapping['source_key'] === $codeProduct, 'Emoji mapping did not use code_product.');
    inlineEmojiAssert($mapping['icon_emoji_key'] === 'diamond', 'Emoji mapping stored the wrong key.');

    $GLOBALS['styled_runtime_emoji_cache']['diamond'] = [
        'key' => 'diamond',
        'custom_emoji_id' => '5368324170671202286',
        'fallback_emoji' => '💎',
        'resolved_fallback' => '💎',
        'is_active' => 1,
        'is_valid' => 1,
    ];
    $button = buildStyledProductButton(
        $product,
        $product['name_product'],
        ['callback_data' => 'product_' . $codeProduct]
    );
    [$telegramButton] = styledPrepareButton($button, 'test:product-inline-emoji');
    inlineEmojiAssert($telegramButton['text'] === '10 گیگ | ماهانه', 'Rendered button text was polluted.');
    inlineEmojiAssert(
        $telegramButton['icon_custom_emoji_id'] === '5368324170671202286',
        'Telegram product button did not render the Premium Emoji.'
    );

    $pdo->exec("UPDATE product SET name_product = '10 گیگ ویژه' WHERE id = 1");
    $mappingAfterRename = $pdo->query("SELECT source_key FROM styled_button_icons WHERE source_type = 'product'")
        ->fetchColumn();
    inlineEmojiAssert($mappingAfterRename === $codeProduct, 'Product rename detached its emoji mapping.');

    $pdo->exec("INSERT INTO product (name_product, code_product, Location, agent)
        VALUES ('20 گیگ | ماهانه', 'plain_product', 'panel_promax', 'f')");
    $plainMappingCount = $pdo->query("SELECT COUNT(*) FROM styled_button_icons WHERE source_key = 'plain_product'")
        ->fetchColumn();
    inlineEmojiAssert((int) $plainMappingCount === 0, 'Plain product unexpectedly received an emoji mapping.');

    $pdo->exec("DELETE FROM product WHERE id = 1");
    inlineEmojiAssert(cleanupProductEmojiMappingAfterDelete($codeProduct), 'Product emoji mapping cleanup failed.');
    $mappingCount = $pdo->query("SELECT COUNT(*) FROM styled_button_icons WHERE source_key = " . $pdo->quote($codeProduct))
        ->fetchColumn();
    inlineEmojiAssert((int) $mappingCount === 0, 'Deleted product left an emoji mapping behind.');

    echo "[OK] inline product emoji extraction, mapping, rendering, rename, and cleanup verified.\n";
} finally {
    $server->exec("DROP DATABASE IF EXISTS `{$database}`");
}
