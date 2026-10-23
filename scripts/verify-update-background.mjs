import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const root = path.resolve(process.argv[2] || '.');
const require = createRequire(path.join(root, 'frontend/package.json'));
const ts = require('typescript');
const axios = require('axios');

console.log('Starting isolated Frontend Background Update Verification...');

function createEnvironment() {
  const modules = new Map();
  let now = 1_000_000;
  class Clock extends Date {
    constructor(...args) {
      if (args.length === 0) super(now);
      else super(...args);
    }
    static now() {
      return now;
    }
  }

  const timers = new Map();
  let timerId = 0;

  const mockWindow = {
    API_BASE_URL: 'http://127.0.0.1',
    posRuntime: {
      getDeltaCapability: async () => ({ capable: true }),
    },
    electronAPI: {
      getVersion: async () => '1.0.0',
    },
  };

  function load(file) {
    const full = path.resolve(root, 'frontend/src', file);
    if (modules.has(full)) return modules.get(full);
    const exports = {};
    modules.set(full, exports);
    const code = ts.transpileModule(fs.readFileSync(full, 'utf8'), {
      compilerOptions: { module: ts.ModuleKind.CommonJS, esModuleInterop: true },
    }).outputText;

    vm.runInNewContext(code, {
      exports,
      Date: Clock,
      Symbol,
      Promise,
      Error,
      setTimeout: (callback, delay) => {
        const id = ++timerId;
        timers.set(id, { callback, at: now + delay });
        return id;
      },
      clearTimeout: (id) => timers.delete(id),
      window: mockWindow,
      localStorage: {},
      sessionStorage: {},
      require: (id) => {
        if (id === 'react-hot-toast') {
          return { __esModule: true, default: { error: () => {} } };
        }
        if (id.startsWith('.')) {
          return load(path.relative(path.join(root, 'frontend/src'), path.resolve(path.dirname(full), id + '.ts')));
        }
        return require(id);
      },
    });

    return exports;
  }

  function runTimers(until = now) {
    let executed = false;
    do {
      executed = false;
      let earliest = null;
      let earliestId = null;
      for (const [id, t] of timers.entries()) {
        if (t.at <= until) {
          if (!earliest || t.at < earliest.at) {
            earliest = t;
            earliestId = id;
          }
        }
      }
      if (earliest && earliestId !== null) {
        timers.delete(earliestId);
        earliest.callback();
        executed = true;
      }
    } while (executed);
  }

  function advanceTime(ms) {
    now += ms;
    runTimers(now);
  }

  async function stepTimer(ms = 2000) {
    for (let tick = 0; tick < 50; tick++) {
      if (timers.size > 0) break;
      await Promise.resolve();
    }
    advanceTime(ms);
    for (let tick = 0; tick < 50; tick++) {
      await Promise.resolve();
    }
  }

  const api = load('api/axios.ts').default;
  const storeModule = load('store/updateStore.ts');
  const useUpdateStore = storeModule.default;

  return {
    api,
    useUpdateStore,
    advanceTime,
    stepTimer,
    hasTimers: () => timers.size > 0,
    getNow: () => now,
    setNow: (v) => { now = v; },
    window: mockWindow,
  };
}

// ══════════════════════════════════════════════════════════════════════════════
// Test 1: 3 concurrent mounts -> 1 request (single-flight guard before awaiting IPC)
// ══════════════════════════════════════════════════════════════════════════════
{
  const env = createEnvironment();
  let requestCount = 0;
  let capturedConfigs = [];

  env.api.defaults.adapter = async (config) => {
    requestCount++;
    capturedConfigs.push(config);
    return {
      status: 200,
      statusText: 'OK',
      headers: {},
      config,
      data: {
        status: 'success',
        message: 'success',
        data: {
          status: 'no_update_available',
          pending: false,
          has_update: false,
          latest_version: null,
          current_version: '1.0.0',
          updates_disabled: false,
          updates_unreachable: false,
          changelog: [],
        },
      },
    };
  };

  const store = env.useUpdateStore;
  const p1 = store.getState().silentCheck();
  const p2 = store.getState().silentCheck();
  const p3 = store.getState().silentCheck();

  await Promise.all([p1, p2, p3]);

  assert.equal(requestCount, 1, '3 concurrent mounts must trigger exactly 1 network request');
  assert.equal(capturedConfigs[0].params?.background, true, 'silentCheck must pass params.background=true');
  console.log('PASS: 3 concurrent mounts result in exactly 1 request (single-flight guard before IPC)');
}

// ══════════════════════════════════════════════════════════════════════════════
// Test 2: Failed-check cooldown (short 60s retry vs success 6h)
// ══════════════════════════════════════════════════════════════════════════════
{
  const env = createEnvironment();
  let requestCount = 0;

  env.api.defaults.adapter = async (config) => {
    requestCount++;
    throw new axios.AxiosError('Network Error', 'ERR_NETWORK', config);
  };

  const store = env.useUpdateStore;
  await store.getState().silentCheck();
  assert.equal(requestCount, 1, 'Initial failed check occurred');
  assert.equal(store.getState().updatesUnreachable, true, 'Unreachable reported on error');

  // Immediate retry within 60s cooldown must NOT trigger another request
  await store.getState().silentCheck();
  assert.equal(requestCount, 1, 'Failed check within 60s cooldown must not trigger request');

  // Advance time past 60s cooldown
  env.advanceTime(61_000);
  await store.getState().silentCheck();
  assert.equal(requestCount, 2, 'Failed check after 60s cooldown allowed to retry');
  console.log('PASS: Failed-check cooldown suppresses immediate floods and retries after 60s');
}

// ══════════════════════════════════════════════════════════════════════════════
// Test 3: Manual failed-check does not masquerade as success (Independent Repro)
// ══════════════════════════════════════════════════════════════════════════════
{
  const env = createEnvironment();
  let requestCount = 0;

  env.api.defaults.adapter = async (config) => {
    requestCount++;
    return {
      status: 200,
      statusText: 'OK',
      headers: {},
      config,
      data: {
        status: 'success',
        message: 'success',
        data: {
          status: 'github_fetch_failed',
          updates_unreachable: true,
          has_update: false,
          latest_version: null,
          current_version: '1.0.0',
        },
      },
    };
  };

  const store = env.useUpdateStore;

  // 1. User performs manual forceCheck that fails with updates_unreachable=true
  await store.getState().forceCheck();
  assert.equal(requestCount, 1, 'Manual forceCheck sent request');
  assert.equal(store.getState().updatesUnreachable, true, 'Store marked unreachable');
  assert.equal(store.getState().lastChecked, null, 'Failed manual check must NOT record lastChecked success timestamp');

  // 2. Advance clock past short 60s failure retry cooldown
  env.advanceTime(61_000);

  // 3. Silent check runs: must send request (total = 2), not be suppressed by 6h success cooldown!
  await store.getState().silentCheck();
  assert.equal(requestCount, 2, 'silentCheck must retry after 60s following failed manual check');
  console.log('PASS: Failed manual check does not masquerade as success or block silent retry');
}

// ══════════════════════════════════════════════════════════════════════════════
// Test 4: Missing data / malformed payload vs valid no_update
// ══════════════════════════════════════════════════════════════════════════════
{
  const env = createEnvironment();
  let requestCount = 0;
  let currentResponse = null;

  env.api.defaults.adapter = async (config) => {
    requestCount++;
    return {
      status: 200,
      statusText: 'OK',
      headers: {},
      config,
      data: currentResponse,
    };
  };

  const store = env.useUpdateStore;

  // 4a. Malformed / empty response data
  currentResponse = { status: 'success', message: 'success', data: {} };
  await store.getState().silentCheck();
  assert.equal(requestCount, 1, 'First silentCheck performed');
  assert.equal(store.getState().updatesUnreachable, true, 'Malformed data treated as failure');
  assert.equal(store.getState().lastChecked, null, 'Malformed data must not mark lastChecked 6h');

  // Advance time past 60s failure cooldown
  env.advanceTime(61_000);

  // 4b. Valid no_update_available response
  currentResponse = {
    status: 'success',
    message: 'success',
    data: {
      status: 'no_update_available',
      pending: false,
      has_update: false,
      latest_version: null,
      current_version: '1.0.0',
      updates_disabled: false,
      updates_unreachable: false,
      changelog: [],
    },
  };
  await store.getState().silentCheck();
  assert.equal(requestCount, 2, 'silentCheck allowed to retry after malformed failure');
  assert.equal(store.getState().updatesUnreachable, false, 'Unreachable cleared on valid result');
  assert.equal(store.getState().lastChecked, env.getNow(), 'Valid result sets lastChecked');

  // Immediate retry should now be suppressed by 6h cooldown
  await store.getState().silentCheck();
  assert.equal(requestCount, 2, 'Valid result enforces 6-hour success cooldown');
  console.log('PASS: Runtime guard handles malformed responses cleanly with short failure cooldown');
}

// ══════════════════════════════════════════════════════════════════════════════
// Test 5: Bounded pending polling to success
// ══════════════════════════════════════════════════════════════════════════════
{
  const env = createEnvironment();
  let callIndex = 0;

  env.api.defaults.adapter = async (config) => {
    callIndex++;
    if (callIndex === 1) {
      return {
        status: 200,
        statusText: 'OK',
        headers: {},
        config,
        data: {
          status: 'success',
          message: 'success',
          data: {
            pending: true,
            status: 'check_pending',
            current_version: '1.0.0',
            has_update: false,
            updates_disabled: false,
            updates_unreachable: false,
            changelog: [],
          },
        },
      };
    }

    return {
      status: 200,
      statusText: 'OK',
      headers: {},
      config,
      data: {
        status: 'success',
        message: 'success',
        data: {
          pending: false,
          status: 'update_available',
          has_update: true,
          latest_version: '1.2.0',
          current_version: '1.0.0',
          updates_disabled: false,
          updates_unreachable: false,
          changelog: [{ version: '1.2.0', date: '2026-05-01', changes: ['Performance fix'] }],
        },
      },
    };
  };

  const store = env.useUpdateStore;
  const silentPromise = store.getState().silentCheck();

  for (let i = 0; i < 20; i++) {
    await Promise.resolve();
    if (store.getState().isPending) break;
  }
  assert.equal(store.getState().isPending, true, 'Store must be marked pending during background job');

  // Advance time for first poll (2s)
  await env.stepTimer(2000);
  await silentPromise;

  assert.equal(store.getState().hasUpdate, true, 'Polling discovered new update');
  assert.equal(store.getState().latestVersion, '1.2.0', 'Latest version updated');
  assert.equal(store.getState().isPending, false, 'Pending cleared on success');
  assert.equal(store.getState().lastChecked, env.getNow(), 'Settled success marks lastChecked cooldown');
  console.log('PASS: Bounded pending polling to success resolves and updates store without navigation');
}

// ══════════════════════════════════════════════════════════════════════════════
// Test 6: No-worker stays pending, NOT green success 6h
// ══════════════════════════════════════════════════════════════════════════════
{
  const env = createEnvironment();

  env.api.defaults.adapter = async (config) => ({
    status: 200,
    statusText: 'OK',
    headers: {},
    config,
    data: {
      status: 'success',
      message: 'success',
      data: {
        pending: true,
        status: 'check_pending',
        current_version: '1.0.0',
        has_update: false,
        updates_disabled: false,
        updates_unreachable: false,
        changelog: [],
      },
    },
  });

  const store = env.useUpdateStore;
  const silentPromise = store.getState().silentCheck();

  // Advance time past the 30s max polling duration (16 * 2s = 32s)
  for (let i = 0; i < 16; i++) {
    await env.stepTimer(2000);
  }
  await silentPromise;

  assert.equal(store.getState().isPending, true, 'Store remains pending when worker did not execute');
  assert.equal(store.getState().hasUpdate, false, 'Pending state must NOT claim hasUpdate=true');
  assert.equal(store.getState().lastChecked, null, 'Pending state must NOT set 6h success cooldown');
  console.log('PASS: Missing CLI worker stays pending and does not mark false green success 6h');
}

// ══════════════════════════════════════════════════════════════════════════════
// Test 7: Manual bypass
// ══════════════════════════════════════════════════════════════════════════════
{
  const env = createEnvironment();
  let manualConfig = null;

  env.api.defaults.adapter = async (config) => {
    manualConfig = config;
    return {
      status: 200,
      statusText: 'OK',
      headers: {},
      config,
      data: {
        status: 'success',
        message: 'success',
        data: {
          pending: false,
          status: 'update_available',
          has_update: true,
          latest_version: '1.5.0',
          current_version: '1.0.0',
          updates_disabled: false,
          updates_unreachable: false,
          changelog: [],
        },
      },
    };
  };

  const store = env.useUpdateStore;
  store.setState({ lastChecked: env.getNow() });

  const result = await store.getState().forceCheck();

  assert.equal(manualConfig.params?.background, undefined, 'Manual check must NOT set background=true');
  assert.equal(result.latest_version, '1.5.0', 'Manual check returns result');
  assert.equal(store.getState().latestVersion, '1.5.0', 'Manual check updates store');
  console.log('PASS: Manual forceCheck bypasses cooldown and preserves manual semantics');
}

// ══════════════════════════════════════════════════════════════════════════════
// Test 8: Manual -> Silent ordering (In-flight manual blocks silent queuing)
// ══════════════════════════════════════════════════════════════════════════════
{
  const env = createEnvironment();
  let resolveManual;
  let requestCount = 0;

  env.api.defaults.adapter = async (config) => {
    requestCount++;
    return new Promise((resolve) => {
      resolveManual = () => resolve({
        status: 200,
        statusText: 'OK',
        headers: {},
        config,
        data: {
          status: 'success',
          message: 'success',
          data: {
            status: 'update_available',
            has_update: true,
            latest_version: '3.0.0-manual-result',
            current_version: '1.0.0',
            updates_disabled: false,
            updates_unreachable: false,
            changelog: [],
          },
        },
      });
    });
  };

  const store = env.useUpdateStore;

  // 1. Start manual check (in-flight)
  const manualPromise = store.getState().forceCheck();
  for (let i = 0; i < 20; i++) {
    if (typeof resolveManual === 'function') break;
    await Promise.resolve();
  }
  assert.equal(store.getState().isChecking, true, 'isChecking must be true for manual');
  assert.equal(requestCount, 1, 'Manual request sent');

  // 2. Start silent check while manual is actively in-flight
  const silentPromise = store.getState().silentCheck();

  // 3. Resolve manual check
  resolveManual();
  await Promise.all([manualPromise, silentPromise]);

  assert.equal(requestCount, 1, 'silentCheck must join in-flight manual check without extra request');
  assert.equal(store.getState().latestVersion, '3.0.0-manual-result');
  assert.equal(store.getState().isChecking, false, 'isChecking properly cleared');
  console.log('PASS: Manual -> Silent ordering quietly joins in-flight manual without competing requests');
}

// ══════════════════════════════════════════════════════════════════════════════
// Test 9: Silent -> Manual ordering (Generation safety)
// ══════════════════════════════════════════════════════════════════════════════
{
  const env = createEnvironment();
  let resolveSilent;

  env.api.defaults.adapter = async (config) => {
    if (config.params?.background) {
      return new Promise((resolve) => {
        resolveSilent = () => resolve({
          status: 200,
          statusText: 'OK',
          headers: {},
          config,
          data: {
            status: 'success',
            message: 'success',
            data: {
              status: 'update_available',
              pending: false,
              has_update: true,
              latest_version: '1.1.0-older-silent',
              current_version: '1.0.0',
              updates_disabled: false,
              updates_unreachable: false,
              changelog: [],
            },
          },
        });
      });
    }

    return {
      status: 200,
      statusText: 'OK',
      headers: {},
      config,
      data: {
        status: 'success',
        message: 'success',
        data: {
          status: 'update_available',
          pending: false,
          has_update: true,
          latest_version: '2.0.0-newer-manual',
          current_version: '1.0.0',
          updates_disabled: false,
          updates_unreachable: false,
          changelog: [],
        },
      },
    };
  };

  const store = env.useUpdateStore;

  // 1. Start silent check
  const silentPromise = store.getState().silentCheck();
  for (let i = 0; i < 20; i++) {
    if (typeof resolveSilent === 'function') break;
    await Promise.resolve();
  }

  // 2. User clicks Check Now (manual forceCheck)
  await store.getState().forceCheck();
  assert.equal(store.getState().latestVersion, '2.0.0-newer-manual');

  // 3. Now let the older silent check finish
  resolveSilent();
  await silentPromise;

  assert.equal(
    store.getState().latestVersion,
    '2.0.0-newer-manual',
    'Older silent completion must NOT overwrite newer manual check result'
  );
  console.log('PASS: Older silent completion cannot overwrite newer manual check result');
}

// ══════════════════════════════════════════════════════════════════════════════
// Test 10: Concurrent manual check deduplication
// ══════════════════════════════════════════════════════════════════════════════
{
  const env = createEnvironment();
  let manualRequests = 0;
  let releaseGate;
  const gatePromise = new Promise((resolve) => { releaseGate = resolve; });

  env.api.defaults.adapter = async (config) => {
    manualRequests++;
    await gatePromise;
    return {
      status: 200,
      statusText: 'OK',
      headers: {},
      config,
      data: {
        status: 'success',
        message: 'success',
        data: {
          status: 'update_available',
          has_update: true,
          latest_version: '4.0.0',
          current_version: '1.0.0',
          updates_disabled: false,
          updates_unreachable: false,
          changelog: [],
        },
      },
    };
  };

  const store = env.useUpdateStore;
  const p1 = store.getState().forceCheck();
  const p2 = store.getState().forceCheck();

  releaseGate();
  const [r1, r2] = await Promise.all([p1, p2]);

  assert.equal(manualRequests, 1, 'Concurrent manual checks must deduplicate to 1 network request');
  assert.equal(r1.latest_version, '4.0.0');
  assert.equal(r2.latest_version, '4.0.0');
  console.log('PASS: Concurrent manual checks deduplicate correctly with deterministic promise gate');
}

// ══════════════════════════════════════════════════════════════════════════════
// Test 11: Loading state correct
// ══════════════════════════════════════════════════════════════════════════════
{
  const env = createEnvironment();
  let resolveReq;

  env.api.defaults.adapter = async (config) => {
    return new Promise((resolve) => {
      resolveReq = () => resolve({
        status: 200,
        statusText: 'OK',
        headers: {},
        config,
        data: {
          status: 'success',
          message: 'success',
          data: {
            status: 'no_update_available',
            pending: false,
            has_update: false,
            latest_version: null,
            current_version: '1.0.0',
            updates_disabled: false,
            updates_unreachable: false,
            changelog: [],
          },
        },
      });
    });
  };

  const store = env.useUpdateStore;
  assert.equal(store.getState().isChecking, false);

  const promise = store.getState().silentCheck();
  for (let i = 0; i < 20; i++) {
    if (typeof resolveReq === 'function') break;
    await Promise.resolve();
  }
  assert.equal(store.getState().isChecking, true, 'isChecking must be true while in flight');

  resolveReq();
  await promise;
  assert.equal(store.getState().isChecking, false, 'isChecking must be false when completed');
  console.log('PASS: Loading state is accurately managed across check lifecycle');
}

// ══════════════════════════════════════════════════════════════════════════════
// Test 12: Late old silent error during gated version IPC cannot overwrite newer manual result or cooldown
// ══════════════════════════════════════════════════════════════════════════════
{
  const env = createEnvironment();
  let resolveSilentVersionIpc;
  const versionGate = new Promise((resolve) => { resolveSilentVersionIpc = resolve; });
  let isSilentRequest = true;

  env.window.electronAPI.getVersion = async () => {
    if (isSilentRequest) {
      await versionGate;
    }
    return '1.0.0';
  };

  env.api.defaults.adapter = async (config) => {
    if (config.params?.background) {
      // Silent check throws network error
      throw new axios.AxiosError('Silent Network Failure', 'ERR_NETWORK', config);
    }
    // Newer manual check succeeds
    return {
      status: 200,
      statusText: 'OK',
      headers: {},
      config,
      data: {
        status: 'success',
        message: 'success',
        data: {
          status: 'update_available',
          has_update: true,
          latest_version: '2.5.0-manual-success',
          current_version: '1.0.0',
          updates_disabled: false,
          updates_unreachable: false,
          changelog: ['Manual feature'],
        },
      },
    };
  };

  const store = env.useUpdateStore;

  // 1. Silent check starts and throws; enters catch block and awaits gated getVersion IPC
  const silentPromise = store.getState().silentCheck();
  for (let i = 0; i < 20; i++) {
    await Promise.resolve();
  }

  // 2. While silent check is suspended in catch awaiting getVersion IPC, user triggers manual forceCheck
  isSilentRequest = false;
  await store.getState().forceCheck();

  assert.equal(store.getState().updatesUnreachable, false, 'Manual check succeeded');
  assert.equal(store.getState().latestVersion, '2.5.0-manual-success', 'Manual check set version');
  assert.equal(store.getState().lastChecked, env.getNow(), 'Manual check recorded success timestamp');

  // 3. Now release the gated getVersion IPC for the old silent check
  resolveSilentVersionIpc();
  await silentPromise;

  // 4. Verify the old silent error did NOT overwrite state or cooldown
  assert.equal(store.getState().updatesUnreachable, false, 'Late silent error must NOT set updatesUnreachable=true');
  assert.equal(store.getState().latestVersion, '2.5.0-manual-success', 'Late silent error must NOT overwrite latestVersion');
  assert.equal(store.getState().lastChecked, env.getNow(), 'Late silent error must NOT clear success timestamp');

  // 5. Advance time by 61s (short failure cooldown). Since manual check succeeded, 6h success cooldown must still be in effect!
  let newNetworkRequests = 0;
  env.api.defaults.adapter = async (config) => {
    newNetworkRequests++;
    return { status: 200, statusText: 'OK', headers: {}, config, data: {} };
  };
  env.advanceTime(61_000);
  await store.getState().silentCheck();
  assert.equal(newNetworkRequests, 0, 'Silent check after 61s must still be blocked by 6h success cooldown, not suppressed failure');
  console.log('PASS: Late old silent error during gated version IPC cannot overwrite newer manual state or cooldown');
}

// ══════════════════════════════════════════════════════════════════════════════
// Test 13: IPC getVersion rejection does not crash silentCheck and preserves manual throw
// ══════════════════════════════════════════════════════════════════════════════
{
  const env = createEnvironment();
  env.window.electronAPI.getVersion = async () => {
    throw new Error('IPC Bridge Crash');
  };

  env.api.defaults.adapter = async (config) => {
    throw new axios.AxiosError('Network Error', 'ERR_NETWORK', config);
  };

  const store = env.useUpdateStore;

  // Silent check must catch without unhandled rejection
  await store.getState().silentCheck();
  assert.equal(store.getState().updatesUnreachable, true, 'Silent check handles IPC error safely');

  // Manual check must rethrow network error, not IPC error
  let manualThrew = false;
  try {
    await store.getState().forceCheck();
  } catch (err) {
    manualThrew = true;
    assert.equal(err.code, 'ERR_NETWORK', 'Manual check cleanly rethrows network error');
  }
  assert.equal(manualThrew, true, 'Manual check threw network error');
  console.log('PASS: IPC getVersion rejection handled safely without unhandled promise rejections');
}

console.log('\nALL FRONTEND BACKGROUND UPDATE VERIFICATION CHECKS PASSED!\n');
