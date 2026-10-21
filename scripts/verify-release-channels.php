<?php
declare(strict_types=1);
require __DIR__ . '/../backend/vendor/autoload.php';
use App\Services\GitHubReleaseProvider;
function expect(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
final class FixtureReleaseProvider extends GitHubReleaseProvider
{
    public array $requests = [];
    public function __construct(private array $releases) { parent::__construct(token: ''); }
    protected function executeCurlGet(string $url, array $headers = [], bool $followRedirects = false): array
    {
        $this->requests[] = $url;
        $latest = str_ends_with($url, '/latest');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $page = (int) ($query['page'] ?? 1);
        return ['ok' => !$latest, 'http_code' => $latest ? 404 : 200, 'body' => $latest ? '{}' : json_encode(array_slice($this->releases, ($page - 1) * 100, 100)), 'curl_error' => '', 'curl_errno' => 0, 'response_headers' => []];
    }
}
function release(string $version, bool $prerelease, string $baseline = '0.0.2'): array
{
    $base = "https://github.com/ABDO-TECK/pos-desktop-updates/releases/download/v{$version}/";
    $names = ["delta-{$baseline}-to-{$version}.zip", "manifest-{$baseline}.json", "manifest-{$baseline}.sig"];
    return ['tag_name' => 'v'.$version, 'prerelease' => $prerelease, 'draft' => false, 'assets' => array_map(static fn ($n) => ['name'=>$n,'browser_download_url'=>$base.$n], $names)];
}
$provider = new FixtureReleaseProvider([release('0.0.4-beta.1', true), release('0.0.3', false), release('0.0.3-rc.1', true)]);
expect($provider->getRepo() === 'pos-desktop-updates', 'Default repository must be the dedicated update repository.');
$stable = $provider->getLatestRelease('stable', '0.0.2');
$beta = $provider->getLatestRelease('beta', '0.0.2');
$rc = $provider->getLatestRelease('rc', '0.0.2');
expect(empty($rc['ok']), 'Retired RC request was accepted.');
expect($stable['latest_version'] === '0.0.3', 'Stable received a beta.');
expect($beta['latest_version'] === '0.0.4-beta.1', 'Beta did not receive newest beta.');
expect(str_ends_with($beta['manifest_url'] ?? '', 'manifest-0.0.2.json'), 'Baseline-specific signed manifest not selected.');
$onlyRc = (new FixtureReleaseProvider([release('0.0.3-rc.1', true)]))->getLatestRelease('beta', '0.0.2');
expect(!empty($onlyRc['no_eligible_release']), 'Retired RC prerelease offered on beta.');
$draft=release('0.0.5-beta.1',true);$draft['draft']=true;
expect(!empty((new FixtureReleaseProvider([$draft]))->getLatestRelease('beta','0.0.2')['no_eligible_release']),'Draft release offered');
$wrongBaseline = (new FixtureReleaseProvider([release('0.0.3', false, '0.0.1')]))->getLatestRelease('stable', '0.0.2');
expect(!empty($wrongBaseline['no_eligible_release']), 'Delta for another baseline was offered.');
$paged = new FixtureReleaseProvider(array_merge(array_fill(0, 100, release('0.0.4-beta.1', true)), [release('0.0.3', false)]));
expect($paged->getLatestRelease('stable', '0.0.2')['latest_version'] === '0.0.3', 'Stable release hidden behind beta page.');
expect(count($paged->requests) === 2, 'Release enumeration did not reach second page.');
$policy = new App\Services\UpdateManifestService();
expect(!$policy->checkReleaseAssociation(['version'=>'0.0.4-beta.1','channel'=>'beta'], '0.0.3', 'v0.0.3', 'stable')['compatible'], 'Signed beta asset accepted under a stable tag.');
expect(!$policy->checkReleaseAssociation(['version'=>'0.0.4-beta.1','channel'=>'beta'], '0.0.4-beta.1', 'v0.0.4-beta.1', 'stable')['compatible'], 'Stable apply accepted beta channel.');
expect($policy->checkReleaseAssociation(['version'=>'0.0.3','channel'=>'stable'], '0.0.3', 'v0.0.3', 'beta')['compatible'], 'Beta cannot promote to stable.');
foreach ($provider->requests as $url) expect(str_contains($url, '/ABDO-TECK/pos-desktop-updates/'), 'Request reached the wrong repository.');
echo "Release channels verified: stable/beta only, RC/drafts excluded, baseline selection and repository isolation.\n";

