<?php

function panelDeleteFail($message)
{
    throw new RuntimeException($message);
}

function panelDeleteAssert($condition, $message)
{
    if (!$condition) {
        panelDeleteFail($message);
    }
}

$host = getenv('MIRZA_TEST_DB_HOST') ?: '127.0.0.1';
$port = getenv('MIRZA_TEST_DB_PORT') ?: '3306';
$user = getenv('MIRZA_TEST_DB_USER') ?: 'root';
$password = getenv('MIRZA_TEST_DB_PASSWORD');
$password = $password === false ? '' : $password;
$database = 'mirza_panel_delete_' . bin2hex(random_bytes(5));

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

    define('CUSTOM_EMOJI_ENABLED', true);
    define('CUSTOM_EMOJI_USAGE_TRACKING', false);
    define('MIRZA_TEST_MODE', true);
    $GLOBALS['styled_test_schema_ready'] = true;
    require_once dirname(__DIR__) . '/product_panel_identity.php';

    $pdo->exec("INSERT INTO styled_emojis
        (`key`, custom_emoji_id, fallback_emoji, is_active, is_valid)
        VALUES ('diamond', '5368324170671202286', '💎', 1, 1)");
    $insertPanel = $pdo->prepare("INSERT INTO marzban_panel
        (code_panel, name_panel, type, version_panel)
        VALUES (:code_panel, :name_panel, 'marzban', '2')");
    $insertPanel->execute(['code_panel' => 'panel_promax', 'name_panel' => 'Promax']);
    $insertPanel->execute(['code_panel' => 'panel_promax_test', 'name_panel' => 'Promax Test']);

    panelDeleteAssert(savePanelEmojiMapping('panel_promax', 'diamond'), 'Promax Premium Emoji mapping was not created.');
    $insertMapping = $pdo->prepare("INSERT INTO styled_button_icons
        (source_type, source_key, icon_emoji_key) VALUES (:source_type, :source_key, 'diamond')");
    $insertMapping->execute(['source_type' => 'marzban_panel', 'source_key' => 'panel_promax']);
    $insertMapping->execute(['source_type' => 'panel', 'source_key' => 'panel_promax_test']);
    $insertMapping->execute(['source_type' => 'product', 'source_key' => 'panel_promax']);
    $insertMapping->execute(['source_type' => 'button', 'source_key' => 'unrelated_button']);

    $selected = resolvePanelDeletionSelection('panel_promax');
    panelDeleteAssert($selected && $selected['resolved_from'] === 'code_panel', 'Inline selection did not resolve by code_panel.');
    panelDeleteAssert($selected['name_panel'] === 'Promax', 'Promax Test collided with Promax selection.');

    $legacy = resolvePanelDeletionSelection('  Promax  ');
    panelDeleteAssert($legacy && $legacy['resolved_from'] === 'name_panel', 'Trimmed legacy name did not resolve exactly once.');
    panelDeleteAssert($legacy['code_panel'] === 'panel_promax', 'Legacy state did not convert to Promax code_panel.');
    panelDeleteAssert(resolvePanelDeletionSelection('Promax Tes') === null, 'Fuzzy legacy name unexpectedly resolved.');

    $pdo->prepare("INSERT INTO product (name_product, code_product, Location)
        VALUES ('Dependent Plan', 'product_dependent', 'panel_promax')")->execute();
    $blocked = deletePanelByCode('panel_promax');
    panelDeleteAssert($blocked['status'] === 'has_products', 'Deletion did not block a code_panel-dependent product.');
    panelDeleteAssert(count($blocked['products']) === 1, 'Dependent product list is incomplete.');
    panelDeleteAssert((int) $pdo->query("SELECT COUNT(*) FROM marzban_panel WHERE code_panel = 'panel_promax'")->fetchColumn() === 1, 'Blocked deletion removed the panel.');

    $pdo->exec("DELETE FROM product WHERE code_product = 'product_dependent'");
    $pdo->prepare("INSERT INTO product (name_product, code_product, Location)
        VALUES ('Legacy Dependent Plan', 'product_legacy_dependent', 'Promax')")->execute();
    $legacyBlocked = deletePanelByCode('panel_promax');
    panelDeleteAssert($legacyBlocked['status'] === 'has_products', 'Deletion did not block an exact legacy name_panel-dependent product.');
    panelDeleteAssert(count($legacyBlocked['products']) === 1, 'Legacy dependent product list is incomplete.');
    panelDeleteAssert((int) $pdo->query("SELECT COUNT(*) FROM marzban_panel WHERE code_panel = 'panel_promax'")->fetchColumn() === 1, 'Legacy dependency block removed the panel.');

    $pdo->exec("DELETE FROM product WHERE code_product = 'product_legacy_dependent'");
    $pdo->prepare("UPDATE marzban_panel SET name_panel = 'Promax Renamed' WHERE code_panel = 'panel_promax'")->execute();
    $renamed = resolvePanelDeletionSelection('panel_promax');
    panelDeleteAssert($renamed && $renamed['name_panel'] === 'Promax Renamed', 'Rename detached stable panel selection.');
    panelDeleteAssert(resolvePanelDeletionSelection('Promax') === null, 'Stale legacy panel name unexpectedly resolved after rename.');

    $deleted = deletePanelByCode('panel_promax');
    panelDeleteAssert($deleted['status'] === 'deleted', 'Renamed Promax panel was not deleted by code_panel.');
    panelDeleteAssert($deleted['affected_rows'] === 1, 'Stable deletion did not affect exactly one row.');
    panelDeleteAssert((int) $pdo->query("SELECT COUNT(*) FROM marzban_panel WHERE code_panel = 'panel_promax'")->fetchColumn() === 0, 'Promax panel row remains after deletion.');
    panelDeleteAssert((int) $pdo->query("SELECT COUNT(*) FROM marzban_panel WHERE code_panel = 'panel_promax_test' AND name_panel = 'Promax Test'")->fetchColumn() === 1, 'Promax Test was changed or deleted.');
    panelDeleteAssert((int) $pdo->query("SELECT COUNT(*) FROM styled_button_icons WHERE source_type = 'panel' AND source_key = 'panel_promax'")->fetchColumn() === 0, 'Promax canonical panel mapping remains after deletion.');
    panelDeleteAssert((int) $pdo->query("SELECT COUNT(*) FROM styled_button_icons WHERE source_type = 'marzban_panel' AND source_key = 'panel_promax'")->fetchColumn() === 1, 'Deletion removed a non-canonical legacy mapping.');
    panelDeleteAssert((int) $pdo->query("SELECT COUNT(*) FROM styled_button_icons WHERE source_type = 'panel' AND source_key = 'panel_promax_test'")->fetchColumn() === 1, 'Another panel mapping was removed.');
    panelDeleteAssert((int) $pdo->query("SELECT COUNT(*) FROM styled_button_icons WHERE source_type = 'product' AND source_key = 'panel_promax'")->fetchColumn() === 1, 'Unrelated product mapping was removed.');
    panelDeleteAssert((int) $pdo->query("SELECT COUNT(*) FROM styled_button_icons WHERE source_type = 'button' AND source_key = 'unrelated_button'")->fetchColumn() === 1, 'Unrelated button mapping was removed.');

    $stale = deletePanelByCode('panel_promax');
    panelDeleteAssert($stale['status'] === 'not_found', 'Duplicate confirmation did not become a safe stale selection.');
    panelDeleteAssert(resolvePanelDeletionSelection('missing_panel') === null, 'Genuinely stale selection did not return null.');
    panelDeleteAssert((int) $pdo->query("SELECT COUNT(*) FROM marzban_panel WHERE code_panel = 'panel_promax_test'")->fetchColumn() === 1, 'Duplicate confirmation deleted another panel.');

    echo "[OK] stable panel deletion, dependency blocking, rename, legacy fallback, mapping cleanup, and stale replay verified.\n";
} finally {
    $server->exec("DROP DATABASE IF EXISTS `{$database}`");
}
