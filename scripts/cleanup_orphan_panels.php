<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "[FAILED] This cleanup can only run from the command line.\n";
    exit(1);
}

$options = getopt('', ['apply', 'panel-id:', 'fixture:']);
$apply = array_key_exists('apply', $options);
$panelId = isset($options['panel-id'])
    ? filter_var($options['panel-id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : null;

if ($apply && $panelId === null) {
    fwrite(STDERR, "[FAILED] --apply requires an explicit positive --panel-id.\n");
    exit(1);
}

function orphanFixtureReport(array $fixture, ?int $onlyPanelId): array
{
    $panelIds = [];
    foreach ($fixture['panels'] ?? [] as $panel) {
        $panelIds[(int) $panel['id']] = true;
    }

    $report = [];
    foreach ($fixture['relations'] ?? [] as $table => $rows) {
        $orphanIds = [];
        foreach ($rows as $row) {
            $candidate = isset($row['panel_id']) ? (int) $row['panel_id'] : 0;
            if ($candidate < 1 || isset($panelIds[$candidate])) {
                continue;
            }
            if ($onlyPanelId !== null && $candidate !== $onlyPanelId) {
                continue;
            }
            $orphanIds[] = $candidate;
        }
        $report[$table] = [
            'count' => count($orphanIds),
            'panel_ids' => array_values(array_unique($orphanIds)),
        ];
    }

    return $report;
}

if (isset($options['fixture'])) {
    if ($apply) {
        fwrite(STDERR, "[FAILED] fixture mode is report-only and never mutates data.\n");
        exit(1);
    }
    $fixture = json_decode((string) file_get_contents($options['fixture']), true);
    if (!is_array($fixture)) {
        fwrite(STDERR, "[FAILED] invalid JSON fixture.\n");
        exit(1);
    }
    echo json_encode(orphanFixtureReport($fixture, $panelId ?: null), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

require __DIR__ . '/../config.php';
if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "[FAILED] PDO is not available.\n");
    exit(1);
}

$relations = [
    // Historical rows are preserved. Apply only detaches an invalid relation.
    'invoice' => 'historical',
    'manualsell' => 'historical',
    // Operational configuration is also preserved; cleanup makes the broken
    // relationship explicit instead of guessing a replacement panel.
    'product' => 'operational',
    'DiscountSell' => 'operational',
    'x_ui' => 'operational',
];

try {
    $report = [];
    foreach ($relations as $table => $kind) {
        $name = preg_replace('/[^A-Za-z0-9_]/', '', $table);
        $exists = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name'
        );
        $exists->execute([':table_name' => $name, ':column_name' => 'panel_id']);
        if ((int) $exists->fetchColumn() !== 1) {
            $report[$table] = ['kind' => $kind, 'count' => 0, 'status' => 'panel_id_not_present'];
            continue;
        }

        $sql =
            "SELECT child.panel_id, COUNT(*) AS row_count
             FROM `{$name}` child
             LEFT JOIN marzban_panel panel ON panel.id = child.panel_id
             WHERE child.panel_id IS NOT NULL AND panel.id IS NULL";
        $params = [];
        if ($panelId !== null) {
            $sql .= ' AND child.panel_id = :panel_id';
            $params[':panel_id'] = $panelId;
        }
        $sql .= ' GROUP BY child.panel_id ORDER BY child.panel_id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $orphans = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $count = array_sum(array_map(static fn(array $row): int => (int) $row['row_count'], $orphans));

        $report[$table] = [
            'kind' => $kind,
            'count' => $count,
            'panel_ids' => array_map(static fn(array $row): int => (int) $row['panel_id'], $orphans),
            'action' => $apply ? 'detach_panel_id' : 'report_only',
        ];

        if ($apply && $count > 0) {
            $detach = $pdo->prepare(
                "UPDATE `{$name}` child
                 LEFT JOIN marzban_panel panel ON panel.id = child.panel_id
                 SET child.panel_id = NULL
                 WHERE child.panel_id = :panel_id AND panel.id IS NULL"
            );
            $detach->execute([':panel_id' => $panelId]);
            if ($detach->rowCount() !== $count) {
                throw new RuntimeException("{$table}: cleanup row count changed during execution");
            }
        }
    }

    echo json_encode([
        'mode' => $apply ? 'apply' : 'dry-run',
        'panel_id' => $panelId,
        'relations' => $report,
        'historical_rows_deleted' => 0,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, '[FAILED] ' . $e->getMessage() . "\n");
    exit(1);
}
