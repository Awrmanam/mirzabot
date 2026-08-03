<?php

function assertDuplicatePanelStatic($condition, $message)
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

assertDuplicatePanelStatic(strpos($identity, 'function panelAdminSelectionLabel') !== false, 'Distinct admin panel labels are missing.');
assertDuplicatePanelStatic(strpos($identity, "' • ' . panelShortCodeSuffix") !== false, 'Admin panel labels do not include a short code suffix.');
assertDuplicatePanelStatic(strpos($identity, 'function panelAdminSelectionDescriptor') !== false, 'Stable panel button descriptor is missing.');
assertDuplicatePanelStatic(strpos($identity, "'callback_data' => \$callbackData") !== false, 'Panel descriptor does not contain callback_data.');
assertDuplicatePanelStatic(strpos($keyboard, 'panelAdminSelectionDescriptor($panel, $callbackPrefix)') !== false, 'Admin list does not use stable descriptors.');
assertDuplicatePanelStatic(strpos($keyboard, "\$button['text'] = \$descriptor['text']") !== false, 'Admin list does not show the distinguishing suffix.');
assertDuplicatePanelStatic(strpos($admin, "panelAdminSelectionKeyboard('panel_delete_select:')") !== false, 'Deletion list is not inline/code-backed.');
assertDuplicatePanelStatic(strpos($admin, 'update("user", "Processing_value_tow", $panel[\'code_panel\']') !== false, 'Dedicated deletion state does not store exact code_panel.');
assertDuplicatePanelStatic(strpos($admin, "\$user['Processing_value_tow']") !== false, 'Confirmation does not read the dedicated deletion state.');
assertDuplicatePanelStatic(strpos($admin, 'panelDeletionConfirmationText($panel)') !== false, 'Deletion preview is not displayed before confirmation.');
foreach (['name_panel', 'code_panel', 'type', 'product_count', 'exact_product_count', 'ambiguous_legacy_product_count', 'unique_legacy_product_count', 'another_same_name_panel_remains'] as $field) {
    assertDuplicatePanelStatic(strpos($admin, "\$panel['{$field}']") !== false, "Deletion preview omits {$field}.");
}
assertDuplicatePanelStatic(strpos($identity, "'another_same_name_panel_remains' => \$sameNamePanelCount > 1") !== false, 'Same-name survivor state is not derived from current rows.');
assertDuplicatePanelStatic(strpos($identity, "'ambiguous_legacy_name_dependencies' => \$sameNamePanelCount > 1") !== false, 'Duplicate-name legacy products are not classified as ambiguous.');
assertDuplicatePanelStatic(strpos($identity, "'unique_legacy_name_dependencies' => \$sameNamePanelCount === 1") !== false, 'Final-panel legacy products are not classified as unique.');
assertDuplicatePanelStatic(strpos($admin, 'محصول قدیمی با نام مشترک (مبهم)') !== false, 'Preview does not show ambiguous legacy products.');
assertDuplicatePanelStatic(strpos($admin, 'این آخرین پنل با نام') !== false, 'Final same-name panel warning is missing.');
assertDuplicatePanelStatic(strpos($admin, "update(\"marzban_panel\", \"Methodextend\", \$text, \"code_panel\"") !== false, 'Methodextend still updates by display name.');
assertDuplicatePanelStatic(strpos($admin, "resolveAdminPanelState(\$user)") !== false, 'Panel edit/rename state does not resolve stable identity.');
assertDuplicatePanelStatic(strpos($admin, "panelDeletionDebugLog('callback_received'") !== false, 'Debug/test callback logging is missing.');
assertDuplicatePanelStatic(strpos($admin, "panelDeletionDebugLog('confirm_state'") !== false, 'Debug/test stored-state logging is missing.');
assertDuplicatePanelStatic(strpos($identity, "defined('APP_DEBUG') && APP_DEBUG") !== false, 'Deletion diagnostics are not debug-gated.');
assertDuplicatePanelStatic(strpos($identity, "defined('MIRZA_TEST_MODE') && MIRZA_TEST_MODE") !== false, 'Deletion diagnostics are not test-gated.');
assertDuplicatePanelStatic(strpos($identity, "DELETE FROM marzban_panel WHERE code_panel = :code_panel") !== false, 'Panel deletion does not target exact code_panel.');
assertDuplicatePanelStatic(strpos($identity, "WHERE source_type = 'panel' AND source_key = :source_key") !== false, 'Canonical panel mapping cleanup is not exact.');
assertDuplicatePanelStatic(strpos($identity, "'status' => 'integrity_error'") !== false, 'Multi-row integrity failure is not explicit.');
assertDuplicatePanelStatic(strpos($identity, "SELECT COUNT(*) FROM marzban_panel WHERE code_panel = :code_panel") !== false, 'Post-delete exact-code verification is missing.');
assertDuplicatePanelStatic(strpos($admin, 'update("user", "Processing_value_tow", "", "id", $from_id)') !== false, 'Deletion state is not cleared on cancel/result paths.');
assertDuplicatePanelStatic(strpos($admin, 'panelShortCodeSuffix($deleteResult[\'panel\'][\'code_panel\'])') !== false, 'Success message does not identify the deleted duplicate.');

echo "[OK] duplicate-name panel deletion static checks passed.\n";
