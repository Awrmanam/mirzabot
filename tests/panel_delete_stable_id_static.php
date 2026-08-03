<?php

function assertPanelDeleteStatic($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$admin = file_get_contents($root . '/admin.php');
$keyboard = file_get_contents($root . '/keyboard.php');
$identity = file_get_contents($root . '/product_panel_identity.php');

assertPanelDeleteStatic(strpos($keyboard, 'function panelAdminSelectionKeyboard') !== false, 'Admin panel selection keyboard is missing.');
assertPanelDeleteStatic(strpos($keyboard, 'panelAdminSelectionDescriptor($panel, $callbackPrefix)') !== false, 'Admin panel buttons do not use stable descriptors.');
assertPanelDeleteStatic(strpos($identity, "(string) \$callbackPrefix . \$codePanel") !== false, 'Admin panel callback is not keyed by code_panel.');
assertPanelDeleteStatic(strpos($identity, 'strlen($callbackData) <= 64') !== false, 'Telegram callback length is not guarded.');
assertPanelDeleteStatic(strpos($admin, "panelAdminSelectionKeyboard('panel_manage_select:')") !== false, 'Panel management does not use stable inline selection.');
assertPanelDeleteStatic(strpos($admin, "panelAdminSelectionKeyboard('panel_delete_select:')") !== false, 'Panel deletion does not use stable inline selection.');
assertPanelDeleteStatic(strpos($admin, 'step(\'panel_delete_select\'') !== false, 'Panel deletion selection step is missing.');
assertPanelDeleteStatic(strpos($admin, 'update("user", "Processing_value_tow", $panel[\'code_panel\']') !== false, 'Dedicated deletion state does not store code_panel.');
assertPanelDeleteStatic(strpos($admin, "\$storedPanelSelection = trim((string) (\$user['Processing_value_tow'] ?? ''))") !== false, 'Confirmation does not read the dedicated deletion state first.');
assertPanelDeleteStatic(strpos($admin, '$legacyPanel = resolveAdminPanelState($user)') !== false, 'Legacy panel state fallback is missing.');
assertPanelDeleteStatic(strpos($admin, '$deleteResult = deletePanelByCode($panel[\'code_panel\'])') !== false, 'Confirmation does not delete by resolved code_panel.');
assertPanelDeleteStatic(substr_count($admin, 'update("user", "Processing_value_tow", "", "id", $from_id)') >= 6, 'Deletion state is not cleared on all exit paths.');
assertPanelDeleteStatic(strpos($admin, "\$datain == 'panel_manage_back'") !== false, 'Inline back action is missing.');
assertPanelDeleteStatic(strpos($admin, "\$deleteResult['status'] === 'has_exact_products'") !== false, 'Exact code dependencies are not handled safely.');
assertPanelDeleteStatic(strpos($admin, "\$deleteResult['status'] === 'has_unique_legacy_products'") !== false, 'Final unique legacy dependencies are not handled safely.');
assertPanelDeleteStatic(strpos($admin, "step('panel_delete_select', \$from_id)") !== false, 'Updated panel deletion list is not restored after deletion.');

$codeLookup = strpos($identity, "foreach (['code_panel', 'name_panel'] as \$column)");
assertPanelDeleteStatic($codeLookup !== false, 'Stable/legacy resolver is missing.');
assertPanelDeleteStatic(strpos($identity, '$storedValue = trim((string) $storedValue)') !== false, 'Legacy selection does not trim surrounding whitespace.');
assertPanelDeleteStatic(strpos($identity, 'normalizePanelLookupLabel($storedValue)') === false, 'Deletion resolver performs forbidden fuzzy/display normalization.');
assertPanelDeleteStatic(strpos($identity, 'DELETE FROM marzban_panel WHERE code_panel = :code_panel') !== false, 'DELETE does not use code_panel.');
assertPanelDeleteStatic(strpos($identity, 'DELETE FROM marzban_panel WHERE name_panel') === false, 'A name_panel DELETE remains in the stable helper.');
assertPanelDeleteStatic(strpos($identity, 'WHERE Location = :panel_code OR Location = :panel_name') === false, 'Code and legacy-name dependencies are still conflated.');
assertPanelDeleteStatic(strpos($identity, 'WHERE Location = :panel_code ORDER BY id{$lockClause}') !== false, 'Exact code dependencies are not queried independently.');
assertPanelDeleteStatic(strpos($identity, 'WHERE Location = :panel_name ORDER BY id{$lockClause}') !== false, 'Legacy name dependencies are not queried independently.');
foreach (['exact_code_dependencies', 'ambiguous_legacy_name_dependencies', 'unique_legacy_name_dependencies'] as $dependencyCategory) {
    assertPanelDeleteStatic(strpos($identity, "'{$dependencyCategory}'") !== false, "Missing {$dependencyCategory} result category.");
}
assertPanelDeleteStatic(strpos($identity, "deleteCanonicalPanelEmojiMapping(\$panel['code_panel'])") !== false, 'Exact canonical panel emoji mapping is not cleaned.');
assertPanelDeleteStatic(strpos($identity, '$affectedRows === 0') !== false, 'Zero-row deletion is not handled.');
assertPanelDeleteStatic(strpos($identity, '$affectedRows > 1') !== false, 'Multi-row deletion is not rolled back as an integrity error.');

echo "[OK] stable panel deletion static checks passed.\n";
