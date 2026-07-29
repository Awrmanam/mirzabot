<?php
$root = dirname(__DIR__);
$passed = $failed = 0;
$check = function ($condition, $label) use (&$passed, &$failed) {
    if ($condition) {
        $passed++;
        echo "[PASS] {$label}\n";
    } else {
        $failed++;
        echo "[FAIL] {$label}\n";
    }
};
class Step3Statement
{
    private $db, $sql;
    private $rows = [], $affected = 0;
    public function __construct($db, $sql, $rows = [])
    {
        $this->db = $db; $this->sql = $sql; $this->rows = $rows;
    }
    public function execute($params = [])
    {
        if (strpos($this->sql, 'DELETE FROM marzban_panel') !== false) {
            $name = (string) ($params[0] ?? '');
            $this->affected = isset($this->db->panels[$name]) ? 1 : 0;
            unset($this->db->panels[$name]);
        } elseif (strpos($this->sql, 'WHERE name_panel = ?') !== false) {
            $name = (string) ($params[0] ?? '');
            $this->rows = isset($this->db->panels[$name]) ? [['name_panel' => $name]] : [];
        }
        return true;
    }
    public function fetchColumn()
    {
        if (strpos($this->sql, "SHOW TABLES LIKE 'styled_emojis'") !== false) {
            return 'styled_emojis';
        }
        return $this->rows ? reset($this->rows[0]) : false;
    }
    public function fetch($mode = null) { return $this->rows ? $this->rows[0] : false; }
    public function fetchAll($mode = null) { return $this->rows; }
    public function rowCount() { return $this->affected; }
}
class Step3Database
{
    public $panels = [], $emojiSelects = 0;
    public function prepare($sql)
    {
        $rows = [];
        if (strpos($sql, 'SELECT name_panel FROM marzban_panel') !== false
            && strpos($sql, 'WHERE name_panel = ?') === false)
            foreach (array_keys($this->panels) as $name) {
                $rows[] = ['name_panel' => $name];
            }
        return new Step3Statement($this, $sql, $rows);
    }
    public function query($sql)
    {
        if (strpos($sql, "SHOW TABLES LIKE 'styled_emojis'") !== false) {
            return new Step3Statement($this, $sql);
        }
        if (strpos($sql, 'FROM styled_settings') !== false) {
            return new Step3Statement($this, $sql,
                [['setting_key' => 'usage_tracking', 'setting_value' => '0']]);
        }
        if (strpos($sql, 'FROM styled_emojis') !== false) {
            $this->emojiSelects++;
            return new Step3Statement($this, $sql, [[
                'key' => 'premium', 'fallback_emoji' => '💎',
                'custom_emoji_id' => '123456789', 'is_active' => 1, 'is_valid' => 1,
            ]]);
        }
        return new Step3Statement($this, $sql);
    }
}
$pdo = new Step3Database(); require $root . DIRECTORY_SEPARATOR . 'emoji_system.php';
$pdo->panels = ['Normal' => true, '🌟 Unicode' => true];
$check(styledResolvePanelName('Normal') === 'Normal', 'normal panel name resolves');
$check(styledResolvePanelName('🌟 Unicode') === '🌟 Unicode', 'ordinary Unicode emoji is unchanged');
$pdo->panels = ['{emoji:premium} Tehran' => true];
$check(styledResolvePanelName('Tehran') === '{emoji:premium} Tehran', 'visible premium panel resolves to stored name');
$resolved = styledResolvePanelName('Tehran');
$pdo->panels[$resolved . ' edited'] = $pdo->panels[$resolved];
unset($pdo->panels[$resolved]);
$check(isset($pdo->panels['{emoji:premium} Tehran edited']),
    'premium panel edit targets stored name');
$pdo->panels = ['Tehran' => true, '{emoji:premium} Tehran' => true];
$check(styledResolvePanelName('Tehran') === 'Tehran', 'exact raw match has priority');
$check(styledPanelNameExists('{emoji:premium} Tehran'),
    'real displayed duplicate is rejected');
$pdo->panels = ['{emoji:premium} Tehran' => true];
$check(styledDeletePanelByName('{emoji:premium} Tehran'),
    'premium panel deletion reports success');
$check(!styledPanelNameExists('{emoji:premium} Tehran'),
    'same name can be recreated after real deletion');
$pdo->panels['{emoji:premium} Tehran'] = true;
$check(styledPanelNameExists('Tehran'), 'visible form of real duplicate is rejected');
$check(!styledDeletePanelByName('missing'), 'failed DELETE does not report success');
$emojiSelects = $pdo->emojiSelects;
$check(styledPanelDisplayName('🌟 Plain') === '🌟 Plain'
    && $pdo->emojiSelects === $emojiSelects, 'tokenless panel name skips emoji lookup');
$check($pdo->emojiSelects <= 1, 'tested path reuses the bounded emoji cache');
$source = file_get_contents($root . DIRECTORY_SEPARATOR . 'emoji_system.php');
$start = strpos($source, 'function styledPrepareButton');
$end = strpos($source, 'function styledPrepareReplyMarkup', $start);
$buttonSource = substr($source, $start, $end - $start);
$check(substr_count($buttonSource, '\\{emoji:') === 1, 'premium button token is parsed once');
$admin = file_get_contents($root . DIRECTORY_SEPARATOR . 'admin.php');
$check(strpos($admin, 'styledResolvePanelName($text)') !== false
    && strpos($admin, 'styledDeletePanelByName(') !== false,
    'panel management uses resolver and checked delete');
echo "[METRIC] emoji_selects_panel_path={$pdo->emojiSelects}\n";
echo "[METRIC] premium_button_token_regexes=" . substr_count($buttonSource, '\\{emoji:') . "\n";
echo "[SUMMARY] passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
