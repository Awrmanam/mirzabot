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

class ProductLocationStatement
{
    private $db;
    private $sql;
    private $rows = [];

    public function __construct($db, $sql)
    {
        $this->db = $db;
        $this->sql = $sql;
    }

    public function execute($params = [])
    {
        $source = strpos($this->sql, 'FROM category') !== false
            ? $this->db->categories
            : $this->db->panels;
        if (strpos($this->sql, 'FROM category') !== false) {
            $this->db->categorySelects++;
            $column = 'remark';
        } elseif (strpos($this->sql, 'FROM marzban_panel') !== false) {
            $this->db->panelSelects++;
            $column = 'name_panel';
        } else {
            return true;
        }
        if (strpos($this->sql, 'WHERE ' . $column . ' = ?') !== false) {
            $name = (string) ($params[0] ?? '');
            $this->rows = isset($source[$name]) ? [[$column => $name]] : [];
        } else {
            foreach (array_keys($source) as $name) {
                $this->rows[] = [$column => $name];
            }
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

    public function fetchAll($mode = null)
    {
        return $this->rows;
    }
}

class ProductLocationDatabase
{
    public $panels = [];
    public $categories = [];
    public $panelSelects = 0;
    public $categorySelects = 0;
    public $emojiSelects = 0;

    public function prepare($sql)
    {
        return new ProductLocationStatement($this, $sql);
    }

    public function query($sql)
    {
        $stmt = new ProductLocationStatement($this, $sql);
        if (strpos($sql, 'FROM styled_settings') !== false) {
            return new class {
                public function fetchAll($mode = null)
                {
                    return [['setting_key' => 'usage_tracking', 'setting_value' => '0']];
                }
            };
        }
        if (strpos($sql, 'FROM styled_emojis') !== false) {
            $this->emojiSelects++;
            return new class {
                public function fetchAll($mode = null)
                {
                    return [[
                        'key' => 'premium',
                        'fallback_emoji' => '💎',
                        'custom_emoji_id' => '123456789',
                        'is_active' => 1,
                        'is_valid' => 1,
                    ]];
                }
            };
        }
        return $stmt;
    }
}

$pdo = new ProductLocationDatabase();
require $root . DIRECTORY_SEPARATOR . 'emoji_system.php';

$selectLocation = function ($incoming) {
    if ($incoming === '/all') {
        return '/all';
    }
    return styledResolvePanelName($incoming);
};

$pdo->panels = ['Normal' => true, '{emoji:premium} Promax' => true];
$pdo->categories = ['Category' => true, '🌟 Unicode' => true, '{emoji:premium} Gold' => true];
$check($selectLocation('Normal') === 'Normal', 'raw normal panel is accepted');
$check(
    $selectLocation('Promax') === '{emoji:premium} Promax',
    'visible Premium Emoji panel resolves to raw location'
);
$check($selectLocation('invalid') === false, 'invalid panel text is rejected');
$check($selectLocation('Category') === false, 'category name is not accepted as panel');

$panelSelects = $pdo->panelSelects;
$emojiSelects = $pdo->emojiSelects;
$check(
    $selectLocation('/all') === '/all'
        && $pdo->panelSelects === $panelSelects
        && $pdo->emojiSelects === $emojiSelects,
    '/all bypasses panel and emoji lookup'
);

$check(styledResolveCategoryName('Category') === 'Category', 'normal category resolves');
$check(styledResolveCategoryName('🌟 Unicode') === '🌟 Unicode', 'ordinary Unicode category is unchanged');
$check(
    styledResolveCategoryName('Gold') === '{emoji:premium} Gold',
    'visible Premium Emoji category resolves to raw remark'
);
$pdo->categories = ['Gold' => true, '{emoji:premium} Gold' => true];
$check(styledResolveCategoryName('Gold') === 'Gold', 'exact raw category has priority');
$check(styledResolveCategoryName('invalid') === false, 'invalid category is rejected');
$check(styledResolveCategoryName('Normal') === false, 'panel name is not accepted as category');

$admin = file_get_contents($root . DIRECTORY_SEPARATOR . 'admin.php');
$locationStart = strpos($admin, '} elseif ($user[\'step\'] == "get_location")');
$categoryStart = strpos($admin, '} elseif ($user[\'step\'] == "getcategory")');
$locationHandler = substr($admin, $locationStart, $categoryStart - $locationStart);
$categoryEnd = strpos($admin, '} elseif ($user[\'step\'] == "get_time")', $categoryStart);
$categoryHandler = substr($admin, $categoryStart, $categoryEnd - $categoryStart);
$check(
    strpos($locationHandler, 'if ($text !== \'/all\')') !== false
        && strpos($locationHandler, '$text = $resolvedPanelName;') !== false
        && strpos($locationHandler, 'savedata("save", "Location", $text)') !== false,
    'resolved raw panel is passed into existing product flow'
);
$check(
    strpos($locationHandler, 'step("getcategory", $from_id)') !== false
        && strpos($categoryHandler, '$text = $resolvedCategoryName;') !== false
        && strpos($categoryHandler, 'savedata("save", "category", $text)') !== false,
    '/all reaches category selection and raw category continues through product flow'
);

echo "[METRIC] panel_selects={$pdo->panelSelects}\n";
echo "[METRIC] category_selects={$pdo->categorySelects}\n";
echo "[METRIC] emoji_selects={$pdo->emojiSelects}\n";
echo "[SUMMARY] passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
