<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "[FAILED] This migration can only run from the command line.\n";
    exit(1);
}

$options = getopt('', ['backup-dir:', 'dry-run', 'fresh-database']);
$dryRun = array_key_exists('dry-run', $options);
$freshDatabase = array_key_exists('fresh-database', $options);

require __DIR__ . '/../config.php';
require_once __DIR__ . '/../panel_service.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "[FAILED] PDO is not available.\n");
    exit(1);
}

function migrationQuoteIdentifier(string $identifier): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
        throw new InvalidArgumentException('Unsafe SQL identifier');
    }
    return "`{$identifier}`";
}

function migrationTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = :table_name'
    );
    $stmt->execute([':table_name' => $table]);
    return (int) $stmt->fetchColumn() === 1;
}

function migrationColumnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name'
    );
    $stmt->execute([':table_name' => $table, ':column_name' => $column]);
    return (int) $stmt->fetchColumn() === 1;
}

function migrationIndexExists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = :table_name AND index_name = :index_name'
    );
    $stmt->execute([':table_name' => $table, ':index_name' => $index]);
    return (int) $stmt->fetchColumn() > 0;
}

function migrationCreateBackup(
    string $backupDirectory,
    string $host,
    string $database,
    string $username,
    string $password
): string {
    if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
        throw new RuntimeException("Cannot create backup directory: {$backupDirectory}");
    }

    $backupPath = rtrim($backupDirectory, DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . 'pre-panel-identity-' . gmdate('Ymd-His') . '.sql';
    $output = fopen($backupPath, 'wb');
    if ($output === false) {
        throw new RuntimeException("Cannot create backup file: {$backupPath}");
    }

    $command = [
        'mysqldump',
        '--single-transaction',
        '--quick',
        '--routines',
        '--triggers',
        '--default-character-set=utf8mb4',
        '--host=' . $host,
        '--user=' . $username,
        $database,
    ];
    $baseEnvironment = getenv();
    $environment = array_merge(is_array($baseEnvironment) ? $baseEnvironment : [], ['MYSQL_PWD' => $password]);
    $pipes = [];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => $output, 2 => ['pipe', 'w']], $pipes, null, $environment);
    if (!is_resource($process)) {
        fclose($output);
        throw new RuntimeException('Unable to start mysqldump');
    }

    fclose($pipes[0]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    fclose($output);
    @chmod($backupPath, 0600);

    clearstatcache(true, $backupPath);
    if ($exitCode !== 0 || !is_file($backupPath) || filesize($backupPath) < 64) {
        @unlink($backupPath);
        throw new RuntimeException('mysqldump failed: ' . trim((string) $error));
    }

    $header = file_get_contents($backupPath, false, null, 0, 4096);
    if (!is_string($header) || !preg_match('/(?:MySQL|MariaDB) dump/i', $header)) {
        @unlink($backupPath);
        throw new RuntimeException('Backup validation failed: database dump header not found');
    }

    return $backupPath;
}

function migrationAddColumn(PDO $pdo, string $table, string $column, string $definition, bool $dryRun): void
{
    if (!migrationTableExists($pdo, $table) || migrationColumnExists($pdo, $table, $column)) {
        return;
    }

    echo "[PLAN] add {$table}.{$column}\n";
    if (!$dryRun) {
        $pdo->exec(
            'ALTER TABLE ' . migrationQuoteIdentifier($table)
            . ' ADD COLUMN ' . migrationQuoteIdentifier($column) . ' ' . $definition
        );
    }
}

function migrationBackfillPanelRows(PDO $pdo, bool $dryRun): array
{
    $rows = $pdo->query(
        'SELECT id, code_panel, name_panel, display_name, normalized_name,
                active_normalized_name, emoji_key, custom_emoji_id, deleted_at
         FROM marzban_panel ORDER BY id'
    )->fetchAll(PDO::FETCH_ASSOC);
    $seenCodes = [];
    $updated = 0;

    foreach ($rows as $row) {
        $identity = panelParseDisplayName((string) $row['name_panel']);
        $code = trim((string) $row['code_panel']);
        if ($code === '' || isset($seenCodes[$code])) {
            do {
                $code = bin2hex(random_bytes(8));
            } while (isset($seenCodes[$code]));
        }
        $seenCodes[$code] = true;
        $activeNormalized = $row['deleted_at'] === null ? $identity['normalized_name'] : null;

        $needsUpdate =
            (string) $row['code_panel'] !== $code
            || (string) $row['display_name'] !== $identity['display_name']
            || (string) $row['normalized_name'] !== $identity['normalized_name']
            || $row['active_normalized_name'] !== $activeNormalized
            || $row['emoji_key'] !== $identity['emoji_key']
            || $row['custom_emoji_id'] !== $identity['custom_emoji_id'];
        if (!$needsUpdate) {
            continue;
        }

        $updated++;
        if ($dryRun) {
            continue;
        }
        $stmt = $pdo->prepare(
            'UPDATE marzban_panel
             SET code_panel = :code_panel,
                 display_name = :display_name,
                 normalized_name = :normalized_name,
                 active_normalized_name = :active_normalized_name,
                 emoji_key = :emoji_key,
                 custom_emoji_id = :custom_emoji_id
             WHERE id = :panel_id'
        );
        $stmt->execute([
            ':code_panel' => $code,
            ':display_name' => $identity['display_name'],
            ':normalized_name' => $identity['normalized_name'],
            ':active_normalized_name' => $activeNormalized,
            ':emoji_key' => $identity['emoji_key'],
            ':custom_emoji_id' => $identity['custom_emoji_id'],
            ':panel_id' => $row['id'],
        ]);
    }

    return ['rows' => count($rows), 'updated' => $updated];
}

function migrationFindConflicts(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT active_normalized_name, GROUP_CONCAT(id ORDER BY id) AS panel_ids, COUNT(*) AS conflict_count
         FROM marzban_panel
         WHERE deleted_at IS NULL AND active_normalized_name IS NOT NULL AND active_normalized_name <> ''
         GROUP BY active_normalized_name
         HAVING COUNT(*) > 1
         ORDER BY active_normalized_name"
    );
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function migrationBackfillRelation(
    PDO $pdo,
    string $table,
    string $legacyColumn,
    string $panelLegacyColumn,
    bool $dryRun
): array {
    if (!migrationTableExists($pdo, $table) || !migrationColumnExists($pdo, $table, $legacyColumn)) {
        return ['table' => $table, 'updated' => 0, 'ambiguous' => 0, 'skipped' => true];
    }

    $tableSql = migrationQuoteIdentifier($table);
    $legacySql = migrationQuoteIdentifier($legacyColumn);
    $panelLegacySql = migrationQuoteIdentifier($panelLegacyColumn);
    $ambiguousSql =
        "SELECT COUNT(*) FROM {$tableSql} child
         WHERE child.panel_id IS NULL AND child.{$legacySql} IS NOT NULL
           AND (SELECT COUNT(*) FROM marzban_panel panel
                WHERE panel.{$panelLegacySql} = child.{$legacySql}) > 1";
    $ambiguous = (int) $pdo->query($ambiguousSql)->fetchColumn();

    $countSql =
        "SELECT COUNT(*) FROM {$tableSql} child
         WHERE child.panel_id IS NULL
           AND (SELECT COUNT(*) FROM marzban_panel panel
                WHERE panel.{$panelLegacySql} = child.{$legacySql}) = 1";
    $updated = (int) $pdo->query($countSql)->fetchColumn();

    if (!$dryRun && $updated > 0) {
        $pdo->exec(
            "UPDATE {$tableSql} child
             JOIN marzban_panel panel ON panel.{$panelLegacySql} = child.{$legacySql}
             SET child.panel_id = panel.id
             WHERE child.panel_id IS NULL
               AND (SELECT match_count FROM (
                    SELECT {$panelLegacySql} AS legacy_value, COUNT(*) AS match_count
                    FROM marzban_panel GROUP BY {$panelLegacySql}
               ) matches WHERE matches.legacy_value = child.{$legacySql}) = 1"
        );
    }

    return ['table' => $table, 'updated' => $updated, 'ambiguous' => $ambiguous, 'skipped' => false];
}

try {
    if (!migrationTableExists($pdo, 'marzban_panel')) {
        throw new RuntimeException('marzban_panel does not exist; run the base schema migration first');
    }

    $tableCount = count($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
    $backupPath = null;
    if (!$dryRun && !$freshDatabase && $tableCount > 0) {
        $backupDirectory = $options['backup-dir'] ?? dirname(__DIR__) . '/backups';
        $backupPath = migrationCreateBackup(
            (string) $backupDirectory,
            (string) $dbhost,
            (string) $dbname,
            (string) $usernamedb,
            (string) $passworddb
        );
        echo "[OK] validated database backup: {$backupPath}\n";
    } elseif ($freshDatabase) {
        echo "[INFO] backup skipped only because --fresh-database was explicitly supplied\n";
    }

    $panelColumns = [
        'display_name' => 'VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL',
        'normalized_name' => 'VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL',
        'active_normalized_name' => 'VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL',
        'emoji_key' => 'VARCHAR(100) NULL',
        'custom_emoji_id' => 'VARCHAR(64) NULL',
        'deleted_at' => 'DATETIME NULL',
    ];
    foreach ($panelColumns as $column => $definition) {
        migrationAddColumn($pdo, 'marzban_panel', $column, $definition, $dryRun);
    }

    $relations = [
        ['product', 'Location', 'name_panel'],
        ['invoice', 'Service_location', 'name_panel'],
        ['manualsell', 'codepanel', 'code_panel'],
        ['DiscountSell', 'code_panel', 'code_panel'],
        ['x_ui', 'codepanel', 'code_panel'],
    ];
    foreach ($relations as [$table, $legacyColumn]) {
        migrationAddColumn($pdo, $table, 'panel_id', 'INT UNSIGNED NULL', $dryRun);
        if (
            !$dryRun
            && migrationTableExists($pdo, $table)
            && !migrationIndexExists($pdo, $table, 'idx_' . strtolower($table) . '_panel_id')
        ) {
            $pdo->exec(
                'ALTER TABLE ' . migrationQuoteIdentifier($table)
                . ' ADD INDEX ' . migrationQuoteIdentifier('idx_' . strtolower($table) . '_panel_id')
                . ' (`panel_id`)'
            );
        }
    }

    if ($dryRun) {
        echo "[OK] dry-run completed without schema or data changes\n";
        exit(0);
    }

    $panelReport = migrationBackfillPanelRows($pdo, false);
    echo "[OK] panel backfill: {$panelReport['updated']} updated of {$panelReport['rows']}\n";

    $relationReports = [];
    foreach ($relations as [$table, $legacyColumn, $panelLegacyColumn]) {
        $relationReports[] = migrationBackfillRelation($pdo, $table, $legacyColumn, $panelLegacyColumn, false);
    }
    foreach ($relationReports as $report) {
        if ($report['skipped']) {
            echo "[INFO] {$report['table']}: table/legacy column not present\n";
            continue;
        }
        echo "[OK] {$report['table']}: {$report['updated']} linked, {$report['ambiguous']} ambiguous\n";
    }

    $conflicts = migrationFindConflicts($pdo);
    if ($conflicts) {
        fwrite(STDERR, "[CONFLICT] active normalized-name duplicates must be resolved before adding the unique index:\n");
        foreach ($conflicts as $conflict) {
            fwrite(
                STDERR,
                "  normalized_name=" . json_encode($conflict['active_normalized_name'], JSON_UNESCAPED_UNICODE)
                . " panel_ids={$conflict['panel_ids']}\n"
            );
        }
        fwrite(STDERR, "[FAILED] suspicious data was not deleted; rerun after resolving conflicts.\n");
        exit(2);
    }

    if (!migrationIndexExists($pdo, 'marzban_panel', 'uq_marzban_panel_active_name')) {
        $pdo->exec(
            'ALTER TABLE marzban_panel
             ADD UNIQUE INDEX uq_marzban_panel_active_name (active_normalized_name)'
        );
    }
    if (!migrationIndexExists($pdo, 'marzban_panel', 'uq_marzban_panel_code')) {
        $pdo->exec('ALTER TABLE marzban_panel ADD UNIQUE INDEX uq_marzban_panel_code (code_panel)');
    }
    if (!migrationIndexExists($pdo, 'marzban_panel', 'idx_marzban_panel_deleted_at')) {
        $pdo->exec('ALTER TABLE marzban_panel ADD INDEX idx_marzban_panel_deleted_at (deleted_at)');
    }

    echo "[OK] panel identity migration completed";
    if ($backupPath) {
        echo "; backup={$backupPath}";
    }
    echo "\n";
} catch (Throwable $e) {
    fwrite(STDERR, '[FAILED] ' . $e->getMessage() . "\n");
    exit(1);
}
