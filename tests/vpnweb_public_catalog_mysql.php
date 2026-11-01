<?php
/** Actual MySQL read path with disposable, rolled-back public-price fixtures.
 * Run ONLY inside isolated stage; NEVER on a real Mirza DB.
 */
declare(strict_types=1);
if (getenv('APP_ENV')!=='staging' || getenv('MIRZA_STAGING_PANEL_MOCK')!=='1')
    throw new RuntimeException('REQUIRES isolated staged PHP and MOCK panel');
define('VPNWEB_PUBLIC_CATALOG_LIB_ONLY',true);
require __DIR__.'/../api/vpnweb_public_catalog.php';
require __DIR__.'/../config.php';
if (!($pdo instanceof PDO)) throw new RuntimeException('Staging PDO missing');
$suffix=bin2hex(random_bytes(5));
$tests=[
    ['panel'=>$suffix.'-active','status'=>'active','agent'=>'all','type'=>'rebecca'],
    ['panel'=>$suffix.'-inactive','status'=>'disable','agent'=>'all','type'=>'rebecca'],
    ['panel'=>$suffix.'-reseller','status'=>'active','agent'=>'n','type'=>'rebecca'],
    ['panel'=>$suffix.'-manual','status'=>'active','agent'=>'all','type'=>'Manualsale']
];
$pdo->beginTransaction();
try {
    $panelInsert=$pdo->prepare("INSERT INTO marzban_panel
       (code_panel,name_panel,status,agent,type,on_hold_test,version_panel)
       VALUES (?,?,?,?,?,'0','staging-test')");
    foreach ($tests as $test)
        $panelInsert->execute([$test['panel'],'PANEL-'.$test['panel'],
                               $test['status'],$test['agent'],$test['type']]);
    $productInsert=$pdo->prepare("INSERT INTO product
       (code_product,name_product,price_product,Volume_constraint,Service_time,
        category,Location,agent,hide_panel,one_buy_status)
        VALUES (?,?,?,?,?,?,?,?,?,?)");
    $active=$tests[0]['panel'];
    // Only public f plans, positive integer base prices, valid location and not hidden.
    $productInsert->execute([$suffix.'-public','PUBLIC TEST','77000','25','30',
        'Lite',$active,'f','[]','0']);
    $productInsert->execute([$suffix.'-hidden','HIDDEN','77000','25','30',
        'Lite',$active,'f',json_encode(['PANEL-'.$active]),'0']);
    $productInsert->execute([$suffix.'-reseller-plan','RESELLER','100','20','30',
        'Lite',$active,'n','[]','0']);
    foreach (array_slice($tests,1) as $test) {
        $productInsert->execute([$suffix.'-'.$test['status'].'-'.$test['agent'].'-'.$test['type'],
            'NOT PUBLIC','70000','20','30','Lite',$test['panel'],'f','[]','0']);
    }
    $rows=vpnwebCatalogRows($pdo);
    $mine=array_values(array_filter($rows,
         fn($r)=>str_starts_with((string)($r['plan_code']??''),$suffix)));
    if (count($mine)!==1 || $mine[0]['title']!=='PUBLIC TEST' ||
        $mine[0]['price_toman']!==77000 || $mine[0]['category']!=='Lite')
        throw new RuntimeException('Public f/hidden/inactive/manual/agent filters failed');
    $allow=['panel_code','panel_name','provider_type','plan_code','title',
            'category','volume_gb','days','price_toman'];
    foreach ($rows as $row) {
        if (array_diff(array_keys($row),$allow))
            throw new RuntimeException('Secret/private field present in catalog');
    }
    $pdo->rollBack(); // No fixture remains.
    echo "PASS: genuine staged MySQL catalog filters, f base pricing, private field allowlist; rolled back\n";
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
}
