<?php
declare(strict_types=1);

function atomicJson(string $path,array $value):void {
    $temporary=$path.'.'.getmypid().'.tmp';
    file_put_contents($temporary,json_encode($value,JSON_THROW_ON_ERROR));
    if(!rename($temporary,$path))throw new RuntimeException('Cannot publish capability receipt');
}
function launchObserver(string $plan):void {
    $quote=static fn(string $v):string=>"'".str_replace("'","''",$v)."'";
    $arguments='"'.__FILE__.'" "observe" "'.$plan.'"';
    $command='Start-Process -WindowStyle Hidden -FilePath '.$quote(PHP_BINARY).' -ArgumentList '.$quote($arguments);
    $process=proc_open(['powershell.exe','-NoProfile','-NonInteractive','-EncodedCommand',base64_encode(iconv('UTF-8','UTF-16LE',$command))],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
    if(!is_resource($process))throw new RuntimeException('Cannot start independent observer');
    fclose($pipes[0]);stream_get_contents($pipes[1]);fclose($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[2]);
    if(proc_close($process)!==0)throw new RuntimeException('Independent observer launch failed');
}
if(PHP_SAPI==='cli-server') {
    launchObserver((string)getenv('POS_CAPABILITY_PLAN'));header('Content-Type: application/json');echo '{"launched":true}';exit;
}
$mode=$argv[1]??'';$argument=$argv[2]??'';
if($mode==='observe') {
    $plan=json_decode(file_get_contents($argument),true,512,JSON_THROW_ON_ERROR);$dir=$plan['directory'];
    $seen=false;$counter=0;$deadline=microtime(true)+240;
    while(microtime(true)<$deadline && !is_file($dir.'/stop')) {
        atomicJson($dir.'/heartbeat.json',['counter'=>++$counter,'parent_mode'=>'disposable_php_http','at'=>microtime(true)]);
        if(($plan['mode']??'')==='desktop') {
            try {
                $version=json_decode(file_get_contents($plan['root'].'/version.json'),true)['version']??'';
                if($version!=='0.0.1')$seen=true;
                $state=json_decode((string)@file_get_contents($plan['storage'].'/update-state.json'),true);
                if($seen && $version==='0.0.1' && ($state['state']??'')==='completed') {
                    $matches=true;foreach($plan['inventory'] as $path=>$expected) {
                        $file=$plan['root'].'/'.$path;
                        if(!is_file($file)||hash_file('sha256',$file)!==$expected){$matches=false;break;}
                    }
                    atomicJson($dir.'/observer.json',['survived_handoff'=>true,'target_inventory_matches'=>$matches,'counter'=>$counter]);exit($matches?0:1);
                }
            }catch(Throwable $e){/* Files can be transient during a handoff; bounded retry. */}
        }
        usleep(200000);
    }
    exit;
}
if($mode==='launch'){launchObserver($argument);echo '{"launched":true}';exit;}
if(!in_array($mode,['seed','inspect','prepare-compatibility','inspect-compatibility'],true)||getenv('GITHUB_ACTIONS')!=='true')throw new RuntimeException('SQL capability fixture requires ephemeral CI');
$config=rtrim((string)getenv('APPDATA'),'/\\').'/POS System';
$credentials=json_decode(file_get_contents($config.'/db_credentials.json'),true,512,JSON_THROW_ON_ERROR);
$ports=json_decode(file_get_contents($config.'/runtime_ports.json'),true,512,JSON_THROW_ON_ERROR);
$db=new PDO('mysql:host=127.0.0.1;port='.$ports['mysqlPort'].';dbname=pos_db;charset=utf8mb4',$credentials['user'],$credentials['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if(in_array($mode,['prepare-compatibility','inspect-compatibility'],true)) {
    require __DIR__.'/../../backend/vendor/autoload.php';
    if($mode==='prepare-compatibility') {
        $migration=new PDO('mysql:host=127.0.0.1;port='.$ports['mysqlPort'].';dbname=pos_db;charset=utf8mb4',$credentials['migrationUser'],$credentials['migrationPassword'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $isolatedStorage=rtrim((string)getenv('RUNNER_TEMP'),'/\\').'/transition-metadata-fixture';
        if(!is_dir($isolatedStorage))mkdir($isolatedStorage,0700,true);
        putenv('APP_STORAGE_DIR='.$isolatedStorage);
        $result=(new App\Services\MigrationService($migration))->runMigrations([App\Services\UpdateDataCompatibilityService::METADATA_MIGRATION],true);
        if($result['errors'])throw new RuntimeException('Compatibility metadata migration failed: '.implode('; ',$result['errors']));
    }
    $description=(new App\Services\UpdateDataCompatibilityService())->describe($db);
    try{$db->exec("UPDATE pos_update_compatibility_metadata SET definition='altered'");throw new RuntimeException('Metadata view was writable');}
    catch(PDOException $expected){/* TEMPTABLE view is intentionally not updatable. */}
    echo json_encode(['schema_sha256'=>$description['schema_sha256'],'ledger_sha256'=>$description['ledger_sha256'],'objects'=>count($description['schema']['objects']),'runtime_metadata_readable'=>true,'metadata_view_read_only'=>true],JSON_THROW_ON_ERROR);exit;
}
if($mode==='seed') {
    $query=$db->prepare('INSERT INTO customers (branch_id,name,phone,initial_balance,loyalty_points) VALUES (1, ?, ?, 27.50, 13)');
    $query->execute(['codex-transition-sentinel',bin2hex(random_bytes(10))]);
    $query=$db->prepare('INSERT INTO settings (`key`,`value`) VALUES (?,?)');
    $query->execute(['codex_transition_sentinel',bin2hex(random_bytes(24))]);
}
$schema=[];
foreach($db->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"')->fetchAll(PDO::FETCH_NUM) as $entry) {
    $name=$entry[0];$create=$db->query('SHOW CREATE TABLE `'.str_replace('`','``',$name).'`')->fetch(PDO::FETCH_NUM)[1];
    $schema[$name]=preg_replace('/AUTO_INCREMENT=\d+\s*/','',$create);
}
ksort($schema);
echo json_encode(['schema_sha256'=>hash('sha256',json_encode($schema)),'ledger'=>$db->query('SELECT version FROM schema_versions ORDER BY version')->fetchAll(PDO::FETCH_COLUMN),'business_rows'=>$db->query("SELECT id,name,phone,initial_balance,loyalty_points FROM customers WHERE name='codex-transition-sentinel' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),'settings'=>$db->query("SELECT `key`,`value` FROM settings WHERE `key`='codex_transition_sentinel'")->fetchAll(PDO::FETCH_ASSOC)],JSON_THROW_ON_ERROR);
