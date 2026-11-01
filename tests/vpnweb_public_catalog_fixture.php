<?php
/** No live database: exercise exact public PHP projection with PDO fixtures. */
declare(strict_types=1);
define('VPNWEB_PUBLIC_CATALOG_LIB_ONLY',true);
require __DIR__.'/../api/vpnweb_public_catalog.php';

class CatalogFixtureStatement extends PDOStatement {
    public function __construct(private string $kind,private CatalogFixturePDO $db,private string $sql) {}
    private array $params=[];
    public function execute(?array $params=null): bool {$this->params=$params??[];return true;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array {
        if($this->kind==='cols')return $this->db->withStatus?['id','status','price_product']:['id','price_product'];
        if($this->kind==='panels')return [
            ['code_panel'=>'de','name_panel'=>'Germany','type'=>'rebecca']];
        if(!str_contains($this->sql,"agent='f'") ||
            $this->params!==['de','Germany'])throw new RuntimeException('SQL public owner/location mismatch');
        return [
            ['code_product'=>'light','name_product'=>'Lite 30GB','price_product'=>'50000',
             'Volume_constraint'=>'30','Service_time'=>'30','category'=>'Lite','hide_panel'=>'[]','status'=>'active'],
            ['code_product'=>'hidden','name_product'=>'HIDDEN','price_product'=>'60000',
             'Volume_constraint'=>'40','Service_time'=>'30','category'=>'Pro','hide_panel'=>'["Germany"]','status'=>'active'],
            ['code_product'=>'invalid','name_product'=>'N/A','price_product'=>'-1',
             'Volume_constraint'=>'40','Service_time'=>'30','category'=>'Pro','hide_panel'=>'[]','status'=>'active'],
            ['code_product'=>'malformed','name_product'=>'MALFORMED','price_product'=>'60000',
             'Volume_constraint'=>'40','Service_time'=>'30','category'=>'Pro','hide_panel'=>'{bad}','status'=>'active'],
            ['code_product'=>'disabled','name_product'=>'DISABLED','price_product'=>'60000',
             'Volume_constraint'=>'40','Service_time'=>'30','category'=>'Pro','hide_panel'=>'[]','status'=>'disable'],
        ];
    }
}
class CatalogFixturePDO extends PDO {
    public bool $withStatus=false;
    public function __construct() {}
    public function query(string $query,?int $fetchMode=null,mixed ...$args):PDOStatement|false {
        if(str_contains($query,'marzban_panel')) {
            if(!str_contains($query,"status='active'") || !str_contains($query,"agent IN ('f','all')") ||
                !str_contains($query,"type <> 'Manualsale'"))throw new RuntimeException('Panel filters missing');
            return new CatalogFixtureStatement('panels',$this,$query);
        }
        if(str_contains($query,'SHOW COLUMNS'))return new CatalogFixtureStatement('cols',$this,$query);
        throw new RuntimeException('Unexpected SQL');
    }
    public function prepare(string $query,array $options=[]):PDOStatement|false {
        if(!str_contains($query,'FROM product') || !str_contains($query,'Location IN'))
            throw new RuntimeException('Product public location filter missing');
        return new CatalogFixtureStatement('products',$this,$query);
    }
}
function verifyCatalog($yes,$reason){if(!$yes)throw new RuntimeException('FAILED: '.$reason);}
$db=new CatalogFixturePDO();
$rows=vpnwebCatalogRows($db);
verifyCatalog(count($rows)===2,'original Mirza product schema includes every available status');
verifyCatalog($rows[0]['title']==='Lite 30GB' && $rows[0]['panel_name']==='Germany' &&
              $rows[0]['category']==='Lite','readable labels');
verifyCatalog($rows[0]['price_toman']===50000,'real integer public price');
verifyCatalog($rows[1]['plan_code']==='disabled','original status absent is not selectively filtered');
$db->withStatus=true;$rows=vpnwebCatalogRows($db);
verifyCatalog(count($rows)===1 && $rows[0]['plan_code']==='light','explicit product status enforced');
verifyCatalog(array_keys($rows[0])===['panel_code','panel_name','provider_type',
    'plan_code','title','category','volume_gb','days','price_toman'],'output-only allowlist');
echo "PASS isolated PDO fixture: PHP public pricing, visibility, optional status, labels and field allowlist\n";
