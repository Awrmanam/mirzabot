<?php

function failTest($message)
{
    throw new RuntimeException($message);
}

function assertTest($condition, $message)
{
    if (!$condition) {
        failTest($message);
    }
}

$host = getenv('MIRZA_TEST_DB_HOST') ?: '127.0.0.1';
$port = getenv('MIRZA_TEST_DB_PORT') ?: '3306';
$user = getenv('MIRZA_TEST_DB_USER') ?: 'root';
$password = getenv('MIRZA_TEST_DB_PASSWORD');
$password = $password === false ? '' : $password;
$database = 'mirza_panel_id_' . bin2hex(random_bytes(5));

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

    $pdo->exec("CREATE TABLE marzban_panel (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        code_panel VARCHAR(64) NOT NULL UNIQUE,
        name_panel VARCHAR(190) NOT NULL,
        type VARCHAR(30) NOT NULL DEFAULT 'marzban'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE product (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name_product VARCHAR(190) NOT NULL,
        code_product VARCHAR(64) NOT NULL,
        Location VARCHAR(200) NOT NULL,
        agent VARCHAR(10) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE styled_button_icons (
        source_type VARCHAR(50) NOT NULL,
        source_key VARCHAR(190) NOT NULL,
        icon_emoji_key VARCHAR(100) NOT NULL,
        UNIQUE KEY uq_source (source_type, source_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("INSERT INTO marzban_panel (code_panel, name_panel) VALUES
        ('panel_promax', 'Promax'),
        ('panel_lite', 'Lite')");
    $pdo->exec("INSERT INTO styled_button_icons (source_type, source_key, icon_emoji_key) VALUES
        ('marzban_panel', 'panel_promax', 'diamond'),
        ('marzban_panel', 'panel_lite', 'lightning')");

    define('CUSTOM_EMOJI_ENABLED', true);
    define('CUSTOM_EMOJI_USAGE_TRACKING', false);
    define('MIRZA_TEST_MODE', true);
    $GLOBALS['styled_test_schema_ready'] = true;

    require_once dirname(__DIR__) . '/product_panel_identity.php';
    require_once dirname(__DIR__) . '/emoji_system.php';

    $GLOBALS['styled_runtime_emoji_cache'] = [
        'diamond' => [
            'key' => 'diamond',
            'custom_emoji_id' => '5368324170671202286',
            'fallback_emoji' => '💎',
            'resolved_fallback' => '💎',
            'is_active' => 1,
            'is_valid' => 1,
        ],
        'lightning' => [
            'key' => 'lightning',
            'custom_emoji_id' => '5368324170671202287',
            'fallback_emoji' => '⚡',
            'resolved_fallback' => '⚡',
            'is_active' => 1,
            'is_valid' => 1,
        ],
    ];

    $keyboard = json_decode(ProductPanelSelectionKeyboard('productpanel_'), true);
    assertTest(is_array($keyboard), 'Panel keyboard was not valid JSON.');
    $buttons = [];
    foreach ($keyboard['inline_keyboard'] as $row) {
        foreach ($row as $button) {
            $buttons[$button['callback_data']] = $button;
        }
    }
    assertTest(isset($buttons['productpanel_panel_promax']), 'Promax callback did not use code_panel.');
    assertTest(isset($buttons['productpanel_panel_lite']), 'Lite callback did not use code_panel.');
    assertTest($buttons['productpanel_panel_promax']['text'] === 'Promax', 'Promax display label was changed.');
    assertTest($buttons['productpanel_panel_lite']['text'] === 'Lite', 'Lite display label was changed.');
    assertTest($buttons['productpanel_panel_promax']['icon_emoji_key'] === 'diamond', 'Promax Premium Emoji metadata was not separate.');
    assertTest($buttons['productpanel_panel_lite']['icon_emoji_key'] === 'lightning', 'Lite Premium Emoji metadata was not separate.');

    [$telegramButton] = styledPrepareButton(
        $buttons['productpanel_panel_promax'],
        'test:product-panel-selection'
    );
    assertTest($telegramButton['text'] === 'Promax', 'Telegram button text was decorated or normalized incorrectly.');
    assertTest(
        $telegramButton['icon_custom_emoji_id'] === '5368324170671202286',
        'Telegram Premium Emoji icon was not rendered from separate metadata.'
    );

    $selectedPanel = resolvePanelByIdentifier('panel_promax');
    assertTest($selectedPanel && $selectedPanel['name_panel'] === 'Promax', 'code_panel selection did not resolve Promax.');
    $plainProductName = '10 گیگ | ماهانه';
    $agent = 'f';
    assertTest(!containsLiteralPremiumEmojiToken($plainProductName), 'Plain product name was rejected.');
    $stmt = $pdo->prepare("INSERT INTO product (name_product, code_product, Location, agent)
        VALUES (:name_product, 'prod1', :location, :agent)");
    $stmt->execute([
        'name_product' => $plainProductName,
        'location' => $selectedPanel['code_panel'],
        'agent' => $agent,
    ]);
    assertTest($stmt->rowCount() === 1, 'Product insertion failed.');
    $product = $pdo->query("SELECT * FROM product WHERE code_product = 'prod1'")->fetch(PDO::FETCH_ASSOC);
    assertTest($product['Location'] === 'panel_promax', 'Product.Location did not store code_panel.');
    assertTest($product['agent'] === 'f', 'Product type f was not preserved.');

    $pdo->exec("UPDATE marzban_panel SET name_panel = 'Promax Renamed' WHERE code_panel = 'panel_promax'");
    $renamedPanel = resolvePanelByIdentifier('panel_promax');
    assertTest($renamedPanel['name_panel'] === 'Promax Renamed', 'Renamed panel did not resolve by code_panel.');
    assertTest(productLocationMatchesPanel($product['Location'], 'panel_promax'), 'Renaming disconnected the stable product.');

    $pdo->exec("INSERT INTO product (name_product, code_product, Location, agent)
        VALUES ('Legacy Lite', 'legacy1', 'Lite', 'f')");
    assertTest(productLocationMatchesPanel('Lite', 'panel_lite'), 'Legacy name_panel product did not resolve.');
    $legacyCondition = productLocationSqlCondition('panel_lite');
    $legacyProduct = $pdo->query("SELECT * FROM product WHERE {$legacyCondition} AND code_product = 'legacy1'")
        ->fetch(PDO::FETCH_ASSOC);
    assertTest($legacyProduct && $legacyProduct['Location'] === 'Lite', 'Legacy SQL compatibility lookup failed.');

    assertTest(
        containsLiteralPremiumEmojiToken('10 گیگ {emoji:diamond} | ماهانه'),
        'Literal Premium Emoji token was not rejected from product business data.'
    );
    assertTest(
        normalizePanelLookupLabel('{emoji:diamond} Promax') === 'Promax',
        'Legacy literal emoji token was not normalized for lookup only.'
    );
    $unicodeLite = resolvePanelByIdentifier("\u{00A0}Lite\u{200C}");
    assertTest($unicodeLite && $unicodeLite['code_panel'] === 'panel_lite', 'Unicode spacing affected panel selection.');
    assertTest(
        normalizePanelLookupLabel("پرومکس\u{00A0}\u{200C} ویژه") === 'پرومکس ویژه',
        'Persian Unicode spacing normalization failed.'
    );

    echo "[OK] stable code_panel creation, rename safety, legacy lookup, token rejection, and Premium Emoji keyboard verified.\n";
} finally {
    $server->exec("DROP DATABASE IF EXISTS `{$database}`");
}
