<?php
declare(strict_types=1);
require __DIR__.'/../backend/vendor/autoload.php';
use App\Services\MigrationService;

final class RestoredMetadataStatement extends PDOStatement {
    public function __construct(private mixed $value, private ?Closure $lookup=null) {}
    public function execute(?array $params=null):bool { if($this->lookup)$this->value=($this->lookup)($params);return true; }
    public function fetchColumn(int $column=0):mixed { return $this->value; }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array { return is_array($this->value)?$this->value:[]; }
}
final class RestoredMetadataDatabase extends PDO {
    public array $executed=[];
    public function __construct(public array $ledger,public array $columns,public bool $tracked=true) {}
    public function query(string $query,?int $fetchMode=null,mixed ...$args):PDOStatement|false {
        if(str_contains($query,'information_schema.TABLES'))return new RestoredMetadataStatement($this->tracked?'schema_versions':false);
        if(str_contains($query,'information_schema.COLUMNS'))return new RestoredMetadataStatement($this->columns);
        if(str_contains($query,'information_schema.VIEWS'))return new RestoredMetadataStatement($this->columns?'product_catalog_trigger_metadata':false);
        throw new RuntimeException('Unexpected metadata query');
    }
    public function prepare(string $query,array $options=[]):PDOStatement|false {
        if(!str_starts_with($query,'SELECT 1 FROM schema_versions'))throw new RuntimeException('Reconciliation changed the ledger');
        return new RestoredMetadataStatement(false,fn(array $params)=>in_array($params[0],$this->ledger,true));
    }
    public function exec(string $statement):int|false {
        if(!preg_match('/^CREATE OR REPLACE ALGORITHM = TEMPTABLE SQL SECURITY DEFINER\s+VIEW product_catalog_trigger_metadata AS/i',$statement))throw new RuntimeException('Reconciliation touched non-derived SQL');
        $this->executed[]=$statement;
        $this->columns=str_contains($statement,'object_type')?['TRIGGER_NAME','object_type','object_name','definition','context','table_name']:['TRIGGER_NAME'];return 0;
    }
}
$legacy='058_add_catalog_trigger_diagnostics_view.sql';$extended='059_add_update_compatibility_metadata.sql';
$columns=['TRIGGER_NAME','object_type','object_name','definition','context','table_name'];
foreach([[[$legacy,$extended],[],$columns],[[$legacy,$extended],['TRIGGER_NAME'],$columns],[[$legacy],[],['TRIGGER_NAME']],[[$legacy,$extended],$columns,$columns]] as [$ledger,$present,$expected]) {
    $db=new RestoredMetadataDatabase($ledger,$present);$service=new MigrationService($db);
    if($service->reconcileBackupMetadata()!==[]||$db->columns!==$expected)throw new RuntimeException('Restored ledger did not recover its exact metadata-view shape');
    $count=count($db->executed);$service->reconcileBackupMetadata();if(count($db->executed)!==$count)throw new RuntimeException('Healthy metadata was recreated unnecessarily');
    if($db->ledger!==$ledger)throw new RuntimeException('Derived reconciliation changed migration history');
}
$db=new RestoredMetadataDatabase([],[],false);if((new MigrationService($db))->reconcileBackupMetadata()!==[]||$db->executed)throw new RuntimeException('Untracked backup was upgraded');
echo "PASS restored metadata follows recorded058/059, repairs legacy shape, stays idempotent and leaves business SQL/ledger untouched\n";
