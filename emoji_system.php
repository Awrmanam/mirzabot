<?php

if (!defined('CUSTOM_EMOJI_ENABLED')) {
    define('CUSTOM_EMOJI_ENABLED', false);
}

function styledCustomEmojiEnabled()
{
    return defined('CUSTOM_EMOJI_ENABLED') && CUSTOM_EMOJI_ENABLED === true;
}

/**
 * Central styling and Telegram Custom Emoji service.
 *
 * Every Telegram request passes through styledPrepareTelegramRequest(), so
 * editable text from every bot module can use {emoji:key} without duplicating
 * rendering logic.
 */

function styledSystemReady()
{
    global $pdo;
    static $ready = null;
    if (!styledCustomEmojiEnabled()) {
        return false;
    }
    if (defined('MIRZA_TEST_MODE')
        && MIRZA_TEST_MODE === true
        && array_key_exists('styled_test_schema_ready', $GLOBALS)) {
        return $GLOBALS['styled_test_schema_ready'] === true;
    }
    if ($ready !== null) {
        return $ready;
    }
    $markerPath = __DIR__ . '/storage/cache/custom_emoji_schema.ready';
    $ready = isset($pdo)
        && $pdo instanceof PDO
        && is_file($markerPath);
    if (!$ready) {
        styledLog(
            'schema_not_ready',
            '',
            'runtime',
            'Custom Emoji is enabled but its explicit migration marker is missing.'
        );
    }
    return $ready;
}

function styledSetting($key, $default = '')
{
    global $pdo;
    if (!styledSystemReady()) {
        return $default;
    }
    $key = (string) $key;
    if (!isset($GLOBALS['styled_runtime_settings'])
        || !is_array($GLOBALS['styled_runtime_settings'])) {
        $GLOBALS['styled_runtime_settings'] = [];
    }
    if (array_key_exists($key, $GLOBALS['styled_runtime_settings'])) {
        $cachedValue = $GLOBALS['styled_runtime_settings'][$key];
        return $cachedValue === null ? $default : $cachedValue;
    }
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM styled_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        $GLOBALS['styled_runtime_settings'][$key] = $value === false ? null : (string) $value;
        return $value === false ? $default : (string) $value;
    } catch (Throwable $e) {
        $GLOBALS['styled_runtime_settings'][$key] = null;
        return $default;
    }
}

function styledSetSetting($key, $value)
{
    global $pdo;
    if (!styledCustomEmojiEnabled() || !styledSystemReady()) {
        return false;
    }
    $stmt = $pdo->prepare("INSERT INTO styled_settings (setting_key, setting_value)
        VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $result = $stmt->execute([(string) $key, (string) $value]);
    if ($result) {
        $GLOBALS['styled_runtime_settings'][(string) $key] = (string) $value;
    }
    return $result;
}

function styledEscape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function styledLimit($value, $length)
{
    $value = trim((string) $value);
    return function_exists('mb_substr')
        ? mb_substr($value, 0, (int) $length, 'UTF-8')
        : substr($value, 0, (int) $length);
}

function styledUtf16Length($value)
{
    $value = (string) $value;
    if ($value === '') {
        return 0;
    }
    if (function_exists('mb_convert_encoding')) {
        $encoded = mb_convert_encoding($value, 'UTF-16LE', 'UTF-8');
        return (int) (strlen($encoded) / 2);
    }
    if (function_exists('iconv')) {
        $encoded = iconv('UTF-8', 'UTF-16LE//IGNORE', $value);
        if ($encoded !== false) {
            return (int) (strlen($encoded) / 2);
        }
    }
    $length = 0;
    $bytes = strlen($value);
    for ($index = 0; $index < $bytes;) {
        $byte = ord($value[$index]);
        if ($byte < 0x80) {
            $codepoint = $byte;
            $index += 1;
        } elseif (($byte & 0xE0) === 0xC0 && $index + 1 < $bytes) {
            $codepoint = (($byte & 0x1F) << 6) | (ord($value[$index + 1]) & 0x3F);
            $index += 2;
        } elseif (($byte & 0xF0) === 0xE0 && $index + 2 < $bytes) {
            $codepoint = (($byte & 0x0F) << 12)
                | ((ord($value[$index + 1]) & 0x3F) << 6)
                | (ord($value[$index + 2]) & 0x3F);
            $index += 3;
        } elseif (($byte & 0xF8) === 0xF0 && $index + 3 < $bytes) {
            $codepoint = (($byte & 0x07) << 18)
                | ((ord($value[$index + 1]) & 0x3F) << 12)
                | ((ord($value[$index + 2]) & 0x3F) << 6)
                | (ord($value[$index + 3]) & 0x3F);
            $index += 4;
        } else {
            $codepoint = 0xFFFD;
            $index += 1;
        }
        $length += $codepoint > 0xFFFF ? 2 : 1;
    }
    return $length;
}

function styledLog($type, $emojiKey, $context, $message)
{
    if (defined('MIRZA_TEST_MODE') && MIRZA_TEST_MODE === true) {
        $GLOBALS['styled_test_logs'][] = [
            'type' => (string) $type,
            'emoji_key' => (string) $emojiKey,
            'context' => (string) $context,
        ];
        return;
    }
    $type = preg_replace('/[^a-z0-9_-]/i', '', (string) $type);
    $emojiKey = styledLimit(preg_replace('/[^a-z0-9_-]/i', '', (string) $emojiKey), 100);
    $context = styledLimit(preg_replace('/[^a-z0-9:_-]/i', '', (string) $context), 120);
    $rateKey = hash('sha256', $type . '|' . $emojiKey . '|' . $context);
    $cacheDirectory = __DIR__ . '/storage/cache';
    if (!is_dir($cacheDirectory)
        && !mkdir($cacheDirectory, 0775, true)
        && !is_dir($cacheDirectory)) {
        return;
    }
    $cacheFile = $cacheDirectory . '/styled_log_rate.json';
    $handle = @fopen($cacheFile, 'c+');
    if ($handle === false) {
        return;
    }
    $shouldLog = false;
    try {
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            return;
        }
        rewind($handle);
        $stored = json_decode((string) stream_get_contents($handle), true);
        if (!is_array($stored)) {
            $stored = [];
        }
        $now = time();
        foreach ($stored as $key => $timestamp) {
            if (!is_numeric($timestamp) || $now - (int) $timestamp > 3600) {
                unset($stored[$key]);
            }
        }
        if (!isset($stored[$rateKey]) || $now - (int) $stored[$rateKey] >= 300) {
            $stored[$rateKey] = $now;
            $shouldLog = true;
        }
        if (count($stored) > 200) {
            asort($stored);
            $stored = array_slice($stored, -200, null, true);
        }
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode($stored));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
    } catch (Throwable $ignored) {
        @flock($handle, LOCK_UN);
        @fclose($handle);
        return;
    }
    if ($shouldLog) {
        $detail = defined('APP_DEBUG') && APP_DEBUG
            ? ' ' . styledLimit(preg_replace('/\s+/u', ' ', (string) $message), 500)
            : '';
        error_log('[Mirza Styled Emoji][' . $type . '][' . $emojiKey . '][' . $context . ']' . $detail);
    }
}

function styledRecordUsage($emojiKey, $sourceType, $sourceKey, $sourceLabel = '')
{
    if (!defined('CUSTOM_EMOJI_USAGE_TRACKING')
        || CUSTOM_EMOJI_USAGE_TRACKING !== true
        || !styledSystemReady()) {
        return;
    }
    $emojiKey = trim((string) $emojiKey);
    if (!preg_match('/^[a-z0-9_]{1,100}$/', $emojiKey)) {
        return;
    }
    if (!isset($GLOBALS['styled_usage_buffer']) || !is_array($GLOBALS['styled_usage_buffer'])) {
        $GLOBALS['styled_usage_buffer'] = [];
    }
    if (!isset($GLOBALS['styled_usage_shutdown_registered'])) {
        $GLOBALS['styled_usage_shutdown_registered'] = true;
        register_shutdown_function('styledFlushUsage');
    }
    if (!isset($GLOBALS['styled_usage_buffer'][$emojiKey])) {
        $GLOBALS['styled_usage_buffer'][$emojiKey] = [
            'count' => 0,
            'source_type' => styledLimit($sourceType, 80),
            'source_label' => styledLimit($sourceLabel, 120),
        ];
    }
    $GLOBALS['styled_usage_buffer'][$emojiKey]['count']++;
}

function styledFlushUsage()
{
    global $pdo;
    if (!defined('CUSTOM_EMOJI_USAGE_TRACKING')
        || CUSTOM_EMOJI_USAGE_TRACKING !== true
        || empty($GLOBALS['styled_usage_buffer'])) {
        return;
    }
    $usageBuffer = $GLOBALS['styled_usage_buffer'];
    $GLOBALS['styled_usage_buffer'] = [];
    if (PHP_SAPI !== 'cli' && function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
    }
    if (defined('MIRZA_TEST_MODE')
        && MIRZA_TEST_MODE === true
        && isset($GLOBALS['styled_test_usage_handler'])
        && is_callable($GLOBALS['styled_test_usage_handler'])) {
        call_user_func($GLOBALS['styled_test_usage_handler'], $usageBuffer);
        return;
    }
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        return;
    }
    try {
        $stmt = $pdo->prepare("INSERT INTO styled_emoji_usage
            (emoji_key, source_type, source_key, source_label, use_count)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                use_count = use_count + VALUES(use_count),
                source_label = VALUES(source_label),
                last_seen_at = CURRENT_TIMESTAMP");
        foreach ($usageBuffer as $emojiKey => $usage) {
            $stmt->execute([
                $emojiKey,
                'request_aggregate',
                $emojiKey,
                $usage['source_label'],
                max(1, (int) $usage['count']),
            ]);
        }
    } catch (Throwable $ignored) {
        styledLog('usage_flush_failed', '', 'shutdown', $ignored->getMessage());
    }
}

function styledFetchEmojiRows($sql, array $parameters)
{
    global $pdo;
    if (defined('MIRZA_TEST_MODE')
        && MIRZA_TEST_MODE === true
        && isset($GLOBALS['styled_test_query_handler'])
        && is_callable($GLOBALS['styled_test_query_handler'])) {
        return (array) call_user_func($GLOBALS['styled_test_query_handler'], $sql, $parameters);
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($parameters);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function styledPreloadEmojiKeys(array $keys)
{
    global $pdo;
    if (!styledCustomEmojiEnabled() || !styledSystemReady()) {
        return;
    }
    if (!isset($GLOBALS['styled_runtime_emoji_cache'])
        || !is_array($GLOBALS['styled_runtime_emoji_cache'])) {
        $GLOBALS['styled_runtime_emoji_cache'] = [];
    }
    $keys = array_values(array_unique(array_filter(array_map(function ($key) {
        $key = trim((string) $key);
        return preg_match('/^[a-z0-9_]{1,100}$/', $key) ? $key : null;
    }, $keys))));
    $missing = array_values(array_filter($keys, function ($key) {
        return !array_key_exists($key, $GLOBALS['styled_runtime_emoji_cache']);
    }));
    if (!$missing) {
        return;
    }
    foreach ($missing as $key) {
        $GLOBALS['styled_runtime_emoji_cache'][$key] = false;
    }
    try {
        $placeholders = implode(',', array_fill(0, count($missing), '?'));
        $rows = styledFetchEmojiRows(
            "SELECT id, display_name, `key`, custom_emoji_id, fallback_emoji,
                    is_active, is_valid, validated_at,
                    COALESCE(NULLIF(fallback_emoji, ''), settings.setting_value, '')
                        AS resolved_fallback
             FROM styled_emojis
             LEFT JOIN styled_settings settings
               ON settings.setting_key = 'global_fallback'
             WHERE `key` IN ({$placeholders})",
            $missing
        );
        foreach ($rows as $row) {
            $GLOBALS['styled_runtime_emoji_cache'][(string) $row['key']] = $row;
            if (!isset($GLOBALS['styled_runtime_global_fallback'])
                && isset($row['resolved_fallback'])) {
                $GLOBALS['styled_runtime_global_fallback'] = (string) $row['resolved_fallback'];
            }
        }
    } catch (Throwable $e) {
        styledLog('library_query_failed', '', 'preload', $e->getMessage());
    }
}

function styledCollectTextEmojiKeys($text)
{
    $keys = [];
    if (is_string($text)
        && preg_match_all('/\{emoji:([a-z0-9_]+)\}/', $text, $matches)) {
        $keys = $matches[1];
    }
    return $keys;
}

function styledPreloadTelegramContext(array $datas)
{
    global $pdo;
    if (!styledCustomEmojiEnabled() || !styledSystemReady()) {
        return;
    }
    $keys = [];
    foreach (['text', 'caption'] as $textField) {
        if (isset($datas[$textField])) {
            $keys = array_merge($keys, styledCollectTextEmojiKeys($datas[$textField]));
        }
    }
    $buttonTexts = [];
    if (isset($datas['reply_markup'])) {
        $markup = is_string($datas['reply_markup'])
            ? json_decode($datas['reply_markup'], true)
            : $datas['reply_markup'];
        if (is_array($markup)) {
            foreach (['inline_keyboard', 'keyboard'] as $container) {
                foreach ((array) ($markup[$container] ?? []) as $row) {
                    foreach ((array) $row as $button) {
                        if (!is_array($button)) {
                            continue;
                        }
                        $buttonText = (string) ($button['text'] ?? '');
                        $buttonKey = trim((string) ($button['icon_emoji_key'] ?? ''));
                        $textKeys = styledCollectTextEmojiKeys($buttonText);
                        if ($buttonKey !== '') {
                            $keys[] = $buttonKey;
                        } elseif ($textKeys) {
                            $keys = array_merge($keys, $textKeys);
                        } elseif ($buttonText !== '') {
                            if (isset($GLOBALS['styled_runtime_button_icon_map'])
                                && is_array($GLOBALS['styled_runtime_button_icon_map'])) {
                                $mappedKey = trim((string) (
                                    $GLOBALS['styled_runtime_button_icon_map'][$buttonText] ?? ''
                                ));
                                if ($mappedKey !== '') {
                                    $keys[] = $mappedKey;
                                }
                            } else {
                                $buttonTexts[] = $buttonText;
                            }
                        }
                    }
                }
            }
        }
    }
    $keys = array_values(array_unique(array_filter($keys, function ($key) {
        return preg_match('/^[a-z0-9_]{1,100}$/', (string) $key);
    })));
    $buttonTexts = array_values(array_unique($buttonTexts));
    if (!$keys && !$buttonTexts) {
        return;
    }
    if (!isset($GLOBALS['styled_runtime_emoji_cache'])
        || !is_array($GLOBALS['styled_runtime_emoji_cache'])) {
        $GLOBALS['styled_runtime_emoji_cache'] = [];
    }
    if (!isset($GLOBALS['styled_runtime_button_icon_map'])
        || !is_array($GLOBALS['styled_runtime_button_icon_map'])) {
        $GLOBALS['styled_runtime_button_icon_map'] = [];
    }
    foreach ($keys as $key) {
        if (!array_key_exists($key, $GLOBALS['styled_runtime_emoji_cache'])) {
            $GLOBALS['styled_runtime_emoji_cache'][$key] = false;
        }
    }
    foreach ($buttonTexts as $buttonText) {
        if (!array_key_exists($buttonText, $GLOBALS['styled_runtime_button_icon_map'])) {
            $GLOBALS['styled_runtime_button_icon_map'][$buttonText] = '';
        }
    }
    $conditions = [];
    $parameters = [];
    if ($keys) {
        $conditions[] = 'e.`key` IN (' . implode(',', array_fill(0, count($keys), '?')) . ')';
        $parameters = array_merge($parameters, $keys);
    }
    if ($buttonTexts) {
        $conditions[] = 't.text IN (' . implode(',', array_fill(0, count($buttonTexts), '?')) . ')';
        $parameters = array_merge($parameters, $buttonTexts);
    }
    try {
        $rows = styledFetchEmojiRows(
            "SELECT e.id, e.display_name, e.`key`, e.custom_emoji_id,
                    e.fallback_emoji, e.is_active, e.is_valid, e.validated_at,
                    t.text AS mapped_button_text,
                    COALESCE(NULLIF(e.fallback_emoji, ''), settings.setting_value, '')
                        AS resolved_fallback
             FROM styled_emojis e
             LEFT JOIN styled_settings settings
               ON settings.setting_key = 'global_fallback'
             LEFT JOIN styled_button_icons bi
               ON bi.icon_emoji_key = e.`key` AND bi.source_type = 'textbot'
             LEFT JOIN textbot t ON t.id_text = bi.source_key
             WHERE " . implode(' OR ', $conditions),
            $parameters
        );
        foreach ($rows as $row) {
            $GLOBALS['styled_runtime_emoji_cache'][(string) $row['key']] = $row;
            if (!isset($GLOBALS['styled_runtime_global_fallback'])
                && isset($row['resolved_fallback'])) {
                $GLOBALS['styled_runtime_global_fallback'] = (string) $row['resolved_fallback'];
            }
            if (!empty($row['mapped_button_text'])) {
                $GLOBALS['styled_runtime_button_icon_map'][(string) $row['mapped_button_text']]
                    = (string) $row['key'];
            }
        }
    } catch (Throwable $e) {
        styledLog('library_query_failed', '', 'telegram_context', $e->getMessage());
    }
}

function styledEmojiByKey($key)
{
    $key = trim((string) $key);
    if (!preg_match('/^[a-z0-9_]{1,100}$/', $key) || !styledSystemReady()) {
        return false;
    }
    if (!isset($GLOBALS['styled_runtime_emoji_cache'])
        || !array_key_exists($key, $GLOBALS['styled_runtime_emoji_cache'])) {
        styledPreloadEmojiKeys([$key]);
    }
    return $GLOBALS['styled_runtime_emoji_cache'][$key] ?? false;
}

function styledClearRuntimeCaches()
{
    $GLOBALS['styled_runtime_emoji_cache'] = [];
    $GLOBALS['styled_runtime_settings'] = [];
    $GLOBALS['styled_runtime_button_icon_map'] = null;
    $GLOBALS['styled_runtime_ui_cache'] = [];
    unset($GLOBALS['styled_runtime_global_fallback']);
}

function styledResolveEmoji($key, $context = '')
{
    $row = styledEmojiByKey($key);
    $environmentFallback = getenv('CUSTOM_EMOJI_DISABLED_FALLBACK');
    $globalFallback = isset($GLOBALS['styled_runtime_global_fallback'])
        ? (string) $GLOBALS['styled_runtime_global_fallback']
        : ($environmentFallback !== false ? (string) $environmentFallback : '');
    if (!$row) {
        styledLog('unknown_key', $key, $context, 'Unknown emoji key {' . $key . '} was rendered.');
        return [
            'key' => (string) $key,
            'fallback' => $globalFallback,
            'custom_emoji_id' => '',
            'usable' => false,
        ];
    }
    $fallback = trim((string) ($row['resolved_fallback'] ?? $row['fallback_emoji'] ?? ''));
    if ($fallback === '') {
        $fallback = $globalFallback;
    }
    $customId = trim((string) $row['custom_emoji_id']);
    $usable = (int) $row['is_active'] === 1
        && preg_match('/^[0-9]{5,64}$/', $customId)
        && (int) $row['is_valid'] === 1;
    if (!$usable && (int) $row['is_active'] !== 1) {
        styledLog('inactive_key', $key, $context, 'Inactive emoji key {' . $key . '} used; fallback rendered.');
    }
    return [
        'key' => (string) $row['key'],
        'fallback' => $fallback,
        'custom_emoji_id' => $usable ? $customId : '',
        'usable' => (bool) $usable,
    ];
}

function styledToken($key)
{
    return '{emoji:' . (string) $key . '}';
}

function styledDisabledFallbackMap()
{
    static $fallbackMap = null;
    if ($fallbackMap !== null) {
        return $fallbackMap;
    }
    $fallbackMap = [];
    if (defined('CUSTOM_EMOJI_STATIC_FALLBACKS')
        && is_array(CUSTOM_EMOJI_STATIC_FALLBACKS)) {
        $fallbackMap = CUSTOM_EMOJI_STATIC_FALLBACKS;
    } else {
        $encoded = getenv('CUSTOM_EMOJI_DISABLED_FALLBACKS');
        $decoded = $encoded !== false ? json_decode($encoded, true) : null;
        if (is_array($decoded)) {
            $fallbackMap = $decoded;
        }
    }
    return array_filter($fallbackMap, function ($value, $key) {
        return preg_match('/^[a-z0-9_]{1,100}$/', (string) $key)
            && is_string($value);
    }, ARRAY_FILTER_USE_BOTH);
}

function styledReplaceDisabledTokens($text)
{
    $fallbackMap = styledDisabledFallbackMap();
    $generalFallback = trim((string) getenv('CUSTOM_EMOJI_DISABLED_FALLBACK'));
    $rendered = preg_replace_callback(
        '/\{emoji:([^{}]+)\}/',
        function ($match) use ($fallbackMap, $generalFallback) {
            return array_key_exists($match[1], $fallbackMap)
                ? (string) $fallbackMap[$match[1]]
                : $generalFallback;
        },
        (string) $text
    );
    return is_string($rendered) ? $rendered : (string) $text;
}

function styledFlattenLanguageValues(array $values, $prefix = '')
{
    $flat = [];
    foreach ($values as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
        if (is_array($value)) {
            $flat += styledFlattenLanguageValues($value, $path);
        } elseif (is_string($value) || is_numeric($value)) {
            $flat[$path] = (string) $value;
        }
    }
    return $flat;
}

function styledSetNestedValue(array &$values, array $segments, $value)
{
    $cursor =& $values;
    foreach ($segments as $index => $segment) {
        if ($index === count($segments) - 1) {
            $cursor[$segment] = $value;
            return;
        }
        if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
            $cursor[$segment] = [];
        }
        $cursor =& $cursor[$segment];
    }
}

function styledApplyLanguageOverrides($language, array $values, $sourcePath = '')
{
    global $pdo;
    if (!styledSystemReady()) {
        return $values;
    }
    $language = preg_replace('/[^a-z0-9_-]/i', '', (string) $language);
    try {
        $stmt = $pdo->prepare("SELECT source_key, value FROM styled_text_overrides
            WHERE source_type = 'language' AND is_active = 1 AND source_key LIKE ?");
        $stmt->execute([$language . '.%']);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $relativePath = substr((string) $row['source_key'], strlen($language) + 1);
            if ($relativePath === '') {
                continue;
            }
            styledSetNestedValue($values, explode('.', $relativePath), (string) $row['value']);
        }
    } catch (Throwable $e) {
        styledLog('language_apply_failed', '', 'language', $e->getMessage());
    }
    return $values;
}

function styledHtmlEntityDefinition($tagName, array $attributes)
{
    $tagName = strtolower((string) $tagName);
    $map = [
        'b' => 'bold',
        'strong' => 'bold',
        'i' => 'italic',
        'em' => 'italic',
        'u' => 'underline',
        'ins' => 'underline',
        's' => 'strikethrough',
        'strike' => 'strikethrough',
        'del' => 'strikethrough',
        'tg-spoiler' => 'spoiler',
        'code' => 'code',
        'pre' => 'pre',
        'blockquote' => 'blockquote',
        'a' => 'text_link',
        'tg-emoji' => 'custom_emoji',
    ];
    if (!isset($map[$tagName])) {
        return false;
    }
    $definition = ['type' => $map[$tagName]];
    if ($tagName === 'a') {
        $href = (string) ($attributes['href'] ?? '');
        if ($href === '') {
            return false;
        }
        $definition['url'] = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    } elseif ($tagName === 'pre' && !empty($attributes['language'])) {
        $definition['language'] = (string) $attributes['language'];
    } elseif ($tagName === 'tg-emoji') {
        $customId = (string) ($attributes['emoji-id'] ?? $attributes['emoji_id'] ?? '');
        if (!preg_match('/^[0-9]{5,64}$/', $customId)) {
            return false;
        }
        $definition['custom_emoji_id'] = $customId;
    }
    if ($tagName === 'blockquote' && isset($attributes['expandable'])) {
        $definition['type'] = 'expandable_blockquote';
    }
    return $definition;
}

function styledParseTagAttributes($rawAttributes)
{
    $attributes = [];
    if (preg_match_all(
        '/([a-zA-Z0-9_-]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+)))?/',
        (string) $rawAttributes,
        $matches,
        PREG_SET_ORDER
    )) {
        foreach ($matches as $match) {
            $name = strtolower($match[1]);
            $value = $match[2] !== '' ? $match[2] : ($match[3] !== '' ? $match[3] : ($match[4] ?? ''));
            $attributes[$name] = $value;
        }
    }
    return $attributes;
}

function styledAppendEntity(array &$entities, array $openEntity, $endOffset)
{
    $length = (int) $endOffset - (int) $openEntity['offset'];
    if ($length <= 0) {
        return;
    }
    $entity = [
        'type' => $openEntity['type'],
        'offset' => (int) $openEntity['offset'],
        'length' => $length,
    ];
    foreach (['url', 'language', 'custom_emoji_id'] as $extraField) {
        if (isset($openEntity[$extraField]) && $openEntity[$extraField] !== '') {
            $entity[$extraField] = $openEntity[$extraField];
        }
    }
    $entities[] = $entity;
}

function styledParseRichText($template, $parseHtml, $context)
{
    $template = (string) $template;
    $parts = preg_split(
        '/(\{emoji:[^{}]+\}|<[^>]+>|&(?:#[0-9]+|#x[0-9a-fA-F]+|[a-zA-Z][a-zA-Z0-9]+);)/u',
        $template,
        -1,
        PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
    );
    if (!is_array($parts)) {
        $parts = [$template];
    }

    $plainText = '';
    $entities = [];
    $fallbackEntities = [];
    $utf16Offset = 0;
    $openEntities = [];
    $tagEntityIds = [];
    $nextEntityId = 0;
    $hasCustom = false;

    foreach ($parts as $part) {
        if (preg_match('/^\{emoji:([a-z0-9_]+)\}$/', $part, $tokenMatch)) {
            $resolved = styledResolveEmoji($tokenMatch[1], $context);
            $offset = $utf16Offset;
            $fallback = (string) $resolved['fallback'];
            $plainText .= $fallback;
            $length = styledUtf16Length($fallback);
            $utf16Offset += $length;
            styledRecordUsage(
                $tokenMatch[1],
                'telegram_text',
                substr(hash('sha256', $template), 0, 40),
                styledLimit(strip_tags($template), 180)
            );
            if ($resolved['usable'] && $fallback !== '' && $length > 0) {
                $entities[] = [
                    'type' => 'custom_emoji',
                    'offset' => $offset,
                    'length' => $length,
                    'custom_emoji_id' => (string) $resolved['custom_emoji_id'],
                ];
                $hasCustom = true;
            }
            continue;
        }
        if (preg_match('/^\{emoji:([^{}]+)\}$/', $part, $invalidTokenMatch)) {
            $fallback = trim((string) getenv('CUSTOM_EMOJI_DISABLED_FALLBACK'));
            $plainText .= $fallback;
            $utf16Offset += styledUtf16Length($fallback);
            styledLog(
                'invalid_key',
                '',
                $context,
                'Invalid Custom Emoji key syntax was removed.'
            );
            continue;
        }

        if ($parseHtml && strlen($part) > 1 && $part[0] === '<') {
            if (preg_match('/^<\s*br\s*\/?\s*>$/i', $part)) {
                $plainText .= "\n";
                $utf16Offset++;
                continue;
            }
            if (preg_match('/^<\s*\/\s*([a-zA-Z0-9_-]+)\s*>$/', $part, $closeMatch)) {
                $tagName = strtolower($closeMatch[1]);
                if (!empty($tagEntityIds[$tagName])) {
                    $entityId = array_pop($tagEntityIds[$tagName]);
                    $open = $openEntities[$entityId] ?? null;
                    unset($openEntities[$entityId]);
                    if ($open) {
                        styledAppendEntity($entities, $open, $utf16Offset);
                        if ($open['type'] !== 'custom_emoji') {
                            styledAppendEntity($fallbackEntities, $open, $utf16Offset);
                        } else {
                            $hasCustom = true;
                        }
                    }
                }
                continue;
            }
            if (preg_match('/^<\s*([a-zA-Z0-9_-]+)([^>]*)>$/s', $part, $openMatch)) {
                $tagName = strtolower($openMatch[1]);
                $attributes = styledParseTagAttributes($openMatch[2]);
                $definition = styledHtmlEntityDefinition($tagName, $attributes);
                if ($definition) {
                    $definition['tag'] = $tagName;
                    $definition['offset'] = $utf16Offset;
                    $entityId = $nextEntityId++;
                    $openEntities[$entityId] = $definition;
                    $tagEntityIds[$tagName][] = $entityId;
                }
                continue;
            }
        }

        $decoded = $parseHtml
            ? html_entity_decode($part, ENT_QUOTES | ENT_HTML5, 'UTF-8')
            : $part;
        $plainText .= $decoded;
        $utf16Offset += styledUtf16Length($decoded);
    }

    foreach (array_reverse($openEntities, true) as $open) {
        styledAppendEntity($entities, $open, $utf16Offset);
        if ($open['type'] !== 'custom_emoji') {
            styledAppendEntity($fallbackEntities, $open, $utf16Offset);
        } else {
            $hasCustom = true;
        }
    }

    usort($entities, function ($left, $right) {
        if ($left['offset'] === $right['offset']) {
            return $right['length'] <=> $left['length'];
        }
        return $left['offset'] <=> $right['offset'];
    });
    usort($fallbackEntities, function ($left, $right) {
        if ($left['offset'] === $right['offset']) {
            return $right['length'] <=> $left['length'];
        }
        return $left['offset'] <=> $right['offset'];
    });

    return [
        'text' => $plainText,
        'entities' => $entities,
        'fallback_entities' => $fallbackEntities,
        'has_custom' => $hasCustom,
    ];
}

function renderStyledText($template, $parseMode = 'HTML', $context = 'renderStyledText')
{
    $template = (string) $template;
    if (!styledCustomEmojiEnabled()) {
        $disabledText = styledReplaceDisabledTokens($template);
        return [
            'text' => $disabledText,
            'entities' => [],
            'fallback_text' => $disabledText,
            'fallback_entities' => [],
            'has_custom' => false,
            'preserve_parse_mode' => true,
        ];
    }
    $normalizedMode = strtolower(trim((string) $parseMode));
    $containsStyledEmoji = preg_match('/\{emoji:[^{}]+\}|<tg-emoji\b/i', $template);
    if (!$containsStyledEmoji) {
        return [
            'text' => $template,
            'entities' => [],
            'fallback_text' => $template,
            'fallback_entities' => [],
            'has_custom' => false,
            'preserve_parse_mode' => true,
        ];
    }
    styledPreloadEmojiKeys(styledCollectTextEmojiKeys($template));
    if ($normalizedMode !== '' && $normalizedMode !== 'html') {
        $fallbackText = preg_replace_callback('/\{emoji:([a-z0-9_]+)\}/', function ($match) use ($context) {
            return styledResolveEmoji($match[1], $context)['fallback'];
        }, $template);
        $fallbackText = preg_replace('/\{emoji:[^{}]+\}/', '', (string) $fallbackText);
        styledLog(
            'parse_mode_fallback',
            '',
            $context,
            'Custom emoji used with unsupported parse mode ' . $parseMode . '; fallback rendered.'
        );
        return [
            'text' => $fallbackText,
            'entities' => [],
            'fallback_text' => $fallbackText,
            'fallback_entities' => [],
            'has_custom' => false,
            'preserve_parse_mode' => true,
        ];
    }
    $parsed = styledParseRichText($template, $normalizedMode === 'html', $context);
    return [
        'text' => $parsed['text'],
        'entities' => $parsed['entities'],
        'fallback_text' => $parsed['text'],
        'fallback_entities' => $parsed['fallback_entities'],
        'has_custom' => $parsed['has_custom'],
        'preserve_parse_mode' => false,
    ];
}

function styledButtonIconKeyForText($buttonText)
{
    $buttonText = (string) $buttonText;
    if (!isset($GLOBALS['styled_runtime_button_icon_map'])
        || !array_key_exists($buttonText, $GLOBALS['styled_runtime_button_icon_map'])) {
        styledPreloadTelegramContext([
            'reply_markup' => [
                'keyboard' => [[['text' => $buttonText]]],
            ],
        ]);
    }
    return $GLOBALS['styled_runtime_button_icon_map'][$buttonText] ?? '';
}

function buildStyledButton($text, array $action, $iconEmojiKey = '')
{
    $button = array_merge(['text' => (string) $text], $action);
    if (styledCustomEmojiEnabled() && $iconEmojiKey !== '') {
        $button['icon_emoji_key'] = (string) $iconEmojiKey;
    }
    return $button;
}

function styledPrepareButton(array $button, $context)
{
    $fallbackButton = $button;
    $text = (string) ($button['text'] ?? '');
    $iconKey = trim((string) ($button['icon_emoji_key'] ?? ''));
    unset($button['icon_emoji_key'], $fallbackButton['icon_emoji_key']);

    if (preg_match('/\{emoji:([a-z0-9_]+)\}/', $text, $tokenMatch)) {
        if ($iconKey === '') {
            $iconKey = $tokenMatch[1];
        }
    }
    $text = preg_replace('/\{emoji:[^{}]+\}\s*/', '', $text);
    if ($iconKey === '') {
        $iconKey = styledButtonIconKeyForText($text);
    }
    if ($iconKey === '') {
        $button['text'] = $text;
        $fallbackButton['text'] = $text;
        return [$button, $fallbackButton, false];
    }

    $resolved = styledResolveEmoji($iconKey, $context);
    $fallback = trim((string) $resolved['fallback']);
    $cleanText = trim($text);
    if ($fallback !== '' && strpos($cleanText, $fallback) === 0) {
        $cleanText = ltrim(substr($cleanText, strlen($fallback)));
    }
    $withoutLeadingEmoji = preg_replace(
        '/^(?:[\p{So}\p{Sk}\p{Mn}\x{FE0F}\x{200D}\x{20E3}]+\s*)+/u',
        '',
        $cleanText
    );
    if (is_string($withoutLeadingEmoji) && $withoutLeadingEmoji !== '') {
        $cleanText = $withoutLeadingEmoji;
    }
    if ($cleanText === '') {
        $cleanText = $fallback !== '' ? $fallback : ' ';
    }
    $fallbackText = trim(($fallback !== '' ? $fallback . ' ' : '') . $cleanText);

    styledRecordUsage(
        $iconKey,
        'telegram_button',
        substr(hash('sha256', $context . '|' . $text), 0, 40),
        styledLimit($text, 180)
    );
    if ($resolved['usable']) {
        $button['text'] = styledLimit($cleanText, 64);
        $button['icon_custom_emoji_id'] = (string) $resolved['custom_emoji_id'];
        $fallbackButton['text'] = styledLimit($fallbackText, 64);
        unset($fallbackButton['icon_custom_emoji_id']);
        return [$button, $fallbackButton, true];
    }

    $button['text'] = styledLimit($fallbackText, 64);
    unset($button['icon_custom_emoji_id']);
    $fallbackButton = $button;
    return [$button, $fallbackButton, false];
}

function styledPrepareReplyMarkup($replyMarkup, $context)
{
    if (!styledCustomEmojiEnabled()) {
        return [
            'primary' => $replyMarkup,
            'fallback' => $replyMarkup,
            'has_custom' => false,
        ];
    }
    $wasJson = is_string($replyMarkup);
    $markup = $wasJson ? json_decode($replyMarkup, true) : $replyMarkup;
    if (!is_array($markup)) {
        return [
            'primary' => $replyMarkup,
            'fallback' => $replyMarkup,
            'has_custom' => false,
        ];
    }
    $primary = $markup;
    $fallback = $markup;
    $hasCustom = false;
    foreach (['inline_keyboard', 'keyboard'] as $container) {
        if (empty($markup[$container]) || !is_array($markup[$container])) {
            continue;
        }
        foreach ($markup[$container] as $rowIndex => $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($row as $buttonIndex => $button) {
                if (!is_array($button)) {
                    continue;
                }
                [$primaryButton, $fallbackButton, $buttonHasCustom] = styledPrepareButton(
                    $button,
                    $context . ':' . $container . ':' . $rowIndex . ':' . $buttonIndex
                );
                $primary[$container][$rowIndex][$buttonIndex] = $primaryButton;
                $fallback[$container][$rowIndex][$buttonIndex] = $fallbackButton;
                $hasCustom = $hasCustom || $buttonHasCustom;
            }
        }
    }
    if ($wasJson) {
        return [
            'primary' => json_encode($primary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'fallback' => json_encode($fallback, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'has_custom' => $hasCustom,
        ];
    }
    return ['primary' => $primary, 'fallback' => $fallback, 'has_custom' => $hasCustom];
}

function styledPrepareTelegramRequest($method, array $datas)
{
    if (!styledCustomEmojiEnabled()) {
        return [
            'primary' => $datas,
            'fallback' => $datas,
            'has_custom' => false,
        ];
    }
    styledPreloadTelegramContext($datas);
    $primary = $datas;
    $fallback = $datas;
    $hasCustom = false;
    $methodContext = strtolower((string) $method);

    foreach (['text' => 'entities', 'caption' => 'caption_entities'] as $textField => $entitiesField) {
        if (!isset($datas[$textField]) || !is_string($datas[$textField])) {
            continue;
        }
        $parseMode = (string) ($datas['parse_mode'] ?? '');
        $rendered = renderStyledText(
            $datas[$textField],
            $parseMode,
            $methodContext . ':' . $textField
        );
        $primary[$textField] = $rendered['text'];
        $fallback[$textField] = $rendered['fallback_text'];
        if (!$rendered['preserve_parse_mode']) {
            unset($primary['parse_mode'], $fallback['parse_mode']);
            if (!empty($rendered['entities'])) {
                $primary[$entitiesField] = json_encode(
                    $rendered['entities'],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
            } else {
                unset($primary[$entitiesField]);
            }
            if (!empty($rendered['fallback_entities'])) {
                $fallback[$entitiesField] = json_encode(
                    $rendered['fallback_entities'],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
            } else {
                unset($fallback[$entitiesField]);
            }
        }
        $hasCustom = $hasCustom || $rendered['has_custom'];
    }

    if (isset($datas['reply_markup'])) {
        $preparedMarkup = styledPrepareReplyMarkup(
            $datas['reply_markup'],
            $methodContext . ':reply_markup'
        );
        $primary['reply_markup'] = $preparedMarkup['primary'];
        $fallback['reply_markup'] = $preparedMarkup['fallback'];
        $hasCustom = $hasCustom || $preparedMarkup['has_custom'];
    }

    return [
        'primary' => $primary,
        'fallback' => $fallback,
        'has_custom' => $hasCustom,
    ];
}

function styledPrepareDisabledTelegramRequest(array $datas)
{
    $topLevelIconKey = trim((string) ($datas['icon_emoji_key'] ?? ''));
    if ($topLevelIconKey !== '' && isset($datas['text'])) {
        $fallbackMap = styledDisabledFallbackMap();
        $fallback = (string) ($fallbackMap[$topLevelIconKey] ?? '');
        if ($fallback !== '') {
            $datas['text'] = trim($fallback . ' ' . (string) $datas['text']);
        }
    }
    unset($datas['icon_custom_emoji_id'], $datas['icon_emoji_key']);
    foreach (['text', 'caption'] as $textField) {
        if (isset($datas[$textField]) && is_string($datas[$textField])) {
            $datas[$textField] = styledReplaceDisabledTokens($datas[$textField]);
        }
        if (!empty($datas[$textField]) && is_string($datas[$textField])
            && strpos($datas[$textField], '<tg-emoji') !== false) {
            $datas[$textField] = preg_replace(
                '/<tg-emoji\b[^>]*>(.*?)<\/tg-emoji>/us',
                '$1',
                $datas[$textField]
            );
        }
    }
    foreach (['entities', 'caption_entities'] as $entitiesField) {
        if (!isset($datas[$entitiesField])) {
            continue;
        }
        $wasJson = is_string($datas[$entitiesField]);
        $entities = $wasJson
            ? json_decode($datas[$entitiesField], true)
            : $datas[$entitiesField];
        if (!is_array($entities)) {
            continue;
        }
        $entities = array_values(array_filter($entities, function ($entity) {
            return !is_array($entity) || ($entity['type'] ?? '') !== 'custom_emoji';
        }));
        if ($entities) {
            $datas[$entitiesField] = $wasJson
                ? json_encode($entities, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : $entities;
        } else {
            unset($datas[$entitiesField]);
        }
    }
    if (!empty($datas['reply_markup'])) {
        $wasJson = is_string($datas['reply_markup']);
        $replyMarkup = $wasJson ? json_decode($datas['reply_markup'], true) : $datas['reply_markup'];
        if (is_array($replyMarkup)) {
            $stripIcons = function (&$value) use (&$stripIcons) {
                if (!is_array($value)) {
                    return;
                }
                $iconKey = trim((string) ($value['icon_emoji_key'] ?? ''));
                if (isset($value['text']) && is_string($value['text'])) {
                    $value['text'] = styledReplaceDisabledTokens($value['text']);
                    if ($iconKey !== '') {
                        $fallbackMap = styledDisabledFallbackMap();
                        $fallback = trim((string) ($fallbackMap[$iconKey] ?? ''));
                        if ($fallback !== ''
                            && strpos(trim($value['text']), $fallback) !== 0) {
                            $value['text'] = trim($fallback . ' ' . $value['text']);
                        }
                    }
                }
                unset($value['icon_custom_emoji_id'], $value['icon_emoji_key']);
                foreach ($value as &$child) {
                    $stripIcons($child);
                }
                unset($child);
            };
            $stripIcons($replyMarkup);
            $datas['reply_markup'] = $wasJson
                ? json_encode($replyMarkup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : $replyMarkup;
        }
    }
    return $datas;
}

function styledTelegramErrorAllowsEmojiFallback(array $response)
{
    $errorCode = (int) ($response['error_code'] ?? 0);
    if ($errorCode === 429 || $errorCode >= 500 || $errorCode !== 400) {
        return false;
    }
    $description = strtolower((string) ($response['description'] ?? ''));
    if ($description === '') {
        return false;
    }
    foreach ([
        'custom emoji',
        'custom_emoji',
        'icon_custom_emoji_id',
        'emoji id',
        'button_type_invalid',
    ] as $signature) {
        if (strpos($description, $signature) !== false) {
            return true;
        }
    }
    return false;
}

function sendStyledMessage($chatId, $text, $replyMarkup = null, $parseMode = 'HTML', $botToken = null)
{
    return telegram('sendMessage', [
        'chat_id' => $chatId,
        'text' => $text,
        'reply_markup' => $replyMarkup,
        'parse_mode' => $parseMode,
    ], $botToken);
}

function editStyledMessage($chatId, $messageId, $text, $replyMarkup = null, $parseMode = 'HTML', $botToken = null)
{
    return telegram('editMessageText', [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $text,
        'reply_markup' => $replyMarkup,
        'parse_mode' => $parseMode,
    ], $botToken);
}

function sendStyledPhoto($chatId, $photo, $caption = '', $replyMarkup = null, $parseMode = 'HTML', $botToken = null)
{
    return telegram('sendPhoto', [
        'chat_id' => $chatId,
        'photo' => $photo,
        'caption' => $caption,
        'reply_markup' => $replyMarkup,
        'parse_mode' => $parseMode,
    ], $botToken);
}

function editStyledCaption($chatId, $messageId, $caption, $replyMarkup = null, $parseMode = 'HTML', $botToken = null)
{
    return telegram('editMessageCaption', [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'caption' => $caption,
        'reply_markup' => $replyMarkup,
        'parse_mode' => $parseMode,
    ], $botToken);
}

function styledExtractCustomEmojiId(array $update)
{
    $containers = [];
    if (!empty($update['message']) && is_array($update['message'])) {
        $containers[] = $update['message'];
    }
    if (!empty($update['edited_message']) && is_array($update['edited_message'])) {
        $containers[] = $update['edited_message'];
    }
    if (!empty($update['channel_post']) && is_array($update['channel_post'])) {
        $containers[] = $update['channel_post'];
    }
    foreach ($containers as $container) {
        foreach (['entities', 'caption_entities'] as $field) {
            foreach (($container[$field] ?? []) as $entity) {
                if (($entity['type'] ?? '') !== 'custom_emoji') {
                    continue;
                }
                $customId = trim((string) ($entity['custom_emoji_id'] ?? ''));
                if (preg_match('/^[0-9]{5,64}$/', $customId)) {
                    return $customId;
                }
            }
        }
    }
    return '';
}

function styledValidateCustomEmojiId($customEmojiId)
{
    global $APIKEY;
    $customEmojiId = trim((string) $customEmojiId);
    if (!preg_match('/^[0-9]{5,64}$/', $customEmojiId) || empty($APIKEY)) {
        return false;
    }
    $url = 'https://api.telegram.org/bot' . $APIKEY . '/getCustomEmojiStickers';
    $handle = curl_init($url);
    if ($handle === false) {
        return false;
    }
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'custom_emoji_ids' => json_encode([$customEmojiId]),
        ],
    ]);
    $raw = curl_exec($handle);
    curl_close($handle);
    $response = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($response) && !empty($response['ok']) && !empty($response['result'][0]);
}

function styledUi($key, $default)
{
    global $pdo;
    if (!styledSystemReady()) {
        return (string) $default;
    }
    $cacheKey = 'system_ui:' . (string) $key;
    if (isset($GLOBALS['styled_runtime_ui_cache'])
        && array_key_exists($cacheKey, $GLOBALS['styled_runtime_ui_cache'])) {
        return $GLOBALS['styled_runtime_ui_cache'][$cacheKey];
    }
    try {
        $stmt = $pdo->prepare("SELECT value, is_active FROM styled_text_overrides
            WHERE source_type = 'system_ui' AND source_key = ? LIMIT 1");
        $stmt->execute([(string) $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && (int) $row['is_active'] === 1) {
            $value = (string) $row['value'];
            $GLOBALS['styled_runtime_ui_cache'][$cacheKey] = $value;
            return $value;
        }
    } catch (Throwable $ignored) {
    }
    $GLOBALS['styled_runtime_ui_cache'][$cacheKey] = (string) $default;
    return (string) $default;
}

function styledIsAdmin($userId)
{
    global $pdo, $admin_ids;
    if (!styledSystemReady() || (int) $userId === 0) {
        return false;
    }
    if (isset($admin_ids) && is_array($admin_ids)) {
        return in_array((string) $userId, array_map('strval', $admin_ids), true);
    }
    try {
        $stmt = $pdo->prepare("SELECT id_admin FROM admin WHERE id_admin = ? LIMIT 1");
        $stmt->execute([(string) $userId]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function styledAdminSession($adminId)
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT current_step, data_json FROM styled_admin_sessions
        WHERE admin_id = ? LIMIT 1");
    $stmt->execute([(int) $adminId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }
    $data = json_decode((string) $row['data_json'], true);
    return [
        'step' => (string) $row['current_step'],
        'data' => is_array($data) ? $data : [],
    ];
}

function styledSaveAdminSession($adminId, $step, array $data = [])
{
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO styled_admin_sessions
        (admin_id, current_step, data_json) VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE current_step = VALUES(current_step),
            data_json = VALUES(data_json), updated_at = CURRENT_TIMESTAMP");
    return $stmt->execute([
        (int) $adminId,
        (string) $step,
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function styledDeleteAdminSession($adminId)
{
    global $pdo;
    $stmt = $pdo->prepare("DELETE FROM styled_admin_sessions WHERE admin_id = ?");
    return $stmt->execute([(int) $adminId]);
}

function styledAdminButton($text, $callbackData, $iconKey = '')
{
    return buildStyledButton((string) $text, ['callback_data' => (string) $callbackData], $iconKey);
}

function styledKeyboard(array $rows)
{
    $filteredRows = [];
    foreach ($rows as $row) {
        $filtered = [];
        foreach ((array) $row as $button) {
            if (is_array($button) && !empty($button['text'])) {
                $filtered[] = $button;
            }
        }
        if ($filtered) {
            $filteredRows[] = $filtered;
        }
    }
    return json_encode(
        ['inline_keyboard' => $filteredRows],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
}

function styledSendAdminPage($adminId, $text, array $rows = [])
{
    return sendmessage(
        $adminId,
        (string) $text,
        $rows ? styledKeyboard($rows) : null,
        'HTML'
    );
}

function styledAppearanceMenuLabel($withFallback = true)
{
    $label = styledUi('appearance_menu', 'شخصی‌سازی ظاهر');
    return $withFallback ? '🎨 ' . $label : $label;
}

function styledEnhanceAdminKeyboard($keyboardJson)
{
    if (!styledSystemReady() || !$keyboardJson) {
        return $keyboardJson;
    }
    $keyboard = json_decode((string) $keyboardJson, true);
    if (!is_array($keyboard) || empty($keyboard['keyboard']) || !is_array($keyboard['keyboard'])) {
        return $keyboardJson;
    }
    $plainLabel = styledAppearanceMenuLabel(false);
    $fallbackLabel = styledAppearanceMenuLabel(true);
    foreach ($keyboard['keyboard'] as $row) {
        foreach ((array) $row as $button) {
            if (in_array((string) ($button['text'] ?? ''), [$plainLabel, $fallbackLabel], true)) {
                return $keyboardJson;
            }
        }
    }
    $backRow = array_pop($keyboard['keyboard']);
    $keyboard['keyboard'][] = [[
        'text' => $plainLabel,
        'icon_emoji_key' => 'appearance',
    ]];
    if ($backRow) {
        $keyboard['keyboard'][] = $backRow;
    }
    return json_encode($keyboard, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function styledAppearanceHome($adminId)
{
    global $pdo;
    $total = (int) $pdo->query("SELECT COUNT(*) FROM styled_emojis")->fetchColumn();
    $active = (int) $pdo->query("SELECT COUNT(*) FROM styled_emojis WHERE is_active = 1")->fetchColumn();
    $text = styledUi(
        'appearance_home',
        "<b>🎨 شخصی‌سازی ظاهر</b>\n\n"
        . "ایموجی‌های ثبت‌شده: {total}\n"
        . "ایموجی‌های فعال: {active}\n"
        . "fallback عمومی: {fallback}\n\n"
        . "از این بخش می‌توانید ایموجی‌ها را یک‌بار تعریف و در تمام ربات با "
        . "<code>{emoji:key}</code> استفاده کنید."
    );
    $text = strtr($text, [
        '{total}' => (string) $total,
        '{active}' => (string) $active,
        '{fallback}' => styledEscape(styledSetting('global_fallback', '') ?: '—'),
    ]);
    styledSendAdminPage($adminId, $text, [
        [styledAdminButton(
            styledUi('library_button', 'کتابخانه ایموجی‌های پریمیوم'),
            'sem_library_1',
            'premium'
        )],
        [styledAdminButton(styledUi('main_texts_button', 'متن‌ها و دکمه‌های اصلی'), 'sem_texts_1')],
        [styledAdminButton(styledUi('language_texts_button', 'همه متن‌های رابط ربات'), 'sem_lang_texts_1')],
        [styledAdminButton(styledUi('ui_texts_button', 'متن‌های بخش ظاهر'), 'sem_ui_texts_1')],
        [styledAdminButton(styledUi('global_fallback_button', 'fallback عمومی'), 'sem_fallback')],
        [styledAdminButton(styledUi('emoji_logs_button', 'لاگ ایموجی‌ها'), 'sem_logs_1')],
    ]);
}

function styledEmojiLibraryPage($adminId, $page = 1)
{
    global $pdo;
    $page = max(1, (int) $page);
    $limit = 10;
    $offset = ($page - 1) * $limit;
    $items = $pdo->query("SELECT * FROM styled_emojis
        ORDER BY is_active DESC, id DESC LIMIT {$limit} OFFSET {$offset}")->fetchAll(PDO::FETCH_ASSOC);
    $rows = [];
    foreach ($items as $item) {
        $state = (int) $item['is_active'] === 1 ? '✅' : '⛔️';
        $rows[] = [styledAdminButton(
            $state . ' ' . $item['fallback_emoji'] . ' ' . $item['display_name'],
            'sem_item_' . $item['id']
        )];
    }
    $navigation = [];
    if ($page > 1) {
        $navigation[] = styledAdminButton('⬅️', 'sem_library_' . ($page - 1));
    }
    if (count($items) === $limit) {
        $navigation[] = styledAdminButton('➡️', 'sem_library_' . ($page + 1));
    }
    if ($navigation) {
        $rows[] = $navigation;
    }
    $rows[] = [styledAdminButton(styledUi('add_emoji_button', 'افزودن ایموجی جدید'), 'sem_add')];
    $rows[] = [styledAdminButton(styledUi('back_button', 'بازگشت'), 'sem_home')];
    styledSendAdminPage(
        $adminId,
        styledUi('library_title', '<b>💎 کتابخانه ایموجی‌های پریمیوم</b>\n\nیک ایموجی را انتخاب کنید.'),
        $rows
    );
}

function styledEmojiById($emojiId)
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM styled_emojis WHERE id = ? LIMIT 1");
    $stmt->execute([(int) $emojiId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function styledEmojiItemPage($adminId, $emojiId)
{
    $item = styledEmojiById($emojiId);
    if (!$item) {
        styledEmojiLibraryPage($adminId);
        return;
    }
    $validText = $item['is_valid'] === null
        ? 'بررسی‌نشده'
        : ((int) $item['is_valid'] === 1 ? 'معتبر' : 'نامعتبر');
    $text = "<b>" . styledEscape($item['display_name']) . "</b>\n\n"
        . "کلید: <code>" . styledEscape($item['key']) . "</code>\n"
        . "Custom Emoji ID: <code>" . styledEscape($item['custom_emoji_id'] ?: '—') . "</code>\n"
        . "fallback: " . styledEscape($item['fallback_emoji'] ?: '—') . "\n"
        . "وضعیت: " . ((int) $item['is_active'] === 1 ? 'فعال' : 'غیرفعال') . "\n"
        . "اعتبار: " . $validText . "\n"
        . "ساخته‌شده: " . styledEscape($item['created_at']) . "\n"
        . "آخرین تغییر: " . styledEscape($item['updated_at']);
    styledSendAdminPage($adminId, $text, [
        [
            styledAdminButton(styledUi('edit_name_button', 'ویرایش نام'), 'sem_edit_name_' . $item['id']),
            styledAdminButton(styledUi('edit_key_button', 'ویرایش کلید'), 'sem_edit_key_' . $item['id']),
        ],
        [
            styledAdminButton(styledUi('edit_custom_id_button', 'ویرایش Custom Emoji'), 'sem_edit_id_' . $item['id']),
            styledAdminButton(styledUi('edit_fallback_button', 'ویرایش fallback'), 'sem_edit_fb_' . $item['id']),
        ],
        [styledAdminButton(
            (int) $item['is_active'] === 1
                ? styledUi('disable_button', 'غیرفعال‌کردن')
                : styledUi('enable_button', 'فعال‌کردن'),
            'sem_toggle_' . $item['id']
        )],
        [
            styledAdminButton(styledUi('preview_button', 'پیش‌نمایش'), 'sem_preview_' . $item['id']),
            styledAdminButton(styledUi('usage_button', 'محل‌های استفاده'), 'sem_usage_' . $item['id']),
        ],
        [styledAdminButton(styledUi('delete_button', 'حذف'), 'sem_delete_' . $item['id'])],
        [styledAdminButton(styledUi('back_button', 'بازگشت'), 'sem_library_1')],
    ]);
}

function styledFindEmojiUsage($emojiKey)
{
    global $pdo;
    $usage = [];
    $stmt = $pdo->prepare("SELECT source_type, source_key, source_label, use_count, last_seen_at
        FROM styled_emoji_usage WHERE emoji_key = ? ORDER BY last_seen_at DESC LIMIT 30");
    $stmt->execute([(string) $emojiKey]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $usage[] = $row['source_type'] . ':' . $row['source_key']
            . ($row['source_label'] !== '' ? ' — ' . $row['source_label'] : '');
    }
    $token = '{emoji:' . $emojiKey . '}';
    $checks = [
        ['textbot', 'id_text', 'text', 'متن اصلی'],
        ['ticket_content', 'content_key', 'value', 'متن/دکمه تیکت'],
        ['ticket_options', 'option_key', 'label', 'گزینه تیکت'],
    ];
    foreach ($checks as $check) {
        try {
            $stmt = $pdo->prepare("SELECT {$check[1]} AS source_key FROM {$check[0]}
                WHERE {$check[2]} LIKE ? LIMIT 30");
            $stmt->execute(['%' . $token . '%']);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $sourceKey) {
                $usage[] = $check[3] . ': ' . $sourceKey;
            }
        } catch (Throwable $ignored) {
        }
    }
    foreach ([
        ['ticket_content', 'content_key', 'emoji_key', 'آیکون متن/دکمه تیکت'],
        ['ticket_options', 'option_key', 'emoji_key', 'آیکون گزینه تیکت'],
        ['styled_button_icons', 'source_key', 'icon_emoji_key', 'آیکون دکمه اصلی'],
    ] as $check) {
        try {
            $stmt = $pdo->prepare("SELECT {$check[1]} FROM {$check[0]}
                WHERE {$check[2]} = ? LIMIT 30");
            $stmt->execute([$emojiKey]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $sourceKey) {
                $usage[] = $check[3] . ': ' . $sourceKey;
            }
        } catch (Throwable $ignored) {
        }
    }
    return array_values(array_unique($usage));
}

function styledEmojiUsagePage($adminId, $emojiId)
{
    $item = styledEmojiById($emojiId);
    if (!$item) {
        styledEmojiLibraryPage($adminId);
        return;
    }
    $usage = styledFindEmojiUsage($item['key']);
    $lines = [];
    foreach (array_slice($usage, 0, 40) as $index => $location) {
        $lines[] = ($index + 1) . '. ' . styledEscape(styledLimit($location, 220));
    }
    $text = "<b>محل‌های استفاده " . styledEscape($item['display_name']) . "</b>\n\n"
        . ($lines ? implode("\n", $lines) : 'هنوز استفاده‌ای ثبت نشده است.');
    styledSendAdminPage($adminId, $text, [
        [styledAdminButton(styledUi('back_button', 'بازگشت'), 'sem_item_' . $item['id'])],
    ]);
}

function styledEmojiPicker($adminId, array $pickerData, $page = 1)
{
    global $pdo;
    styledSaveAdminSession($adminId, 'picker', $pickerData);
    $page = max(1, (int) $page);
    $limit = 10;
    $offset = ($page - 1) * $limit;
    $items = $pdo->query("SELECT * FROM styled_emojis WHERE is_active = 1
        ORDER BY display_name, id LIMIT {$limit} OFFSET {$offset}")->fetchAll(PDO::FETCH_ASSOC);
    $rows = [];
    foreach ($items as $item) {
        $rows[] = [styledAdminButton(
            $item['fallback_emoji'] . ' ' . $item['display_name'] . ' [' . $item['key'] . ']',
            'sem_pick_' . $item['id']
        )];
    }
    $navigation = [];
    if ($page > 1) {
        $navigation[] = styledAdminButton('⬅️', 'sem_picker_' . ($page - 1));
    }
    if (count($items) === $limit) {
        $navigation[] = styledAdminButton('➡️', 'sem_picker_' . ($page + 1));
    }
    if ($navigation) {
        $rows[] = $navigation;
    }
    if (!empty($pickerData['allow_empty'])) {
        $rows[] = [styledAdminButton(styledUi('remove_icon_button', 'حذف آیکون'), 'sem_pick_0')];
    }
    $rows[] = [styledAdminButton(styledUi('cancel_button', 'انصراف'), 'sem_home')];
    styledSendAdminPage(
        $adminId,
        styledUi('picker_title', '<b>💎 درج ایموجی</b>\n\nیک ایموجی را از کتابخانه انتخاب کنید.'),
        $rows
    );
}

function styledRenameEmojiKey($emojiId, $newKey)
{
    global $pdo;
    $item = styledEmojiById($emojiId);
    if (!$item) {
        return false;
    }
    $oldKey = (string) $item['key'];
    $oldToken = '{emoji:' . $oldKey . '}';
    $newToken = '{emoji:' . $newKey . '}';
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("UPDATE styled_emojis SET `key` = ? WHERE id = ?");
        $stmt->execute([$newKey, (int) $emojiId]);
        foreach ([
            ['textbot', 'text'],
            ['ticket_content', 'value'],
            ['ticket_options', 'label'],
            ['styled_text_overrides', 'value'],
        ] as $table) {
            try {
                $stmt = $pdo->prepare("UPDATE {$table[0]} SET {$table[1]} =
                    REPLACE({$table[1]}, ?, ?) WHERE {$table[1]} LIKE ?");
                $stmt->execute([$oldToken, $newToken, '%' . $oldToken . '%']);
            } catch (Throwable $ignored) {
            }
        }
        foreach ([
            ['ticket_content', 'emoji_key'],
            ['ticket_options', 'emoji_key'],
            ['styled_button_icons', 'icon_emoji_key'],
        ] as $table) {
            try {
                $stmt = $pdo->prepare("UPDATE {$table[0]} SET {$table[1]} = ? WHERE {$table[1]} = ?");
                $stmt->execute([$newKey, $oldKey]);
            } catch (Throwable $ignored) {
            }
        }
        $stmt = $pdo->prepare("UPDATE styled_emoji_usage SET emoji_key = ? WHERE emoji_key = ?");
        $stmt->execute([$newKey, $oldKey]);
        $pdo->commit();
        styledClearRuntimeCaches();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        styledLog('rename_failed', $oldKey, 'admin', $e->getMessage());
        return false;
    }
}

function styledTextbotPage($adminId, $page = 1)
{
    global $pdo;
    $page = max(1, (int) $page);
    $limit = 10;
    $offset = ($page - 1) * $limit;
    $items = $pdo->query("SELECT id_text, text FROM textbot
        ORDER BY id_text LIMIT {$limit} OFFSET {$offset}")->fetchAll(PDO::FETCH_ASSOC);
    $rows = [];
    foreach ($items as $item) {
        $rows[] = [styledAdminButton(
            styledLimit($item['id_text'] . ' — ' . strip_tags($item['text']), 55),
            'sem_text_' . $item['id_text']
        )];
    }
    $navigation = [];
    if ($page > 1) {
        $navigation[] = styledAdminButton('⬅️', 'sem_texts_' . ($page - 1));
    }
    if (count($items) === $limit) {
        $navigation[] = styledAdminButton('➡️', 'sem_texts_' . ($page + 1));
    }
    if ($navigation) {
        $rows[] = $navigation;
    }
    $rows[] = [styledAdminButton(styledUi('back_button', 'بازگشت'), 'sem_home')];
    styledSendAdminPage(
        $adminId,
        styledUi(
            'main_texts_title',
            "<b>متن‌ها و دکمه‌های اصلی</b>\n\n"
            . "همه این مقادیر می‌توانند از قالب <code>{emoji:key}</code> استفاده کنند."
        ),
        $rows
    );
}

function styledTextbotItemPage($adminId, $textKey)
{
    global $pdo;
    if (!preg_match('/^[a-zA-Z0-9_]{1,100}$/', (string) $textKey)) {
        styledTextbotPage($adminId);
        return;
    }
    $stmt = $pdo->prepare("SELECT id_text, text FROM textbot WHERE id_text = ? LIMIT 1");
    $stmt->execute([(string) $textKey]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$item) {
        styledTextbotPage($adminId);
        return;
    }
    $stmt = $pdo->prepare("SELECT icon_emoji_key FROM styled_button_icons
        WHERE source_type = 'textbot' AND source_key = ? LIMIT 1");
    $stmt->execute([(string) $textKey]);
    $iconKey = (string) ($stmt->fetchColumn() ?: '');
    $text = "<b>" . styledEscape($item['id_text']) . "</b>\n\n"
        . styledEscape(styledLimit($item['text'], 3000)) . "\n\n"
        . "آیکون دکمه: <code>" . styledEscape($iconKey ?: '—') . "</code>";
    styledSendAdminPage($adminId, $text, [
        [styledAdminButton(styledUi('edit_text_button', 'ویرایش متن/عنوان'), 'sem_text_edit_' . $item['id_text'])],
        [styledAdminButton(styledUi('insert_emoji_button', 'درج ایموجی'), 'sem_text_insert_' . $item['id_text'], 'premium')],
        [styledAdminButton(styledUi('select_icon_button', 'انتخاب آیکون دکمه'), 'sem_text_icon_' . $item['id_text'])],
        [styledAdminButton(styledUi('back_button', 'بازگشت'), 'sem_texts_1')],
    ]);
}

function styledUiTextsPage($adminId, $page = 1)
{
    global $pdo;
    $page = max(1, (int) $page);
    $limit = 10;
    $offset = ($page - 1) * $limit;
    $stmt = $pdo->prepare("SELECT * FROM styled_text_overrides WHERE source_type = 'system_ui'
        ORDER BY source_key LIMIT {$limit} OFFSET {$offset}");
    $stmt->execute();
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $rows = [];
    foreach ($items as $item) {
        $rows[] = [styledAdminButton(
            styledLimit($item['source_key'] . ' — ' . strip_tags($item['value']), 55),
            'sem_ui_' . $item['id']
        )];
    }
    $navigation = [];
    if ($page > 1) {
        $navigation[] = styledAdminButton('⬅️', 'sem_ui_texts_' . ($page - 1));
    }
    if (count($items) === $limit) {
        $navigation[] = styledAdminButton('➡️', 'sem_ui_texts_' . ($page + 1));
    }
    if ($navigation) {
        $rows[] = $navigation;
    }
    $rows[] = [styledAdminButton(styledUi('back_button', 'بازگشت'), 'sem_home')];
    styledSendAdminPage($adminId, '<b>متن‌های بخش شخصی‌سازی ظاهر</b>', $rows);
}

function styledLanguageTextsPage($adminId, $page = 1)
{
    global $pdo;
    $page = max(1, (int) $page);
    $limit = 10;
    $offset = ($page - 1) * $limit;
    $stmt = $pdo->prepare("SELECT * FROM styled_text_overrides WHERE source_type = 'language'
        ORDER BY source_key LIMIT {$limit} OFFSET {$offset}");
    $stmt->execute();
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $rows = [];
    foreach ($items as $item) {
        $rows[] = [styledAdminButton(
            styledLimit($item['source_key'] . ' — ' . strip_tags($item['value']), 55),
            'sem_lang_' . $item['id']
        )];
    }
    $navigation = [];
    if ($page > 1) {
        $navigation[] = styledAdminButton('⬅️', 'sem_lang_texts_' . ($page - 1));
    }
    if (count($items) === $limit) {
        $navigation[] = styledAdminButton('➡️', 'sem_lang_texts_' . ($page + 1));
    }
    if ($navigation) {
        $rows[] = $navigation;
    }
    $rows[] = [styledAdminButton(styledUi('back_button', 'بازگشت'), 'sem_home')];
    styledSendAdminPage(
        $adminId,
        "<b>همه متن‌های رابط ربات</b>\n\n"
        . "این متن‌ها از فایل زبان به پایگاه داده منتقل شده‌اند و می‌توانند "
        . "<code>{emoji:key}</code> داشته باشند.",
        $rows
    );
}

function styledEmojiLogsPage($adminId, $page = 1)
{
    global $pdo;
    $page = max(1, (int) $page);
    $limit = 15;
    $offset = ($page - 1) * $limit;
    $items = $pdo->query("SELECT * FROM styled_emoji_logs
        ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}")->fetchAll(PDO::FETCH_ASSOC);
    $lines = [];
    foreach ($items as $item) {
        $lines[] = '• <b>' . styledEscape($item['log_type']) . '</b> '
            . styledEscape($item['emoji_key'])
            . "\n" . styledEscape(styledLimit($item['message'], 180));
    }
    $rows = [];
    $navigation = [];
    if ($page > 1) {
        $navigation[] = styledAdminButton('⬅️', 'sem_logs_' . ($page - 1));
    }
    if (count($items) === $limit) {
        $navigation[] = styledAdminButton('➡️', 'sem_logs_' . ($page + 1));
    }
    if ($navigation) {
        $rows[] = $navigation;
    }
    $rows[] = [styledAdminButton(styledUi('back_button', 'بازگشت'), 'sem_home')];
    styledSendAdminPage(
        $adminId,
        "<b>لاگ ایموجی‌ها</b>\n\n" . ($lines ? implode("\n\n", $lines) : 'لاگی ثبت نشده است.'),
        $rows
    );
}

function styledSaveNewEmoji(array $data, $customEmojiId)
{
    global $pdo;
    $valid = styledValidateCustomEmojiId($customEmojiId);
    $stmt = $pdo->prepare("INSERT INTO styled_emojis
        (display_name, `key`, custom_emoji_id, fallback_emoji, is_active, is_valid, validated_at)
        VALUES (?, ?, ?, ?, 1, ?, NOW())");
    $stmt->execute([
        styledLimit($data['display_name'] ?? '', 190),
        (string) ($data['key'] ?? ''),
        (string) $customEmojiId,
        styledLimit($data['fallback_emoji'] ?? '', 32),
        $valid ? 1 : 0,
    ]);
    styledClearRuntimeCaches();
    return [(int) $pdo->lastInsertId(), $valid];
}

function styledHandlePickerSelection($adminId, array $session, $emojiId)
{
    global $pdo;
    $data = $session['data'];
    $target = (string) ($data['target'] ?? '');
    $emoji = (int) $emojiId > 0 ? styledEmojiById($emojiId) : false;
    $emojiKey = $emoji ? (string) $emoji['key'] : '';

    if ($target === 'insert_textbot') {
        $sourceKey = (string) ($data['source_key'] ?? '');
        $stmt = $pdo->prepare("UPDATE textbot SET text = CONCAT(
            RTRIM(text), CASE WHEN RTRIM(text) = '' THEN '' ELSE ' ' END, ?
        ) WHERE id_text = ?");
        $stmt->execute([styledToken($emojiKey), $sourceKey]);
        styledDeleteAdminSession($adminId);
        styledTextbotItemPage($adminId, $sourceKey);
        return true;
    }
    if ($target === 'icon_textbot') {
        $sourceKey = (string) ($data['source_key'] ?? '');
        $stmt = $pdo->prepare("INSERT INTO styled_button_icons
            (source_type, source_key, icon_emoji_key) VALUES ('textbot', ?, ?)
            ON DUPLICATE KEY UPDATE icon_emoji_key = VALUES(icon_emoji_key)");
        $stmt->execute([$sourceKey, $emojiKey]);
        styledDeleteAdminSession($adminId);
        styledClearRuntimeCaches();
        styledTextbotItemPage($adminId, $sourceKey);
        return true;
    }
    if ($target === 'ticket_content_icon') {
        $contentId = (int) ($data['item_id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE ticket_content SET emoji_key = ?, custom_emoji_id = '' WHERE id = ?");
        $stmt->execute([$emojiKey, $contentId]);
        styledDeleteAdminSession($adminId);
        if (function_exists('ticketAdminContentDetail')) {
            ticketAdminContentDetail($adminId, $contentId);
        }
        return true;
    }
    if ($target === 'ticket_option_icon') {
        $optionId = (int) ($data['item_id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE ticket_options SET emoji_key = ?, custom_emoji_id = '' WHERE id = ?");
        $stmt->execute([$emojiKey, $optionId]);
        styledDeleteAdminSession($adminId);
        if (function_exists('ticketAdminOptionDetail')) {
            ticketAdminOptionDetail($adminId, $optionId);
        }
        return true;
    }
    if ($target === 'insert_ticket_content') {
        $contentId = (int) ($data['item_id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE ticket_content SET value = CONCAT(
            RTRIM(value), CASE WHEN RTRIM(value) = '' THEN '' ELSE ' ' END, ?
        ) WHERE id = ?");
        $stmt->execute([styledToken($emojiKey), $contentId]);
        styledDeleteAdminSession($adminId);
        if (function_exists('ticketAdminContentDetail')) {
            ticketAdminContentDetail($adminId, $contentId);
        }
        return true;
    }
    if ($target === 'insert_language_text') {
        $itemId = (int) ($data['item_id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE styled_text_overrides SET value = CONCAT(
            RTRIM(value), CASE WHEN RTRIM(value) = '' THEN '' ELSE ' ' END, ?
        ) WHERE id = ? AND source_type = 'language'");
        $stmt->execute([styledToken($emojiKey), $itemId]);
        styledDeleteAdminSession($adminId);
        styledLanguageTextsPage($adminId);
        return true;
    }
    styledDeleteAdminSession($adminId);
    styledAppearanceHome($adminId);
    return true;
}

function styledHandleAdminSession(array $session)
{
    global $from_id, $text, $update, $pdo;
    $step = (string) $session['step'];
    $data = $session['data'];
    $incomingText = trim((string) $text);

    if ($step === 'add_name') {
        if ($incomingText === '') {
            styledSendAdminPage($from_id, 'نام نمایشی نمی‌تواند خالی باشد.');
            return true;
        }
        $data['display_name'] = styledLimit($incomingText, 190);
        styledSaveAdminSession($from_id, 'add_key', $data);
        styledSendAdminPage(
            $from_id,
            "کلید یکتا را ارسال کنید.\nفقط حروف انگلیسی کوچک، عدد و underscore مجاز است.\nمثال: <code>brand</code>"
        );
        return true;
    }
    if ($step === 'add_key') {
        if (!preg_match('/^[a-z0-9_]{1,100}$/', $incomingText)) {
            styledSendAdminPage($from_id, 'کلید نامعتبر است. نمونه صحیح: <code>brand_purple</code>');
            return true;
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM styled_emojis WHERE `key` = ?");
        $stmt->execute([$incomingText]);
        if ((int) $stmt->fetchColumn() > 0) {
            styledSendAdminPage($from_id, 'این کلید قبلاً ثبت شده است؛ کلید دیگری بفرستید.');
            return true;
        }
        $data['key'] = $incomingText;
        styledSaveAdminSession($from_id, 'add_fallback', $data);
        styledSendAdminPage(
            $from_id,
            "ایموجی معمولی fallback را ارسال کنید؛ مثال: 💎\n"
            . "اگر fallback نمی‌خواهید یک خط تیره <code>-</code> بفرستید."
        );
        return true;
    }
    if ($step === 'add_fallback') {
        if ($incomingText === '') {
            styledSendAdminPage($from_id, 'یک ایموجی یا خط تیره ارسال کنید.');
            return true;
        }
        $data['fallback_emoji'] = $incomingText === '-' ? '' : styledLimit($incomingText, 32);
        styledSaveAdminSession($from_id, 'add_choose_method', $data);
        styledSendAdminPage($from_id, 'روش ثبت Custom Emoji را انتخاب کنید.', [
            [styledAdminButton('ورود دستی Custom Emoji ID', 'sem_add_manual')],
            [styledAdminButton('ارسال مستقیم Custom Emoji', 'sem_add_direct')],
            [styledAdminButton(styledUi('cancel_button', 'انصراف'), 'sem_home')],
        ]);
        return true;
    }
    if ($step === 'add_manual_id' || $step === 'add_direct_id') {
        $customId = $step === 'add_direct_id'
            ? styledExtractCustomEmojiId($update)
            : $incomingText;
        if (!preg_match('/^[0-9]{5,64}$/', (string) $customId)) {
            $message = $step === 'add_direct_id'
                ? 'یک Custom Emoji پریمیوم را مستقیماً برای ربات ارسال کنید.'
                : 'شناسه باید فقط یک عدد معتبر باشد.';
            styledSendAdminPage($from_id, $message);
            return true;
        }
        try {
            [$emojiId, $valid] = styledSaveNewEmoji($data, $customId);
        } catch (Throwable $e) {
            styledLog('create_failed', (string) ($data['key'] ?? ''), 'admin', $e->getMessage());
            styledSendAdminPage($from_id, 'ذخیره انجام نشد؛ کلید یا شناسه را دوباره بررسی کنید.');
            return true;
        }
        styledDeleteAdminSession($from_id);
        styledSendAdminPage(
            $from_id,
            $valid
                ? 'ایموجی با موفقیت ثبت و اعتبارسنجی شد.'
                : 'ایموجی ثبت شد، اما Telegram آن را معتبر تشخیص نداد؛ تا زمان اصلاح، fallback نمایش داده می‌شود.',
            [[styledAdminButton('مشاهده ایموجی', 'sem_item_' . $emojiId)]]
        );
        return true;
    }
    if (strpos($step, 'edit_') === 0) {
        $emojiId = (int) ($data['emoji_id'] ?? 0);
        $item = styledEmojiById($emojiId);
        if (!$item) {
            styledDeleteAdminSession($from_id);
            styledEmojiLibraryPage($from_id);
            return true;
        }
        if ($step === 'edit_name') {
            if ($incomingText === '') {
                styledSendAdminPage($from_id, 'نام نمی‌تواند خالی باشد.');
                return true;
            }
            $stmt = $pdo->prepare("UPDATE styled_emojis SET display_name = ? WHERE id = ?");
            $stmt->execute([styledLimit($incomingText, 190), $emojiId]);
        } elseif ($step === 'edit_key') {
            if (!preg_match('/^[a-z0-9_]{1,100}$/', $incomingText)) {
                styledSendAdminPage($from_id, 'کلید نامعتبر است.');
                return true;
            }
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM styled_emojis WHERE `key` = ? AND id <> ?");
            $stmt->execute([$incomingText, $emojiId]);
            if ((int) $stmt->fetchColumn() > 0 || !styledRenameEmojiKey($emojiId, $incomingText)) {
                styledSendAdminPage($from_id, 'تغییر کلید انجام نشد؛ احتمالاً کلید تکراری است.');
                return true;
            }
        } elseif ($step === 'edit_id') {
            $customId = preg_match('/^[0-9]{5,64}$/', $incomingText)
                ? $incomingText
                : styledExtractCustomEmojiId($update);
            if (!preg_match('/^[0-9]{5,64}$/', (string) $customId)) {
                styledSendAdminPage(
                    $from_id,
                    'Custom Emoji را مستقیم ارسال کنید یا شناسه عددی آن را بفرستید.'
                );
                return true;
            }
            $valid = styledValidateCustomEmojiId($customId);
            $stmt = $pdo->prepare("UPDATE styled_emojis SET custom_emoji_id = ?,
                is_valid = ?, validated_at = NOW() WHERE id = ?");
            $stmt->execute([$customId, $valid ? 1 : 0, $emojiId]);
        } elseif ($step === 'edit_fallback') {
            if ($incomingText === '') {
                styledSendAdminPage($from_id, 'یک ایموجی یا خط تیره ارسال کنید.');
                return true;
            }
            $stmt = $pdo->prepare("UPDATE styled_emojis SET fallback_emoji = ? WHERE id = ?");
            $stmt->execute([$incomingText === '-' ? '' : styledLimit($incomingText, 32), $emojiId]);
        }
        styledDeleteAdminSession($from_id);
        styledClearRuntimeCaches();
        styledEmojiItemPage($from_id, $emojiId);
        return true;
    }
    if ($step === 'global_fallback') {
        if ($incomingText === '') {
            styledSendAdminPage($from_id, 'یک ایموجی یا خط تیره ارسال کنید.');
            return true;
        }
        styledSetSetting('global_fallback', $incomingText === '-' ? '' : styledLimit($incomingText, 32));
        styledDeleteAdminSession($from_id);
        styledAppearanceHome($from_id);
        return true;
    }
    if ($step === 'edit_textbot') {
        $sourceKey = (string) ($data['source_key'] ?? '');
        if ($incomingText === '') {
            styledSendAdminPage($from_id, 'متن نمی‌تواند خالی باشد.');
            return true;
        }
        $stmt = $pdo->prepare("UPDATE textbot SET text = ? WHERE id_text = ?");
        $stmt->execute([styledLimit($text, 20000), $sourceKey]);
        styledDeleteAdminSession($from_id);
        styledTextbotItemPage($from_id, $sourceKey);
        return true;
    }
    if ($step === 'edit_ui_text') {
        $itemId = (int) ($data['item_id'] ?? 0);
        if ($incomingText === '') {
            styledSendAdminPage($from_id, 'متن نمی‌تواند خالی باشد.');
            return true;
        }
        $stmt = $pdo->prepare("UPDATE styled_text_overrides SET value = ? WHERE id = ?");
        $stmt->execute([styledLimit($text, 20000), $itemId]);
        styledDeleteAdminSession($from_id);
        styledAppearanceHome($from_id);
        return true;
    }
    if ($step === 'edit_language_text') {
        $itemId = (int) ($data['item_id'] ?? 0);
        if ($incomingText === '') {
            styledSendAdminPage($from_id, 'متن نمی‌تواند خالی باشد.');
            return true;
        }
        $stmt = $pdo->prepare("UPDATE styled_text_overrides SET value = ?
            WHERE id = ? AND source_type = 'language'");
        $stmt->execute([styledLimit($text, 20000), $itemId]);
        styledDeleteAdminSession($from_id);
        styledLanguageTextsPage($from_id);
        return true;
    }
    return false;
}

function styledHandleAdminUpdate()
{
    global $from_id, $text, $datain, $pdo;
    if (!styledIsAdmin($from_id)) {
        return false;
    }

    $plainMenu = styledAppearanceMenuLabel(false);
    $fallbackMenu = styledAppearanceMenuLabel(true);
    if (in_array((string) $text, [$plainMenu, $fallbackMenu], true) || $datain === 'sem_home') {
        styledDeleteAdminSession($from_id);
        styledAppearanceHome($from_id);
        return true;
    }
    if (!preg_match('/^sem_/', (string) $datain)) {
        $session = styledAdminSession($from_id);
        if ($session && ((string) $text !== '' || !empty($GLOBALS['photoid']) || !empty($GLOBALS['caption']))) {
            return styledHandleAdminSession($session);
        }
        return false;
    }

    if (preg_match('/^sem_library_([0-9]+)$/', $datain, $match)) {
        styledDeleteAdminSession($from_id);
        styledEmojiLibraryPage($from_id, $match[1]);
        return true;
    }
    if ($datain === 'sem_add') {
        styledSaveAdminSession($from_id, 'add_name');
        styledSendAdminPage($from_id, 'نام نمایشی ایموجی را ارسال کنید؛ مثال: الماس بنفش برند');
        return true;
    }
    if ($datain === 'sem_add_manual' || $datain === 'sem_add_direct') {
        $session = styledAdminSession($from_id);
        if (!$session || $session['step'] !== 'add_choose_method') {
            styledEmojiLibraryPage($from_id);
            return true;
        }
        $step = $datain === 'sem_add_manual' ? 'add_manual_id' : 'add_direct_id';
        styledSaveAdminSession($from_id, $step, $session['data']);
        styledSendAdminPage(
            $from_id,
            $step === 'add_manual_id'
                ? 'Custom Emoji ID عددی را ارسال کنید.'
                : 'اکنون خود Custom Emoji پریمیوم را مستقیماً برای ربات ارسال کنید.'
        );
        return true;
    }
    if (preg_match('/^sem_item_([0-9]+)$/', $datain, $match)) {
        styledDeleteAdminSession($from_id);
        styledEmojiItemPage($from_id, $match[1]);
        return true;
    }
    if (preg_match('/^sem_edit_(name|key|id|fb)_([0-9]+)$/', $datain, $match)) {
        $stepMap = ['name' => 'edit_name', 'key' => 'edit_key', 'id' => 'edit_id', 'fb' => 'edit_fallback'];
        styledSaveAdminSession($from_id, $stepMap[$match[1]], ['emoji_id' => (int) $match[2]]);
        $prompts = [
            'name' => 'نام نمایشی جدید را ارسال کنید.',
            'key' => 'کلید جدید را با حروف انگلیسی کوچک، عدد یا underscore ارسال کنید.',
            'id' => 'شناسه عددی یا خود Custom Emoji پریمیوم را ارسال کنید.',
            'fb' => 'fallback جدید را ارسال کنید؛ برای حذف، خط تیره بفرستید.',
        ];
        styledSendAdminPage($from_id, $prompts[$match[1]]);
        return true;
    }
    if (preg_match('/^sem_toggle_([0-9]+)$/', $datain, $match)) {
        $stmt = $pdo->prepare("UPDATE styled_emojis SET is_active = IF(is_active = 1, 0, 1) WHERE id = ?");
        $stmt->execute([(int) $match[1]]);
        styledClearRuntimeCaches();
        styledEmojiItemPage($from_id, $match[1]);
        return true;
    }
    if (preg_match('/^sem_usage_([0-9]+)$/', $datain, $match)) {
        styledEmojiUsagePage($from_id, $match[1]);
        return true;
    }
    if (preg_match('/^sem_preview_([0-9]+)$/', $datain, $match)) {
        $item = styledEmojiById($match[1]);
        if (!$item) {
            styledEmojiLibraryPage($from_id);
            return true;
        }
        $token = styledToken($item['key']);
        sendStyledMessage(
            $from_id,
            $token . ' پیش‌نمایش پیام با Custom Emoji',
            styledKeyboard([[
                buildStyledButton('دکمه نمونه', ['callback_data' => 'sem_item_' . $item['id']], $item['key']),
            ]]),
            'HTML'
        );
        $fallback = $item['fallback_emoji'] ?: styledSetting('global_fallback', '');
        sendmessage(
            $from_id,
            styledEscape($fallback . ' پیش‌نمایش حالت fallback'),
            styledKeyboard([[styledAdminButton(trim($fallback . ' دکمه fallback'), 'sem_item_' . $item['id'])]]),
            'HTML'
        );
        return true;
    }
    if (preg_match('/^sem_delete_([0-9]+)$/', $datain, $match)) {
        $item = styledEmojiById($match[1]);
        if (!$item) {
            styledEmojiLibraryPage($from_id);
            return true;
        }
        $usage = styledFindEmojiUsage($item['key']);
        $warning = $usage
            ? "این ایموجی در " . count($usage) . " محل استفاده می‌شود. پس از حذف، fallback عمومی جایگزین خواهد شد."
            : 'این ایموجی در محل شناخته‌شده‌ای استفاده نمی‌شود.';
        styledSendAdminPage($from_id, $warning . "\n\nبرای حذف قطعی دوباره تأیید کنید.", [
            [styledAdminButton('تأیید حذف قطعی', 'sem_delete_yes_' . $item['id'])],
            [styledAdminButton(styledUi('cancel_button', 'انصراف'), 'sem_item_' . $item['id'])],
        ]);
        return true;
    }
    if (preg_match('/^sem_delete_yes_([0-9]+)$/', $datain, $match)) {
        $stmt = $pdo->prepare("DELETE FROM styled_emojis WHERE id = ?");
        $stmt->execute([(int) $match[1]]);
        styledClearRuntimeCaches();
        styledEmojiLibraryPage($from_id);
        return true;
    }
    if ($datain === 'sem_fallback') {
        styledSaveAdminSession($from_id, 'global_fallback');
        styledSendAdminPage(
            $from_id,
            "fallback عمومی را ارسال کنید.\nبرای غیرفعال‌کردن، یک خط تیره <code>-</code> بفرستید."
        );
        return true;
    }
    if (preg_match('/^sem_texts_([0-9]+)$/', $datain, $match)) {
        styledDeleteAdminSession($from_id);
        styledTextbotPage($from_id, $match[1]);
        return true;
    }
    if (preg_match('/^sem_text_([a-zA-Z0-9_]{1,100})$/', $datain, $match)) {
        styledDeleteAdminSession($from_id);
        styledTextbotItemPage($from_id, $match[1]);
        return true;
    }
    if (preg_match('/^sem_text_edit_([a-zA-Z0-9_]{1,100})$/', $datain, $match)) {
        styledSaveAdminSession($from_id, 'edit_textbot', ['source_key' => $match[1]]);
        styledSendAdminPage(
            $from_id,
            "متن کامل جدید را ارسال کنید. می‌توانید از <code>{emoji:key}</code> استفاده کنید."
        );
        return true;
    }
    if (preg_match('/^sem_text_insert_([a-zA-Z0-9_]{1,100})$/', $datain, $match)) {
        styledEmojiPicker($from_id, [
            'target' => 'insert_textbot',
            'source_key' => $match[1],
        ]);
        return true;
    }
    if (preg_match('/^sem_text_icon_([a-zA-Z0-9_]{1,100})$/', $datain, $match)) {
        styledEmojiPicker($from_id, [
            'target' => 'icon_textbot',
            'source_key' => $match[1],
            'allow_empty' => true,
        ]);
        return true;
    }
    if (preg_match('/^sem_tc_icon_([0-9]+)$/', $datain, $match)) {
        styledEmojiPicker($from_id, [
            'target' => 'ticket_content_icon',
            'item_id' => (int) $match[1],
            'allow_empty' => true,
        ]);
        return true;
    }
    if (preg_match('/^sem_tc_insert_([0-9]+)$/', $datain, $match)) {
        styledEmojiPicker($from_id, [
            'target' => 'insert_ticket_content',
            'item_id' => (int) $match[1],
        ]);
        return true;
    }
    if (preg_match('/^sem_to_icon_([0-9]+)$/', $datain, $match)) {
        styledEmojiPicker($from_id, [
            'target' => 'ticket_option_icon',
            'item_id' => (int) $match[1],
            'allow_empty' => true,
        ]);
        return true;
    }
    if (preg_match('/^sem_picker_([0-9]+)$/', $datain, $match)) {
        $session = styledAdminSession($from_id);
        if ($session && $session['step'] === 'picker') {
            styledEmojiPicker($from_id, $session['data'], $match[1]);
        } else {
            styledAppearanceHome($from_id);
        }
        return true;
    }
    if (preg_match('/^sem_pick_([0-9]+)$/', $datain, $match)) {
        $session = styledAdminSession($from_id);
        if (!$session || $session['step'] !== 'picker') {
            styledAppearanceHome($from_id);
            return true;
        }
        return styledHandlePickerSelection($from_id, $session, (int) $match[1]);
    }
    if (preg_match('/^sem_ui_texts_([0-9]+)$/', $datain, $match)) {
        styledDeleteAdminSession($from_id);
        styledUiTextsPage($from_id, $match[1]);
        return true;
    }
    if (preg_match('/^sem_lang_texts_([0-9]+)$/', $datain, $match)) {
        styledDeleteAdminSession($from_id);
        styledLanguageTextsPage($from_id, $match[1]);
        return true;
    }
    if (preg_match('/^sem_lang_([0-9]+)$/', $datain, $match)) {
        $stmt = $pdo->prepare("SELECT * FROM styled_text_overrides
            WHERE id = ? AND source_type = 'language' LIMIT 1");
        $stmt->execute([(int) $match[1]]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) {
            styledLanguageTextsPage($from_id);
            return true;
        }
        styledSendAdminPage(
            $from_id,
            '<b>' . styledEscape($item['source_key']) . "</b>\n\n" . styledEscape($item['value']),
            [
                [styledAdminButton('ویرایش', 'sem_lang_edit_' . $item['id'])],
                [styledAdminButton(styledUi('insert_emoji_button', 'درج ایموجی'), 'sem_lang_insert_' . $item['id'], 'premium')],
                [styledAdminButton(styledUi('back_button', 'بازگشت'), 'sem_lang_texts_1')],
            ]
        );
        return true;
    }
    if (preg_match('/^sem_lang_edit_([0-9]+)$/', $datain, $match)) {
        styledSaveAdminSession($from_id, 'edit_language_text', ['item_id' => (int) $match[1]]);
        styledSendAdminPage(
            $from_id,
            "متن کامل جدید را ارسال کنید. می‌توانید از <code>{emoji:key}</code> استفاده کنید."
        );
        return true;
    }
    if (preg_match('/^sem_lang_insert_([0-9]+)$/', $datain, $match)) {
        styledEmojiPicker($from_id, [
            'target' => 'insert_language_text',
            'item_id' => (int) $match[1],
        ]);
        return true;
    }
    if (preg_match('/^sem_ui_([0-9]+)$/', $datain, $match)) {
        $stmt = $pdo->prepare("SELECT * FROM styled_text_overrides
            WHERE id = ? AND source_type = 'system_ui' LIMIT 1");
        $stmt->execute([(int) $match[1]]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) {
            styledUiTextsPage($from_id);
            return true;
        }
        styledSendAdminPage(
            $from_id,
            '<b>' . styledEscape($item['source_key']) . "</b>\n\n" . styledEscape($item['value']),
            [
                [styledAdminButton('ویرایش', 'sem_ui_edit_' . $item['id'])],
                [styledAdminButton(styledUi('back_button', 'بازگشت'), 'sem_ui_texts_1')],
            ]
        );
        return true;
    }
    if (preg_match('/^sem_ui_edit_([0-9]+)$/', $datain, $match)) {
        styledSaveAdminSession($from_id, 'edit_ui_text', ['item_id' => (int) $match[1]]);
        styledSendAdminPage($from_id, 'متن کامل جدید را ارسال کنید.');
        return true;
    }
    if (preg_match('/^sem_logs_([0-9]+)$/', $datain, $match)) {
        styledDeleteAdminSession($from_id);
        styledEmojiLogsPage($from_id, $match[1]);
        return true;
    }
    return false;
}

function styledHandleUpdate()
{
    if (!styledSystemReady()) {
        return false;
    }
    return styledHandleAdminUpdate();
}
