<?php
/** Real PHP checkout functions + disposable MySQL + mocked panel adapter.
 * No bank, Telegram, real VPN panel, or user data.
 */
declare(strict_types=1);
define('MIRZA_VPNAPP_TEST_LIB', true);
define('MIRZA_VPNAPP_LIBRARY_ONLY', true);
require __DIR__.'/../api/vpnapp.php';
function generateUsername($fromId,$method,$username,$suffix,$text,$name,$custom) {
    return 'webtest_'.substr(hash('sha256', $fromId.$suffix),0,12);
}
class ManagePanel {
    public static int $createCount=0;
    public static int $renewCount=0;
    public static bool $timeoutOnCreate=false;
    public static bool $timeoutOnRenew=false;
    public function createUser($panel,$plan,$username,$options) {
        self::$createCount++;
        // Mock an ambiguous outcome AFTER the remote panel may have succeeded.
        if (self::$timeoutOnCreate) throw new RuntimeException('simulated timeout after create');
        return ['username'=>$username,'subscription_url'=>'https://staging.example.invalid/sub/test'];
    }
    public function extend($method,$volume,$days,$username,$plan,$panelCode) {
        self::$renewCount++;
        if (self::$timeoutOnRenew) throw new RuntimeException('simulated timeout after extend');
        return ['status'=>'success'];
    }
}

$db = new PDO(getenv('TEST_MYSQL_DSN')?:'mysql:host=127.0.0.1;dbname=vpnweb_test;charset=utf8mb4',
    getenv('TEST_MYSQL_USER')?:'root',getenv('TEST_MYSQL_PASSWORD')?:'testpass',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach (['vpnweb_identities','vpnapp_orders','invoice','product','marzban_panel','user'] as $table)
    $db->exec('DROP TABLE IF EXISTS '.$table);
$names = ['step','username','Processing_value','Processing_value_one','Processing_value_tow',
 'Processing_value_four','User_Status','number','agent','message_count','last_message_time',
 'affiliates','affiliatescount','cardpayment','number_username','namecustom','register',
 'verify','codeInvitation','pricediscount','maxbuyagent','joinchannel','status_cron'];
$columns = ['id VARCHAR(500) PRIMARY KEY', 'limit_usertest INT NOT NULL',
 'roll_Status INT NOT NULL', 'Balance INT NOT NULL','pagenumber INT NOT NULL',
 'score INT NOT NULL'];
foreach ($names as $name) $columns[]=$name.' VARCHAR(500) NOT NULL';
$db->exec('CREATE TABLE user ('.implode(',',$columns).
 ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
$db->exec('CREATE TABLE vpnapp_orders(
 external_id VARCHAR(64) PRIMARY KEY, owner_id VARCHAR(64) NOT NULL,
 kind VARCHAR(16) NOT NULL, status VARCHAR(24) NOT NULL,
 payment_id VARCHAR(32), invoice_id VARCHAR(32), result_json TEXT,
 created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL,
 INDEX idx_owner(owner_id,created_at)) ENGINE=InnoDB');
$db->exec('CREATE TABLE vpnweb_identities(
 app_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 commercial_id VARCHAR(64) NOT NULL UNIQUE,created_at BIGINT NOT NULL,
 FOREIGN KEY (commercial_id) REFERENCES user(id))
 ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
$db->exec('CREATE TABLE marzban_panel(
 code_panel VARCHAR(64) PRIMARY KEY,name_panel VARCHAR(200),
 status VARCHAR(50),type VARCHAR(50),MethodUsername VARCHAR(100),
 namecustom VARCHAR(100),Methodextend VARCHAR(100)) ENGINE=InnoDB');
$db->exec('CREATE TABLE product(
 code_product VARCHAR(128) PRIMARY KEY,agent VARCHAR(50),
 Location VARCHAR(100),name_product VARCHAR(100),
 price_product INT,Volume_constraint FLOAT,Service_time INT,
 hide_panel TEXT,one_buy_status VARCHAR(10)) ENGINE=InnoDB');
$db->exec('CREATE TABLE invoice(
 id_invoice VARCHAR(200) PRIMARY KEY,id_user VARCHAR(200),
 username VARCHAR(300),time_sell BIGINT,Service_location VARCHAR(200),
 name_product VARCHAR(200),price_product INT,Volume FLOAT,
 Service_time INT,Status VARCHAR(30),note TEXT,refral VARCHAR(100),
 notifctions TEXT) ENGINE=InnoDB');
$db->exec("INSERT INTO marzban_panel VALUES(
 'fixture_panel','Fixture Panel','active','rebecca','random','none','extend')");
$db->exec("INSERT INTO product VALUES(
 'fixture_plan','f','/all','TEST ONLY ProMax',100000,40,30,'[]','')");
putenv('MIRZA_VPNWEB_IDENTITY_ENABLED=1');
putenv('MIRZA_VPNAPP_CHECKOUT_ENABLED=1');
$appId=str_repeat('c',32);
$identity=vpnwebCreateIdentity($db,$appId);
$commercial=$identity['commercial_id'];
$db->prepare("UPDATE user SET Balance=400000 WHERE id=?")->execute([$commercial]);
$u=vpnappUser($db,$commercial);
$panel=vpnappPanel($db,'fixture_panel');
$plan=vpnappProduct($db,$u['agent'],$panel,'fixture_plan');
$key='11111111-1111-4111-8111-111111111111';
$checkout=function($key)use($db,$u,$panel,$plan) {
  $previous=vpnappExisting($db,$u,$key);
  if ($previous) return json_decode($previous['result_json'],true);
  vpnappNewOrder($db,$u,'wallet',$key);
  return vpnappWalletPurchase($db,$u,$panel,$plan,$key);
};
$a=$checkout($key);
$b=$checkout($key);
$c=$checkout($key);
if (($a['status']??'')!=='fulfilled'||$a!==$b||$a!==$c)
    throw new RuntimeException('Checkout replay was not stable');
if (ManagePanel::$createCount!==1)
    throw new RuntimeException('Duplicate panel createUser');
$balance=(int)$db->query("SELECT Balance FROM user WHERE id='".$commercial."'")->fetchColumn();
if ($balance!==300000)
    throw new RuntimeException('Duplicate wallet debit');
$service=$a['service'];
$renewKey='22222222-2222-4222-8222-222222222222';
$renew=function($key)use($db,$u,$service) {
  $previous=vpnappExisting($db,$u,$key);
  if ($previous) return json_decode($previous['result_json'],true);
  return vpnappRenewWallet($db,$u,$key,$service,'fixture_panel','fixture_plan');
};
$a=$renew($renewKey);
$b=$renew($renewKey);
if (($a['status']??'')!=='fulfilled'||$a!==$b)
    throw new RuntimeException('Renew replay was not stable');
if (ManagePanel::$renewCount!==1)
    throw new RuntimeException('Duplicate panel extension');
if ((int)$db->query("SELECT Balance FROM user WHERE id='".$commercial."'")->fetchColumn()!==200000)
    throw new RuntimeException('Renewal balance incorrect');
if ((int)$db->query('SELECT COUNT(*) FROM invoice')->fetchColumn()!==1)
    throw new RuntimeException('Unexpected extra service invoice');
if ((int)$db->query('SELECT COUNT(*) FROM vpnapp_orders')->fetchColumn()!==2)
    throw new RuntimeException('Duplicate order records');
// Ambiguous panel timeout MUST keep the original order id, not repeat a debit
// or repeat createUser. This is reconciliation required, NOT fulfilled.
ManagePanel::$timeoutOnCreate = true;
$unknownId='33333333-3333-4333-8333-333333333333';
$unknownOne=$checkout($unknownId);
$unknownTwo=$checkout($unknownId);
if (($unknownOne['status']??'')!=='unknown' || $unknownOne!==$unknownTwo)
    throw new RuntimeException('Unknown operation should remain pending review');
if (ManagePanel::$createCount!==2)
    throw new RuntimeException('Blind retry after ambiguous create');
if ((int)$db->query("SELECT Balance FROM user WHERE id='".$commercial."'")->fetchColumn()!==100000)
    throw new RuntimeException('Unknown operation charged more than once');
ManagePanel::$timeoutOnCreate = false;

ManagePanel::$timeoutOnRenew = true;
$unknownRenew='44444444-4444-4444-8444-444444444444';
$renewOne=$renew($unknownRenew);
$renewTwo=$renew($unknownRenew);
if (($renewOne['status']??'')!=='unknown'||$renewOne!==$renewTwo)
    throw new RuntimeException('Unknown renewal was retried');
if (ManagePanel::$renewCount!==2)
    throw new RuntimeException('Blind retry after renewal timeout');
if ((int)$db->query("SELECT Balance FROM user WHERE id='".$commercial."'")->fetchColumn()!==0)
    throw new RuntimeException('Unknown renewal charged more than once');
echo "PASS: actual PHP logic, one wallet debit/create/extension per UUID, timeout requires reconciliation\n";
