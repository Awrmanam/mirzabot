<?php

/**
 * Stable identity and lifecycle operations for connection panels.
 *
 * Canonical identity is marzban_panel.id. code_panel and name_panel are legacy
 * compatibility fields and must never be used to authorize a CRUD operation.
 */

class PanelConflictException extends RuntimeException
{
}

class PanelNotFoundException extends RuntimeException
{
}

function panelNormalizeName(string $name): string
{
    $name = preg_replace('/\{emoji:[A-Za-z0-9_.-]+\}/u', ' ', $name);
    $name = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2069}\x{FEFF}]/u', '', (string) $name);
    $name = strtr((string) $name, [
        "\u{064A}" => "\u{06CC}",
        "\u{0649}" => "\u{06CC}",
        "\u{0643}" => "\u{06A9}",
    ]);

    if (class_exists('Normalizer')) {
        $normalized = Normalizer::normalize($name, Normalizer::FORM_C);
        if (is_string($normalized)) {
            $name = $normalized;
        }
    }

    $name = preg_replace('/[\p{Z}\s]+/u', ' ', $name);
    $name = trim((string) $name);

    return function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
}

function panelParseDisplayName(string $rawName, array $entities = []): array
{
    $emojiKey = null;
    if (preg_match('/\{emoji:([A-Za-z0-9_.-]+)\}/u', $rawName, $match)) {
        $emojiKey = $match[1];
    }

    $displayName = panelRemoveCustomEmojiEntities($rawName, $entities);
    $displayName = preg_replace('/\{emoji:[A-Za-z0-9_.-]+\}/u', ' ', $displayName);
    $displayName = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2069}\x{FEFF}]/u', '', (string) $displayName);
    $displayName = trim((string) preg_replace('/[\p{Z}\s]+/u', ' ', (string) $displayName));

    $customEmojiId = null;
    foreach ($entities as $entity) {
        if (($entity['type'] ?? '') !== 'custom_emoji') {
            continue;
        }
        $candidate = (string) ($entity['custom_emoji_id'] ?? '');
        if ($candidate !== '' && preg_match('/^[0-9]{1,64}$/', $candidate)) {
            $customEmojiId = $candidate;
            break;
        }
    }

    return [
        'raw_name' => $rawName,
        'display_name' => $displayName,
        'normalized_name' => panelNormalizeName($displayName),
        'emoji_key' => $emojiKey,
        'custom_emoji_id' => $customEmojiId,
    ];
}

function panelRemoveCustomEmojiEntities(string $text, array $entities): string
{
    $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($characters)) {
        return $text;
    }

    $unitToByte = [0 => 0];
    $utf16Units = 0;
    $byteOffset = 0;
    foreach ($characters as $character) {
        $first = ord($character[0]);
        if (($first & 0x80) === 0) {
            $codepoint = $first;
        } elseif (($first & 0xE0) === 0xC0) {
            $codepoint = (($first & 0x1F) << 6) | (ord($character[1]) & 0x3F);
        } elseif (($first & 0xF0) === 0xE0) {
            $codepoint = (($first & 0x0F) << 12)
                | ((ord($character[1]) & 0x3F) << 6)
                | (ord($character[2]) & 0x3F);
        } else {
            $codepoint = (($first & 0x07) << 18)
                | ((ord($character[1]) & 0x3F) << 12)
                | ((ord($character[2]) & 0x3F) << 6)
                | (ord($character[3]) & 0x3F);
        }

        $unitLength = $codepoint > 0xFFFF ? 2 : 1;
        $byteOffset += strlen($character);
        $utf16Units += $unitLength;
        $unitToByte[$utf16Units] = $byteOffset;
    }

    $ranges = [];
    foreach ($entities as $entity) {
        if (($entity['type'] ?? '') !== 'custom_emoji') {
            continue;
        }
        $start = filter_var($entity['offset'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $length = filter_var($entity['length'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($start === false || $length === false || !isset($unitToByte[$start], $unitToByte[$start + $length])) {
            continue;
        }
        $ranges[] = [
            'offset' => $unitToByte[$start],
            'length' => $unitToByte[$start + $length] - $unitToByte[$start],
        ];
    }

    usort($ranges, static fn(array $left, array $right): int => $right['offset'] <=> $left['offset']);
    foreach ($ranges as $range) {
        $text = substr_replace($text, '', $range['offset'], $range['length']);
    }

    return $text;
}

function panelDisplayName(array $panel): string
{
    $displayName = trim((string) ($panel['display_name'] ?? ''));
    if ($displayName !== '') {
        return $displayName;
    }

    return panelParseDisplayName((string) ($panel['name_panel'] ?? ''))['display_name'];
}

function panelCallbackData(string $action, int $panelId): string
{
    $allowedActions = ['select', 'edit', 'delete', 'confirm_delete'];
    if (!in_array($action, $allowedActions, true) || $panelId < 1) {
        throw new InvalidArgumentException('Invalid panel callback');
    }

    $callback = "panel:{$action}:{$panelId}";
    if (strlen($callback) > 64) {
        throw new LengthException('Telegram callback_data exceeds 64 bytes');
    }

    return $callback;
}

function panelParseCallbackData(string $callback): ?array
{
    if (!preg_match('/^panel:(select|edit|delete|confirm_delete):([1-9][0-9]*)$/D', $callback, $match)) {
        return null;
    }

    $panelId = filter_var($match[2], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($panelId === false) {
        return null;
    }

    return ['action' => $match[1], 'panel_id' => (int) $panelId];
}

function panelIdentitySchemaReady(PDO $pdo): bool
{
    static $readyByConnection = [];
    $key = spl_object_hash($pdo);
    if (array_key_exists($key, $readyByConnection)) {
        return $readyByConnection[$key];
    }

    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $columns = $pdo->query("PRAGMA table_info(marzban_panel)")->fetchAll(PDO::FETCH_ASSOC);
            $names = array_column($columns, 'name');
        } else {
            $columns = $pdo->query("SHOW COLUMNS FROM marzban_panel")->fetchAll(PDO::FETCH_ASSOC);
            $names = array_column($columns, 'Field');
        }
        $required = ['display_name', 'normalized_name', 'active_normalized_name', 'deleted_at'];
        $readyByConnection[$key] = count(array_diff($required, $names)) === 0;
    } catch (Throwable $e) {
        $readyByConnection[$key] = false;
    }

    return $readyByConnection[$key];
}

class PanelService
{
    private PDO $pdo;
    private $logger;

    public function __construct(PDO $pdo, ?callable $logger = null)
    {
        $this->pdo = $pdo;
        $this->logger = $logger ?: static function (string $message): void {
            error_log($message);
        };
    }

    public function listActive(): array
    {
        if (panelIdentitySchemaReady($this->pdo)) {
            return $this->pdo
                ->query("SELECT * FROM marzban_panel WHERE deleted_at IS NULL ORDER BY id")
                ->fetchAll(PDO::FETCH_ASSOC);
        }

        return $this->pdo->query("SELECT * FROM marzban_panel ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findActive(int $panelId, bool $forUpdate = false): ?array
    {
        if ($panelId < 1) {
            return null;
        }

        $where = 'id = :panel_id';
        if (panelIdentitySchemaReady($this->pdo)) {
            $where .= ' AND deleted_at IS NULL';
        }
        $lock = $forUpdate && $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite' ? ' FOR UPDATE' : '';
        $stmt = $this->pdo->prepare("SELECT * FROM marzban_panel WHERE {$where}{$lock}");
        $stmt->execute([':panel_id' => $panelId]);
        $panel = $stmt->fetch(PDO::FETCH_ASSOC);

        return $panel ?: null;
    }

    public function resolveSelection($selection): ?array
    {
        $selection = (string) $selection;
        if (ctype_digit($selection) && (int) $selection > 0) {
            return $this->findActive((int) $selection);
        }

        // Temporary read compatibility for sessions created before this
        // release. New sessions always store the canonical numeric id.
        $deletedFilter = panelIdentitySchemaReady($this->pdo) ? ' AND deleted_at IS NULL' : '';
        $stmt = $this->pdo->prepare(
            "SELECT * FROM marzban_panel
             WHERE (name_panel = :selection_name OR code_panel = :selection_code){$deletedFilter}
             ORDER BY id LIMIT 2"
        );
        $stmt->execute([':selection_name' => $selection, ':selection_code' => $selection]);
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return count($matches) === 1 ? $matches[0] : null;
    }

    public function normalizedNameExists(string $rawName, ?int $exceptPanelId = null): bool
    {
        $normalizedName = panelNormalizeName($rawName);
        if ($normalizedName === '') {
            return false;
        }

        if (panelIdentitySchemaReady($this->pdo)) {
            $sql = 'SELECT id FROM marzban_panel WHERE active_normalized_name = :normalized_name';
            $params = [':normalized_name' => $normalizedName];
            if ($exceptPanelId !== null) {
                $sql .= ' AND id <> :panel_id';
                $params[':panel_id'] = $exceptPanelId;
            }
            $sql .= ' LIMIT 1';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return (bool) $stmt->fetchColumn();
        }

        foreach ($this->listActive() as $panel) {
            if ($exceptPanelId !== null && (int) $panel['id'] === $exceptPanelId) {
                continue;
            }
            if (panelNormalizeName((string) $panel['name_panel']) === $normalizedName) {
                return true;
            }
        }

        return false;
    }

    public function identityColumns(string $rawName, array $entities = []): array
    {
        $identity = panelParseDisplayName($rawName, $entities);
        if ($identity['normalized_name'] === '') {
            throw new InvalidArgumentException('Panel name is empty after normalization');
        }
        if ($this->normalizedNameExists($identity['normalized_name'])) {
            throw new PanelConflictException('An active panel already uses this normalized name');
        }

        return [
            'name_panel' => $identity['raw_name'],
            'display_name' => $identity['display_name'],
            'normalized_name' => $identity['normalized_name'],
            'active_normalized_name' => $identity['normalized_name'],
            'emoji_key' => $identity['emoji_key'],
            'custom_emoji_id' => $identity['custom_emoji_id'],
        ];
    }

    public function rename(int $panelId, string $rawName, array $entities = []): array
    {
        $startedTransaction = !$this->pdo->inTransaction();
        if ($startedTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $panel = $this->findActive($panelId, true);
            if (!$panel) {
                throw new PanelNotFoundException('Panel is not active');
            }

            $identity = panelParseDisplayName($rawName, $entities);
            if ($identity['normalized_name'] === '') {
                throw new InvalidArgumentException('Panel name is empty after normalization');
            }
            if ($this->normalizedNameExists($identity['normalized_name'], $panelId)) {
                throw new PanelConflictException('An active panel already uses this normalized name');
            }

            $stmt = $this->pdo->prepare(
                'UPDATE marzban_panel
                 SET name_panel = :raw_name,
                     display_name = :display_name,
                     normalized_name = :normalized_name,
                     active_normalized_name = :active_normalized_name,
                     emoji_key = :emoji_key,
                     custom_emoji_id = :custom_emoji_id
                 WHERE id = :panel_id AND deleted_at IS NULL'
            );
            $stmt->execute([
                ':raw_name' => $identity['raw_name'],
                ':display_name' => $identity['display_name'],
                ':normalized_name' => $identity['normalized_name'],
                ':active_normalized_name' => $identity['normalized_name'],
                ':emoji_key' => $identity['emoji_key'],
                ':custom_emoji_id' => $identity['custom_emoji_id'],
                ':panel_id' => $panelId,
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('Panel rename did not update exactly one active row');
            }

            // Product names are operational data. Invoices/manual sales are
            // historical snapshots and intentionally keep their original name.
            if ($this->tableHasColumn('product', 'panel_id')) {
                $product = $this->pdo->prepare('UPDATE product SET Location = :name WHERE panel_id = :panel_id');
                $product->execute([':name' => $identity['raw_name'], ':panel_id' => $panelId]);
            }

            if ($startedTransaction) {
                $this->pdo->commit();
            }

            return $this->findActive($panelId) ?: [];
        } catch (Throwable $e) {
            if ($startedTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            ($this->logger)("panel rename failed for id {$panelId}: " . $e->getMessage());
            throw $e;
        }
    }

    public function updateField(int $panelId, string $field, $value): void
    {
        $allowed = [
            'url_panel', 'username_panel', 'password_panel', 'agent', 'linksubx',
            'secret_code', 'limit_panel', 'time_usertest', 'val_usertest',
            'inboundid', 'MethodUsername', 'Methodextend', 'datelogin',
            'changeloc', 'conecton', 'config', 'customvolume', 'hide_user',
            'inbound_deactive', 'inbounds', 'inboundstatus', 'maintime',
            'mainvolume', 'maxtime', 'maxvolume', 'namecustom', 'on_hold_test',
            'priceChangeloc', 'pricecustomtime', 'pricecustomvolume',
            'priceextratime', 'priceextravolume', 'proxies', 'status',
            'status_extend', 'sublink', 'subvip', 'TestAccount', 'version_panel',
        ];
        if (!in_array($field, $allowed, true)) {
            throw new InvalidArgumentException('Unsupported panel field');
        }
        if (!$this->findActive($panelId)) {
            throw new PanelNotFoundException('Panel is not active');
        }

        $stmt = $this->pdo->prepare(
            "UPDATE marzban_panel SET `{$field}` = :value WHERE id = :panel_id AND deleted_at IS NULL"
        );
        $stmt->execute([':value' => $value, ':panel_id' => $panelId]);
    }

    public function updateSelectedField($selection, string $field, $value): void
    {
        $panel = $this->resolveSelection($selection);
        if (!$panel) {
            throw new PanelNotFoundException('Panel selection is missing or ambiguous');
        }
        $this->updateField((int) $panel['id'], $field, $value);
    }

    public function softDelete(int $panelId): array
    {
        $startedTransaction = !$this->pdo->inTransaction();
        if ($startedTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $panel = $this->findActive($panelId, true);
            if (!$panel) {
                if ($startedTransaction) {
                    $this->pdo->commit();
                }
                return ['status' => 'already_deleted', 'panel_id' => $panelId];
            }

            $stmt = $this->pdo->prepare(
                "UPDATE marzban_panel
                 SET deleted_at = CURRENT_TIMESTAMP,
                     active_normalized_name = NULL,
                     status = 'deleted'
                 WHERE id = :panel_id AND deleted_at IS NULL"
            );
            $stmt->execute([':panel_id' => $panelId]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('Panel deletion did not update exactly one active row');
            }

            // Product rows are operational mappings, not financial history.
            // Move their legacy name out of the active namespace so a new
            // panel with the same display name cannot inherit old products.
            if ($this->tableHasColumn('product', 'panel_id')) {
                $detachProducts = $this->pdo->prepare(
                    "UPDATE product
                     SET panel_id = NULL, Location = :deleted_location
                     WHERE panel_id = :panel_id"
                );
                $detachProducts->execute([
                    ':deleted_location' => '@deleted-panel:' . $panelId,
                    ':panel_id' => $panelId,
                ]);
            }

            if ($startedTransaction) {
                $this->pdo->commit();
            }

            return ['status' => 'deleted', 'panel_id' => $panelId];
        } catch (Throwable $e) {
            if ($startedTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            ($this->logger)("panel deletion failed for id {$panelId}: " . $e->getMessage());
            throw $e;
        }
    }

    private function tableHasColumn(string $table, string $column): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table . $column)) {
            return false;
        }

        try {
            if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $columns = $this->pdo->query("PRAGMA table_info(`{$table}`)")->fetchAll(PDO::FETCH_ASSOC);
                return in_array($column, array_column($columns, 'name'), true);
            }
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name'
            );
            $stmt->execute([':table_name' => $table, ':column_name' => $column]);
            return (int) $stmt->fetchColumn() === 1;
        } catch (Throwable $e) {
            return false;
        }
    }
}
