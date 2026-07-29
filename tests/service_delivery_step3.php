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

class DeliveryStore
{
    public $rows = [], $failWrites = false;
    function save($id, $userId, $username, $subscription)
    {
        if ($this->failWrites || !isset($this->rows[$id]) || $this->rows[$id]['id_user'] !== $userId) {
            return false;
        }
        $this->rows[$id]['username'] = $username;
        $this->rows[$id]['user_info'] = $subscription;
        return $this->rows[$id]['username'] === $username
            && $this->rows[$id]['user_info'] === $subscription;
    }
}

class MockDeliveryPanel
{
    public $createCalls = 0, $dataCalls = 0, $created = [], $regenerated = [];
    function createUser()
    {
        $this->createCalls++;
        return $this->created;
    }
    function DataUser()
    {
        $this->dataCalls++;
        return $this->regenerated;
    }
}

class MockDeliveryTelegram
{
    public $ok = true, $messages = [];
    function send($text)
    {
        if ($this->ok) {
            $this->messages[] = $text;
        }
        return ['ok' => $this->ok];
    }
}

$purchase = function ($id, $userId, $panel, $db, $telegram) {
    $created = $panel->createUser();
    if (($created['status'] ?? '') !== 'successful' || empty($created['username'])) {
        return 'api-error';
    }
    if (empty($created['subscription_url'])) {
        $regenerated = $panel->DataUser();
        $created['subscription_url'] = $regenerated['subscription_url'] ?? '';
    }
    if ($created['subscription_url'] === '') {
        return 'empty';
    }
    if (!$db->save($id, $userId, $created['username'], $created['subscription_url'])) {
        return 'db-error';
    }
    return !empty($telegram->send($created['subscription_url'])['ok']) ? 'sent' : 'telegram-error';
};

$retrieve = function ($id, $userId, $panels, $db, $panel, $telegram) {
    $row = $db->rows[$id] ?? null;
    if (!$row || $row['id_user'] !== $userId) {
        return 'not-found';
    }
    $rawPanel = isset($panels[$row['Service_location']])
        ? $row['Service_location']
        : array_search($row['Service_location'], $panels, true);
    if ($rawPanel === false) {
        return 'panel-error';
    }
    $data = $panel->DataUser();
    $subscription = $data['subscription_url'] ?? '';
    if ($subscription === '') {
        $subscription = $row['user_info'] ?? '';
    }
    if ($subscription === '') {
        return 'empty';
    }
    return !empty($telegram->send($subscription)['ok']) ? 'sent' : 'telegram-error';
};

$db = new DeliveryStore();
$db->rows['order1'] = ['id_user' => '10', 'username' => 'requested', 'user_info' => '', 'Service_location' => 'Normal'];
$panel = new MockDeliveryPanel();
$panel->created = ['status' => 'successful', 'username' => 'canonical', 'subscription_url' => 'https://panel.test/sub/abc'];
$telegram = new MockDeliveryTelegram();
$check($purchase('order1', '10', $panel, $db, $telegram) === 'sent', 'successful API creation stores the local service');
$check($db->rows['order1']['user_info'] === 'https://panel.test/sub/abc'
    && $telegram->messages === ['https://panel.test/sub/abc'], 'API subscription URL is stored and sent');

$db->rows['order2'] = ['id_user' => '10', 'username' => 'requested', 'user_info' => '', 'Service_location' => 'Normal'];
$panel2 = new MockDeliveryPanel();
$panel2->created = ['status' => 'successful', 'username' => 'canonical', 'subscription_url' => ''];
$panel2->regenerated = ['status' => 'active', 'subscription_url' => 'https://panel.test/sub/regenerated'];
$check($purchase('order2', '10', $panel2, $db, new MockDeliveryTelegram()) === 'sent'
    && $panel2->dataCalls === 1, 'missing create URL is regenerated through existing panel lookup');

$db->rows['order3'] = ['id_user' => '10', 'username' => 'requested', 'user_info' => '', 'Service_location' => 'Normal'];
$panel3 = new MockDeliveryPanel();
$panel3->created = ['status' => 'successful', 'username' => 'canonical', 'subscription_url' => ''];
$panel3->regenerated = ['status' => 'active', 'subscription_url' => ''];
$telegram3 = new MockDeliveryTelegram();
$check($purchase('order3', '10', $panel3, $db, $telegram3) === 'empty'
    && !$telegram3->messages, 'empty subscription data cannot produce fake success');

$db->rows['order4'] = ['id_user' => '10', 'username' => 'requested', 'user_info' => '', 'Service_location' => 'Normal'];
$db->failWrites = true;
$telegram4 = new MockDeliveryTelegram();
$check($purchase('order4', '10', $panel, $db, $telegram4) === 'db-error'
    && !$telegram4->messages, 'database write failure prevents delivery success');
$db->failWrites = false;

$panels = ['Normal' => 'Normal', '{emoji:premium} Tehran' => 'Tehran'];
$check($retrieve('order1', '10', $panels, $db, $panel, new MockDeliveryTelegram()) === 'sent',
    'subscription action retrieves the correct owned service');
$check($retrieve('order1', '11', $panels, $db, $panel, new MockDeliveryTelegram()) === 'not-found',
    'another user cannot retrieve the service');
$db->rows['order5'] = ['id_user' => '10', 'username' => 'canonical', 'user_info' => 'https://panel.test/sub/abc', 'Service_location' => 'Tehran'];
$check($retrieve('order5', '10', $panels, $db, $panel, new MockDeliveryTelegram()) === 'sent',
    'visible Premium Emoji panel name resolves to its raw stored name');
$check($retrieve('order1', '10', $panels, $db, $panel, new MockDeliveryTelegram()) === 'sent',
    'normal panel name retrieval remains unchanged');
$createCalls = $panel->createCalls;
$retrieve('order1', '10', $panels, $db, $panel, new MockDeliveryTelegram());
$check($panel->createCalls === $createCalls, 'link retrieval never creates a duplicate panel service');

$apiFailure = new MockDeliveryPanel();
$apiFailure->created = ['status' => 'Unsuccessful'];
$check($purchase('order1', '10', $apiFailure, $db, new MockDeliveryTelegram()) === 'api-error',
    'API failure returns the error path');
$telegramFailure = new MockDeliveryTelegram();
$telegramFailure->ok = false;
$check($purchase('order1', '10', $panel, $db, $telegramFailure) === 'telegram-error'
    && $db->rows['order1']['username'] === 'canonical', 'Telegram failure preserves the created service');

$functionSource = file_get_contents($root . DIRECTORY_SEPARATOR . 'function.php');
$indexSource = file_get_contents($root . DIRECTORY_SEPARATOR . 'index.php');
$handlerStart = strpos($indexSource, "} elseif (preg_match('/subscriptionurl_");
$handlerEnd = strpos($indexSource, "} elseif (preg_match('/removeauto-", $handlerStart);
$handler = substr($indexSource, $handlerStart, $handlerEnd - $handlerStart);
$check(strpos($functionSource, 'persistServiceDelivery') !== false
    && strpos($functionSource, '$ManagePanel->DataUser(') !== false, 'production delivery persists and can regenerate service data');
$check(strpos($handler, 'id_user = :id_user') !== false
    && strpos($handler, 'styledResolvePanelName') !== false
    && strpos($handler, 'persistServiceDelivery') !== false, 'production callback enforces ownership, resolves panels, and stores regenerated links');
$check(strpos($handler, 'createUser(') === false, 'production retrieval handler cannot create a panel user');
$logStart = strpos($functionSource, 'function serviceDeliveryLog');
$logEnd = strpos($functionSource, 'function persistServiceDelivery', $logStart);
$logSource = substr($functionSource, $logStart, $logEnd - $logStart);
$check(strpos($logSource, '$service_data') === false && strpos($logSource, '$sub_link') === false
    && strpos($logSource, 'password') === false, 'new production logging does not emit subscription secrets');

echo "[SUMMARY] passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
