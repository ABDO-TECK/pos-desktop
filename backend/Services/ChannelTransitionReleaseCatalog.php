<?php
declare(strict_types=1);
namespace App\Services;

use RuntimeException;
use Throwable;

final class ChannelTransitionReleaseCatalog
{
    public function __construct(private GitHubReleaseProvider $provider) {}

    /** @return array{ok:bool,target?:?array,carrier?:?array,reason_code?:?string,error?:string} */
    public function resolve(string $channel, array $installed): array
    {
        try {
            if (!in_array($channel, UpdateChannelPolicy::active(), true)) throw new RuntimeException('Inactive channel.');
            $candidates = [];
            for ($page = 1; $page <= 10; $page++) {
                $result = $this->provider->listChannelCandidates($channel, $page);
                if (!$result['ok']) throw new RuntimeException($result['error'] ?? 'Release discovery failed.');
                foreach ($result['releases'] as $release) {
                    if (ReleaseVersionPolicy::assess($installed['version'], ltrim($release['tag_name'],'vV'))['compatible']) $candidates[] = ['repo'=>'pos-desktop-updates','release'=>$release];
                }
                if ($result['next_page'] === null) break;
                if ($page === 10) throw new RuntimeException('Release pagination limit exceeded.');
            }
            if ($channel === 'stable') {
                $setup = $this->provider->getLatestSetupRelease($installed['version']);
                if (!$setup['ok']) throw new RuntimeException($setup['error'] ?? 'Setup discovery failed.');
                if ($setup['release'] !== null) $candidates[] = ['repo'=>'pos-desktop','release'=>$setup['release']];
            }
            usort($candidates, static fn(array $a,array $b): int => version_compare(ltrim($b['release']['tag_name'],'vV'),ltrim($a['release']['tag_name'],'vV')));
            if (!$candidates) return ['ok'=>true,'target'=>null,'carrier'=>null,'reason_code'=>'no_target'];
            $selected = $candidates[0]; $release = $selected['release'];
            $version = ltrim($release['tag_name'], 'vV');
            $target = ['version'=>$version,'channel'=>$channel,'repo'=>$selected['repo'],'tag'=>$release['tag_name'],'inventory_sha256'=>'','inventory'=>[],'release'=>$release];
            $asset = self::asset($release, 'deployment-inventory.json');
            if ($asset === null) return ['ok'=>true,'target'=>$target,'carrier'=>null,'reason_code'=>'target_inventory_unavailable'];
            $fetched = $this->provider->fetchAssetContent($asset['browser_download_url']);
            if (!$fetched['ok'] || !is_string($fetched['content']??null)) throw new RuntimeException('Destination inventory download failed.');
            $inventory = json_decode($fetched['content'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($inventory) || ($inventory['version']??null)!==$version || !is_array($inventory['files']??null) || !$inventory['files']) throw new RuntimeException('Destination inventory does not match release.');
            $target = ['version'=>$version,'channel'=>$channel,'repo'=>$selected['repo'],'tag'=>$release['tag_name'],'inventory_sha256'=>hash('sha256',$fetched['content']),'inventory'=>$inventory,'release'=>$release];
            $carrier = $release;
            if (version_compare($version, $installed['version'], '<')) {
                $source = $this->provider->getReleaseByTag('v'.$installed['version']);
                $carrier = $source['ok'] ? $source['release'] : null;
            }
            return ['ok'=>true,'target'=>$target,'carrier'=>$carrier,'reason_code'=>$carrier===null?'transition_package_unavailable':null];
        } catch (Throwable $error) {
            return ['ok'=>false,'target'=>null,'carrier'=>null,'reason_code'=>'discovery_failed','error'=>$error->getMessage()];
        }
    }

    public static function asset(array $release, string $name): ?array
    {
        $matches = array_values(array_filter($release['assets']??[], static fn($asset):bool => is_array($asset) && ($asset['name']??null)===$name));
        if (count($matches)!==1 || !is_string($matches[0]['browser_download_url']??null)) return null;
        return $matches[0];
    }
}
