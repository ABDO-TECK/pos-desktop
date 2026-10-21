# Stable/Beta Channel Transitions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement explicit, signed, data-compatible stable/beta transitions, retire active rc support, and publish only a tested beta update.

**Architecture:** Keep channel preference separate from installed application identity. Dedicated catalog, compatibility, preview, receipt and transition services validate a narrowly scoped signed transition before using the existing locked Electron handoff. Normal updates retain their newer-version requirement; complete application packages require the unchanged compatible desktop shell.

**Tech Stack:** Existing PHP 8.2 services/PDO/MariaDB, React/TypeScript/Zustand, Electron handoff, Node regression scripts and Windows GitHub Actions; no new product dependency.

**Spec:** `docs/superpowers/specs/2026-10-21-channel-transitions-design.md` (approved; rc retirement added by explicit request).

## Global Constraints

- Public publication: beta only on `ABDO-TECK/pos-desktop-updates`. Existing `ABDO-TECK/pos-desktop` Setup `v0.0.1` and all five assets remain unchanged.
- Candidate `0.0.10-beta.5`; verify availability immediately before choosing an immutable tag. Keep installer/package shell version `0.0.1`.
- Active channels are exactly `stable` and `beta`. Historical rc versions/events remain readable and are never reclassified as active stable/beta releases.
- Migrate a saved rc preference to stable only; do not install, change `version.json`, erase telemetry or touch business SQL as part of that migration.
- Preserve the exact v0.0.1 shell fingerprint: no delta changes to `electron/`, `build/`, root `package.json`, root `package-lock.json` or `scripts/desktop-runtime.json`.
- For the new transition contract, `shell_sha256` hashes a canonical path/hash map of the actual installed `resources/app.asar` and `POS Desktop.exe`, measured from the verified Setup. Keep this runtime digest distinct from the existing deployment inventory's Git-source shell fingerprint; do not compare the two digest schemes.
- Full package means approved application artifacts on that shell. A different shell requires a separately compatible Setup and blocks internal installation.
- No ordinary manifest downgrade, unsigned fallback, down-migrations, automatic SQL restore over current sales, uninstaller invocation or `force` bypass of security/compatibility.
- Original install/data are never test targets. Capture fresh protected-file receipts at execution time; use isolated SQL fixtures. Do not use a live datadir hash as proof of unchanged database contents.
- Secrets stay in GitHub Actions. No new public stable release, replacement Setup, secret extraction, or mocked-download claim as live GitHub proof.
- Returning to historical versions restores their original UI, including historical rc controls. New UI/rc-removal guarantees apply to the corrected beta.
- Test commands use PHP 8.2 (the verified bundled PHP locally, `setup-php` in CI). No dependency installation or product testing is performed merely to author this plan.

## Review Focus

1. Old saved rc preference plus an installed beta must become stable *preference* without changing installed identity (Task 2).
2. Missing delta must not turn an invalid source hash into an authorized full-package replacement (Tasks 3/4).
3. Two tabs, expired previews, channel changes or a newly published target must not install an unconfirmed target or replay a token (Task 5).
4. Automatic startup migrations in the older destination must not alter current business data after a nominally files-only return (Tasks 1/4/8).
5. A retired rc tag, draft release or malformed page must not enter either active channel through classification/fallback (Tasks 2/3).

## Ownership and contracts

New production files, each with one responsibility:

- `backend/Services/UpdateChannelPolicy.php`: active-channel validation, installed channel classification and saved-preference migration.
- `backend/Services/ChannelTransitionReleaseCatalog.php`: resolve target identity across the two approved repositories and locate signed transition assets.
- `backend/Services/ChannelTransitionManifestService.php`: verify transition signature, carrier/target association, inventories, package selection and data contract.
- `backend/Services/UpdateDataCompatibilityService.php`: read-only canonical schema and `schema_versions` assessment.
- `backend/Services/ChannelTransitionPreviewStore.php`: atomic, expiring, single-use previews, stored below application storage.
- `backend/Services/ChannelTransitionService.php`: orchestrate preview, locked preparation and existing desktop handoff.
- `backend/Services/ChannelTransitionReceiptService.php`, `backend/cli/verify-channel-transition.php`: independent verification receipt across a return to older backend code; capability-gated by Task 1.
- `backend/Controllers/ChannelTransitionController.php`: thin authenticated API boundary.
- `frontend/src/hooks/useChannelTransition.ts`, `frontend/src/pages/settings/ChannelTransitionPanel.tsx`: preview state and explicit UI; existing settings page integrates them.

Shared PHP array contracts (document with PHPDoc; TypeScript mirrors them):

- `Installed = {version:string, channel:'stable'|'beta'|'retired', shell_sha256:string}`.
- `TransitionTarget = {version:string, channel:'stable'|'beta', repo:'pos-desktop'|'pos-desktop-updates', tag:string, inventory_sha256:string}`.
- `Preview = {status:'ready'|'same_release'|'waiting'|'blocked'|'unreachable', installed:Installed, selected_channel:string, target:?TransitionTarget, package_type:?'delta'|'full', preview_id:?string, expires_at:?string, reason_code:?string, message:string}`.
- `Prepared = {ok:bool, code:int, requires_desktop_handoff?:bool, handoff_version?:string, receipt_id?:string, logs:list<string>, error?:string}`.
- Preview IDs: 32 random bytes as 64 lowercase hex characters; lifetime 600 seconds; bind user ID, installed inventory hash, chosen channel, target identity, signed descriptor digest and data-contract digest. Never accept a client URL, manifest path or target version in place of this binding.
- Signed index `channel-transitions.json` + `.sig` lists per-source descriptors. Each signed descriptor contains `operation=channel_transition`, source version/channel/inventory, carrier tag, target repository/tag/version/channel/inventory, `shell_sha256`, package kind/size/hash/asset name, file hashes/sizes/deletions and `data_compatibility`. Resolve asset URLs from approved GitHub asset records, not arbitrary descriptor URLs.

---

### Task 1: Prove immutable-shell execution and post-return verification

**Files:** Create `scripts/verify-channel-transition-capability.mjs`, `scripts/fixtures/channel-transition-capability.php`. Read existing `electron/main.js`, `electron/services/delta-update-handoff.js`, `backend/Services/MigrationService.php`; do not modify them for this probe.

**Interfaces:** Produces an external `capability.json` recording child-process survival, actual stop/restart, exact target inventory, destination startup schema/ledger, unchanged shell and data-fixture checks. Later tasks may proceed only with all required capabilities true.

- [ ] Write a failing isolated probe which starts from extracted verified Setup 0.0.1, stages signed candidate-like application files through existing handoff, and has an independent PHP receipt process survive PHP-server/worker shutdown. Use only `RUNNER_TEMP` paths and fresh runner application storage; reject existing POS data before launch.

```js
assert.equal(receipt.survived_handoff, true);
assert.equal(receipt.target_inventory_matches, true);
assert.deepEqual(after.businessRows, before.businessRows);
```
- [ ] Run on ephemeral Windows: `node scripts/verify-channel-transition-capability.mjs <isolated-app> <evidence-dir>`; initially expect missing verifier support/capability failure, never success inferred from a PID.
- [ ] Prove a bounded verifier launch using the bundled PHP executable and hidden process facilities; all arguments are allowlisted task-owned paths, never credentials. Test real process-tree termination behavior, target-version code lacking new endpoints, and a destination missing expected runtime files.
- [ ] Assert `receipt.survived_handoff===true`, all target SHA-256 values match, destination startup leaves normalized schema/ledger and fixture row values unchanged, and v0.0.1 shell fingerprint matches. Record failures and do not publish or silently require a changed shell if proof fails; report that concrete blocker before choosing a new design.
- [ ] Commit only reproducible probe/support fixtures: `test: establish channel transition runtime capability`.

### Task 2: Retire rc on active surfaces and migrate preferences safely

**Files:** Create `UpdateChannelPolicy.php`, `scripts/verify-active-channels.php`. Modify `UpdateService.php`, `UpdateController.php`, `UpdateManifestService.php`, `GitHubReleaseProvider.php`, `UpdateTelemetryService.php`, `frontend/src/types/settings.d.ts`, `UpdateSection.tsx`, `FleetDashboard.tsx`; update rc-positive channel tests and publishing validators listed below.

**Interfaces:** `UpdateChannelPolicy::active():array` -> `['stable','beta']`; `normalizePreference(?string):string`; `installedChannel(string):string`; `migratePreference(string $file):array` -> `{channel, migrated_from:?string}`. No SQL or installed-version writes in preference migration.

- [ ] Write failing assertions: saved `rc` normalizes atomically to stable; installed beta remains beta; setting rc returns HTTP 400; a corrupt preference uses stable without overwriting unrelated content; missing storage path errors are visible. Preserve historical telemetry rows and aggregate retired channels under `legacy_channel_distribution`, not active selectors.

```php
assert(UpdateChannelPolicy::active() === ['stable', 'beta']);
assert(UpdateChannelPolicy::normalizePreference('rc') === 'stable');
assert(UpdateChannelPolicy::installedChannel('0.0.10-beta.5') === 'beta');
```
- [ ] Run `php scripts/verify-active-channels.php` and `php scripts/verify-release-channels.php`; expect failures for currently accepted rc and UI/API lists of three channels.
- [ ] Implement policy and replace active enums/options. Skip `-rc.` tags explicitly before prerelease classification; normal beta may continue consuming stable/beta according to existing policy, while explicit transition targets match the selected channel. Generic historical semver parsing continues accepting old rc identities. Telemetry ingestion can retain old rc event labels without enabling rc updates.
- [ ] Update `scripts/release-metadata.mjs`, `scripts/validate-release-source.mjs`, `scripts/build-release-package.php`, `scripts/verify-release-package.php`, `scripts/build-delta-release.ps1`, `scripts/verify-desktop-release.ps1`, `scripts/verify-installer-package.mjs`, `scripts/check-update-infrastructure.php` and `.github/workflows/windows-setup.yml` so new rc publications/metadata fail before signing; keep historical inspection explicit. Extend `scripts/verify-release-channels.php` to assert rc-only feeds yield no active candidate, drafts are ignored, and rc is never treated as stable. Run existing channel-policy tests too; leave immutable Electron files unchanged.
- [ ] Run `php scripts/verify-active-channels.php`, `php scripts/verify-release-channels.php`, `node scripts/verify-update-channel-policy.cjs`, `npm --prefix frontend run typecheck`; commit green deliverable: `feat: retire active rc channel without deleting history`.

### Task 3: Discover the actual selected-channel target across repositories

**Files:** Create `ChannelTransitionReleaseCatalog.php`, `scripts/verify-transition-catalog.php`. Modify `GitHubReleaseProvider.php` with fixed-repository metadata entrypoints; preserve its ordinary update-repository redirect behavior.

**Interfaces:** `GitHubReleaseProvider::getLatestSetupRelease():array` reads only `ABDO-TECK/pos-desktop` stable metadata; `listChannelCandidates(string $channel,int $page=1):array` -> `{ok,releases,next_page,error}`; `getReleaseByTag(string $tag):array` reads the updates repository. `ChannelTransitionReleaseCatalog::resolve(string $channel,array $installed):array` -> `{ok,target:?TransitionTarget,carrier:?array,reason_code:?string}`; `$installed` has the shared `Installed` shape.

- [ ] Write failing fixtures for updates repo lacking stable, source repo containing original Setup 0.0.1, beta newer than stable, stable newer than the installed beta, pagination, malformed records, two repositories advertising different stable versions, and a new stable lacking installable transport.

```php
$result = $catalog->resolve('stable', $installedBeta5);
assert($result['target']['tag'] === 'v0.0.1');
assert($result['target']['repo'] === 'pos-desktop');
```
- [ ] Run `php scripts/verify-transition-catalog.php`; expect the missing catalog failure.
- [ ] Implement highest compatible target selection before choosing transport; missing transport must not silently select an older target. Do not instantiate the existing provider with `repo=pos-desktop` and assume it stays there: its constructor currently redirects that name to updates. New fixed Setup entrypoint must reuse approved TLS/host transport without weakening normal repository isolation.
- [ ] Prefer an exact-source signed delta; otherwise locate a signed complete application package for that same target. A bad source hash blocks the transition, rather than qualifying for full fallback. Cover draft/rc/unsupported-generation exclusion, cache partitioning by installed inventory and selected channel, and network errors distinct from no release.
- [ ] Run catalog and existing repository-isolation tests; commit: `feat: resolve channel transition targets across release repositories`.

### Task 4: Verify signed transition packages and live data compatibility

**Files:** Create `ChannelTransitionManifestService.php`, `UpdateDataCompatibilityService.php`, `scripts/verify-transition-manifest.php`, `scripts/verify-transition-data.php`. Reuse `ManifestSignatureService.php`, path protection from `UpdateManifestService.php`, and `schema_versions` semantics from `MigrationService.php`.

**Interfaces:** `ChannelTransitionManifestService::verify(string $raw,string $signature,array $carrier,array $installed,array $target):array` -> `{ok,descriptor:?array,reason_code:?string}`; installed/target arrays use the shared shapes. `UpdateDataCompatibilityService::describe(PDO $db):array`; `assess(PDO $db,array $contract,bool $downgrade):array` -> `{compatible:bool,schema_sha256:string,ledger_sha256:string,reason_code:?string}`.

- [ ] Write failing tests: wrong RSA key, carrier/tag/target substitution, duplicate ZIP paths, protected data paths, source-inventory mismatch, complete-package shell mismatch, source `rc` disguised as stable, and normal manifest downgrade still rejected. Verify both delta and full descriptors retain exact source authority.

```php
assert(!$manifests->verify($tamperedRaw, $signature, $carrier, $installed, $target)['ok']);
assert(!$compatibility->assess($schemaMismatchDb, $contract, true)['compatible']);
```
- [ ] Run `php scripts/verify-transition-manifest.php` and `php scripts/verify-transition-data.php`; expect missing-service failures.
- [ ] Implement canonical schema extraction with stable ordering of owned tables, columns, indexes, constraints and executable schema objects, excluding volatile counters/statistics and environment-specific definers. Bind `schema_versions` filename/content semantics to the signed compatibility contract. Missing ledger, unsupported schema differences and unreadable metadata fail closed. Assert known fixture row values and auto-increment changes do not falsely alter the structural fingerprint.
- [ ] Require signed source/destination startup migration compatibility, not just matching `database/pos_schema.sql`. Test an older backend starting against the fixture after return: it must not opportunistically run undeclared schema changes. Keep downgrade manifests migration-free; forward migrations retain existing declared-migration safety.
- [ ] Run both new scripts and `node scripts/verify-rollback-flow.mjs`; commit: `feat: validate signed transition authority and data schema compatibility`.

### Task 5: Add expiring previews and locked transition preparation

**Files:** Create `ChannelTransitionPreviewStore.php`, `ChannelTransitionService.php`, `ChannelTransitionReceiptService.php`, `backend/cli/verify-channel-transition.php`, `scripts/verify-channel-transition-flow.mjs`, `scripts/verify-channel-transition-flow.php`. Reuse `DeltaUpdateService`, `UpdateOperationLock` and verified backup facilities; do not add a second file replacement engine.

**Interfaces:** Preview store `issue(int $userId,array $binding):array`, `claim(string $id,int $userId,array $binding):array`. Transition service `preview(int $userId,string $selectedChannel):array` returns `Preview`; `prepare(int $userId,string $previewId,bool $deltaCapable):array` returns `Prepared`. Receipt service `start(array $verifiedPlan):array`, `read(string $receiptId):array`; CLI consumes a task-owned verified plan path and emits an atomic receipt.

- [ ] Write failing flow tests for stable->beta, beta->older stable, wait/no installation, empty/invalid ID, wrong user, 600-second expiry, two concurrent requests, channel/target changing after preview, active sale, insufficient space, and tampering after staging.

```php
assert($service->prepare($otherUserId, $previewId, true)['ok'] === false);
assert($service->prepare($userId, $expiredPreviewId, true)['ok'] === false);
assert($service->prepare($userId, $changedTargetPreviewId, true)['ok'] === false);
```
- [ ] Run `node scripts/verify-channel-transition-flow.mjs`; expect missing preparation support, with no original paths in fixtures.
- [ ] Implement atomic issue/claim under application storage, check owner and all binding fields again under the update lock, and revalidate remote target authority and current schema after acquiring it. Reject stale previews with a new-preview message. Do not accept URL/version/path overrides or let force bypass the gate.
- [ ] Prepare through the existing downloader/staging/handoff. Retain a verified SQL backup for manual recovery without attaching automatic DB restore to a files-only handoff; mark operation snapshot `rollback_safety`. Start the independently surviving verifier proven in Task 1, retaining only bounded signed plan inputs. A receipt reports success only after exact target inventory and post-start data checks; a mismatch must be visible through existing update-state/status surfaces even when target code is old. Existing handoff exceptions recover source files; a post-start mismatch records `failed`/`requires_recovery`, never completed, and must not overwrite later business data.
- [ ] Run flow plus existing deletion/download/rollback failure tests; verify introduced artifacts are removed, untouched file identity is preserved and SQL fixture rows/settings remain equal; commit: `feat: prepare authenticated channel transitions through desktop handoff`.

### Task 6: Connect thin APIs and explicit channel transition UI

**Files:** Create `ChannelTransitionController.php`, `frontend/src/hooks/useChannelTransition.ts`, `frontend/src/pages/settings/ChannelTransitionPanel.tsx`. Modify `backend/routes/admin.php`, `backend/Controllers/UpdateController.php` status composition, `frontend/src/api/endpoints.ts`, `frontend/src/types/settings.d.ts`, `UpdateSection.tsx`, `updateStore.ts`. Create `scripts/verify-transition-api.php`, `scripts/verify-transition-ui.mjs`.

**Interfaces:** `POST /api/updates/transition/preview` body `{}` uses server-saved channel and returns `Preview`; `POST /api/updates/transition/apply` body `{preview_id,delta_capable}` returns `Prepared`. Preview requires authenticated `updates.check`; apply requires both `updates.apply` and `updates.manage_channel`, using existing CSRF and rate limits (10/min preview, 2/300s apply). Hook returns `{preview,loading,applying,refresh,start,wait}`; component consumes it plus installed/selected identity.

- [ ] Write failing API/UI tests for unauthorized roles, csrf/unknown fields, stale preview after a selector change, wait after reopening, target version lower/equal/higher, missing transport, legacy rc migration notice and installed beta with selected stable. Assert channel selection alone produces zero install requests.

```js
assert.equal(screen.installedChannel, 'beta');
assert.equal(screen.selectedChannel, 'stable');
assert.equal(requests.filter(r => r.path === '/api/updates/transition/apply').length, 0);
```
- [ ] Run `php scripts/verify-transition-api.php`, `node scripts/verify-transition-ui.mjs`, `npm --prefix frontend run typecheck`; expect missing endpoints/panel failure.
- [ ] Implement thin input validation/routes and service composition; no raw SQL or manifest handling in controllers. Keep existing regular check/apply semantics. Status explicitly returns installed channel and selected channel; the transition hook must not turn ordinary background update checks into downgrade offers or run concurrent installs.
- [ ] Show `الإصدار المثبّت`, `قناة متابعة التحديثات`, selected target, package method, readiness/blocking reason, `الانتقال إلى stable` / `الانتقال إلى beta`, and `البقاء على نسختي وانتظار stable أحدث`. Confirmation states source/target and older UI/features returning. Use existing theme variables/confirm/toast/restart suppression; true errors remain visible. No rc selector/card for active channels. Avoid describing a complete app bundle as a Setup installer.
- [ ] Run API/UI tests, TypeScript, accepted lint and frontend build; commit: `feat: show explicit stable and beta transition previews`.

### Task 7: Build signed transition assets without publishing stable

**Files:** Create `scripts/build-channel-transition-package.php`, `scripts/verify-channel-transition-package.php`, `scripts/verify-transition-publication.mjs`. Modify `scripts/build-delta-release.ps1`. Modify the actual publisher `.github/workflows/delta-release.yml` in the managed `pos-desktop-updates` checkout, not an assumed mirror in this source repo.

**Interfaces:** Builder CLI `--source-inventory=<candidate> --target-inventory=<stable> --target-app=<verified-extracted-setup> --data-contract=<json> --carrier-tag=<beta-tag> --output-dir=<dir>` emits signed index/descriptors and exact-source delta/full ZIPs. Verifier CLI takes `--release-dir=<dir>` and rejects association, contents or signature errors.

- [ ] Write failing package/publication fixtures: copied payload not matching the original stable inventory, missing carrier/source binding, changed protected shell, legacy rc tag, accidental stable publication and full ZIP including user files.

```js
assert.equal(candidate.prerelease, true);
assert.equal(publishedNewStableRelease, false);
assert.deepEqual(setupAssetsAfter, setupAssetsBefore);
```
- [ ] Run `node scripts/verify-transition-publication.mjs`; expect missing builder/gates failure.
- [ ] Build the stable target from the original hash-verified Setup and inventory; require all application hashes to match it. Build source contracts from actual candidate deployment inventory and isolated migrated SQL, never customer data. Emit changed-only delta and complete approved-app ZIP, with deletion lists relative to candidate inventory and no shell/user artifacts. Keep keys in existing CI secret handling.
- [ ] Gate publication on tests and immutable source tag; package normal beta baselines `v0.0.1`, `v0.0.10-beta.1` through `.4` plus transition assets carried by the new beta. Preserve all original Setup asset IDs/hashes and `latest.yml`; no new `stable` release entry or RC metadata. If carrier assets cannot be inspected privately/locally before publication, do not publish to make the test pass.
- [ ] Verify all ZIP members/signatures and shell fingerprints in CI; commit source and publisher separately with their actual SHAs recorded: `build: produce signed channel transition assets on beta releases`.

### Task 8: Run actual isolated Windows transitions, then release and verify

**Files:** Create `scripts/prepare-channel-transition-ui.ps1`, `scripts/verify-channel-transition-ui.mjs`; reuse runtime/login helpers from `scripts/verify-rollback-ui.mjs` without weakening its original tests. Extend publisher gates and evidence uploads. Evidence lives outside the source tree and never includes real credentials or customer SQL.

**Interfaces:** `acceptance.json` includes exact signed source/target inventories, live/copied transport distinction, SQL row/settings/offline-sale assertions, shell fingerprint, failure receipts, RC retirement and `transitions:[stable1,betaCandidate,stable1,betaCandidate]`.

- [ ] Add failing acceptance assertions for the complete round trip using actual application windows, new transition buttons, wait/cancel, real errors and the historical stable UI on return. Fixture discovery is explicitly isolated; shipped routes/signature checks must not gain a testing bypass. Scroll relevant controls into view before screenshots and wait for rendered controls before clicking.

```js
assert.deepEqual(receipt.transitions, ['0.0.1', candidateVersion, '0.0.1', candidateVersion]);
assert.deepEqual(after.businessRows, before.businessRows);
assert.equal(receipt.newerArtifactsRemoved, true);
```
- [ ] On a fresh ephemeral Windows runner, verify original Setup hash, extract into `RUNNER_TEMP`, create isolated SQL/auth/customer/sale fixtures and preserve their normalized snapshots. Any existing POS ProgramData/AppData or unsafe path aborts the test before execution.
- [ ] Exercise delta first and full fallback with delta absent for the same verified source; test corrupt signature/ZIP, actual slow/interrupted download, schema mismatch, late apply failure, busy sale, expired preview and retired rc. After each successful transition, assert exact target files, absent newer assets, unchanged file identity where applicable, equal business SQL values, settings/offline sales intact, correct installed-vs-selected channel, and readable post-restart receipt. Returning Setup 0.0.1 uses its historical UI to choose beta again.
- [ ] Require all unit/integration/UI/package gates green, independent final code review, and original-protection receipts before public beta publication. Check tag availability before creating it; preserve failed evidence and never move a published tag. No tests on the owner's install or original database.
- [ ] Publish only the verified beta carrier; download its actual assets and validate GitHub digests/RSA/ZIP membership. Probe both real feeds from an isolated Setup baseline, require stable publication unchanged and beta offered appropriately, recheck all five Setup assets, merge the tested source without unrelated changes, and save an external final report with source/tag/publisher SHAs and CI URLs. Commit: `test: gate beta channel transitions on isolated Windows acceptance`.

## Self-review and handoff

- Coverage: identity/preference/rc migration Task 2; repositories Task 3; signed authority and schema Task 4; locking/previews/receipts Task 5; UI/API Task 6; packages Task 7; actual round trip and publication Task 8; immutable-shell feasibility Task 1.
- All five Review Focus cases have explicit assertions in their owning tasks. The normal updater's strict downgrade rejection and historical UI behavior remain intentional.
- Failures at runtime capability, data compatibility, shell fingerprint or original Setup preservation block publication; no unapproved downgrade in scope substitutes for those gates.
- The only changes made during planning are this plan and the approved spec's rc addendum. Product code, version tags, release assets and original data are untouched.
- Recommended execution: **Native** (same session), because the tasks share signed contracts and inventory formats; use one independent whole-branch reviewer and the isolated CI release gate. Subagent-driven execution is available if the owner prefers per-task independent reviews and the additional context cost.
- Await the owner's plan review and execution-method selection before implementation.
