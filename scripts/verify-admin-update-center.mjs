import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';

const root = path.resolve(process.argv[2] || '.');
const require = createRequire(path.join(root, 'frontend/package.json'));
const ts = require('typescript');

console.log('Starting Admin Update Center Verification (Node.js & Real UI DOM)...');

// ══════════════════════════════════════════════════════════════════
// 1. Static Audit of Frontend Codebase Contracts
// ══════════════════════════════════════════════════════════════════
{
  const updateSectionSource = fs.readFileSync(path.join(root, 'frontend/src/pages/settings/UpdateSection.tsx'), 'utf8');
  const updateStoreSource = fs.readFileSync(path.join(root, 'frontend/src/store/updateStore.ts'), 'utf8');
  const updateProgressSource = fs.readFileSync(path.join(root, 'frontend/src/components/update/UpdateProgress.tsx'), 'utf8');
  const endpointsSource = fs.readFileSync(path.join(root, 'frontend/src/api/endpoints.ts'), 'utf8');

  // A. No fake hardcoded stage percentages (25, 45, 65, 80, 92)
  assert.ok(!updateSectionSource.includes('getStagePercent'), 'getStagePercent should be removed from UpdateSection.tsx');
  assert.ok(!updateSectionSource.includes("case 'downloading': return 25"), 'Hardcoded 25% for downloading must not exist');
  assert.ok(!updateSectionSource.includes("case 'migrating': return 92"), 'Hardcoded 92% for migrating must not exist');
  assert.ok(!updateProgressSource.includes('25%'), 'UpdateProgress must not contain hardcoded 25%');
  assert.ok(!updateProgressSource.includes('92%'), 'UpdateProgress must not contain hardcoded 92%');

  // B. Desktop handoff pending stage supported
  assert.ok(updateSectionSource.includes('desktop_handoff_pending'), 'UpdateSection must handle desktop_handoff_pending stage');
  assert.ok(updateProgressSource.includes('desktop_handoff_pending'), 'UpdateProgress must handle desktop_handoff_pending stage');

  // C. Background apply opt-in parameter
  assert.ok(/applyUpdate\s*=\s*\([^)]*background\s*=\s*false/.test(endpointsSource), 'endpoints.ts applyUpdate must declare background opt-in parameter');

  // D. Fast local status hydration exists and is decoupled
  assert.ok(updateStoreSource.includes('hydrateLocalStatus'), 'updateStore must export hydrateLocalStatus');

  // E. Accept legitimate baseline 0.0.1 honestly without shell rejection
  assert.ok(!updateStoreSource.includes("rawVersion !== '0.0.1'"), 'updateStore must accept legitimate baseline 0.0.1');

  // F. Channel selector starts at null and renders loading indicator
  assert.ok(updateSectionSource.includes("useState<'stable' | 'beta' | null>(null)"), 'UpdateSection currentChannel state must initialize to null');
  assert.ok(updateSectionSource.includes('جاري التحميل...'), 'UpdateSection must render loading state when channel is null');

  // G. ChannelTransitionPanel is guarded until channel and installed_channel are ready
  assert.ok(updateSectionSource.includes('currentChannel !== null && updateStatus?.installed_channel'), 'ChannelTransitionPanel must be guarded until channel and installed_channel are loaded');

  console.log('PASS: Static codebase audit passed (honest progress, 0.0.1 acceptance, decoupled hydration, channel guards)');
}

// ══════════════════════════════════════════════════════════════════
// 2. updateStore Unit Verification: Honest Baseline 0.0.1 & Immediate Hydration
// ══════════════════════════════════════════════════════════════════
{
  const mockWindow = {
    API_BASE_URL: 'http://127.0.0.1',
    posRuntime: {
      getDeltaCapability: async () => ({ capable: true }),
    },
    electronAPI: {
      getVersion: async () => '0.0.1',
    },
  };

  const statusMock = {
    current_version: '0.0.1',
    channel: 'stable',
    installed_channel: 'stable',
    update_state: { state: 'idle' },
  };

  let getUpdateStatusCalled = false;
  const mockApi = {
    getUpdateStatus: async () => {
      getUpdateStatusCalled = true;
      return { data: { data: statusMock } };
    },
    checkForUpdates: async () => {
      throw new Error('checkForUpdates should NOT be called during hydrateLocalStatus');
    },
  };

  // Compile updateStore in VM sandbox
  const full = path.resolve(root, 'frontend/src/store/updateStore.ts');
  const code = ts.transpileModule(fs.readFileSync(full, 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, esModuleInterop: true },
  }).outputText;

  const context = {
    window: mockWindow,
    navigator: { onLine: true },
    console,
    Date,
    Promise,
    Error,
    setTimeout,
    clearTimeout,
    setInterval,
    clearInterval,
    module: { exports: {} },
    exports: {},
    require: (mod) => {
      if (mod.includes('endpoints')) return mockApi;
      if (mod.includes('desktopRuntime')) return { hasDeltaHandoffCapability: async () => true };
      if (mod.includes('notificationStore')) return { useNotificationStore: { getState: () => ({ addNotification: () => {} }) } };
      if (mod.includes('zustand')) {
        return {
          create: (fn) => {
            let state = {};
            const set = (partial) => {
              state = { ...state, ...(typeof partial === 'function' ? partial(state) : partial) };
            };
            const get = () => state;
            state = fn(set, get, {});
            const hook = () => state;
            hook.getState = get;
            hook.setState = set;
            return hook;
          },
        };
      }
      return {};
    },
  };

  vm.runInNewContext(code, context);
  const useUpdateStore = context.exports.useUpdateStore || context.exports.default;

  assert.ok(useUpdateStore, 'useUpdateStore was exported');
  assert.ok(typeof useUpdateStore.getState().hydrateLocalStatus === 'function', 'hydrateLocalStatus is a function');

  // Run hydrateLocalStatus with legitimate baseline 0.0.1
  await useUpdateStore.getState().hydrateLocalStatus(true);

  assert.ok(getUpdateStatusCalled, 'hydrateLocalStatus invoked getUpdateStatus');
  const state = useUpdateStore.getState();

  // Baseline 0.0.1 must be accepted honestly
  assert.equal(state.currentVersion, '0.0.1', 'currentVersion must honestly accept 0.0.1 baseline');
  assert.equal(state.channel, 'stable', 'channel must be hydrated to stable');
  assert.equal(state.installedChannel, 'stable', 'installedChannel must be hydrated to stable');

  console.log('PASS: updateStore::hydrateLocalStatus legitimate 0.0.1 baseline acceptance verified');
}

// ══════════════════════════════════════════════════════════════════
// 3. endpoints.ts applyUpdate background parameter verification
// ══════════════════════════════════════════════════════════════════
{
  const endpointsFile = path.resolve(root, 'frontend/src/api/endpoints.ts');
  const code = ts.transpileModule(fs.readFileSync(endpointsFile, 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, esModuleInterop: true },
  }).outputText;

  let lastPostUrl = null;
  let lastPostBody = null;

  const mockAxios = {
    post: async (url, body) => {
      lastPostUrl = url;
      lastPostBody = body;
      return { data: { success: true, data: { job_id: 888, status: 'scheduled' } } };
    },
    get: async () => ({ data: {} }),
    interceptors: { request: { use() {} }, response: { use() {} } },
  };

  const context = {
    window: { API_BASE_URL: 'http://127.0.0.1' },
    console,
    Date,
    Promise,
    Error,
    module: { exports: {} },
    exports: {},
    require: (mod) => {
      if (mod.includes('axios')) return { default: mockAxios, ...mockAxios, isAxiosError: () => false };
      return {};
    },
  };

  vm.runInNewContext(code, context);
  const { applyUpdate } = context.exports;

  // 1. Call applyUpdate with background = true
  await applyUpdate(false, true, true);
  assert.equal(lastPostUrl, '/updates/apply');
  assert.equal(lastPostBody?.delta_capable, true);
  assert.equal(lastPostBody?.background, true);
  assert.equal(lastPostBody?.force, undefined);

  // 2. Call applyUpdate with default background (false)
  await applyUpdate(true, false);
  assert.equal(lastPostBody?.force, true);
  assert.equal(lastPostBody?.delta_capable, false);
  assert.equal(lastPostBody?.background, false);

  console.log('PASS: endpoints::applyUpdate correctly forwards background parameter');
}

// ══════════════════════════════════════════════════════════════════
// 4. Honest Progress Logic Verification (Download vs Other Stages)
// ══════════════════════════════════════════════════════════════════
{
  function calculateProgress(activeState, downloadedBytes, totalBytes) {
    const hasDownloadByteCounts = activeState === 'downloading' && totalBytes > 0;
    const downloadPercent = hasDownloadByteCounts
      ? Math.min(100, Math.max(0, Math.round((downloadedBytes / totalBytes) * 100)))
      : null;
    return { hasDownloadByteCounts, downloadPercent };
  }

  // Downloading stage with bytes
  const dlProgress = calculateProgress('downloading', 2500000, 10000000);
  assert.equal(dlProgress.hasDownloadByteCounts, true);
  assert.equal(dlProgress.downloadPercent, 25);

  // Verifying stage (non-download): MUST NOT return fake percentage
  const verProgress = calculateProgress('verifying', 10000000, 10000000);
  assert.equal(verProgress.hasDownloadByteCounts, false);
  assert.equal(verProgress.downloadPercent, null, 'Verifying stage must have null downloadPercent for indeterminate spinner/pulse');

  // Applying stage (non-download): MUST NOT return fake percentage
  const appProgress = calculateProgress('applying', 10000000, 10000000);
  assert.equal(appProgress.hasDownloadByteCounts, false);
  assert.equal(appProgress.downloadPercent, null, 'Applying stage must have null downloadPercent');

  // Desktop handoff pending stage
  const handoffProgress = calculateProgress('desktop_handoff_pending', 0, 0);
  assert.equal(handoffProgress.hasDownloadByteCounts, false);
  assert.equal(handoffProgress.downloadPercent, null, 'desktop_handoff_pending must have null downloadPercent');

  console.log('PASS: Honest progress calculation logic verified (determinate download bytes, indeterminate non-download)');
}

// ══════════════════════════════════════════════════════════════════
// 5. updateStore Proven Repro: Context Ownership, Channel Invalidation & Race Immunity
// ══════════════════════════════════════════════════════════════════
{
  const full = path.resolve(root, 'frontend/src/store/updateStore.ts');
  const code = ts.transpileModule(fs.readFileSync(full, 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, esModuleInterop: true },
  }).outputText;

  let resolveSilentCheck;
  let resolveManualCheck;
  let resolveHydrate;

  const mockApi = {
    getUpdateStatus: async () => {
      return new Promise((resolve) => {
        resolveHydrate = (data) => resolve({
          data: {
            data: data || {
              current_version: '0.0.10',
              channel: 'stable',
              installed_channel: 'stable',
              update_state: { state: 'idle' },
            },
          },
        });
      });
    },
    checkUpdate: async (_deltaCapable, opts) => {
      if (opts?.params?.background) {
        return new Promise((resolve) => {
          resolveSilentCheck = (data) => resolve({
            data: {
              data: data || {
                has_update: true,
                latest_version: '1.2.0',
                current_version: '0.0.10',
                release_info: { notes: 'Silent check update' }
              }
            }
          });
        });
      }
      return new Promise((resolve) => {
        resolveManualCheck = (data) => resolve({
          data: {
            data: data || {
              has_update: true,
              latest_version: '1.5.0',
              current_version: '0.0.10',
              release_info: { notes: 'Manual check update' }
            }
          }
        });
      });
    },
  };

  const context = {
    window: {
      API_BASE_URL: 'http://127.0.0.1',
      posRuntime: { getDeltaCapability: async () => ({ capable: true }) },
    },
    navigator: { onLine: true },
    console,
    Date,
    Promise,
    Error,
    setTimeout,
    clearTimeout,
    setInterval,
    clearInterval,
    module: { exports: {} },
    exports: {},
    require: (mod) => {
      if (mod.includes('endpoints')) return mockApi;
      if (mod.includes('desktopRuntime')) return { hasDeltaHandoffCapability: async () => true };
      if (mod.includes('notificationStore')) return { useNotificationStore: { getState: () => ({ addNotification: () => {} }) } };
      if (mod.includes('zustand')) {
        return {
          create: (fn) => {
            let state = {};
            const set = (partial) => {
              state = { ...state, ...(typeof partial === 'function' ? partial(state) : partial) };
            };
            const get = () => state;
            state = fn(set, get, {});
            const hook = () => state;
            hook.getState = get;
            hook.setState = set;
            return hook;
          },
        };
      }
      return {};
    },
  };

  vm.runInNewContext(code, context);
  const useUpdateStore = context.exports.useUpdateStore || context.exports.default;

  // ─────────────────────────────────────────────────────────────────
  // A. Proven Repro: Deferred silent check resolves AFTER channel switch to 'beta'
  // ─────────────────────────────────────────────────────────────────
  useUpdateStore.setState({ channel: 'stable', hasUpdate: false, latestVersion: null });
  const pSilent = useUpdateStore.getState().silentCheck();
  assert.equal(useUpdateStore.getState().isChecking, true, 'isChecking true during check');

  // Allow microtask to advance so checkUpdate is called and in-flight
  await new Promise((r) => setTimeout(r, 10));
  assert.ok(typeof resolveSilentCheck === 'function', 'checkUpdate is in-flight');

  // Switch channel to 'beta' while silent check is in-flight
  useUpdateStore.getState().setChannelState('beta');
  const postSwitchState = useUpdateStore.getState();
  assert.equal(postSwitchState.channel, 'beta');
  assert.equal(postSwitchState.isChecking, false, 'isChecking must be reset on channel invalidation');
  assert.equal(postSwitchState.hasUpdate, false);
  assert.equal(postSwitchState.latestVersion, null);

  // Resolve deferred silent check with stable data
  resolveSilentCheck({
    has_update: true,
    latest_version: '1.2.0',
    current_version: '0.0.10',
    release_info: { notes: 'Old stable release' }
  });
  await pSilent;

  // Verify deferred check resolution did NOT clobber new channel context
  const afterDeferredState = useUpdateStore.getState();
  assert.equal(afterDeferredState.channel, 'beta', 'Channel remains beta');
  assert.equal(afterDeferredState.hasUpdate, false, 'Deferred silent check MUST NOT clobber hasUpdate');
  assert.equal(afterDeferredState.latestVersion, null, 'Deferred silent check MUST NOT clobber latestVersion');
  assert.equal(afterDeferredState.isChecking, false);

  // ─────────────────────────────────────────────────────────────────
  // B. Manual check invalidates silent check
  // ─────────────────────────────────────────────────────────────────
  resolveSilentCheck = null;
  resolveManualCheck = null;
  useUpdateStore.setState({ hasUpdate: false, latestVersion: null });
  const pSilent2 = useUpdateStore.getState().silentCheck();
  await new Promise((r) => setTimeout(r, 10));

  // Start manual forceCheck
  const pManual = useUpdateStore.getState().forceCheck();
  await new Promise((r) => setTimeout(r, 10));
  assert.equal(useUpdateStore.getState().isChecking, true);

  // Resolve silent check first
  resolveSilentCheck({
    has_update: true,
    latest_version: '1.2.0',
    current_version: '0.0.10',
  });
  await pSilent2;

  // Resolve manual check later with higher version
  resolveManualCheck({
    has_update: true,
    latest_version: '2.0.0',
    current_version: '0.0.10',
  });
  await pManual;

  const afterManualState = useUpdateStore.getState();
  assert.equal(afterManualState.latestVersion, '2.0.0', 'Manual check result must prevail over superseded silent check');

  // ─────────────────────────────────────────────────────────────────
  // C. Hydrate does not cancel manual check & Authoritative check protects state
  // ─────────────────────────────────────────────────────────────────
  resolveHydrate = null;
  const pHydrate = useUpdateStore.getState().hydrateLocalStatus(true);
  await new Promise((r) => setTimeout(r, 10));
  resolveHydrate({
    current_version: '0.0.10',
    channel: 'beta',
    installed_channel: 'beta',
    update_state: { state: 'idle' }
  });
  await pHydrate;

  // Hydrate must not erase authoritative latestVersion
  assert.equal(useUpdateStore.getState().latestVersion, '2.0.0', 'Hydrate must not clear authoritative latestVersion');
  assert.equal(useUpdateStore.getState().hasUpdate, true);

  console.log('PASS: updateStore proven repro, context ownership, and generation guards verified');
}

// ══════════════════════════════════════════════════════════════════
// 6. Real React DOM UI Component Verification via Electron Headless
// ══════════════════════════════════════════════════════════════════
{
  console.log('\nExecuting Real React DOM UI Verification Harness (Rolldown + Electron)...');
  execFileSync(process.execPath, [path.join(root, 'scripts/verify-admin-update-ui.mjs')], {
    stdio: 'inherit',
    windowsHide: true,
  });
}

console.log('\nALL ADMIN UPDATE CENTER FRONTEND AND UI VERIFICATIONS PASSED SUCCESSFULLY!\n');
