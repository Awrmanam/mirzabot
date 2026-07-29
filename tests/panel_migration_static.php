<?php

$root = dirname(__DIR__);
$migration = file_get_contents($root . '/scripts/migrate_panel_identity.php');
$cleanup = file_get_contents($root . '/scripts/cleanup_orphan_panels.php');
$failures = [];

$check = function ($condition, string $message) use (&$failures): void {
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $message . "\n";
    if (!$condition) {
        $failures[] = $message;
    }
};

$check(strpos($migration, "PHP_SAPI !== 'cli'") !== false, 'panel migration is CLI-only');
$check(strpos($migration, 'mysqldump') !== false, 'migration requires a pre-schema database dump');
$check(strpos($migration, 'filesize($backupPath) < 64') !== false, 'migration validates a non-empty dump');
$check(strpos($migration, 'migrationFindConflicts') !== false, 'migration reports normalized-name conflicts');
$check(strpos($migration, 'suspicious data was not deleted') !== false, 'migration never auto-deletes suspicious data');
$check(strpos($migration, 'uq_marzban_panel_active_name') !== false, 'active normalized names receive a unique index after conflict checks');
$check(strpos($migration, 'uq_marzban_panel_code') !== false, 'legacy code_panel receives a unique compatibility index');
$check(substr_count($migration, "'panel_id', 'INT UNSIGNED NULL'") === 1, 'relation panel_id migration is centralized');
$check(stripos($migration, 'FOREIGN KEY') === false, 'dirty legacy data is not forced behind a blind foreign key');
$check(strpos($cleanup, "\$apply && \$panelId === null") !== false, 'cleanup apply requires an explicit panel id');
$check(strpos($cleanup, "'historical_rows_deleted' => 0") !== false, 'cleanup preserves historical rows');
$check(strpos($cleanup, "'mode' => \$apply ? 'apply' : 'dry-run'") !== false, 'cleanup defaults to an explicit dry-run report');

if ($failures) {
    echo "\n" . count($failures) . " panel migration check(s) failed.\n";
    exit(1);
}

echo "\nAll panel migration static checks passed.\n";
