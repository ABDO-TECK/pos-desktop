<?php
declare(strict_types=1);
namespace App\Services;

use RuntimeException;
use Throwable;

final class ChannelTransitionReleaseCatalog
{
    public function __construct(private GitHubReleaseProvider $provider) {}

    /** @return array{ok:bool,target?:?array,carrier?:?array,carriers?:array<array>,reason_code?:?string,error?:string} */
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
            usort($candidates, static function(array $a, array $b): int {
                $vA = ltrim($a['release']['tag_name'], 'vV');
                $vB = ltrim($b['release']['tag_name'], 'vV');
                $cmp = version_compare($vB, $vA);
                if ($cmp !== 0) return $cmp;
                // Deterministic tie-breaker for equal versions: pos-desktop-updates takes deterministic precedence over pos-desktop
                return ($a['repo'] === 'pos-desktop-updates' ? 0 : 1) <=> ($b['repo'] === 'pos-desktop-updates' ? 0 : 1);
            });
            if (!$candidates) return ['ok'=>true,'target'=>null,'carrier'=>null,'carriers'=>[],'reason_code'=>'no_target'];
            $selected = $candidates[0]; $release = $selected['release'];
            $version = ltrim($release['tag_name'], 'vV');
            $target = ['version'=>$version,'channel'=>$channel,'repo'=>$selected['repo'],'tag'=>$release['tag_name'],'inventory_sha256'=>'','inventory'=>[],'release'=>$release];
            $asset = self::asset($release, 'deployment-inventory.json');
            if ($asset === null) return ['ok'=>true,'target'=>$target,'carrier'=>null,'carriers'=>[],'reason_code'=>'target_inventory_unavailable'];
            $fetched = $this->provider->fetchAssetContent($asset['browser_download_url']);
            if (!$fetched['ok'] || !is_string($fetched['content']??null)) throw new RuntimeException('Destination inventory download failed.');
            $inventory = json_decode($fetched['content'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($inventory) || ($inventory['version']??null)!==$version || !is_array($inventory['files']??null) || !$inventory['files']) throw new RuntimeException('Destination inventory does not match release.');
            $target = ['version'=>$version,'channel'=>$channel,'repo'=>$selected['repo'],'tag'=>$release['tag_name'],'inventory_sha256'=>hash('sha256',$fetched['content']),'inventory'=>$inventory,'release'=>$release];

            $carriers = $this->discoverCarriers($target, $installed);
            $carrier = $carriers[0] ?? null;
            return ['ok'=>true,'target'=>$target,'carrier'=>$carrier,'carriers'=>$carriers,'reason_code'=>$carrier===null?'transition_package_unavailable':null];
        } catch (Throwable $error) {
            return ['ok'=>false,'target'=>null,'carrier'=>null,'carriers'=>[],'reason_code'=>'discovery_failed','error'=>$error->getMessage()];
        }
    }

    /**
     * Discovers bounded, deterministic carrier release candidates across trusted
     * source and target releases across both canonical repositories, independent
     * of version ordering or semver direction.
     * Probes unseen source-tag/repo and target-tag/repo even with source cached,
     * deduping actual repo+tag across at most 4 canonical pairs and reusing known releases.
     *
     * @return array<array>
     */
    public function discoverCarriers(array $target, array $installed): array
    {
        $candidates = [];
        $seen = [];

        $targetRelease = $target['release'] ?? null;
        $targetRepo = $target['repo'] ?? 'pos-desktop-updates';
        $targetVersion = $target['version'] ?? (isset($target['tag']) ? ltrim($target['tag'], 'vV') : null);
        $targetTag = $target['tag'] ?? ($targetVersion !== null ? 'v' . $targetVersion : null);

        $sourceRelease = $installed['release'] ?? null;
        $installedVersion = $installed['version'] ?? null;
        $installedChannel = $installed['channel'] ?? ($installedVersion !== null ? UpdateChannelPolicy::installedChannel($installedVersion) : 'stable');
        $sourceRepo = $installed['source_repo'] ?? $installed['repo'] ?? ($installedChannel === 'stable' ? 'pos-desktop' : 'pos-desktop-updates');
        $sourceTag = $installedVersion !== null ? 'v' . $installedVersion : null;

        $altTargetRepo = $targetRepo === 'pos-desktop' ? 'pos-desktop-updates' : 'pos-desktop';
        $altSourceRepo = $sourceRepo === 'pos-desktop' ? 'pos-desktop-updates' : 'pos-desktop';

        // Exactly 4 canonical pairs independent of channel: [targetRepo, altTargetRepo] x [targetTag], [sourceRepo, altSourceRepo] x [sourceTag]
        $pairs = [];
        if ($targetTag !== null) {
            $pairs[] = ['repo' => $targetRepo, 'tag' => $targetTag, 'cached' => $targetRelease];
            $pairs[] = ['repo' => $altTargetRepo, 'tag' => $targetTag, 'cached' => null];
        }
        if ($sourceTag !== null && ReleaseVersionPolicy::isValid($installedVersion ?? '')) {
            $pairs[] = ['repo' => $sourceRepo, 'tag' => $sourceTag, 'cached' => $sourceRelease];
            $pairs[] = ['repo' => $altSourceRepo, 'tag' => $sourceTag, 'cached' => null];
        }

        foreach ($pairs as $item) {
            $repo = $item['repo'];
            $tag = $item['tag'];
            $key = $repo . ':' . $tag;
            if (isset($seen[$key])) continue;
            $seen[$key] = true;

            // Deduplicate cache properly: check if release is already in memory from target or source
            $cached = $item['cached'];
            if ($cached === null) {
                if ($sourceRelease !== null && ($sourceRelease['tag_name'] ?? '') === $tag && $sourceRepo === $repo) {
                    $cached = $sourceRelease;
                } elseif ($targetRelease !== null && ($targetRelease['tag_name'] ?? '') === $tag && $targetRepo === $repo) {
                    $cached = $targetRelease;
                }
            }

            if ($cached !== null && !($cached['draft'] ?? false) && is_string($cached['tag_name'] ?? null)) {
                $candidates[] = $cached;
                continue;
            }

            $result = $this->provider->getReleaseByTag($tag, $repo);
            if ($result['ok']) {
                if ($result['release'] !== null && !($result['release']['draft'] ?? false)) {
                    $candidates[] = $result['release'];
                }
            } else {
                $httpCode = (int)($result['http_code'] ?? 0);
                $error = (string)($result['error'] ?? '');
                // Distinguish absent release (HTTP 404 explicitly). Do not reclassify HTTP 500 by text even if error message contains "not found".
                if ($httpCode === 404) {
                    continue;
                }
                if ($httpCode === 0 && ($error === 'Release is unavailable.' || str_contains(strtolower($error), 'not found'))) {
                    continue;
                }
                throw new RuntimeException($error !== '' ? $error : 'Release discovery failed.');
            }
        }

        // Prioritize releases carrying valid transition manifests, maintaining deterministic order
        usort($candidates, static function (array $a, array $b): int {
            $aHas = (ChannelTransitionReleaseCatalog::asset($a, 'channel-transitions.json') !== null && ChannelTransitionReleaseCatalog::asset($a, 'channel-transitions.sig') !== null) ? 1 : 0;
            $bHas = (ChannelTransitionReleaseCatalog::asset($b, 'channel-transitions.json') !== null && ChannelTransitionReleaseCatalog::asset($b, 'channel-transitions.sig') !== null) ? 1 : 0;
            if ($aHas !== $bHas) return $bHas <=> $aHas;
            return strcmp($b['tag_name'] ?? '', $a['tag_name'] ?? '');
        });

        return $candidates;
    }

    public static function asset(array $release, string $name): ?array
    {
        $matches = array_values(array_filter($release['assets']??[], static fn($asset):bool => is_array($asset) && ($asset['name']??null)===$name));
        if (count($matches)!==1 || !is_string($matches[0]['browser_download_url']??null)) return null;
        return $matches[0];
    }
}
