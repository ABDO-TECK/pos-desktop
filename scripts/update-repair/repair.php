<?php
declare(strict_types=1);

// Invoked only after the launcher verifies the repair engine's RSA signature.
// All mutable state is kept in a separate recovery directory, never the DB.
try {
    [$script, $mode, $root, $work] = $argv;
    $root = str_replace('\\', '/', (string)realpath($root));
    if (!in_array($mode, ['prepare','apply'], true) || !$root || !is_dir($work)) throw new RuntimeException('Invalid repair arguments');
    putenv('APP_DEPLOY_ROOT='.$root);
    putenv('APP_STORAGE_DIR='.$work.'/state');
    $_ENV['APP_STORAGE_DIR'] = $work.'/state';
    // Never load or execute customer .env, router, migrations or bootstrap.
    if (!is_file($work.'/engine.phar') && !copy($root.'/backend/backend.phar', $work.'/engine.phar')) throw new RuntimeException('Cannot copy installed update engine');
    require 'phar://'.str_replace('\\','/',$work).'/engine.phar/vendor/autoload.php';
    require __DIR__.'/UpdateAssetDownloader.php';
    $policy = new App\Services\UpdateManifestService();
    $signature = new App\Services\ManifestSignatureService(__DIR__.'/update_public_key.pem');
    $current = json_decode((string)file_get_contents($root.'/version.json'), true, 512, JSON_THROW_ON_ERROR)['version'];
    if (!in_array($current, ['0.0.1','0.0.10-beta.1'], true)) throw new RuntimeException('Repair supports only 0.0.1 and 0.0.10-beta.1');
    $target = '0.0.10-beta.2';
    $base = 'https://github.com/ABDO-TECK/pos-desktop-updates/releases/download/v'.$target.'/';
    $names = ['manifest-'.$current.'.json','manifest-'.$current.'.sig','delta-'.$current.'-to-'.$target.'.zip'];
    $download = new App\Services\UpdateAssetDownloader($root.'/backend/certs/cacert.pem');
    $assets = $argv[4] ?? '';
    foreach ($names as $name) {
        if ($mode !== 'prepare') continue;
        if ($assets !== '') {
            if (!copy($assets.'/'.$name,$work.'/'.$name)) throw new RuntimeException('Cannot read offline signed asset '.$name);
        } else {
            echo 'Downloading '.$name."\n";
            $result=$download->download($base.$name,$work.'/'.$name);
            if (!$result['ok']) throw new RuntimeException($result['error']);
        }
        // Verify the manifest before downloading the large ZIP.
        if ($name === $names[1] && !$signature->verifyManifestFile($work.'/'.$names[0],$work.'/'.$names[1],__DIR__.'/update_public_key.pem')['ok']) {
            throw new RuntimeException('RSA manifest signature rejected');
        }
    }
    if (!$signature->verifyManifestFile($work.'/'.$names[0],$work.'/'.$names[1],__DIR__.'/update_public_key.pem')['ok']) throw new RuntimeException('RSA manifest signature rejected');
    $validated=$policy->validateManifest((string)file_get_contents($work.'/'.$names[0]));
    if (!$validated['valid']) throw new RuntimeException('Invalid signed manifest');
    $manifest=$validated['manifest'];
    if (($manifest['type']??'') !== 'delta' || empty($manifest['baseline_files']) || !empty($manifest['migrations'])) throw new RuntimeException('Repair requires a baseline-specific delta without migrations');
    if (!$policy->checkReleaseAssociation($manifest,$target,'v'.$target,'beta')['compatible'] || !$policy->checkVersionCompatibility($current,$manifest)['compatible']) throw new RuntimeException('Wrong repair target or baseline');
    if (!$policy->validateManifestPaths($manifest,$root)['ok'] || !$policy->verifyBaselineFiles($root,$manifest)) throw new RuntimeException('Installed files differ from the signed baseline');
    // Narrower than the general updater: repair cannot change DB schema/certs.
    foreach (array_merge(array_column($manifest['files'],'path'),$manifest['deleted_files']??[]) as $relative) {
        if ($relative !== 'version.json' && $relative !== 'backend/backend.phar' && !str_starts_with($relative,'frontend/dist/')) throw new RuntimeException('Unexpected repair target');
    }
    $delta=new App\Services\DeltaUpdateService($policy,$root,$work.'/state');
    if ($mode === 'prepare') {
        $zip=new ZipArchive();
        if ($zip->open($work.'/'.$names[2]) !== true) throw new RuntimeException('Cannot open downloaded delta ZIP');
        $expected=array_fill_keys(array_column($manifest['files'],'path'),true);
        $seen=[];
        for ($i=0;$i<$zip->numFiles;$i++) {
            $entry=$zip->getNameIndex($i);
            if (str_ends_with($entry,'/')) continue;
            $entry=preg_replace('#^files/#','',$entry);
            if (!isset($expected[$entry])) throw new RuntimeException('Unexpected ZIP entry');
            if (isset($seen[$entry])) throw new RuntimeException('Duplicate ZIP entry');
            $seen[$entry]=true;
        }
        $zip->close();
        $stage=$delta->extractZipToStaging($work.'/'.$names[2],$delta->getStagingDir($target));
        if (!$stage['ok']) throw new RuntimeException(implode('; ',$stage['errors']));
    }
    if (!$policy->verifyStagedFiles($delta->getStagingDir($target),$manifest['files'])['ok']) throw new RuntimeException('Staged payload hash mismatch');
    if ($mode === 'apply') {
        $snapshot=$delta->createBackupSnapshot($current,$target,$manifest);
        if (!$snapshot['ok']) throw new RuntimeException($snapshot['error']);
        $result=$delta->applyStagedFiles($manifest,$snapshot['snapshot_path']);
        if (!$result['ok']) throw new RuntimeException(json_encode($result,JSON_UNESCAPED_UNICODE));
        if (!$policy->verifyBaselineFiles($root,['baseline_files'=>array_column($manifest['files'],'sha256','path')])) {
            $rollback=$delta->rollbackFiles($snapshot['snapshot_path']);
            throw new RuntimeException('Post-apply verification failed; rollback: '.json_encode($rollback));
        }
        file_put_contents($work.'/result.json',json_encode(['ok'=>true,'from'=>$current,'to'=>$target,'snapshot'=>$snapshot['snapshot_path'],'applied'=>$result['applied_files']],JSON_PRETTY_PRINT));
    }
    echo strtoupper($mode)." verified successfully. Recovery directory: ".$work."\n";
} catch (Throwable $error) {
    fwrite(STDERR,'Repair stopped: '.$error->getMessage()."\n"); exit(1);
}
