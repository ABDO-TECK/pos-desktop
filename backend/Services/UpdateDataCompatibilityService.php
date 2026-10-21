<?php
declare(strict_types=1);
namespace App\Services;

use PDO;
use RuntimeException;
use Throwable;

/** Read-only structural compatibility. Business values never enter the contract. */
final class UpdateDataCompatibilityService
{
    public const METADATA_MIGRATION = '059_add_update_compatibility_metadata.sql';
    private const METADATA_VIEW = 'product_catalog_trigger_metadata';

    public function describe(PDO $db): array
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') throw new RuntimeException('Compatibility requires the actual MariaDB schema.');
        $queries = [
            'tables' => 'SELECT TABLE_NAME AS name,TABLE_TYPE AS type,ENGINE AS engine,TABLE_COLLATION AS collation FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()',
            'columns' => 'SELECT TABLE_NAME AS table_name,COLUMN_NAME AS name,ORDINAL_POSITION AS position,COLUMN_TYPE AS type,IS_NULLABLE AS nullable,COLUMN_DEFAULT AS default_value,EXTRA AS extra,CHARACTER_SET_NAME AS charset_name,COLLATION_NAME AS collation FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()',
            'indexes' => 'SELECT TABLE_NAME AS table_name,INDEX_NAME AS name,NON_UNIQUE AS non_unique,SEQ_IN_INDEX AS position,COLUMN_NAME AS column_name,COLLATION AS collation,SUB_PART AS sub_part,INDEX_TYPE AS type FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()',
            'keys' => 'SELECT TABLE_NAME AS table_name,CONSTRAINT_NAME AS name,COLUMN_NAME AS column_name,ORDINAL_POSITION AS position,REFERENCED_TABLE_NAME AS referenced_table,REFERENCED_COLUMN_NAME AS referenced_column FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE()',
            'references' => 'SELECT TABLE_NAME AS table_name,CONSTRAINT_NAME AS name,REFERENCED_TABLE_NAME AS referenced_table,UPDATE_RULE AS update_rule,DELETE_RULE AS delete_rule,MATCH_OPTION AS match_option FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE()',
            'checks' => 'SELECT TABLE_NAME AS table_name,CONSTRAINT_NAME AS name,CHECK_CLAUSE AS clause_text FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE()',
            'objects' => 'SELECT object_type,object_name,definition,context,table_name FROM product_catalog_trigger_metadata',
        ];
        $schema = [];
        foreach ($queries as $kind => $sql) {
            $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            if ($kind === 'objects') foreach ($rows as $row) {
                if (!is_string($row['definition'] ?? null) || $row['definition'] === '') throw new RuntimeException('Schema object definitions are not fully visible.');
            }
            $rows = array_values(array_filter($rows, static fn(array $row):bool => ($row['table_name']??$row['name']??'') !== self::METADATA_VIEW));
            if ($kind === 'objects') foreach ($rows as &$row) $row['definition']=$this->canonicalDefinition($row['definition']);
            unset($row);
            foreach ($rows as &$row) {
                foreach ($row as &$value) if ($value !== null) $value = (string)$value;
                unset($value); ksort($row);
            }
            unset($row);
            usort($rows, static fn(array $a,array $b):int => strcmp(json_encode($a,JSON_THROW_ON_ERROR), json_encode($b,JSON_THROW_ON_ERROR)));
            $schema[$kind] = $rows;
        }
        if (!$schema['tables'] || !$schema['columns']) throw new RuntimeException('Application schema is unavailable.');
        $ledgerRows = $db->query('SELECT version FROM schema_versions ORDER BY version')->fetchAll(PDO::FETCH_ASSOC);
        $ledger = [];
        foreach ($ledgerRows as $row) {
            if (!is_string($row['version']??null) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*\.sql$/',$row['version'])) throw new RuntimeException('Migration ledger is invalid.');
            if ($row['version'] !== self::METADATA_MIGRATION) $ledger[] = $row['version'];
        }
        sort($ledger, SORT_STRING);
        if (!$ledger || count($ledger)!==count(array_unique($ledger))) throw new RuntimeException('Migration ledger is empty or duplicated.');
        return ['format'=>1,'schema_sha256'=>hash('sha256',json_encode($schema,JSON_THROW_ON_ERROR)),'ledger_sha256'=>hash('sha256',json_encode($ledger,JSON_THROW_ON_ERROR)),'schema'=>$schema,'ledger'=>$ledger];
    }

    /** Ignore formatting outside SQL literals/identifiers; preserve comments verbatim. */
    private function canonicalDefinition(string $sql): string
    {
        $result='';$pendingSpace=false;$length=strlen($sql);
        for($position=0;$position<$length;$position++) {
            $character=$sql[$position];
            if(str_contains(" \t\r\n\v\f",$character)) { $pendingSpace=true;continue; }
            if($pendingSpace && $result!=='')$result.=' ';
            $pendingSpace=false;
            if(in_array($character,["'",'"','`'],true)) {
                $quote=$character;$result.=$character;
                while(++$position<$length) {
                    $character=$sql[$position];$result.=$character;
                    if($character==='\\' && $position+1<$length) { $result.=$sql[++$position];continue; }
                    if($character===$quote) {
                        if($position+1<$length && $sql[$position+1]===$quote) { $result.=$sql[++$position];continue; }
                        break;
                    }
                }
            } elseif(substr($sql,$position,2)==='/*') {
                $end=strpos($sql,'*/',$position+2);$end=$end===false?$length:$end+2;
                $result.=substr($sql,$position,$end-$position);$position=$end-1;
            } elseif($character==='#' || (substr($sql,$position,2)==='--' && ($position+2===$length || ord($sql[$position+2])<=32))) {
                $end=strpos($sql,"\n",$position);$end=$end===false?$length:$end+1;
                $result.=substr($sql,$position,$end-$position);$position=$end-1;
            } else $result.=$character;
        }
        return $result;
    }

    public function assess(PDO $db, array $contract, bool $downgrade): array
    {
        try {
            if (($contract['format']??null)!==1 || ($contract['metadata_migration']??null)!==self::METADATA_MIGRATION) throw new RuntimeException('Unsupported data compatibility contract.');
            foreach (['schema_sha256','ledger_sha256'] as $key) if (!is_string($contract[$key]??null) || !preg_match('/^[a-f0-9]{64}$/',$contract[$key])) throw new RuntimeException('Invalid data compatibility digest.');
            $actual = $this->describe($db);
            $compatible = hash_equals($contract['schema_sha256'],$actual['schema_sha256']) && hash_equals($contract['ledger_sha256'],$actual['ledger_sha256']);
            return ['compatible'=>$compatible,'schema_sha256'=>$actual['schema_sha256'],'ledger_sha256'=>$actual['ledger_sha256'],'reason_code'=>$compatible?null:'data_schema_incompatible'];
        } catch (Throwable $error) {
            return ['compatible'=>false,'schema_sha256'=>'','ledger_sha256'=>'','reason_code'=>'data_compatibility_unverifiable','error'=>$error->getMessage()];
        }
    }
}
