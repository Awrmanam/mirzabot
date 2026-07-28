<?php

define('MIRZA_TEST_MODE', true);
$mode = $argv[1] ?? 'enabled';
define('CUSTOM_EMOJI_ENABLED', $mode !== 'disabled');
define('CUSTOM_EMOJI_USAGE_TRACKING', $mode === 'usage');
define('APP_DEBUG', false);

$GLOBALS['styled_test_schema_ready'] = true;
$GLOBALS['styled_test_query_count'] = 0;
$GLOBALS['styled_test_queries'] = [];
$GLOBALS['styled_test_query_handler'] = function ($sql, array $parameters) {
    $GLOBALS['styled_test_query_count']++;
    $GLOBALS['styled_test_queries'][] = $sql;
    $rows = [];
    foreach ($parameters as $parameter) {
        if ($parameter !== 'pro_max') {
            continue;
        }
        $rows[] = [
            'id' => 1,
            'display_name' => 'Pro Max',
            'key' => 'pro_max',
            'custom_emoji_id' => '5368324170671202286',
            'fallback_emoji' => '💎',
            'resolved_fallback' => '💎',
            'is_active' => 1,
            'is_valid' => 1,
            'validated_at' => '2026-01-01 00:00:00',
            'mapped_button_text' => null,
        ];
    }
    return $rows;
};

require dirname(__DIR__) . '/emoji_system.php';

function testAssert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, '[FAILED] ' . $message . PHP_EOL);
        exit(1);
    }
}

function resetStyledTestState()
{
    styledClearRuntimeCaches();
    $GLOBALS['styled_test_query_count'] = 0;
    $GLOBALS['styled_test_queries'] = [];
}

if ($mode === 'disabled') {
    $source = '{emoji:pro_max} متن عادی';
    $rendered = renderStyledText($source, 'HTML', 'disabled-test');
    $prepared = styledPrepareDisabledTelegramRequest([
        'text' => $source,
        'reply_markup' => json_encode([
            'inline_keyboard' => [[[
                'text' => '{emoji:pro_max} دکمه',
                'callback_data' => 'noop',
                'icon_emoji_key' => 'pro_max',
                'icon_custom_emoji_id' => '5368324170671202286',
            ]]],
        ], JSON_UNESCAPED_UNICODE),
    ]);
    testAssert($GLOBALS['styled_test_query_count'] === 0, 'Disabled mode executed a library query.');
    testAssert(strpos($rendered['text'], '{emoji:') === false, 'Disabled render exposed a raw placeholder.');
    testAssert(strpos($prepared['text'], '{emoji:') === false, 'Disabled transport exposed a raw placeholder.');
    testAssert(strpos($prepared['text'], 'متن عادی') !== false, 'Disabled transport damaged normal text.');
    testAssert(strpos($prepared['reply_markup'], 'icon_custom_emoji_id') === false, 'Disabled transport kept a premium icon.');
    testAssert(strpos($prepared['reply_markup'], '{emoji:') === false, 'Disabled keyboard exposed a raw placeholder.');
    fwrite(STDOUT, '[OK] disabled: queries=0, raw_placeholders=0, premium_icons=0' . PHP_EOL);
    exit(0);
}

if ($mode === 'usage') {
    $GLOBALS['styled_test_usage_flushes'] = [];
    $GLOBALS['styled_test_usage_handler'] = function (array $usageBuffer) {
        $GLOBALS['styled_test_usage_flushes'][] = $usageBuffer;
    };
    $usageRendered = renderStyledText(
        str_repeat('{emoji:pro_max} ', 10) . 'usage',
        'HTML',
        'usage-test'
    );
    testAssert($usageRendered['has_custom'] === true, 'Usage tracking disabled Custom Emoji rendering.');
    styledFlushUsage();
    testAssert(count($GLOBALS['styled_test_usage_flushes']) === 1, 'Usage buffer did not flush once.');
    $usageRows = $GLOBALS['styled_test_usage_flushes'][0];
    testAssert(count($usageRows) === 1, 'Repeated key generated more than one usage write candidate.');
    testAssert($usageRows['pro_max']['count'] === 10, 'Aggregated usage count is incorrect.');
    fwrite(STDOUT, '[OK] usage: rendering=enabled, keys_written=1, aggregated_count=10' . PHP_EOL);
    exit(0);
}

testAssert(
    styledTelegramErrorAllowsEmojiFallback([
        'error_code' => 400,
        'description' => 'Bad Request: CUSTOM_EMOJI_ID_INVALID',
    ]) === true,
    'A Custom Emoji error did not allow one fallback.'
);
testAssert(
    styledTelegramErrorAllowsEmojiFallback([
        'error_code' => 400,
        'description' => 'Bad Request: chat not found',
    ]) === false,
    'An unrelated Telegram error allowed emoji fallback.'
);
testAssert(
    styledTelegramErrorAllowsEmojiFallback([
        'error_code' => 429,
        'description' => 'Too Many Requests: retry after 10',
    ]) === false,
    'HTTP 429 incorrectly allowed immediate fallback.'
);
testAssert(
    styledTelegramErrorAllowsEmojiFallback([
        'error_code' => 500,
        'description' => 'Internal Server Error',
    ]) === false,
    'HTTP 5xx incorrectly allowed fallback.'
);

resetStyledTestState();
$GLOBALS['styled_runtime_button_icon_map'] = [];
$plainPrepared = styledPrepareTelegramRequest('sendMessage', [
    'chat_id' => 1,
    'text' => 'متن بدون ایموجی',
    'reply_markup' => json_encode([
        'inline_keyboard' => [[[
            'text' => 'دکمه عادی',
            'callback_data' => 'plain',
        ]]],
    ], JSON_UNESCAPED_UNICODE),
]);
testAssert($GLOBALS['styled_test_query_count'] === 0, 'Plain text and keyboard executed a library SELECT.');
testAssert($plainPrepared['primary']['text'] === 'متن بدون ایموجی', 'Plain text was changed.');

resetStyledTestState();
$tenTokens = str_repeat('{emoji:pro_max} ', 10) . 'A💎';
$tenRendered = renderStyledText($tenTokens, 'HTML', 'ten-emoji-test');
testAssert($GLOBALS['styled_test_query_count'] === 1, 'Ten emoji placeholders used more than one library SELECT.');
testAssert(count($tenRendered['entities']) === 10, 'Ten emoji placeholders did not create ten entities.');
testAssert(styledUtf16Length('A💎') === 3, 'UTF-16 length is incorrect.');
foreach ($tenRendered['entities'] as $entityIndex => $entity) {
    testAssert(
        $entity['offset'] === $entityIndex * 3 && $entity['length'] === 2,
        'Incremental UTF-16 entity offsets are incorrect.'
    );
}
testAssert(empty($GLOBALS['styled_usage_buffer']), 'Usage was buffered while tracking was disabled.');
foreach ($GLOBALS['styled_test_queries'] as $query) {
    testAssert(!preg_match('/\b(CREATE|ALTER|INSERT|UPDATE|DELETE)\b/i', $query), 'Runtime executed DDL or a write query.');
}

resetStyledTestState();
$buttons = [];
for ($index = 0; $index < 20; $index++) {
    $buttons[] = [[
        'text' => 'دکمه ' . $index,
        'callback_data' => 'button_' . $index,
        'icon_emoji_key' => 'pro_max',
    ]];
}
$keyboardPrepared = styledPrepareTelegramRequest('sendMessage', [
    'chat_id' => 1,
    'text' => 'متن عادی',
    'reply_markup' => json_encode(['inline_keyboard' => $buttons], JSON_UNESCAPED_UNICODE),
]);
testAssert($GLOBALS['styled_test_query_count'] === 1, 'Twenty buttons used more than one library SELECT.');
$primaryKeyboard = json_decode($keyboardPrepared['primary']['reply_markup'], true);
$fallbackKeyboard = json_decode($keyboardPrepared['fallback']['reply_markup'], true);
foreach ($primaryKeyboard['inline_keyboard'] as $rowIndex => $row) {
    testAssert(isset($row[0]['icon_custom_emoji_id']), 'A premium button is missing its icon ID.');
    testAssert(strpos($row[0]['text'], '💎') === false, 'Primary button contains fallback and premium icon together.');
    testAssert(!isset($fallbackKeyboard['inline_keyboard'][$rowIndex][0]['icon_custom_emoji_id']), 'Fallback button retained premium icon.');
    testAssert(strpos($fallbackKeyboard['inline_keyboard'][$rowIndex][0]['text'], '💎') === 0, 'Fallback button is missing fallback emoji.');
}

resetStyledTestState();
$combined = styledPrepareTelegramRequest('sendPhoto', [
    'chat_id' => 1,
    'photo' => 'file-id',
    'caption' => '{emoji:pro_max} کپشن',
    'reply_markup' => json_encode(['inline_keyboard' => $buttons], JSON_UNESCAPED_UNICODE),
]);
testAssert($GLOBALS['styled_test_query_count'] === 1, 'Caption and keyboard did not share one library SELECT.');
testAssert(!empty($combined['primary']['caption_entities']), 'Caption custom emoji entity was not created.');

resetStyledTestState();
$invalidHandler = $GLOBALS['styled_test_query_handler'];
$GLOBALS['styled_test_query_handler'] = function ($sql, array $parameters) use ($invalidHandler) {
    $rows = $invalidHandler($sql, $parameters);
    foreach ($rows as &$row) {
        $row['is_valid'] = 0;
    }
    unset($row);
    return $rows;
};
$invalid = renderStyledText('{emoji:pro_max} نامعتبر', 'HTML', 'invalid-test');
testAssert($GLOBALS['styled_test_query_count'] === 1, 'Invalid emoji used more than one library SELECT.');
testAssert($invalid['has_custom'] === false, 'Invalid emoji was treated as premium.');
testAssert(strpos($invalid['text'], '💎') === 0, 'Invalid emoji did not render fallback.');

resetStyledTestState();
$GLOBALS['styled_test_query_handler'] = function ($sql, array $parameters) {
    $GLOBALS['styled_test_query_count']++;
    $GLOBALS['styled_test_queries'][] = $sql;
    return [];
};
$unknown = renderStyledText('{emoji:unknown_key} متن', 'HTML', 'unknown-test');
testAssert(strpos($unknown['text'], '{emoji:') === false, 'Unknown key exposed a raw placeholder.');
testAssert(strpos($unknown['text'], 'متن') !== false, 'Unknown key damaged the original text.');

testAssert(
    !in_array(realpath(dirname(__DIR__) . '/emoji_install.php'), array_map('realpath', get_included_files()), true),
    'Runtime included emoji_install.php.'
);

fwrite(
    STDOUT,
    '[OK] enabled: ten_emoji_queries=1, twenty_button_queries=1, combined_queries=1, usage_writes=0, ddl=0' . PHP_EOL
);
