<?php
declare(strict_types=1);
require __DIR__.'/../backend/vendor/autoload.php';
use App\Services\UpdateDataCompatibilityService;
class CompatibilityStatement extends PDOStatement {
    public function __construct(private array $rows){}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return $this->rows;}
}
class CompatibilityDatabase extends PDO {
    public bool $metadata=true;public string $type='varchar(200)';public array $ledger=['001_initial.sql'];public string $trigger='SET NEW.name = NEW.name';public array $queries=[];
    public function __construct(){}
    public function getAttribute(int $attribute):mixed{return 'mysql';}
    public function query(string $query,?int $fetchMode=null,mixed ...$args):PDOStatement|false {
        $this->queries[]=$query;
        if(str_contains($query,'FROM product_catalog_trigger_metadata')){if(!$this->metadata)throw new RuntimeException('metadata missing');return new CompatibilityStatement([['object_type'=>'trigger','object_name'=>'t','definition'=>$this->trigger,'context'=>'BEFORE|INSERT','table_name'=>'customers']]);}
        if(str_contains($query,'FROM schema_versions'))return new CompatibilityStatement(array_map(fn($version)=>['version'=>$version],$this->ledger));
        if(str_contains($query,'information_schema.COLUMNS'))return new CompatibilityStatement([['table_name'=>'customers','name'=>'name','type'=>$this->type,'default'=>null]]);
        if(str_contains($query,'information_schema.TABLES'))return new CompatibilityStatement([['name'=>'customers','type'=>'BASE TABLE','engine'=>'InnoDB','collation'=>'utf8mb4_general_ci']]);
        return new CompatibilityStatement([]);
    }
}
function dataAssert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$db=new CompatibilityDatabase();$service=new UpdateDataCompatibilityService();$description=$service->describe($db);
$contract=['format'=>1,'schema_sha256'=>$description['schema_sha256'],'ledger_sha256'=>$description['ledger_sha256'],'metadata_migration'=>UpdateDataCompatibilityService::METADATA_MIGRATION];
dataAssert($service->assess($db,$contract,true)['compatible'],'Exact compatible schema rejected');
$db->ledger[]=UpdateDataCompatibilityService::METADATA_MIGRATION;
dataAssert($service->assess($db,$contract,true)['compatible'],'Read-only updater metadata blocks historical app');
$db->ledger[]='060_business_change.sql';dataAssert(!$service->assess($db,$contract,true)['compatible'],'Unknown later migration accepted');array_pop($db->ledger);
$db->type='varchar(30)';dataAssert(!$service->assess($db,$contract,true)['compatible'],'Changed business column accepted');$db->type='varchar(200)';
$db->trigger='DELETE FROM customers';dataAssert(!$service->assess($db,$contract,true)['compatible'],'Changed trigger body accepted');$db->trigger='SET NEW.name = NEW.name';
$db->metadata=false;dataAssert(!$service->assess($db,$contract,true)['compatible'],'Missing object metadata guessed compatible');
foreach($db->queries as $query)dataAssert((bool)preg_match('/^SELECT\s/i',$query),'Compatibility assessment wrote SQL');
echo "PASS data compatibility: exact structural/ledger contract, narrow metadata exception, altered column/trigger/ledger and missing visibility blocked; read-only queries\n";
