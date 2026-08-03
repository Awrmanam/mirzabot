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
assertPanelDeleteStatic(strpos($keyboard, "['callback_data' => \$callbackData]") !== false, 'Admin panel buttons do not carry callback data.');
assertPanelDeleteStatic(strpos($keyboard, "(string) \$panel['code_panel']") !== false, 'Admin panel callback is not keyed by code_panel.');
assertPanelDeleteStatic(strpos($keyboard, "strlen(\$callbackData) > 64") !== false, 'Telegram callback length is not guarded.');
assertPanelDeleteStatic(strpos($admin, "panelAdminSelectionKeyboard('panel_manage_select:')") !== false, 'Panel management does not use stable inline selection.');
assertPanelDeleteStatic(strpos($admin, "panelAdminSelectionKeyboard('panel_delete_select:')") !== false, 'Panel deletion does not use stable inline selection.');
assertPanelDeleteStatic(strpos($admin, 'step(\'panel_delete_select\'') !== false, 'Panel deletion selection step is missing.');
assertPanelDeleteStatic(strpos($admin, 'update("user", "Processing_value_one", $panel[\'code_panel\']') !== false, 'Dedicated deletion state does not store code_panel.');
assertPanelDeleteStatic(substr_count($admin, "\$storedPanelSelection = trim((string) (\$user['Processing_value_one'] ?? ''))") >= 2, 'Delete action does not preserve and reuse the selected code_panel.');
assertPanelDeleteStatic(strpos($admin, "\$storedPanelSelection = trim((string) (\$user['Processing_value_one'] ?? ''))") !== false, 'Confirmation does not read the dedicated code_panel state first.');
assertPanelDeleteStatic(strpos($admin, "\$storedPanelSelection = trim((string) (\$user['Processing_value'] ?? ''))") !== false, 'Legacy panel-name state fallback is missing.');
assertPanelDeleteStatic(strpos($admin, "if (\$panel['resolved_from'] === 'name_panel')") !== false, 'Legacy panel-name state is not identified for conversion.');
assertPanelDeleteStatic(strpos($admin, '$deleteResult = deletePanelByCode($panel[\'code_panel\'])') !== false, 'Confirmation does not delete by resolved code_panel.');
assertPanelDeleteStatic(substr_count($admin, 'update("user", "Processing_value_one", "", "id", $from_id)') >= 6, 'Deletion state is not cleared on all exit paths.');
assertPanelDeleteStatic(strpos($admin, "\$datain == 'panel_manage_back'") !== false, 'Inline back action is missing.');
assertPanelDeleteStatic(strpos($admin, "\$deleteResult['status'] === 'has_products'") !== false, 'Dependent products are not handled safely.');
assertPanelDeleteStatic(strpos($admin, "step('GetLocationEdit', \$from_id)") !== false, 'Updated panel management list is not restored after deletion.');

$codeLookup = strpos($identity, "foreach (['code_panel', 'name_panel'] as \$column)");
assertPanelDeleteStatic($codeLookup !== false, 'Stable/legacy resolver is missing.');
assertPanelDeleteStatic(strpos($identity, '$storedValue = trim((string) $storedValue)') !== false, 'Legacy selection does not trim surrounding whitespace.');
assertPanelDeleteStatic(strpos($identity, 'normalizePanelLookupLabel($storedValue)') === false, 'Deletion resolver performs forbidden fuzzy/display normalization.');
assertPanelDeleteStatic(strpos($identity, 'DELETE FROM marzban_panel WHERE code_panel = :code_panel') !== false, 'DELETE does not use code_panel.');
assertPanelDeleteStatic(strpos($identity, 'DELETE FROM marzban_panel WHERE name_panel') === false, 'A name_panel DELETE remains in the stable helper.');
assertPanelDeleteStatic(strpos($identity, 'WHERE Location = :panel_code OR Location = :panel_name') !== false, 'Code and exact legacy-name product dependencies are not checked.');
assertPanelDeleteStatic(strpos($identity, "deletePanelEmojiMapping(\$panel['code_panel'])") !== false, 'Exact panel emoji mapping is not cleaned.');
assertPanelDeleteStatic(strpos($identity, '$stmt->rowCount() !== 1') !== false, 'Exact one-row deletion is not verified.');

echo "[OK] stable panel deletion static checks passed.\n";
