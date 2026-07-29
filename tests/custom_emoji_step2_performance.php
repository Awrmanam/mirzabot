<?php

$memoryAtStart = memory_get_usage(true);

class StyledStep2FakeStatement
{
    private $pdo;
    private $sql;
    private $rows;
    private $column;

    public function __construct($pdo, $sql, array $rows = [], $column = false)
    {
        $this->pdo = $pdo;
        $this->sql = $sql;
        $this->rows = $rows;
        $this->column = $column;
    }

    public function execute($params = [])
    {
        if (strpos($this->sql, 'INSERT INTO styled_settings') !== false) {
            $this->pdo->settings[(string) $params[0]] = (string) $params[1];
            return true;
        }
        if (strpos($this->sql, 'INSERT IGNORE INTO styled_text_overrides') !== false) {
            $this->pdo->uiInsertExecutions++;
            $key = (string) $params[0];
            if (!isset($this->pdo->uiRows[$key])) {
                $this->pdo->uiRows[$key] = [
                    'source_key' => $key,
                    'value' => (string) $params[2],
                    'is_active' => 1,
                ];
            }
            return true;
        }
        if (strpos($this->sql, 'INSERT INTO styled_emoji_usage') !== false) {
            $this->pdo->usageExecutions++;
            $this->pdo->usageWrites[] = $params;
            $tuple = serialize([(string) $params[0], (string) $params[1], (string) $params[2]]);
            if (!isset($this->pdo->usageTotals[$tuple])) {
                $this->pdo->usageTotals[$tuple] = 0;
            }
            $this->pdo->usageTotals[$tuple] += (int) $params[4];
            return true;
        }
        return true;
    }

    public function fetchColumn()
    {
        return $this->column;
    }

    public function fetchAll($mode = null)
    {
        return $this->rows;
    }

    public function fetch($mode = null)
    {
        return $this->rows ? reset($this->rows) : false;
    }
}

class StyledStep2FakePdo
{
    public $settings = [];
    public $emojis = [];
    public $uiRows = [];
    public $buttonRows = [];
    public $queryCounts = [
        'readiness' => 0,
        'settings' => 0,
        'emojis' => 0,
        'system_ui' => 0,
        'button_icons' => 0,
    ];
    public $uiInsertExecutions = 0;
    public $usageExecutions = 0;
    public $usageWrites = [];
    public $usageTotals = [];
    public $beginCount = 0;
    public $commitCount = 0;
    public $rollbackCount = 0;
    private $inTransaction = false;

    public function query($sql)
    {
        if (strpos($sql, "SHOW TABLES LIKE 'styled_emojis'") !== false) {
            $this->queryCounts['readiness']++;
            return new StyledStep2FakeStatement($this, $sql, [], true);
        }
        if (strpos($sql, 'FROM styled_settings') !== false) {
            $this->queryCounts['settings']++;
            $rows = [];
            foreach ($this->settings as $key => $value) {
                $rows[] = ['setting_key' => $key, 'setting_value' => $value];
            }
            return new StyledStep2FakeStatement($this, $sql, $rows);
        }
        if (strpos($sql, 'FROM styled_emojis') !== false) {
            $this->queryCounts['emojis']++;
            return new StyledStep2FakeStatement($this, $sql, array_values($this->emojis));
        }
        if (strpos($sql, "source_type = 'system_ui'") !== false) {
            $this->queryCounts['system_ui']++;
            return new StyledStep2FakeStatement($this, $sql, array_values($this->uiRows));
        }
        if (strpos($sql, 'FROM styled_button_icons') !== false) {
            $this->queryCounts['button_icons']++;
            return new StyledStep2FakeStatement($this, $sql, $this->buttonRows);
        }
        throw new RuntimeException('Unexpected query: ' . $sql);
    }

    public function prepare($sql)
    {
        return new StyledStep2FakeStatement($this, $sql);
    }

    public function beginTransaction()
    {
        $this->beginCount++;
        $this->inTransaction = true;
        return true;
    }

    public function inTransaction()
    {
        return $this->inTransaction;
    }

    public function commit()
    {
        $this->commitCount++;
        $this->inTransaction = false;
        return true;
    }

    public function rollBack()
    {
        $this->rollbackCount++;
        $this->inTransaction = false;
        return true;
    }
}

$root = dirname(__DIR__);
$failures = [];
$check = function ($condition, $message) use (&$failures) {
    if ($condition) {
        echo "[PASS] {$message}\n";
        return;
    }
    $failures[] = $message;
    echo "[FAIL] {$message}\n";
};
$delta = function ($pdo, $name, $before) {
    return $pdo->queryCounts[$name] - $before;
};
$bufferCount = function () {
    $cache =& styledRuntimeCache();
    return count($cache['usage_buffer']);
};

$pdo = new StyledStep2FakePdo();
$pdo->settings = [
    'global_fallback' => '🌐',
    'unknown_key_logging' => '0',
    'usage_tracking' => '1',
    'another_setting' => 'second',
];
$pdo->emojis = [
    'premium' => [
        'id' => 1,
        'key' => 'premium',
        'fallback_emoji' => '💎',
        'custom_emoji_id' => '123456789',
        'is_active' => 1,
        'is_valid' => 1,
    ],
    'global_only' => [
        'id' => 2,
        'key' => 'global_only',
        'fallback_emoji' => '',
        'custom_emoji_id' => '987654321',
        'is_active' => 1,
        'is_valid' => 1,
    ],
    'inactive' => [
        'id' => 3,
        'key' => 'inactive',
        'fallback_emoji' => '▫️',
        'custom_emoji_id' => '1111122222',
        'is_active' => 0,
        'is_valid' => 1,
    ],
];
$pdo->uiRows = [
    'existing_active' => [
        'source_key' => 'existing_active',
        'value' => 'Saved value',
        'is_active' => 1,
    ],
    'existing_inactive' => [
        'source_key' => 'existing_inactive',
        'value' => 'Hidden value',
        'is_active' => 0,
    ],
];
$pdo->buttonRows = [
    ['text' => 'Visible button', 'icon_emoji_key' => 'premium'],
];

require $root . DIRECTORY_SEPARATOR . 'emoji_system.php';

styledClearRuntimeCaches();
$before = $pdo->queryCounts['settings'];
$firstSetting = styledSetting('global_fallback', 'missing');
$secondSetting = styledSetting('another_setting', 'missing');
$thirdSetting = styledSetting('global_fallback', 'missing');
$settingsSelects = $delta($pdo, 'settings', $before);
$check(
    $firstSetting === '🌐' && $secondSetting === 'second' && $thirdSetting === '🌐'
        && $settingsSelects <= 1,
    'multiple setting keys use at most one styled_settings SELECT'
);

styledClearRuntimeCaches();
$before = $pdo->queryCounts['emojis'];
$premium = styledEmojiByKey('premium');
$inactive = styledEmojiByKey('inactive');
$unknown = styledEmojiByKey('missing');
$emojiSelects = $delta($pdo, 'emojis', $before);
$check(
    $premium && $inactive && $unknown === false && $emojiSelects <= 1,
    'multiple emoji keys use at most one styled_emojis SELECT'
);

styledClearRuntimeCaches();
$before = $pdo->queryCounts['settings'];
styledResolveEmoji('premium', 'step2-test');
styledResolveEmoji('global_only', 'step2-test');
styledResolveEmoji('premium', 'step2-test');
$resolveSettingsSelects = $delta($pdo, 'settings', $before);
$check(
    $resolveSettingsSelects <= 1,
    'repeated styledResolveEmoji calls do not repeatedly query global_fallback'
);

styledClearRuntimeCaches();
$writesBefore = $pdo->usageExecutions;
styledRecordUsage('premium', 'telegram_text', 'same-source', 'First label');
styledRecordUsage('premium', 'telegram_text', 'same-source', '');
styledRecordUsage('premium', 'telegram_text', 'same-source', 'Latest label');
$identicalWritesBeforeFlush = $pdo->usageExecutions - $writesBefore;
$check(
    $identicalWritesBeforeFlush === 0 && $bufferCount() === 1,
    'repeated usage records are buffered without an immediate write'
);
$cache =& styledRuntimeCache();
$bufferedEntry = reset($cache['usage_buffer']);
$check(
    $bufferedEntry['count'] === 3 && $bufferedEntry['source_label'] === 'Latest label',
    'identical usage records preserve count and latest non-empty label'
);
styledFlushUsageBuffer();
$identicalWritesAfterFlush = $pdo->usageExecutions - $writesBefore;
$lastUsageWrite = end($pdo->usageWrites);
$check(
    $identicalWritesAfterFlush === 1 && (int) $lastUsageWrite[4] === 3 && $bufferCount() === 0,
    'three identical usage records flush as one UPSERT with increment 3'
);

$writesBefore = $pdo->usageExecutions;
styledRecordUsage('premium', 'telegram_text', 'tuple-one', 'One');
styledRecordUsage('premium', 'telegram_text', 'tuple-two', 'Two');
$twoTupleWritesBeforeFlush = $pdo->usageExecutions - $writesBefore;
styledFlushUsageBuffer();
$twoTupleWritesAfterFlush = $pdo->usageExecutions - $writesBefore;
$check(
    $twoTupleWritesBeforeFlush === 0 && $twoTupleWritesAfterFlush === 2,
    'two different usage tuples flush as exactly two UPSERT executions'
);

$pdo->settings['usage_tracking'] = '0';
styledClearRuntimeCaches();
$writesBefore = $pdo->usageExecutions;
$bufferBefore = $bufferCount();
styledRecordUsage('premium', 'telegram_text', 'disabled', 'Disabled');
$check(
    $bufferCount() === $bufferBefore && $pdo->usageExecutions === $writesBefore,
    'disabled usage tracking creates no buffer entry and no write'
);
$pdo->settings['usage_tracking'] = '1';

styledClearRuntimeCaches();
$before = $pdo->queryCounts['system_ui'];
$insertsBefore = $pdo->uiInsertExecutions;
$activeUi = styledUi('existing_active', 'Default active');
$inactiveUi = styledUi('existing_inactive', 'Default inactive');
$uiSelects = $delta($pdo, 'system_ui', $before);
$check(
    $activeUi === 'Saved value' && $inactiveUi === 'Default inactive'
        && $uiSelects === 1 && $pdo->uiInsertExecutions === $insertsBefore,
    'styledUi loads all rows once and does not insert existing keys'
);

$before = $pdo->queryCounts['system_ui'];
$insertsBefore = $pdo->uiInsertExecutions;
$missingUi = styledUi('new_key', 'New default');
$missingUiAgain = styledUi('new_key', 'Changed default');
$missingUiSelects = $delta($pdo, 'system_ui', $before);
$missingUiInserts = $pdo->uiInsertExecutions - $insertsBefore;
$check(
    $missingUi === 'New default' && $missingUiAgain === 'New default'
        && $missingUiInserts === 1 && $missingUiSelects === 0,
    'a missing styledUi key inserts once without a second SELECT'
);

styledClearRuntimeCaches();
styledSetting('global_fallback', '');
styledEmojiByKey('premium');
styledUi('existing_active', '');
$buttonIcon = styledButtonIconKeyForText('Visible button');
$countsBeforeInvalidation = $pdo->queryCounts;
styledSetting('global_fallback', '');
styledEmojiByKey('global_only');
styledUi('existing_inactive', '');
styledButtonIconKeyForText('Visible button');
$check(
    $pdo->queryCounts === $countsBeforeInvalidation && $buttonIcon === 'premium',
    'settings, emoji, UI, and button reads remain cached before invalidation'
);
styledClearRuntimeCaches();
styledSetting('global_fallback', '');
styledEmojiByKey('premium');
styledUi('existing_active', '');
styledButtonIconKeyForText('Visible button');
$check(
    $pdo->queryCounts['settings'] === $countsBeforeInvalidation['settings'] + 1
        && $pdo->queryCounts['emojis'] === $countsBeforeInvalidation['emojis'] + 1
        && $pdo->queryCounts['system_ui'] === $countsBeforeInvalidation['system_ui'] + 1
        && $pdo->queryCounts['button_icons'] === $countsBeforeInvalidation['button_icons'] + 1,
    'styledClearRuntimeCaches forces every read cache to reload'
);

styledClearRuntimeCaches();
styledRecordUsage('premium', 'telegram_text', 'survives-clear', 'Buffered');
$bufferBefore = $bufferCount();
styledClearRuntimeCaches();
$check(
    $bufferBefore === 1 && $bufferCount() === 1,
    'cache invalidation does not erase buffered usage records'
);
styledFlushUsageBuffer();

styledClearRuntimeCaches();
$before = $pdo->queryCounts['readiness'];
styledSystemReady();
styledSystemReady();
styledSystemReady();
$readinessQueries = $delta($pdo, 'readiness', $before);
$check($readinessQueries <= 1, 'styledSystemReady queries readiness at most once before invalidation');
styledClearRuntimeCaches();
styledSystemReady();
$check(
    $delta($pdo, 'readiness', $before) === 2,
    'explicit cache invalidation permits one new readiness query'
);

styledClearRuntimeCaches();
$usableResult = styledResolveEmoji('premium', 'step2-test');
$fallbackResult = styledResolveEmoji('global_only', 'step2-test');
$check(
    $usableResult['fallback'] === '💎'
        && $usableResult['custom_emoji_id'] === '123456789'
        && $usableResult['usable'] === true
        && $fallbackResult['fallback'] === '🌐',
    'fallback text and usable Custom Emoji results remain unchanged'
);

styledClearRuntimeCaches();
$before = $pdo->queryCounts['settings'];
$check(styledSetSetting('new_setting', 'new value') === true, 'styledSetSetting preserves its UPSERT');
$setSettingSelects = $delta($pdo, 'settings', $before);
$check(
    styledSetting('new_setting', 'missing') === 'new value'
        && $delta($pdo, 'settings', $before) === $setSettingSelects,
    'styledSetSetting updates the request-local value without another SELECT'
);

$emojiSystem = file_get_contents($root . DIRECTORY_SEPARATOR . 'emoji_system.php');
$installer = file_get_contents($root . DIRECTORY_SEPARATOR . 'install.sh');
$check(
    strpos($emojiSystem, 'emoji_install.php') === false,
    'emoji_system.php still does not include emoji_install.php'
);
$check(
    !preg_match('/marker|\.ready|\.installed|file_exists|is_file/i', $emojiSystem)
        && strpos($emojiSystem, 'CUSTOM_EMOJI_ENABLED') === false,
    'no marker-file or CUSTOM_EMOJI_ENABLED dependency was introduced'
);
$check(
    strpos(
        $installer,
        'require_value MIRZA_RELEASE_SHA256'
    ) !== false,
    'install.sh requires a release checksum'
);

$protectedFiles = [
    'emoji_system.php',
    'emoji_install.php',
    'scripts/migrate_custom_emoji.php',
];
$command = 'git -C ' . escapeshellarg($root)
    . ' diff --name-only c9ca081e88ede2ce7978a05e91811b1e59ea0678 -- '
    . implode(' ', array_map('escapeshellarg', $protectedFiles));
exec($command, $protectedChanges, $gitExitCode);
$check(
    $gitExitCode === 0 && count($protectedChanges) === 0,
    'Step 2 emoji runtime and explicit migration entrypoints are unchanged'
);

$cache =& styledRuntimeCache();
$check(
    $cache['usage_shutdown_registered'] === true,
    'usage flush shutdown callback is registered request-locally'
);

$memoryDelta = memory_get_usage(true) - $memoryAtStart;
$check(
    $memoryDelta <= 16 * 1024 * 1024,
    'repeatable Custom Emoji regression fixture stays within a 16 MiB memory delta'
);

echo "\n[METRIC] settings_selects_multiple_keys={$settingsSelects}\n";
echo "[METRIC] emoji_selects_multiple_keys={$emojiSelects}\n";
echo "[METRIC] resolve_settings_selects={$resolveSettingsSelects}\n";
echo "[METRIC] ui_preload_selects={$uiSelects}\n";
echo "[METRIC] readiness_queries_before_invalidation={$readinessQueries}\n";
echo "[METRIC] identical_usage_writes_before_flush={$identicalWritesBeforeFlush}\n";
echo "[METRIC] identical_usage_writes_after_flush={$identicalWritesAfterFlush}\n";
echo "[METRIC] two_tuple_writes_before_flush={$twoTupleWritesBeforeFlush}\n";
echo "[METRIC] two_tuple_writes_after_flush={$twoTupleWritesAfterFlush}\n";
echo "[METRIC] memory_delta_bytes={$memoryDelta}\n";
echo "[METRIC] peak_memory_bytes=" . memory_get_peak_usage(true) . "\n";

if ($failures) {
    echo "\n" . count($failures) . " Step 2 performance check(s) failed.\n";
    exit(1);
}

echo "\nAll Custom Emoji Step 2 performance checks passed.\n";
