<?php
// Disposable MySQL integration test: no Telegram, banking, or real VPN panel.
declare(strict_types=1);
function vpnappError($status, $message) {
    throw new RuntimeException($message, (int)$status);
}
require __DIR__.'/../api/vpnweb_identity.php';
$dsn = getenv('TEST_MYSQL_DSN') ?: 'mysql:host=127.0.0.1;dbname=vpnweb_test;charset=utf8mb4';
$db = new PDO($dsn, getenv('TEST_MYSQL_USER') ?: 'root',
    getenv('TEST_MYSQL_PASSWORD') ?: 'testpass',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]);
$db->exec('DROP TABLE IF EXISTS vpnweb_identities');
$db->exec('DROP TABLE IF EXISTS vpnapp_orders');
$db->exec('DROP TABLE IF EXISTS user');
$names = ['step','username','Processing_value','Processing_value_one',
    'Processing_value_tow','Processing_value_four','User_Status',
    'number','agent','message_count','last_message_time','affiliates',
    'affiliatescount','cardpayment','number_username','namecustom','register',
    'verify','codeInvitation','pricediscount','maxbuyagent','joinchannel',
    'status_cron'];
$columns = ["id VARCHAR(500) PRIMARY KEY", "limit_usertest INT NOT NULL",
    "roll_Status INT NOT NULL", "Balance INT NOT NULL",
    "pagenumber INT NOT NULL", "score INT NOT NULL"];
foreach ($names as $name) $columns[] = $name.' VARCHAR(500) NOT NULL';
$db->exec('CREATE TABLE user ('.implode(',', $columns).
    ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
$db->exec('CREATE TABLE vpnapp_orders (external_id VARCHAR(64) PRIMARY KEY,
    owner_id BIGINT NOT NULL, kind VARCHAR(16) NOT NULL,
    status VARCHAR(24) NOT NULL) ENGINE=InnoDB');
$db->exec("INSERT INTO vpnapp_orders VALUES ('existing-order',123456789,'card','waiting')");
$db->exec('ALTER TABLE vpnapp_orders MODIFY owner_id VARCHAR(64) NOT NULL');
$db->exec('CREATE TABLE vpnweb_identities (
    app_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    commercial_id VARCHAR(64) NOT NULL UNIQUE,
    created_at BIGINT NOT NULL,
    FOREIGN KEY (commercial_id) REFERENCES user(id))
    ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
putenv('MIRZA_VPNWEB_IDENTITY_ENABLED=1');
$id = str_repeat('a',32);
$first = vpnwebCreateIdentity($db, $id);
$second = vpnwebCreateIdentity($db, $id);
if ($first['commercial_id'] !== 'web_'.$id || $first['created'] !== true ||
    $second['created'] !== false) throw new RuntimeException('Not idempotent');
if (vpnwebResolveIdentity($db, $first['commercial_id']) !== $first['commercial_id'])
    throw new RuntimeException('Mapping mismatch');
if ((int)$db->query("SELECT COUNT(*) FROM user")->fetchColumn() !== 1)
    throw new RuntimeException('Duplicate user');
if ((int)$db->query("SELECT COUNT(*) FROM vpnweb_identities")->fetchColumn() !== 1)
    throw new RuntimeException('Duplicate identity');
if ((int)$db->query("SELECT Balance FROM user WHERE id='web_".$id."'")->fetchColumn() !== 0)
    throw new RuntimeException('Unexpected wallet state');
if ((string)$db->query("SELECT owner_id FROM vpnapp_orders WHERE external_id='existing-order'")->fetchColumn() !== '123456789')
    throw new RuntimeException('Legacy owner altered');
try {
    vpnwebResolveIdentity($db, 'web_'.str_repeat('b',32));
    throw new LogicException('Unexpected identity accepted');
} catch (RuntimeException $e) {
    if ($e->getCode() !== 403) throw $e;
}
try {
    vpnwebCreateIdentity($db, '../bad');
    throw new LogicException('Malformed ID accepted');
} catch (RuntimeException $e) {
    if ($e->getCode() !== 400) throw $e;
}
putenv('MIRZA_VPNWEB_IDENTITY_ENABLED=0');
try {
    vpnwebCreateIdentity($db, str_repeat('b',32));
    throw new LogicException('Disabled registration succeeded');
} catch (RuntimeException $e) {
    if ($e->getCode() !== 503) throw $e;
}
echo "PASS: web identity, unchanged wallet/legacy owner and fail-closed validation\n";
