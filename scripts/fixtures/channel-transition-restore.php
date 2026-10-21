<?php
declare(strict_types=1);
// Restore only disposable runner data, using the deployed version's real code.
if(getenv('GITHUB_ACTIONS')!=='true')throw new RuntimeException('Ephemeral restore fixture only');
$root=realpath($argv[1]??'');$temporary=realpath((string)getenv('RUNNER_TEMP'));
if(!$root||!$temporary||!str_starts_with(strtolower(str_replace('\\','/',$root)),strtolower(str_replace('\\','/',$temporary)).'/'))throw new RuntimeException('Restore target must be inside the owned runner lab');
$config=rtrim((string)getenv('APPDATA'),'/\\').'/POS System';
$credentials=json_decode(file_get_contents($config.'/db_credentials.json'),true,32,JSON_THROW_ON_ERROR);
$ports=json_decode(file_get_contents($config.'/runtime_ports.json'),true,32,JSON_THROW_ON_ERROR);
$storage=$temporary.'/transition-restore-fixture';if(!is_dir($storage))mkdir($storage,0700,true);
putenv('APP_STORAGE_DIR='.$storage);
putenv('DB_MIGRATION_USER='.$credentials['migrationUser']);putenv('DB_MIGRATION_PASS='.$credentials['migrationPassword']);
require 'phar://'.str_replace('\\','/',$root).'/backend/backend.phar/vendor/autoload.php';
$db=new PDO('mysql:host=127.0.0.1;port='.$ports['mysqlPort'].';dbname=pos_db;charset=utf8mb4',$credentials['user'],$credentials['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$backup=new App\Services\BackupService();$backup->setDb($db);
$file=$backup->createBackupFile($storage.'/backups');
$result=$backup->restoreFromSql(file_get_contents($file),true,['host'=>'127.0.0.1','port'=>(int)$ports['mysqlPort'],'name'=>'pos_db','user'=>$credentials['migrationUser'],'pass'=>$credentials['migrationPassword']]);
if(!$result['ok'])throw new RuntimeException('Actual deployed SQL backup/restore failed: '.($result['error']??''));
$columns=$db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='product_catalog_trigger_metadata' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_COLUMN);
echo json_encode(['ok'=>true,'version'=>json_decode(file_get_contents($root.'/version.json'),true)['version'],'extended_metadata'=>in_array('object_type',$columns,true)],JSON_THROW_ON_ERROR);
