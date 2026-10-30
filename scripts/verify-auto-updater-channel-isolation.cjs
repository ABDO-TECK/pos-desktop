const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { EventEmitter } = require('node:events');
const Module = require('node:module');

// Guard: Run only under GitHub Actions CI environment. Throw rather than fake green before fixtures.
if (process.env.GITHUB_ACTIONS !== 'true') {
  throw new Error('AutoUpdater Channel Isolation test must run under GitHub Actions CI environment only.');
}

// Preserve original runtime references for guaranteed restoration in finally
const originalModuleLoad = Module._load;
const originalFetch = global.fetch;
const originalSetInterval = global.setInterval;
const originalClearInterval = global.clearInterval;
const originalEnv = { ...process.env };

// Set up clean isolated temporary test directory
const testRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'pos-updater-test-'));
const programDataBase = path.join(testRoot, 'ProgramData');
const appDataBase = path.join(testRoot, 'AppData', 'Roaming');
const localAppDataBase = path.join(testRoot, 'AppData', 'Local');
const testAppUnpacked = path.join(testRoot, 'app.asar.unpacked');

fs.mkdirSync(programDataBase, { recursive: true });
fs.mkdirSync(appDataBase, { recursive: true });
fs.mkdirSync(localAppDataBase, { recursive: true });
fs.mkdirSync(testAppUnpacked, { recursive: true });

// Isolate environment variables before requiring paths or services
process.env.PROGRAMDATA = programDataBase;
process.env.APPDATA = appDataBase;
process.env.LOCALAPPDATA = localAppDataBase;

let currentNativeVersion = '0.0.13';
let dialogResponse = 0;
let dialogHook = null;
let downloadPromiseHook = null;
const ipcHandlers = {};
const rendererBroadcasts = [];

const mockWindow = {
  isDestroyed: () => false,
  setProgressBar: () => {},
  webContents: {
    send: (channel, payload) => {
      rendererBroadcasts.push({ channel, payload: JSON.parse(JSON.stringify(payload)) });
    },
  },
};

const mockElectron = {
  app: {
    isPackaged: true,
    getVersion: () => currentNativeVersion,
    getAppPath: () => testAppUnpacked,
    getPath: (name) => {
      if (name === 'appData') return appDataBase;
      if (name === 'userData') return path.join(appDataBase, 'POS System');
      return path.join(testRoot, name);
    },
  },
  dialog: {
    showMessageBox: async (win, opts) => {
      if (typeof dialogHook === 'function') {
        await dialogHook();
      }
      return { response: dialogResponse };
    },
  },
  ipcMain: {
    handle: (channel, handler) => {
      ipcHandlers[channel] = handler;
    },
  },
};

class MockAutoUpdater extends EventEmitter {
  constructor() {
    super();
    this.autoDownload = false;
    this.autoInstallOnAppQuit = false;
    this.logger = null;
    this.channel = null;
    this.allowPrerelease = false;
    this.allowDowngrade = false;
    this.feedUrl = null;
    this.checkForUpdatesCalls = 0;
    this.downloadUpdateCalls = 0;
    this.quitAndInstallCalls = 0;
  }

  setFeedURL(options) {
    this.feedUrl = options;
  }

  async checkForUpdates() {
    this.checkForUpdatesCalls++;
    return null;
  }

  async downloadUpdate() {
    this.downloadUpdateCalls++;
    if (typeof downloadPromiseHook === 'function') {
      return new Promise((resolve, reject) => {
        downloadPromiseHook(resolve, reject);
      });
    }
    // Default positive behavior: emit matching update-downloaded event with version from feedUrl
    const feedUrl = this.feedUrl?.url || '';
    const match = feedUrl.match(/\/download\/v?([^/]+)\//);
    const version = match ? decodeURIComponent(match[1]).replace(/^v/i, '') : null;
    if (version) {
      this.emit('update-downloaded', { version });
    }
    return [];
  }

  quitAndInstall(isSilent, isForceRunAfter) {
    this.quitAndInstallCalls++;
  }
}

const mockAutoUpdater = new MockAutoUpdater();
const mockElectronUpdater = {
  autoUpdater: mockAutoUpdater,
};

// Module mock interceptor
Module._load = function (request, parent, isMain) {
  if (request === 'electron') return mockElectron;
  if (request === 'electron-updater') return mockElectronUpdater;
  return originalModuleLoad(request, parent, isMain);
};

async function runTests() {
  const coordination = require('../electron/utils/update-coordination');
  const realWriteUpdateStateIfOwned = coordination.writeUpdateStateIfOwned;
  let injectHook = null;

  // Wrap coordination.writeUpdateStateIfOwned before requiring auto-updater service
  // so auto-updater's destructured reference calls our wrapper with injectHook
  coordination.writeUpdateStateIfOwned = function (storageDir, state, context, expectedOwner) {
    if (typeof injectHook === 'function') {
      injectHook(storageDir, state, context, expectedOwner);
    }
    return realWriteUpdateStateIfOwned.call(coordination, storageDir, state, context, expectedOwner);
  };

  try {
    const { getDataDir } = require('../electron/utils/paths');
    const testStorageDir = getDataDir();
    // Validate that getDataDir resolves to nested POS System under isolated PROGRAMDATA
    assert.equal(testStorageDir, path.join(programDataBase, 'POS System'), 'getDataDir must resolve to nested POS System');
    fs.mkdirSync(testStorageDir, { recursive: true });

    const autoUpdaterService = require('../electron/services/auto-updater');

    const updateChannelFile = path.join(testStorageDir, 'update_channel.json');
    const versionFile = path.join(testAppUnpacked, 'version.json');
    const lockFile = path.join(testStorageDir, 'update-operation.lock');
    const stateFile = path.join(testStorageDir, 'update-state.json');

    const setClientChannel = (ch) => {
      fs.writeFileSync(updateChannelFile, JSON.stringify({ channel: ch }));
    };
    const setDeployedVersion = (ver, ch = 'beta') => {
      fs.writeFileSync(versionFile, JSON.stringify({ version: ver, update_channel: ch }));
    };

    autoUpdaterService.setupAutoUpdater(mockWindow);

    // =========================================================================
    // Case 1: Retained Native Shell 13 with Deployed Beta 12.1 rejects Stable 13
    // =========================================================================
    setClientChannel('beta');
    setDeployedVersion('0.0.12-beta.1', 'beta');
    currentNativeVersion = '0.0.13';

    global.fetch = async () => ({
      ok: true,
      json: async () => [
        {
          tag_name: 'v0.0.13',
          draft: false,
          prerelease: false,
          assets: [{ name: 'latest.yml' }, { name: 'POS-Desktop-Setup-0.0.13.exe' }],
        },
        {
          tag_name: 'v0.0.12-beta.1',
          draft: false,
          prerelease: true,
          assets: [{ name: 'beta.yml' }],
        },
      ],
    });

    rendererBroadcasts.length = 0;
    const check1 = await autoUpdaterService.checkForUpdates();
    assert.equal(check1.state, 'update_not_available');
    assert.equal(check1.updateInfo, null);
    assert.equal(check1.canInstall, false);

    // Assert Beta UI renderer NEVER received a Stable 0.0.13 offer or progress
    for (const b of rendererBroadcasts) {
      assert.notEqual(b.payload?.updateInfo?.version, '0.0.13', 'Beta UI must never be offered Stable 0.0.13');
      assert.equal(b.payload?.canInstall, false, 'canInstall must remain false');
    }
    console.log('✓ Case 1 passed: Native Shell 13 with Deployed Beta 12.1 correctly rejects Stable 13');

    // =========================================================================
    // Case 2: Same-channel stale check race (Check A vs Check B)
    // =========================================================================
    setClientChannel('beta');
    setDeployedVersion('0.0.12-beta.1', 'beta');

    let resolveSlowFetch = null;
    let slowFetchStarted = false;

    global.fetch = async (url) => {
      if (!slowFetchStarted) {
        slowFetchStarted = true;
        return new Promise((resolve) => {
          resolveSlowFetch = () => resolve({
            ok: true,
            json: async () => [{
              tag_name: 'v0.0.14-beta.1',
              draft: false,
              prerelease: true,
              assets: [{ name: 'beta.yml' }],
            }],
          });
        });
      }
      return {
        ok: true,
        json: async () => [{
          tag_name: 'v0.0.15-beta.1',
          draft: false,
          prerelease: true,
          assets: [{ name: 'beta.yml' }],
        }],
      };
    };

    const promiseA = autoUpdaterService.checkForUpdates();
    const promiseB = autoUpdaterService.checkForUpdates();

    await promiseB;
    assert.equal(mockAutoUpdater.channel, 'beta');
    assert.ok(mockAutoUpdater.feedUrl?.url?.includes('0.0.15-beta.1'), 'Feed URL must point to newer Check B target');

    // Finish superseded Check A
    if (resolveSlowFetch) resolveSlowFetch();
    await promiseA;

    // Check A must not overwrite Check B feed target
    assert.ok(mockAutoUpdater.feedUrl?.url?.includes('0.0.15-beta.1'), 'Superseded Check A must not overwrite feed target');

    // Stale available event from Check A (0.0.14-beta.1) must be ignored
    mockAutoUpdater.emit('update-available', { version: '0.0.14-beta.1' });
    assert.notEqual(autoUpdaterService.getUpdaterStatus().updateInfo?.version, '0.0.14-beta.1', 'Stale 0.0.14 event must be ignored');

    // Matching available event from Check B (0.0.15-beta.1) must be accepted
    mockAutoUpdater.emit('update-available', { version: '0.0.15-beta.1' });
    assert.equal(autoUpdaterService.getUpdaterStatus().state, 'update_available');
    assert.equal(autoUpdaterService.getUpdaterStatus().updateInfo?.version, '0.0.15-beta.1');
    console.log('✓ Case 2 passed: Same-channel stale async check does not corrupt active job');

    // =========================================================================
    // Case 3: Channel changes WHILE download is pending -> aborted, no ready
    // =========================================================================
    setClientChannel('beta');
    setDeployedVersion('0.0.12-beta.1', 'beta');

    global.fetch = async () => ({
      ok: true,
      json: async () => [{
        tag_name: 'v0.0.14-beta.1',
        draft: false,
        prerelease: true,
        assets: [{ name: 'beta.yml' }],
      }],
    });

    await autoUpdaterService.checkForUpdates();
    mockAutoUpdater.emit('update-available', { version: '0.0.14-beta.1' });
    assert.equal(autoUpdaterService.getUpdaterStatus().state, 'update_available');

    // Hook downloadUpdate to switch channel while in flight
    downloadPromiseHook = (resolve) => {
      assert.ok(fs.existsSync(stateFile), 'State file must exist during download');
      const inFlightState = JSON.parse(fs.readFileSync(stateFile, 'utf8'));
      assert.equal(inFlightState.state, 'downloading');
      assert.equal(inFlightState.operation, 'electron_full_download');

      // Operator switches channel to stable during inflight download
      setClientChannel('stable');
      mockAutoUpdater.emit('update-downloaded', { version: '0.0.14-beta.1' });
      resolve([]);
    };

    const abortDownloadRes = await autoUpdaterService.downloadUpdate();
    downloadPromiseHook = null;

    assert.equal(abortDownloadRes.state, 'error', 'Inflight channel change must abort download with error');
    assert.equal(abortDownloadRes.canInstall, false);

    // Verify state was marked failed, NOT full_ready_to_install
    const stateAfterAbort = JSON.parse(fs.readFileSync(stateFile, 'utf8'));
    assert.equal(stateAfterAbort.state, 'failed');
    assert.equal(stateAfterAbort.reason_code, 'channel_mismatch');
    assert.ok(!fs.existsSync(lockFile), 'Download lock must be released on abort');
    console.log('✓ Case 3 passed: Channel change while download pending cleanly aborts without ready or heartbeat');

    // =========================================================================
    // Case 4: Remote events during pending download: ignore mismatched, require matching
    // =========================================================================
    setClientChannel('beta');
    setDeployedVersion('0.0.12-beta.1', 'beta');

    // 4a. Negative: Wrong Stable 13 event ONLY, then resolve -> error, no ready, no install
    try { fs.unlinkSync(stateFile); } catch {}
    try { fs.unlinkSync(lockFile); } catch {}
    await autoUpdaterService.checkForUpdates();
    mockAutoUpdater.emit('update-available', { version: '0.0.14-beta.1' });
    assert.equal(autoUpdaterService.getUpdaterStatus().state, 'update_available');

    downloadPromiseHook = (resolve) => {
      mockAutoUpdater.emit('update-downloaded', { version: '0.0.13' });
      resolve([]);
    };
    const wrongStableRes = await autoUpdaterService.downloadUpdate();
    downloadPromiseHook = null;
    assert.equal(wrongStableRes.state, 'error', 'Wrong stable event must settle download as error');
    assert.equal(wrongStableRes.canInstall, false);
    const state4a = JSON.parse(fs.readFileSync(stateFile, 'utf8'));
    assert.equal(state4a.state, 'failed');
    assert.ok(!fs.existsSync(lockFile), 'Lock released on failed download');

    // 4b. Negative: Wrong SAME channel Beta 15 event ONLY, then resolve -> error, no ready, no install
    try { fs.unlinkSync(stateFile); } catch {}
    try { fs.unlinkSync(lockFile); } catch {}
    await autoUpdaterService.checkForUpdates();
    mockAutoUpdater.emit('update-available', { version: '0.0.14-beta.1' });

    downloadPromiseHook = (resolve) => {
      mockAutoUpdater.emit('update-downloaded', { version: '0.0.15-beta.1' });
      resolve([]);
    };
    const wrongSameChanRes = await autoUpdaterService.downloadUpdate();
    downloadPromiseHook = null;
    assert.equal(wrongSameChanRes.state, 'error', 'Wrong same-channel beta event must settle download as error');
    assert.equal(wrongSameChanRes.canInstall, false);
    const state4b = JSON.parse(fs.readFileSync(stateFile, 'utf8'));
    assert.equal(state4b.state, 'failed');
    assert.ok(!fs.existsSync(lockFile), 'Lock released on failed download');

    // 4c. Negative: No event at all, then resolve -> error, missing_download_event
    try { fs.unlinkSync(stateFile); } catch {}
    try { fs.unlinkSync(lockFile); } catch {}
    await autoUpdaterService.checkForUpdates();
    mockAutoUpdater.emit('update-available', { version: '0.0.14-beta.1' });

    downloadPromiseHook = (resolve) => {
      resolve([]);
    };
    const noEventRes = await autoUpdaterService.downloadUpdate();
    downloadPromiseHook = null;
    assert.equal(noEventRes.state, 'error', 'Resolving without update-downloaded event must settle download as error');
    assert.equal(noEventRes.canInstall, false);
    const state4c = JSON.parse(fs.readFileSync(stateFile, 'utf8'));
    assert.equal(state4c.state, 'failed');
    assert.equal(state4c.reason_code, 'missing_download_event');
    assert.ok(!fs.existsSync(lockFile), 'Lock released on missing event download');

    // 4d. Positive: Mismatched events arrive first, but then matching 0.0.14-beta.1 arrives before resolve
    try { fs.unlinkSync(stateFile); } catch {}
    try { fs.unlinkSync(lockFile); } catch {}
    await autoUpdaterService.checkForUpdates();
    mockAutoUpdater.emit('update-available', { version: '0.0.14-beta.1' });

    downloadPromiseHook = (resolve) => {
      mockAutoUpdater.emit('update-downloaded', { version: '0.0.15-beta.1' });
      mockAutoUpdater.emit('update-downloaded', { version: '0.0.13' });
      mockAutoUpdater.emit('update-downloaded', {});
      const stateDuringEvents = JSON.parse(fs.readFileSync(stateFile, 'utf8'));
      assert.equal(stateDuringEvents.state, 'downloading', 'Mismatched events must not change downloading state');

      // Matching event arrives
      mockAutoUpdater.emit('update-downloaded', { version: '0.0.14-beta.1' });
      resolve([]);
    };
    const exactDownloadRes = await autoUpdaterService.downloadUpdate();
    downloadPromiseHook = null;

    assert.equal(exactDownloadRes.state, 'ready_to_install');
    assert.equal(exactDownloadRes.canInstall, true);
    assert.equal(exactDownloadRes.updateInfo?.version, '0.0.14-beta.1');
    const stateAfterMatch = JSON.parse(fs.readFileSync(stateFile, 'utf8'));
    assert.equal(stateAfterMatch.state, 'full_ready_to_install');
    assert.equal(stateAfterMatch.target_version, '0.0.14-beta.1');
    console.log('✓ Case 4 passed: Mismatched and missing events rejected; matching event promoted to ready');

    // =========================================================================
    // Case 5: Foreign state replaces own state during pending download -> files byte unchanged
    // =========================================================================
    setClientChannel('beta');
    setDeployedVersion('0.0.12-beta.1', 'beta');
    try { fs.unlinkSync(stateFile); } catch {}
    try { fs.unlinkSync(lockFile); } catch {}

    await autoUpdaterService.checkForUpdates();
    mockAutoUpdater.emit('update-available', { version: '0.0.14-beta.1' });

    let foreignStateBytes = null;
    let foreignLockBytes = null;

    downloadPromiseHook = (resolve) => {
      // A foreign delta update operation takes over the state and lock
      const foreignState = {
        state: 'applying',
        operation: 'delta_apply',
        owner_id: 'delta-worker-uuid-777',
        target_version: '0.0.14-beta.1',
        started_at: new Date().toISOString(),
        updated_at: new Date().toISOString(),
      };
      const foreignLock = {
        owner_id: 'delta-worker-uuid-777',
        operation: 'delta_apply',
        pid: process.pid + 100,
        time: Math.floor(Date.now() / 1000),
      };
      fs.writeFileSync(stateFile, JSON.stringify(foreignState, null, 2) + '\n');
      fs.writeFileSync(lockFile, JSON.stringify(foreignLock) + '\n');

      foreignStateBytes = fs.readFileSync(stateFile);
      foreignLockBytes = fs.readFileSync(lockFile);

      resolve([]);
    };

    const foreignClashRes = await autoUpdaterService.downloadUpdate();
    downloadPromiseHook = null;

    assert.equal(foreignClashRes.state, 'error', 'Ownership loss must settle download as error');

    // Assert foreign state and lock bytes are EXACTLY UNCHANGED (not overwritten or released!)
    const currentFinalStateBytes = fs.readFileSync(stateFile);
    const currentFinalLockBytes = fs.readFileSync(lockFile);
    assert.deepEqual(currentFinalStateBytes, foreignStateBytes, 'Foreign state file must be byte unchanged');
    assert.deepEqual(currentFinalLockBytes, foreignLockBytes, 'Foreign lock file must be byte unchanged');
    console.log('✓ Case 5 passed: Foreign state takeover during download preserved byte-for-byte');

    // Clean up foreign fixture files
    try { fs.unlinkSync(lockFile); } catch {}
    try { fs.unlinkSync(stateFile); } catch {}

    // =========================================================================
    // Case 6: Heartbeat execution and foreign collision on interval tick
    // =========================================================================
    let capturedHeartbeatCallback = null;
    let capturedIntervalMs = null;
    global.setInterval = (cb, ms) => {
      capturedHeartbeatCallback = cb;
      capturedIntervalMs = ms;
      return { unref: () => {} };
    };

    setClientChannel('beta');
    setDeployedVersion('0.0.12-beta.1', 'beta');

    fs.writeFileSync(stateFile, JSON.stringify({
      state: 'full_ready_to_install',
      operation: 'electron_full_download',
      owner_id: 'heartbeat-owner-101',
      target_version: '0.0.14-beta.1',
      updated_at: new Date(Date.now() - 5000).toISOString(),
    }, null, 2) + '\n');

    autoUpdaterService.setupAutoUpdater(mockWindow);
    assert.equal(capturedIntervalMs, 30_000, 'Heartbeat interval must be 30 seconds');
    assert.equal(typeof capturedHeartbeatCallback, 'function', 'Heartbeat callback must be scheduled');

    // Execute first heartbeat tick: should refresh updated_at
    const timeBeforeTick = Date.now();
    const tickResult1 = capturedHeartbeatCallback();
    assert.equal(tickResult1, true, 'Heartbeat refresh must succeed when owned');

    const stateAfterTick1 = JSON.parse(fs.readFileSync(stateFile, 'utf8'));
    assert.ok(Date.parse(stateAfterTick1.updated_at) >= timeBeforeTick - 1000);

    // Foreign takeover of full_ready_to_install state
    const foreignHeartbeatState = {
      state: 'full_ready_to_install',
      operation: 'delta_apply',
      owner_id: 'foreign-delta-888',
      target_version: '0.0.14-beta.1',
      updated_at: new Date().toISOString(),
    };
    fs.writeFileSync(stateFile, JSON.stringify(foreignHeartbeatState, null, 2) + '\n');
    const foreignHeartbeatBytes = fs.readFileSync(stateFile);

    // Execute second heartbeat tick: must detect ownership loss, stop, and NOT write
    const tickResult2 = capturedHeartbeatCallback();
    assert.equal(tickResult2, false, 'Heartbeat must fail and stop on foreign takeover');
    assert.deepEqual(fs.readFileSync(stateFile), foreignHeartbeatBytes, 'Foreign state must remain byte unchanged on tick');

    // Restore original setInterval
    global.setInterval = originalSetInterval;
    console.log('✓ Case 6 passed: Heartbeat interval callback updates owned state and halts on foreign collision');

    // Clean up
    try { fs.unlinkSync(stateFile); } catch {}

    // =========================================================================
    // Case 7: Dialog race: Foreign owner with SAME operation & target -> abort
    // =========================================================================
    setClientChannel('beta');
    setDeployedVersion('0.0.12-beta.1', 'beta');

    fs.writeFileSync(stateFile, JSON.stringify({
      state: 'full_ready_to_install',
      operation: 'electron_full_download',
      owner_id: 'verified-downloader-1',
      target_version: '0.0.14-beta.1',
      updated_at: new Date().toISOString(),
    }, null, 2) + '\n');

    autoUpdaterService.setupAutoUpdater(mockWindow);
    assert.equal(autoUpdaterService.getUpdaterStatus().state, 'ready_to_install');
    assert.equal(autoUpdaterService.getUpdaterStatus().canInstall, true);

    dialogResponse = 0;
    let foreignSameTargetBytes = null;
    dialogHook = async () => {
      // Another worker takes over state with SAME operation and SAME target but DIFFERENT owner_id
      const foreignSameTarget = {
        state: 'full_ready_to_install',
        operation: 'electron_full_download',
        owner_id: 'other-full-downloader-2',
        target_version: '0.0.14-beta.1',
        updated_at: new Date().toISOString(),
      };
      fs.writeFileSync(stateFile, JSON.stringify(foreignSameTarget, null, 2) + '\n');
      foreignSameTargetBytes = fs.readFileSync(stateFile);
    };

    mockAutoUpdater.quitAndInstallCalls = 0;
    const sameTargetClashRes = await autoUpdaterService.installUpdate();
    dialogHook = null;

    assert.equal(sameTargetClashRes.state, 'error', 'Install must abort when owner changes during dialog');
    assert.equal(mockAutoUpdater.quitAndInstallCalls, 0, 'quitAndInstall must NOT be called on foreign owner');
    assert.deepEqual(fs.readFileSync(stateFile), foreignSameTargetBytes, 'Foreign same-target state byte unchanged');
    console.log('✓ Case 7 passed: Foreign owner with same operation and target aborted without install');

    // Clean up
    try { fs.unlinkSync(stateFile); } catch {}

    // =========================================================================
    // Case 8: CAS losing ownership between precheck and writeUpdateStateIfOwned
    // =========================================================================
    setClientChannel('beta');
    setDeployedVersion('0.0.12-beta.1', 'beta');

    fs.writeFileSync(stateFile, JSON.stringify({
      state: 'full_ready_to_install',
      operation: 'electron_full_download',
      owner_id: 'verified-downloader-3',
      target_version: '0.0.14-beta.1',
      updated_at: new Date().toISOString(),
    }, null, 2) + '\n');

    autoUpdaterService.setupAutoUpdater(mockWindow);
    dialogResponse = 0;
    dialogHook = null;

    // Simulate losing CAS ownership right at write time: injectHook writes foreign state
    let casIntercepted = false;
    let foreignCasStateBytes = null;
    injectHook = (storageDir, state, context, expectedOwner) => {
      if (state === 'installing') {
        casIntercepted = true;
        // Foreign write occurred before gate
        const foreignUsurperState = {
          state: 'full_ready_to_install',
          operation: 'electron_full_download',
          owner_id: 'usurper-downloader-4',
          target_version: '0.0.14-beta.1',
          updated_at: new Date().toISOString(),
        };
        fs.writeFileSync(stateFile, JSON.stringify(foreignUsurperState, null, 2) + '\n');
        foreignCasStateBytes = fs.readFileSync(stateFile);
      }
    };

    mockAutoUpdater.quitAndInstallCalls = 0;
    const casLossRes = await autoUpdaterService.installUpdate();
    injectHook = null;

    assert.ok(casIntercepted, 'CAS check must have been intercepted at install state transition');
    assert.equal(casLossRes.state, 'error', 'Install must fail cleanly when CAS returns false');
    assert.equal(mockAutoUpdater.quitAndInstallCalls, 0, 'quitAndInstall must NOT be called when CAS fails');
    assert.equal(autoUpdaterService.getUpdaterStatus().canInstall, false);
    assert.ok(!fs.existsSync(lockFile), 'Install lock must be released on CAS failure');
    assert.deepEqual(fs.readFileSync(stateFile), foreignCasStateBytes, 'Foreign state must be byte unchanged on CAS failure');
    console.log('✓ Case 8 passed: CAS ownership loss between precheck and write cleanly aborts install');

    // Clean up
    try { fs.unlinkSync(stateFile); } catch {}

    // =========================================================================
    // Case 9: Positive Beta Lifecycle: Discover -> Download -> Ready -> Install
    // =========================================================================
    setClientChannel('beta');
    setDeployedVersion('0.0.12-beta.1', 'beta');
    try { fs.unlinkSync(stateFile); } catch {}
    try { fs.unlinkSync(lockFile); } catch {}

    global.fetch = async () => ({
      ok: true,
      json: async () => [{
        tag_name: 'v0.0.14-beta.1',
        draft: false,
        prerelease: true,
        assets: [{ name: 'beta.yml' }],
      }],
    });

    const discoverRes = await autoUpdaterService.checkForUpdates();
    assert.equal(discoverRes.state, 'checking');
    mockAutoUpdater.emit('update-available', { version: '0.0.14-beta.1' });
    assert.equal(autoUpdaterService.getUpdaterStatus().state, 'update_available');

    // Successful download execution: mockAutoUpdater emits matching update-downloaded with feed target
    const downloadRes = await autoUpdaterService.downloadUpdate();
    assert.equal(downloadRes.state, 'ready_to_install');
    assert.equal(downloadRes.canInstall, true);
    assert.equal(downloadRes.updateInfo?.version, '0.0.14-beta.1');

    const stateWhenReady = JSON.parse(fs.readFileSync(stateFile, 'utf8'));
    assert.equal(stateWhenReady.state, 'full_ready_to_install');
    assert.equal(stateWhenReady.operation, 'electron_full_download');
    assert.equal(stateWhenReady.target_version, '0.0.14-beta.1');

    // Successful installation execution
    dialogResponse = 0;
    dialogHook = null;
    mockAutoUpdater.quitAndInstallCalls = 0;

    await autoUpdaterService.installUpdate();
    assert.equal(mockAutoUpdater.quitAndInstallCalls, 1, 'quitAndInstall must be called for verified positive Beta flow');
    console.log('✓ Case 9 passed: Positive Beta Discover -> Download -> Ready -> Install lifecycle verified');

    // Clean up
    try { fs.unlinkSync(stateFile); } catch {}
    try { fs.unlinkSync(lockFile); } catch {}

    // =========================================================================
    // Case 10: Positive Stable Lifecycle: Discover -> Download -> Ready -> Install (latest.yml)
    // =========================================================================
    setClientChannel('stable');
    setDeployedVersion('0.0.13', 'stable');
    currentNativeVersion = '0.0.13';

    global.fetch = async () => ({
      ok: true,
      json: async () => [{
        tag_name: 'v0.0.14',
        draft: false,
        prerelease: false,
        assets: [{ name: 'latest.yml' }, { name: 'POS-Desktop-Setup-0.0.14.exe' }],
      }],
    });

    const discoverStable = await autoUpdaterService.checkForUpdates();
    assert.equal(discoverStable.state, 'checking');
    assert.equal(mockAutoUpdater.channel, 'latest');
    mockAutoUpdater.emit('update-available', { version: '0.0.14' });
    assert.equal(autoUpdaterService.getUpdaterStatus().state, 'update_available');
    assert.equal(autoUpdaterService.getUpdaterStatus().updateInfo?.version, '0.0.14');

    const downloadStable = await autoUpdaterService.downloadUpdate();
    assert.equal(downloadStable.state, 'ready_to_install');
    assert.equal(downloadStable.canInstall, true);
    assert.equal(downloadStable.updateInfo?.version, '0.0.14');

    dialogResponse = 0;
    dialogHook = null;
    mockAutoUpdater.quitAndInstallCalls = 0;

    await autoUpdaterService.installUpdate();
    assert.equal(mockAutoUpdater.quitAndInstallCalls, 1, 'quitAndInstall must be called for verified positive Stable flow');
    console.log('✓ Case 10 passed: Positive Stable Discover -> Download -> Ready -> Install lifecycle verified');

    // Clean up
    try { fs.unlinkSync(stateFile); } catch {}
    try { fs.unlinkSync(lockFile); } catch {}

    // =========================================================================
    // Case 11: Periodic check poll while ready does NOT retarget or lose canInstall
    // =========================================================================
    setClientChannel('beta');
    setDeployedVersion('0.0.12-beta.1', 'beta');

    fs.writeFileSync(stateFile, JSON.stringify({
      state: 'full_ready_to_install',
      operation: 'electron_full_download',
      owner_id: 'ready-owner-poll-999',
      target_version: '0.0.14-beta.1',
      updated_at: new Date().toISOString(),
    }, null, 2) + '\n');

    autoUpdaterService.setupAutoUpdater(mockWindow);
    assert.equal(autoUpdaterService.getUpdaterStatus().state, 'ready_to_install');
    assert.equal(autoUpdaterService.getUpdaterStatus().canInstall, true);

    const checkCallsBeforePoll = mockAutoUpdater.checkForUpdatesCalls;
    rendererBroadcasts.length = 0;

    // Periodic check occurs while in verified ready_to_install state
    const pollCheckRes = await autoUpdaterService.checkForUpdates();
    assert.equal(pollCheckRes.state, 'ready_to_install', 'Poll check must preserve ready_to_install state');
    assert.equal(pollCheckRes.canInstall, true, 'canInstall must remain true');
    assert.equal(mockAutoUpdater.checkForUpdatesCalls, checkCallsBeforePoll, 'Mock checkForUpdates must not be invoked');
    assert.equal(rendererBroadcasts.length, 0, 'No checking broadcasts sent to renderer during poll');
    console.log('✓ Case 11 passed: Periodic check while ready preserves ready_to_install without retargeting');

    console.log('PASS AutoUpdater Channel Isolation: All 11 orchestration scenarios verified successfully.');
  } finally {
    // Restore coordination helper wrapper
    injectHook = null;
    coordination.writeUpdateStateIfOwned = realWriteUpdateStateIfOwned;

    // Restore runtime mocks and environment
    Module._load = originalModuleLoad;
    global.fetch = originalFetch;
    global.setInterval = originalSetInterval;
    global.clearInterval = originalClearInterval;
    for (const key of Object.keys(process.env)) {
      if (!(key in originalEnv)) delete process.env[key];
    }
    Object.assign(process.env, originalEnv);

    // Clean up temporary test directory
    try { fs.rmSync(testRoot, { recursive: true, force: true }); } catch { /* best effort */ }
  }
}

runTests().catch((err) => {
  console.error('FAIL AutoUpdater Channel Isolation test failure:', err);
  process.exit(1);
});
