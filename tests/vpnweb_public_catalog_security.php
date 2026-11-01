<?php
/** No DB, no production calls: public catalog security regression tests. */
declare(strict_types=1);
define('VPNWEB_PUBLIC_CATALOG_LIB_ONLY',true);
require __DIR__.'/../api/vpnweb_public_catalog.php';

function check($value,$label) {
    if (!$value) throw new RuntimeException('FAIL public catalog: '.$label);
}
$key=str_repeat('a',64);
$ts='1793512800'; // fixed test epoch, never an active signed request
$sig=hash_hmac('sha256',"GET\n/api/vpnweb_public_catalog.php\n".$ts,$key);
check(vpnwebCatalogAuthenticated('GET',$ts,$sig,$key,1793512800),'valid HMAC');
check(!vpnwebCatalogAuthenticated('POST',$ts,$sig,$key,1793512800),'method forbidden');
check(!vpnwebCatalogAuthenticated('GET',$ts,$sig,str_repeat('b',64),1793512800),'separate key');
check(!vpnwebCatalogAuthenticated('GET',$ts,$sig,$key,1793513000),'expired HMAC');
check(!vpnwebCatalogAuthenticated('GET',$ts,str_repeat('0',64),$key,1793512800),'forged signature');
check(!vpnwebCatalogAuthenticated('GET','bad',$sig,$key,1793512800),'invalid timestamp');
check(vpnwebCatalogEnabledProduct([],null),'no status flag in original Mirza schema');
check(!vpnwebCatalogEnabledProduct(['status'=>'disable'],'status'),'disabled product not public');
check(vpnwebCatalogEnabledProduct(['status'=>'active'],'status'),'enabled product public');
check(!vpnwebCatalogEnabledProduct(['is_active'=>'0'],'is_active'),'is_active false hidden');
check(vpnwebCatalogLabel('تست فارسی',4)==='تست ','unicode truncation');
check(vpnwebCatalogLabel('ProMax',200)==='ProMax','public title remains visible');
check(vpnwebCatalogLabel('Lite',80)==='Lite','public category remains visible');
check(vpnwebCatalogLabel('🇩🇪 Germany',100)!=='','panel name remains visible');
putenv('MIRZA_PUBLIC_CATALOG_SECRET='.str_repeat('Z',48));
putenv('MIRZA_APP_BRIDGE_SECRET='.str_repeat('Z',48));
check(vpnwebCatalogSecret()==='','financial and catalog keys cannot be identical');
putenv('MIRZA_APP_BRIDGE_SECRET='.str_repeat('Y',48));
check(vpnwebCatalogSecret()===str_repeat('Z',48),'separate key accepted');
echo "PASS: independent read-only HMAC, expiry, strict method, status visibility, no financial-key reuse\n";
