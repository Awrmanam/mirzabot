<?php

/**
 * Central Custom Emoji schema and one-time migrations.
 *
 * This file is intentionally safe to include on every update. Destructive
 * changes are never performed and data migrations are guarded by a version.
 */

if (!defined('MIRZA_CUSTOM_EMOJI_MIGRATION') || MIRZA_CUSTOM_EMOJI_MIGRATION !== true) {
    $GLOBALS['mirza_custom_emoji_migration_result'] = [
        'ok' => false,
        'message' => 'Custom Emoji migration must be invoked explicitly.',
    ];
    return;
}

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('PDO is not available for the emoji installer.');
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS styled_emojis (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        display_name VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        `key` VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        custom_emoji_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        fallback_emoji VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        is_valid TINYINT(1) NULL DEFAULT NULL,
        validated_at DATETIME NULL DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_styled_emojis_key (`key`),
        KEY idx_styled_emojis_active (is_active),
        KEY idx_styled_emojis_custom_id (custom_emoji_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS styled_settings (
        setting_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        setting_value TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (setting_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS styled_emoji_usage (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        emoji_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        source_type VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        source_key VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        source_label VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
        first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        use_count BIGINT UNSIGNED NOT NULL DEFAULT 1,
        PRIMARY KEY (id),
        UNIQUE KEY uq_styled_usage (emoji_key, source_type, source_key),
        KEY idx_styled_usage_key (emoji_key),
        KEY idx_styled_usage_last_seen (last_seen_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS styled_emoji_logs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        log_type VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        emoji_key VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
        context VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
        message TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_styled_logs_created (created_at),
        KEY idx_styled_logs_type (log_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS styled_admin_sessions (
        admin_id BIGINT NOT NULL,
        current_step VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        data_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (admin_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS styled_button_icons (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        source_type VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        source_key VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        icon_emoji_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_styled_button_source (source_type, source_key),
        KEY idx_styled_button_emoji (icon_emoji_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS styled_text_overrides (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        source_type VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        source_key VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        display_name VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
        value LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_styled_text_source (source_type, source_key),
        KEY idx_styled_text_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $settings = [
        ['schema_version', '0'],
        ['global_fallback', ''],
        ['usage_tracking', '0'],
        ['unknown_key_logging', '1'],
    ];
    $stmt = $pdo->prepare("INSERT IGNORE INTO styled_settings (setting_key, setting_value) VALUES (?, ?)");
    foreach ($settings as $settingRow) {
        $stmt->execute($settingRow);
    }

    $seedEmoji = $pdo->prepare("INSERT IGNORE INTO styled_emojis
        (display_name, `key`, custom_emoji_id, fallback_emoji, is_active, is_valid)
        VALUES (?, ?, '', ?, 1, 0)");
    $seedEmoji->execute(['ظاهر و طراحی', 'appearance', '🎨']);
    $seedEmoji->execute(['ایموجی پریمیوم', 'premium', '💎']);

    $schemaVersionStmt = $pdo->prepare("SELECT setting_value FROM styled_settings WHERE setting_key = 'schema_version'");
    $schemaVersionStmt->execute();
    $schemaVersion = (int) $schemaVersionStmt->fetchColumn();

    if ($schemaVersion < 1) {
        $tableExists = function ($tableName) use ($pdo) {
            $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
            $stmt->execute([$tableName]);
            return (bool) $stmt->fetchColumn();
        };
        $columnExists = function ($tableName, $columnName) use ($pdo) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
            $stmt->execute([$tableName, $columnName]);
            return (int) $stmt->fetchColumn() > 0;
        };

        if ($tableExists('ticket_content') && !$columnExists('ticket_content', 'emoji_key')) {
            $pdo->exec("ALTER TABLE ticket_content
                ADD COLUMN emoji_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' AFTER emoji");
            $pdo->exec("CREATE INDEX idx_ticket_content_emoji_key ON ticket_content (emoji_key)");
        }
        if ($tableExists('ticket_options') && !$columnExists('ticket_options', 'emoji_key')) {
            $pdo->exec("ALTER TABLE ticket_options
                ADD COLUMN emoji_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' AFTER emoji");
            $pdo->exec("CREATE INDEX idx_ticket_options_emoji_key ON ticket_options (emoji_key)");
        }

        $migrateLegacyRows = function ($table, $idColumn) use ($pdo, $tableExists) {
            if (!$tableExists($table)) {
                return;
            }
            $rows = $pdo->query("SELECT {$idColumn} AS row_id, emoji, custom_emoji_id
                FROM {$table} WHERE custom_emoji_id <> ''")->fetchAll(PDO::FETCH_ASSOC);
            $insertEmoji = $pdo->prepare("INSERT IGNORE INTO styled_emojis
                (display_name, `key`, custom_emoji_id, fallback_emoji, is_active, is_valid)
                VALUES (?, ?, ?, ?, 1, NULL)");
            $updateRow = $pdo->prepare("UPDATE {$table}
                SET emoji_key = ?, custom_emoji_id = '' WHERE {$idColumn} = ?");
            $insertUsage = $pdo->prepare("INSERT INTO styled_emoji_usage
                (emoji_key, source_type, source_key, source_label)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE use_count = use_count + 1, last_seen_at = CURRENT_TIMESTAMP");

            foreach ($rows as $row) {
                $customId = trim((string) $row['custom_emoji_id']);
                if (!preg_match('/^[0-9]{5,64}$/', $customId)) {
                    continue;
                }
                $key = 'legacy_' . substr(hash('sha256', $customId), 0, 16);
                $label = 'ایموجی مهاجرت‌یافته تیکت ' . $row['row_id'];
                $fallback = trim((string) $row['emoji']);
                $insertEmoji->execute([$label, $key, $customId, $fallback,]);
                $updateRow->execute([$key, $row['row_id']]);
                $insertUsage->execute([
                    $key,
                    $table,
                    (string) $row['row_id'],
                    $label,
                ]);
            }
        };

        $pdo->beginTransaction();
        try {
            $migrateLegacyRows('ticket_content', 'id');
            $migrateLegacyRows('ticket_options', 'id');
            $pdo->prepare("UPDATE styled_settings SET setting_value = '1'
                WHERE setting_key = 'schema_version'")->execute();
            $pdo->commit();
            $schemaVersion = 1;
        } catch (Throwable $migrationError) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $migrationError;
        }
    }

    if ($schemaVersion < 2) {
        $ticketTableStmt = $pdo->prepare("SHOW TABLES LIKE 'ticket_content'");
        $ticketTableStmt->execute();
        if ($ticketTableStmt->fetchColumn()) {
            $pdo->prepare("UPDATE ticket_content
                SET value = 'انتخاب از کتابخانه ایموجی'
                WHERE content_key = 'admin_edit_custom_emoji'
                    AND value = 'Custom Emoji ID'")->execute();
            $pdo->prepare("UPDATE ticket_content
                SET value = REPLACE(value, 'Custom Emoji ID', 'کلید کتابخانه ایموجی')
                WHERE content_key IN ('admin_content_detail', 'admin_option_detail')
                    AND value LIKE '%Custom Emoji ID%'")->execute();
        }
        $pdo->prepare("UPDATE styled_settings SET setting_value = '2'
            WHERE setting_key = 'schema_version'")->execute();
        $schemaVersion = 2;
    }

    $emojiSystemPath = __DIR__ . '/emoji_system.php';
    $emojiSystemSource = is_file($emojiSystemPath)
        ? (string) file_get_contents($emojiSystemPath)
        : '';
    if ($emojiSystemSource !== ''
        && preg_match_all(
            "/styledUi\\(\\s*'((?:\\\\'|[^'])*)'\\s*,\\s*'((?:\\\\'|[^'])*)'\\s*\\)/",
            $emojiSystemSource,
            $uiMatches,
            PREG_SET_ORDER
        )) {
        $uiInsert = $pdo->prepare(
            "INSERT IGNORE INTO styled_text_overrides
             (source_type, source_key, display_name, value, is_active)
             VALUES ('system_ui', ?, ?, ?, 1)"
        );
        foreach ($uiMatches as $uiMatch) {
            $uiKey = str_replace("\\'", "'", $uiMatch[1]);
            $uiValue = str_replace("\\'", "'", $uiMatch[2]);
            $uiInsert->execute([$uiKey, $uiKey, $uiValue]);
        }
    }

    $languagePath = __DIR__ . '/text.json';
    $languagePayload = is_file($languagePath)
        ? json_decode((string) file_get_contents($languagePath), true)
        : null;
    if (is_array($languagePayload)) {
        $flattenLanguage = function (array $values, $prefix = '') use (&$flattenLanguage) {
            $flat = [];
            foreach ($values as $key => $value) {
                $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
                if (is_array($value)) {
                    $flat += $flattenLanguage($value, $path);
                } elseif (is_string($value) || is_numeric($value)) {
                    $flat[$path] = (string) $value;
                }
            }
            return $flat;
        };
        $languageInsert = $pdo->prepare(
            "INSERT IGNORE INTO styled_text_overrides
             (source_type, source_key, display_name, value, is_active)
             VALUES ('language', ?, ?, ?, 1)"
        );
        $hashUpdate = $pdo->prepare(
            "INSERT INTO styled_settings (setting_key, setting_value)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        foreach ($languagePayload as $languageCode => $languageValues) {
            if (!is_array($languageValues)
                || !preg_match('/^[a-z0-9_-]+$/i', (string) $languageCode)) {
                continue;
            }
            $flatLanguage = $flattenLanguage($languageValues);
            foreach ($flatLanguage as $path => $value) {
                $sourceKey = $languageCode . '.' . $path;
                $languageInsert->execute([$sourceKey, $sourceKey, $value]);
            }
            $hashUpdate->execute([
                'language_seed_hash_' . $languageCode,
                hash('sha256', $languagePath . '|' . json_encode($flatLanguage, JSON_UNESCAPED_UNICODE)),
            ]);
        }
    }

    $markerDirectory = __DIR__ . '/storage/cache';
    $markerPath = $markerDirectory . '/custom_emoji_schema.ready';
    if (!is_dir($markerDirectory)
        && !mkdir($markerDirectory, 0775, true)
        && !is_dir($markerDirectory)) {
        throw new RuntimeException('Unable to create the Custom Emoji schema marker directory.');
    }
    $markerPayload = json_encode([
        'schema_version' => max(2, $schemaVersion),
        'migrated_at' => gmdate('c'),
    ], JSON_UNESCAPED_SLASHES);
    if ($markerPayload === false || file_put_contents($markerPath, $markerPayload, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write the Custom Emoji schema marker.');
    }
    $GLOBALS['mirza_custom_emoji_migration_result'] = [
        'ok' => true,
        'message' => 'Custom Emoji schema is ready.',
        'marker' => $markerPath,
    ];
} catch (Throwable $e) {
    error_log('[Mirza Styled Emoji Install] ' . $e->getMessage());
    $GLOBALS['mirza_custom_emoji_migration_result'] = [
        'ok' => false,
        'message' => $e->getMessage(),
    ];
}
