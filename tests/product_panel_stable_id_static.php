<?php

function assertStatic($condition, $message)
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
$emoji = file_get_contents($root . '/emoji_system.php');
$panelProduct = file_get_contents($root . '/panel/product.php');
$apiProduct = file_get_contents($root . '/api/product.php');

assertStatic(strpos($admin, "ProductPanelSelectionKeyboard('productpanel_')") !== false, 'Product creation does not use the stable inline panel keyboard.');
assertStatic(strpos($admin, "resolvePanelByIdentifier(\$selectedPanelIdentifier)") !== false, 'Product creation does not resolve callback values to a real panel.');
assertStatic(strpos($admin, 'savedata("save", "Location", $locationValue)') !== false, 'Product creation state does not store code_panel.');
$locationStart = strpos($admin, '} elseif ($user[\'step\'] == "get_location") {');
$locationEnd = strpos($admin, '} elseif ($user[\'step\'] == "getcategory") {', $locationStart);
$locationHandler = substr($admin, $locationStart, $locationEnd - $locationStart);
assertStatic(strpos($locationHandler, 'in_array($text, $marzban_list)') === false, 'Legacy raw-name comparison still guards product panel selection.');
assertStatic(strpos($admin, "preg_match('/^productdelete_([0-9]+)\$/', \$datain") !== false, 'Product deletion does not use stable product IDs.');
assertStatic(strpos($admin, 'DELETE FROM product WHERE id = :product_id') !== false, 'Product deletion SQL is not ID-based.');
assertStatic(strpos($admin, 'UPDATE product SET name_product = :name_products WHERE id = :product_id') !== false, 'Product editing is not ID-based.');
assertStatic(strpos($admin, 'update("product", "Location", $text, "Location", $user[\'Processing_value\'])') === false, 'Panel rename still rewrites product locations by display name.');
assertStatic(substr_count($admin, 'containsLiteralPremiumEmojiToken(') >= 3, 'Panel and final-storage token guards are incomplete.');
assertStatic(substr_count($admin, 'extractProductEmojiToken(') >= 2, 'Product create/edit token extraction is not connected.');

assertStatic(strpos($identity, "foreach (['code_panel', 'name_panel'] as \$column)") !== false, 'Compatibility resolver order is not code_panel then name_panel.');
assertStatic(strpos($identity, 'normalizePanelLookupLabel') !== false, 'Legacy visible-label normalization is missing.');
assertStatic(strpos($identity, "'callback_data' => \$callbackPrefix . \$panel['code_panel']") !== false, 'Panel keyboard callback_data does not use code_panel.');
assertStatic(strpos($emoji, "styledButtonIconKeyForSource('marzban_panel', \$codePanel)") !== false, 'Panel Premium Emoji metadata is not keyed separately by code_panel.');
assertStatic(strpos($keyboard, "'callback_data' => 'locationedit_' . \$row['code_panel']") !== false, 'Product edit panel keyboard does not use code_panel.');
assertStatic(strpos($panelProduct, "\$stableLocation = \$panel ? \$panel['code_panel'] : '/all'") !== false, 'Web admin product creation does not store code_panel.');
assertStatic(strpos($apiProduct, "'Location' => \$panel ? \$panel['code_panel'] : '/all'") !== false, 'Product API creation does not store code_panel.');

foreach (['index.php', 'keyboard.php', 'panels.php', 'api/miniapp.php'] as $file) {
    $source = file_get_contents($root . '/' . $file);
    assertStatic(
        strpos($source, 'panel_code') !== false && strpos($source, 'panel_name') !== false,
        "{$file} does not support both stable and legacy product locations."
    );
}

echo "[OK] product panel stable-ID static checks passed.\n";
