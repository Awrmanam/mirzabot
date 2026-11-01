<?php
/**
 * Standalone, read-only, public-base Mirza catalog for the VPN website.
 * Independent from vpnapp.php and any financial checkout credentials.
 * Enabled ONLY with MIRZA_PUBLIC_CATALOG_ENABLED=1.
 * Dedicated MIRZA_PUBLIC_CATALOG_SECRET (32+ bytes) or _SECRET_FILE.
 * Request HMAC is over "GET\n/api/vpnweb_public_catalog.php\n"+timestamp.
 */
declare(strict_types=1);

function vpnwebCatalogReply(int $code, array $payload): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function vpnwebCatalogSecret(): string {
    $file=trim((string)(getenv('MIRZA_PUBLIC_CATALOG_SECRET_FILE')?:''));
    if ($file!=='') {
        $real=realpath($file);
        $root=realpath((string)($_SERVER['DOCUMENT_ROOT']??''));
        if (!$real || is_link($file) || ($root &&
            ($real===$root || str_starts_with($real,$root.'/')))) return '';
        $contents=@file_get_contents($real);
        $secret=is_string($contents)?trim($contents):'';
    } else {
        $secret=trim((string)(getenv('MIRZA_PUBLIC_CATALOG_SECRET')?:''));
    }
    $financial=trim((string)(getenv('MIRZA_APP_BRIDGE_SECRET')?:''));
    if (strlen($secret)<32 || ($financial!=='' && hash_equals($financial,$secret))) return '';
    return $secret;
}
function vpnwebCatalogAuthenticated(string $method,$ts,$sig,string $secret,?int $now=null): bool {
    if ($method!=='GET'||strlen($secret)<32||!is_string($ts)||
        !preg_match('/^[0-9]{10,12}$/D',$ts)||!is_string($sig)||
        !preg_match('/^[a-fA-F0-9]{64}$/D',$sig)) return false;
    if (abs(($now??time())-(int)$ts)>90) return false;
    $expected=hash_hmac('sha256',"GET\n/api/vpnweb_public_catalog.php\n".$ts,$secret);
    return hash_equals($expected,strtolower($sig));
}
function vpnwebCatalogEnabledProduct(array $product,?string $flag): bool {
    // Original Mirza has no status column on product. one_buy_status
    // restricts previous buyers, and MUST NOT be treated as disabled.
    if ($flag===null) return true;
    return in_array(strtolower(trim((string)($product[$flag]??''))),
        ['1','active','enabled','enable','on','true'],true);
}
function vpnwebCatalogRows(PDO $pdo): array {
    // Mirza Mini App public-user filter: active panel + f/all + not manual sale.
    $panels=$pdo->query("SELECT code_panel,name_panel,type FROM marzban_panel
      WHERE status='active' AND agent IN ('f','all') AND type <> 'Manualsale'
      ORDER BY id ASC LIMIT 150")->fetchAll(PDO::FETCH_ASSOC);
    $columns=$pdo->query("SHOW COLUMNS FROM product")->fetchAll(PDO::FETCH_COLUMN,0);
    $flag=null;
    foreach(['status_product','is_active','status'] as $candidate) {
        if (in_array($candidate,$columns,true)) {$flag=$candidate;break;}
    }
    $extra=$flag===null?'':(','.chr(96).$flag.chr(96));
    $query=$pdo->prepare("SELECT code_product,name_product,price_product,
      Volume_constraint,Service_time,category,hide_panel".$extra."
      FROM product WHERE agent='f' AND Location IN (?,?, '/all')
      ORDER BY id ASC LIMIT 600");
    $items=[];
    foreach($panels as $panel) {
        if(count($items)>=300) break;
        $code=trim((string)($panel['code_panel']??''));
        $name=trim((string)($panel['name_panel']??''));
        if($code===''||$name==='') continue;
        $query->execute([$code,$name]);
        foreach($query->fetchAll(PDO::FETCH_ASSOC) as $product) {
            if(count($items)>=300) break;
            if(!vpnwebCatalogEnabledProduct($product,$flag)) continue;
            $raw=(string)($product['hide_panel']??'[]');
            $hidden=trim($raw)===''?[]:json_decode($raw,true);
            if(!is_array($hidden)||in_array($name,$hidden,true)||
                in_array($code,$hidden,true)) continue;
            $price=trim((string)($product['price_product']??''));
            $volume=$product['Volume_constraint']??null;
            $days=(string)($product['Service_time']??'');
            $plan=trim((string)($product['code_product']??''));
            $title=trim((string)($product['name_product']??''));
            if($plan===''||strlen($plan)>128||$title===''||strlen($title)>200||
                !preg_match('/^[0-9]{1,10}$/D',$price)||
                (int)$price<=0||(int)$price>1000000000||
                !is_numeric($volume)||!is_finite((float)$volume)||
                (float)$volume<0||(float)$volume>100000||
                !preg_match('/^[0-9]{1,5}$/D',$days)||(int)$days>3650) continue;
            $items[]=[
                'panel_code'=>$code,
                'panel_name'=>mb_substr($name,0,100),
                'provider_type'=>mb_substr((string)($panel['type']??''),0,60),
                'plan_code'=>$plan,
                'title'=>mb_substr($title,0,200),
                'category'=>mb_substr(trim((string)($product['category']??'')),0,80),
                'volume_gb'=>(float)$volume,
                'days'=>(int)$days,
                'price_toman'=>(int)$price
            ];
        }
    }
    return $items;
}

// CLI-only library seam; impossible to set through ordinary HTTP headers/URL.
if(defined('VPNWEB_PUBLIC_CATALOG_LIB_ONLY')&&VPNWEB_PUBLIC_CATALOG_LIB_ONLY)return;
if(getenv('MIRZA_PUBLIC_CATALOG_ENABLED')!=='1')
    vpnwebCatalogReply(503,['error'=>'Catalog disabled']);
$secret=vpnwebCatalogSecret();
if(!vpnwebCatalogAuthenticated($_SERVER['REQUEST_METHOD']??'',
    $_SERVER['HTTP_X_CATALOG_TIMESTAMP']??null,
    $_SERVER['HTTP_X_CATALOG_SIGNATURE']??null,$secret))
    vpnwebCatalogReply(403,['error'=>'Unauthorized']);
if(!empty($_SERVER['QUERY_STRING']))
    vpnwebCatalogReply(400,['error'=>'Unexpected query']);
try {
    require_once __DIR__.'/../config.php'; // Existing PDO credentials; SELECT only.
    if(!isset($pdo)||!($pdo instanceof PDO))
        throw new RuntimeException('No database');
    vpnwebCatalogReply(200,[
        'items'=>vpnwebCatalogRows($pdo),
        'price_type'=>'public_base','scope'=>'public','cache_seconds'=>60
    ]);
} catch(Throwable $exception) {
    error_log('[vpnweb-catalog] Read-only fetch failure: '.get_class($exception));
    vpnwebCatalogReply(503,['error'=>'Catalog unavailable']);
}
