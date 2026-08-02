<?php

function assertInlineEmojiStatic($condition, $message)
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
$api = file_get_contents($root . '/api/product.php');
$web = file_get_contents($root . '/panel/product.php');

assertInlineEmojiStatic(strpos($identity, 'function extractProductEmojiToken') !== false, 'Shared token extractor is missing.');
assertInlineEmojiStatic(strpos($identity, 'FROM styled_emojis WHERE `key` = :emoji_key') !== false, 'Emoji key validation does not use styled_emojis.');
assertInlineEmojiStatic(strpos($identity, "VALUES ('product', :source_key, :emoji_key)") !== false, 'Product mapping does not use the existing mapping table/source type.');
assertInlineEmojiStatic(strpos($identity, "source_type = 'product' AND source_key = :source_key") !== false, 'Product mapping cleanup is missing.');
assertInlineEmojiStatic(strpos($admin, 'savedata("save", "product_emoji_key"') !== false, 'Emoji key is not retained during product creation.');
assertInlineEmojiStatic(strpos($admin, 'saveProductEmojiMapping($randomString, $productEmojiKey)') !== false, 'Admin creation does not map the generated code_product.');
assertInlineEmojiStatic(strpos($admin, "cleanupProductEmojiMappingAfterDelete(\$productToDelete['code_product'])") !== false, 'Admin deletion does not clean the mapping.');
assertInlineEmojiStatic(strpos($admin, "saveProductEmojiMapping(\$product['code_product']") !== false, 'Product rename cannot update the stable mapping.');
assertInlineEmojiStatic(strpos($emoji, "styledButtonIconKeyForSource('product', \$codeProduct)") !== false, 'Product renderer is not keyed by code_product.');
assertInlineEmojiStatic(strpos($keyboard, 'buildStyledProductButton($result') !== false, 'Customer product keyboard is not connected to Premium Emoji rendering.');
assertInlineEmojiStatic(substr_count($api, 'saveProductEmojiMapping(') >= 2, 'Product API create/edit mapping is incomplete.');
assertInlineEmojiStatic(strpos($api, 'cleanupProductEmojiMappingAfterDelete(') !== false, 'Product API delete cleanup is missing.');
assertInlineEmojiStatic(strpos($web, 'saveProductEmojiMapping(') !== false, 'Web product creation mapping is missing.');
assertInlineEmojiStatic(strpos($web, 'cleanupProductEmojiMappingAfterDelete(') !== false, 'Web product delete cleanup is missing.');

foreach (['name_product', 'code_product', 'Location'] as $field) {
    assertInlineEmojiStatic(
        strpos($admin, "'{$field}' => '{emoji:") === false,
        "Literal token is assigned to {$field}."
    );
}

echo "[OK] inline product emoji static checks passed.\n";
