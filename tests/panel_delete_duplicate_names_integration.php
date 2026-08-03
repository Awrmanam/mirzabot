<?php

function duplicatePanelFail($message)
{
    throw new RuntimeException($message);
}

function duplicatePanelAssert($condition, $message)
{
    if (!$condition) {
        duplicatePanelFail($message);
    }
}

$host = getenv('MIRZA_TEST_DB_HOST') ?: '127.0.0.1';
$port = getenv('MIRZA_TEST_DB_PORT') ?: '3306';
$user = getenv('MIRZA_TEST_DB_USER') ?: 'root';
$password = getenv('MIRZA_TEST_DB_PASSWORD');
$password = $password === false ? '' : $password;
$database = 'mirza_panel_duplicate_' . bin2hex(random_bytes(5));

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
        Methodextend VARCHAR(190) NOT NULL DEFAULT '',
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
        (code_panel, name_panel, type, Methodextend, version_panel)
        VALUES (:code_panel, :name_panel, :type, '', '2')");
    $insertPanel->execute(['code_panel' => 'panel_a', 'name_panel' => 'Promax', 'type' => 'marzban']);
    $insertPanel->execute(['code_panel' => 'panel_b', 'name_panel' => 'Promax', 'type' => 'rebecca']);
    $insertPanel->execute(['code_panel' => 'panel_fa', 'name_panel' => 'پنل ویژه تهران', 'type' => 'marzban']);

    duplicatePanelAssert(savePanelEmojiMapping('panel_a', 'diamond'), 'panel_a Premium Emoji mapping was not created.');
    duplicatePanelAssert(savePanelEmojiMapping('panel_b', 'diamond'), 'panel_b Premium Emoji mapping was not created.');
    duplicatePanelAssert(savePanelEmojiMapping('panel_fa', 'diamond'), 'Persian panel Premium Emoji mapping was not created.');
    $pdo->exec("INSERT INTO styled_button_icons
        (source_type, source_key, icon_emoji_key) VALUES
        ('product', 'product_unrelated', 'diamond'),
        ('button', 'button_unrelated', 'diamond'),
        ('marzban_panel', 'panel_a', 'diamond')");

    $panelA = resolvePanelDeletionSelection('panel_a');
    $panelB = resolvePanelDeletionSelection('panel_b');
    duplicatePanelAssert($panelA && $panelB, 'Duplicate-name panels did not resolve independently by code_panel.');
    duplicatePanelAssert(resolvePanelDeletionSelection('Promax') === null, 'Ambiguous display name resolved to an arbitrary panel.');

    $buttonA = panelAdminSelectionDescriptor($panelA, 'panel_delete_select:');
    $buttonB = panelAdminSelectionDescriptor($panelB, 'panel_delete_select:');
    duplicatePanelAssert($buttonA['callback_data'] === 'panel_delete_select:panel_a', 'panel_a callback_data is wrong.');
    duplicatePanelAssert($buttonB['callback_data'] === 'panel_delete_select:panel_b', 'panel_b callback_data is wrong.');
    duplicatePanelAssert($buttonA['text'] !== $buttonB['text'], 'Duplicate-name buttons are not visually distinguishable.');
    duplicatePanelAssert(strpos($buttonA['text'], 'Promax • ') === 0, 'panel_a label lost its plain display name or suffix.');
    duplicatePanelAssert(strpos($buttonB['text'], 'Promax • ') === 0, 'panel_b label lost its plain display name or suffix.');

    $persianButton = panelAdminSelectionDescriptor(
        resolvePanelDeletionSelection('panel_fa'),
        'panel_delete_select:'
    );
    duplicatePanelAssert($persianButton['callback_data'] === 'panel_delete_select:panel_fa', 'Persian/spaced panel identity depends on its label.');
    duplicatePanelAssert(strpos($persianButton['text'], 'پنل ویژه تهران • ') === 0, 'Persian/spaced panel label was corrupted.');

    $adminState = ['Processing_value_one' => 'panel_a', 'Processing_value' => 'Promax'];
    $selected = resolveAdminPanelState($adminState);
    duplicatePanelAssert($selected && $selected['code_panel'] === 'panel_a', 'Dedicated management state did not retain panel_a.');
    $deletionState = $selected['code_panel'];
    duplicatePanelAssert($deletionState === 'panel_a', 'Dedicated deletion state would store a name instead of code_panel.');

    $pdo->exec("INSERT INTO product (name_product, code_product, Location) VALUES
        ('Legacy Promax Plan', 'legacy_promax', 'Promax'),
        ('Exact panel_a Plan', 'exact_panel_a', 'panel_a')");

    $previewA = panelDeletionPreviewByCode('panel_a');
    duplicatePanelAssert($previewA['name_panel'] === 'Promax', 'Deletion preview has the wrong name.');
    duplicatePanelAssert($previewA['type'] === 'marzban', 'Deletion preview has the wrong panel type.');
    duplicatePanelAssert($previewA['exact_product_count'] === 1, 'Exact panel_a dependency count is wrong.');
    duplicatePanelAssert($previewA['ambiguous_legacy_product_count'] === 1, 'Ambiguous legacy dependency count is wrong.');
    duplicatePanelAssert($previewA['unique_legacy_product_count'] === 0, 'Duplicate-name legacy dependency was classified as unique.');
    duplicatePanelAssert($previewA['another_same_name_panel_remains'] === true, 'Preview did not report the remaining same-name panel.');

    $blockedA = deletePanelByCode($deletionState);
    duplicatePanelAssert($blockedA['status'] === 'has_exact_products', 'Exact panel_a dependency did not block deletion.');
    duplicatePanelAssert(count($blockedA['exact_code_dependencies']) === 1, 'Exact dependency result is incomplete.');
    duplicatePanelAssert(count($blockedA['ambiguous_legacy_name_dependencies']) === 1, 'Ambiguous legacy dependency result is incomplete.');
    duplicatePanelAssert((int) $pdo->query("SELECT COUNT(*) FROM marzban_panel WHERE code_panel = 'panel_a'")->fetchColumn() === 1, 'Blocked exact deletion removed panel_a.');

    $pdo->exec("DELETE FROM product WHERE code_product = 'exact_panel_a'");
    $previewA = panelDeletionPreviewByCode('panel_a');
    duplicatePanelAssert($previewA['exact_product_count'] === 0, 'Removed exact dependency remains in the preview.');
    duplicatePanelAssert($previewA['ambiguous_legacy_product_count'] === 1, 'Legacy Promax dependency is no longer classified as ambiguous.');
    duplicatePanelAssert($previewA['product_count'] === 0, 'Ambiguous legacy dependency was counted as a deletion blocker.');

    $deletedA = deletePanelByCode($deletionState);
    duplicatePanelAssert($deletedA['status'] === 'deleted', 'panel_a was not deleted while only an ambiguous legacy dependency existed.');
    duplicatePanelAssert($deletedA['affected_rows'] === 1, 'panel_a deletion did not affect exactly one row.');
    duplicatePanelAssert((int) $pdo->query("SELECT COUNT(*) FROM marzban_panel WHERE code_panel = 'panel_a'")->fetchColumn() === 0, 'panel_a remains after deletion.');
    duplicatePanelAssert((int) $pdo->query("SELECT COUNT(*) FROM marzban_panel WHERE code_panel = 'panel_b' AND name_panel = 'Promax'")->fetchColumn() === 1, 'panel_b was deleted or changed with panel_a.');
    duplicatePanelAssert((int) $pdo->query("SELECT COUNT(*) FROM styled_button_icons WHERE source_type = 'panel' AND source_key = 'panel_a'")->fetchColumn() === 0, 'panel_a canonical icon mapping remains.');
    duplicatePanelAssert((int) $pdo->query("SELECT COUNT(*) FROM styled_button_icons WHERE source_type = 'panel' AND source_key = 'panel_b'")->fetchColumn() === 1, 'panel_b icon mapping was removed.');
    duplicatePanelAssert((int) $pdo->query("SELECT COUNT(*) FROM styled_button_icons WHERE source_type = 'product' AND source_key = 'product_unrelated'")->fetchColumn() === 1, 'Unrelated product mapping was removed.');
    duplicatePanelAssert((int) $pdo->query("SELECT COUNT(*) FROM styled_button_icons WHERE source_type = 'button' AND source_key = 'button_unrelated'")->fetchColumn() === 1, 'Unrelated button mapping was removed.');
    duplicatePanelAssert((int) $pdo->query("SELECT COUNT(*) FROM styled_button_icons WHERE source_type = 'marzban_panel' AND source_key = 'panel_a'")->fetchColumn() === 1, 'Non-canonical legacy mapping was removed.');
    duplicatePanelAssert($pdo->query("SELECT Location FROM product WHERE code_product = 'legacy_promax'")->fetchColumn() === 'Promax', 'Legacy product Location was rewritten or deleted.');
    $legacyResolution = resolvePanelDeletionSelection('Promax');
    duplicatePanelAssert($legacyResolution && $legacyResolution['code_panel'] === 'panel_b', 'Legacy product name no longer resolves through the remaining same-name panel.');

    $replayed = deletePanelByCode('panel_a');
    duplicatePanelAssert($replayed['status'] === 'not_found', 'Repeated confirmation was not a safe stale result.');
    duplicatePanelAssert((int) $pdo->query("SELECT COUNT(*) FROM marzban_panel WHERE code_panel = 'panel_b'")->fetchColumn() === 1, 'Repeated confirmation deleted panel_b.');
    duplicatePanelAssert(panelDeletionPreviewByCode('panel_missing') === null, 'Stale callback did not return a clean missing result.');

    $finalPreview = panelDeletionPreviewByCode('panel_b');
    duplicatePanelAssert($finalPreview['exact_product_count'] === 0, 'Final panel has an unexpected exact dependency.');
    duplicatePanelAssert($finalPreview['ambiguous_legacy_product_count'] === 0, 'Final panel still classifies its legacy dependency as ambiguous.');
    duplicatePanelAssert($finalPreview['unique_legacy_product_count'] === 1, 'Final panel legacy dependency was not classified as unique.');
    duplicatePanelAssert($finalPreview['another_same_name_panel_remains'] === false, 'Final panel incorrectly reports another same-name panel.');
    $finalBlocked = deletePanelByCode('panel_b');
    duplicatePanelAssert($finalBlocked['status'] === 'has_unique_legacy_products', 'Final Promax panel deletion was not blocked by its legacy dependency.');
    duplicatePanelAssert(count($finalBlocked['unique_legacy_name_dependencies']) === 1, 'Final-panel legacy dependency result is incomplete.');
    duplicatePanelAssert((int) $pdo->query("SELECT COUNT(*) FROM marzban_panel WHERE code_panel = 'panel_b'")->fetchColumn() === 1, 'Final-panel dependency block removed panel_b.');
    duplicatePanelAssert($pdo->query("SELECT Location FROM product WHERE code_product = 'legacy_promax'")->fetchColumn() === 'Promax', 'Blocked final deletion changed the legacy product.');
    $pdo->exec("DELETE FROM product WHERE code_product = 'legacy_promax'");

    $pdo->exec("INSERT INTO product (name_product, code_product, Location)
        VALUES ('Protected Plan', 'product_b', 'panel_b')");
    $previewB = panelDeletionPreviewByCode('panel_b');
    duplicatePanelAssert($previewB['exact_product_count'] === 1, 'Associated exact product count is wrong for panel_b.');
    $blockedB = deletePanelByCode('panel_b');
    duplicatePanelAssert($blockedB['status'] === 'has_exact_products', 'Unsafe panel_b deletion was not blocked.');
    duplicatePanelAssert((int) $pdo->query("SELECT COUNT(*) FROM marzban_panel WHERE code_panel = 'panel_b'")->fetchColumn() === 1, 'Dependency block removed panel_b.');
    $pdo->exec("DELETE FROM product WHERE code_product = 'product_b'");

    $pdo->exec("UPDATE marzban_panel SET Methodextend = 'method_b' WHERE code_panel = 'panel_b'");
    duplicatePanelAssert($pdo->query("SELECT Methodextend FROM marzban_panel WHERE code_panel = 'panel_b'")->fetchColumn() === 'method_b', 'Methodextend exact-code update failed.');
    $deletedB = deletePanelByCode('panel_b');
    duplicatePanelAssert($deletedB['status'] === 'deleted' && $deletedB['affected_rows'] === 1, 'panel_b exact deletion failed.');
    duplicatePanelAssert((int) $pdo->query("SELECT COUNT(*) FROM marzban_panel WHERE code_panel = 'panel_fa'")->fetchColumn() === 1, 'Deleting panel_b changed the Persian panel.');

    $insertPanel->execute(['code_panel' => 'duplicate_code', 'name_panel' => 'First', 'type' => 'marzban']);
    $insertPanel->execute(['code_panel' => 'duplicate_code', 'name_panel' => 'Second', 'type' => 'marzban']);
    $integrity = deletePanelByCode('duplicate_code');
    duplicatePanelAssert($integrity['status'] === 'integrity_error', 'Duplicate code_panel did not trigger an integrity error.');
    duplicatePanelAssert((int) $pdo->query("SELECT COUNT(*) FROM marzban_panel WHERE code_panel = 'duplicate_code'")->fetchColumn() === 2, 'Integrity error deleted duplicate-code rows.');

    echo "[OK] duplicate display names, exact callbacks/state/deletion, dependencies, mappings, stale replay, and integrity rollback verified.\n";
} finally {
    $server->exec("DROP DATABASE IF EXISTS `{$database}`");
}
