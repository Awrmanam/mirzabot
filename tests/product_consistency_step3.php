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

class ProductConsistencyStore
{
    public $rows = [], $categories = [], $panels = [], $failDelete = false;

    public function customerRows($location, $agent, $categoryMode = true)
    {
        $candidates = array_values(array_filter($this->rows, function ($row) use ($location, $agent, $categoryMode) {
            $validLocation = $row['Location'] === '/all' || isset($this->panels[$row['Location']]);
            $validCategory = !$categoryMode || isset($this->categories[$row['category']]);
            return ($row['Location'] === $location || $row['Location'] === '/all')
                && $row['agent'] === $agent && $validLocation && $validCategory
                && $row['code_product'] !== '';
        }));
        return $candidates;
    }

    public function selectCustomerProduct($code, $location, $agent)
    {
        $matches = array_values(array_filter($this->rows, function ($row) use ($code, $location, $agent) {
            return $row['code_product'] === $code && $row['agent'] === $agent
                && ($row['Location'] === $location || $row['Location'] === '/all');
        }));
        return count($matches) === 1 ? $matches[0] : false;
    }

    public function selectCustomerProductId($id, $location, $agent)
    {
        $row = $this->rows[$id] ?? null;
        return $row && $row['agent'] === $agent
            && ($row['Location'] === $location || $row['Location'] === '/all') ? $row : false;
    }

    public function editRows($location, $agent)
    {
        return array_values(array_filter($this->rows, function ($row) use ($location, $agent) {
            return $row['agent'] === $agent
                && ($row['Location'] === $location || $row['Location'] === '/all');
        }));
    }

    public function deleteRows()
    {
        return array_values($this->rows);
    }

    public function deleteById($id)
    {
        if ($this->failDelete || !isset($this->rows[$id])) {
            return false;
        }
        unset($this->rows[$id]);
        return true;
    }
}

$store = new ProductConsistencyStore();
$store->categories = ['Normal category' => true, '{emoji:diamond}Premium category' => true];
$store->panels = ['Normal panel' => true, '{emoji:diamond}Promax' => true];
$store->rows = [
    1 => ['id' => 1, 'name_product' => 'Normal', 'code_product' => 'normal1', 'category' => 'Normal category', 'Location' => 'Normal panel', 'agent' => 'f'],
    2 => ['id' => 2, 'name_product' => '{emoji:diamond}Premium', 'code_product' => 'premium2', 'category' => '{emoji:diamond}Premium category', 'Location' => '{emoji:diamond}Promax', 'agent' => 'f'],
    3 => ['id' => 3, 'name_product' => 'All panels', 'code_product' => 'all3', 'category' => 'Normal category', 'Location' => '/all', 'agent' => 'f'],
    4 => ['id' => 4, 'name_product' => 'Orphan', 'code_product' => 'orphan4', 'category' => 'Missing category', 'Location' => 'Missing panel', 'agent' => 'f'],
    5 => ['id' => 5, 'name_product' => 'Duplicate A', 'code_product' => 'duplicate', 'category' => 'Normal category', 'Location' => 'Normal panel', 'agent' => 'f'],
    6 => ['id' => 6, 'name_product' => 'Duplicate B', 'code_product' => 'duplicate', 'category' => 'Normal category', 'Location' => '/all', 'agent' => 'f'],
];

$normalRows = $store->customerRows('Normal panel', 'f');
$normalIds = array_column($normalRows, 'id');
$check(in_array(1, $normalIds, true)
    && $store->selectCustomerProductId(1, 'Normal panel', 'f')['id'] === 1,
    'active normal product appears and is selectable');

$premiumRows = $store->customerRows('{emoji:diamond}Promax', 'f');
$premium = array_values(array_filter($premiumRows, fn($row) => $row['id'] === 2));
$check(count($premium) === 1
    && $store->selectCustomerProductId(2, '{emoji:diamond}Promax', 'f')['name_product'] === '{emoji:diamond}Premium',
    'Premium Emoji visible button resolves to the raw stored product');

$check(!in_array(4, $normalIds, true), 'orphaned product is absent from customer list');
$check(!array_filter($normalRows, fn($row) => $store->selectCustomerProductId($row['id'], 'Normal panel', 'f') === false),
    'every customer-visible product resolves uniquely by stable ID');

$editIds = array_column($store->editRows('Normal panel', 'f'), 'id');
$deleteIds = array_column($store->deleteRows(), 'id');
$check(!array_diff($editIds, $deleteIds), 'every editable product is present in delete list');
$check(in_array(4, $deleteIds, true), 'legacy orphan appears in admin cleanup/delete list');
$check(in_array(5, $normalIds, true) && in_array(6, $normalIds, true)
    && $store->selectCustomerProductId(5, 'Normal panel', 'f')['id'] === 5
    && $store->selectCustomerProductId(6, 'Normal panel', 'f')['id'] === 6
    && $store->selectCustomerProduct('duplicate', 'Normal panel', 'f') === false,
    'stable IDs select duplicates while ambiguous legacy codes are rejected');
$check(in_array(3, $normalIds, true), '/all product remains customer-visible');
$check(in_array(1, $normalIds, true) && in_array(2, array_column($premiumRows, 'id'), true),
    'normal and Premium Emoji categories both remain valid');

$snapshot = $store->rows;
$check($store->deleteById(1)
    && !in_array(1, array_column($store->customerRows('Normal panel', 'f'), 'id'), true)
    && !in_array(1, array_column($store->editRows('Normal panel', 'f'), 'id'), true)
    && !in_array(1, array_column($store->deleteRows(), 'id'), true),
    'successful stable-ID deletion removes the row from all lists');
$check(isset($store->rows[2]) && isset($store->rows[3]), 'successful deletion changes no unrelated rows');

$store->failDelete = true;
$beforeFailure = $store->rows;
$check(!$store->deleteById(2) && $store->rows === $beforeFailure,
    'failed deletion cannot report success or change data');
$store->failDelete = false;

$adminSource = file_get_contents($root . DIRECTORY_SEPARATOR . 'admin.php');
$keyboardSource = file_get_contents($root . DIRECTORY_SEPARATOR . 'keyboard.php');
$indexSource = file_get_contents($root . DIRECTORY_SEPARATOR . 'index.php');
$deleteStart = strpos($adminSource, '$text == "❌ حذف محصول"');
$deleteEnd = strpos($adminSource, '$text == "✏️ ویرایش محصول"', $deleteStart);
$deleteHandler = substr($adminSource, $deleteStart, $deleteEnd - $deleteStart);
$selectionStart = strpos($indexSource, '$parts = explode("_", $loc);');
$selectionEnd = strpos($indexSource, 'if (!isset($info_product[\'price_product\']))', $selectionStart);
$selectionHandler = substr($indexSource, $selectionStart, $selectionEnd - $selectionStart);

$check(strpos($deleteHandler, "callback_data' => 'removeproduct_'") !== false
    && strpos($deleteHandler, 'DELETE FROM product WHERE id = :id') !== false
    && strpos($deleteHandler, 'rowCount() === 1') !== false,
    'production deletion lists and deletes by stable ID with affected-row verification');
$check(strpos($keyboardSource, "'pid-' . \$result['id']") !== false
    && strpos($selectionHandler, 'ambiguous_product_code') !== false
    && strpos($selectionHandler, 'fetchAll(PDO::FETCH_ASSOC)') !== false
    && strpos($selectionHandler, 'LIMIT 1') === false,
    'production customer buttons use stable IDs and legacy codes reject ambiguity without LIMIT 1');

echo "[SUMMARY] passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
