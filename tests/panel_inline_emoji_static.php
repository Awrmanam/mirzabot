<?php

function assertPanelEmojiStatic($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$identity = file_get_contents($root . '/product_panel_identity.php');
$admin = file_get_contents($root . '/admin.php');
$emoji = file_get_contents($root . '/emoji_system.php');
$keyboard = file_get_contents($root . '/keyboard.php');
$panelApi = file_get_contents($root . '/api/panels.php');
$miniApp = file_get_contents($root . '/api/miniapp.php');

assertPanelEmojiStatic(strpos($identity, 'function extractPanelEmojiToken') !== false, 'Panel token extractor is missing.');
assertPanelEmojiStatic(strpos($identity, 'extractProductEmojiToken($value)') !== false, 'Panel extraction does not reuse the existing token parser.');
assertPanelEmojiStatic(strpos($identity, "VALUES ('panel', :source_key, :emoji_key)") !== false, 'Panel mapping does not use source_type=panel.');
assertPanelEmojiStatic(strpos($identity, "source_type IN ('panel', 'marzban_panel')") !== false, 'Panel mapping cleanup is not compatibility-safe.');
assertPanelEmojiStatic(strpos($admin, 'savedata("save", "panel_emoji_key"') !== false, 'Panel emoji key is not retained during creation.');
assertPanelEmojiStatic(strpos($admin, 'savedata("save", "namepanel", $plainPanelName)') !== false, 'Plain panel name is not retained separately.');
assertPanelEmojiStatic(strpos($admin, 'savePanelEmojiMapping($randomString, $panelEmojiKey)') !== false, 'Panel creation does not map the generated code_panel.');
assertPanelEmojiStatic(strpos($admin, "savePanelEmojiMapping(\$panel['code_panel']") !== false, 'Panel rename cannot update the stable mapping.');
assertPanelEmojiStatic(strpos($identity, "deleteCanonicalPanelEmojiMapping(\$panel['code_panel'])") !== false, 'Panel deletion does not clean the canonical stable mapping.');
assertPanelEmojiStatic(strpos($identity, 'DELETE FROM marzban_panel WHERE code_panel = :code_panel') !== false, 'Panel deletion is not code_panel-based.');
assertPanelEmojiStatic(strpos($emoji, "styledButtonIconKeyForSource('panel', \$codePanel)") !== false, 'Panel renderer does not prefer the canonical source type.');
assertPanelEmojiStatic(strpos($emoji, "styledButtonIconKeyForSource('marzban_panel', \$codePanel)") !== false, 'Legacy panel mappings lost backward-compatible rendering.');
assertPanelEmojiStatic(strpos($keyboard, 'function panelKeyboardButton') !== false, 'Shared reply/inline panel button builder is missing.');
assertPanelEmojiStatic(substr_count($keyboard, 'panelKeyboardButton($result') >= 8, 'Not all customer inline panel keyboards use styled rendering.');
assertPanelEmojiStatic(strpos($keyboard, 'panelKeyboardButton($panel)') !== false, 'Admin reply panel keyboard is not styled.');
assertPanelEmojiStatic(strpos($panelApi, 'INSERT INTO marzban_panel') === false, 'Unrelated catalog API unexpectedly writes connection panels.');
assertPanelEmojiStatic(strpos($miniApp, 'INSERT INTO marzban_panel') === false, 'Mini-app unexpectedly writes connection panels.');

foreach (['name_panel', 'code_panel', 'type', 'version_panel', 'Processing_value', 'Location'] as $field) {
    assertPanelEmojiStatic(
        strpos($admin, "'{$field}' => '{emoji:") === false,
        "Literal token is assigned to {$field}."
    );
}

echo "[OK] inline panel emoji static checks passed.\n";
