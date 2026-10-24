import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { pathToFileURL } from 'node:url';

const root = process.cwd();
const lab = fs.mkdtempSync(path.join(os.tmpdir(), 'pos-admin-ui-'));
const require = createRequire(import.meta.url);
const { rolldown } = await import(pathToFileURL(path.join(root, 'frontend/node_modules/rolldown/dist/index.mjs')).href);

console.log('Building Admin Update Center Real React DOM Test Bundle (Rolldown)...');

try {
  const entry = path.join(lab, 'entry.js');
  fs.writeFileSync(entry, [
    `import React from ${JSON.stringify(path.join(root, 'frontend/node_modules/react/index.js'))};`,
    `import { createRoot } from ${JSON.stringify(path.join(root, 'frontend/node_modules/react-dom/client.js'))};`,
    `import UpdateSection from ${JSON.stringify(path.join(root, 'frontend/src/pages/settings/UpdateSection.tsx'))};`,
    `import UpdateProgress from ${JSON.stringify(path.join(root, 'frontend/src/components/update/UpdateProgress.tsx'))};`,
    `import useChannelTransition from ${JSON.stringify(path.join(root, 'frontend/src/hooks/useChannelTransition.ts'))};`,
    `window.React = React;`,
    `let sectionRoot = null;`,
    `let progressRoot = null;`,
    `let hookRoot = null;`,
    `window.mountUpdateSection = () => {`,
    `  const container = document.getElementById('section-root');`,
    `  sectionRoot = createRoot(container);`,
    `  sectionRoot.render(React.createElement(UpdateSection));`,
    `};`,
    `window.unmountUpdateSection = () => {`,
    `  if (sectionRoot) { sectionRoot.unmount(); sectionRoot = null; }`,
    `};`,
    `window.mountUpdateProgress = (props) => {`,
    `  const container = document.getElementById('progress-root');`,
    `  progressRoot = createRoot(container);`,
    `  progressRoot.render(React.createElement(UpdateProgress, props));`,
    `};`,
    `window.unmountUpdateProgress = () => {`,
    `  if (progressRoot) { progressRoot.unmount(); progressRoot = null; }`,
    `};`,
    `function HookProbe({ channel, options }) {`,
    `  const hook = useChannelTransition(channel, options);`,
    `  window.__channelHook = hook;`,
    `  return null;`,
    `}`,
    `window.mountChannelHook = (channel, options) => {`,
    `  const container = document.getElementById('hook-root');`,
    `  hookRoot = createRoot(container);`,
    `  hookRoot.render(React.createElement(HookProbe, { channel, options }));`,
    `};`,
    `window.unmountChannelHook = () => {`,
    `  if (hookRoot) { hookRoot.unmount(); hookRoot = null; }`,
    `};`,
  ].join('\n'));

  const bundle = await rolldown({
    input: entry,
    plugins: [{
      name: 'isolated-admin-ui-stubs',
      resolveId(source) {
        if (source === 'react') return path.join(root, 'frontend/node_modules/react/index.js');
        if (source === 'react-dom') return path.join(root, 'frontend/node_modules/react-dom/index.js');
        if (source === 'react-dom/client') return path.join(root, 'frontend/node_modules/react-dom/client.js');
        if (source === 'lucide-react') return '\0fixture-lucide';
        if (source === 'react-hot-toast') return '\0fixture-toast';
        if (source === 'axios') return '\0fixture-axios';
        if (source.includes('api/endpoints')) return '\0fixture-api';
        if (source.includes('cartStore')) return '\0fixture-cart';
        if (source.includes('confirmStore')) return '\0fixture-confirm';
        if (source.includes('updateStore')) return '\0fixture-store';
        if (source.includes('updateRestart')) return '\0fixture-restart';
        if (source.includes('SectionTitle')) return '\0fixture-section-title';
        if (source.includes('RollbackPoints')) return '\0fixture-rollback-points';
        if (source.includes('ChannelTransitionPanel')) return '\0fixture-channel-panel';
      },
      load(id) {
        if (id === '\0fixture-lucide') {
          return `
import React from 'react';
const Icon = (props) => React.createElement('span', { 'data-icon': true, ...props });
export const Download = Icon;
export const AlertTriangle = Icon;
export const RotateCcw = Icon;
export const RefreshCw = Icon;
export const Clock = Icon;
export const ShieldCheck = Icon;
export const CheckCircle2 = Icon;
export const ChevronDown = Icon;
export const ChevronUp = Icon;
export const Activity = Icon;
export const FileCode2 = Icon;
export const HardDrive = Icon;
export const ExternalLink = Icon;
export const Info = Icon;
export const Loader2 = Icon;
export const List = Icon;
export const History = Icon;
export const Wrench = Icon;
export const HeartPulse = Icon;
export default Icon;
`;
        }
        if (id === '\0fixture-toast') {
          return `
export default {
  error(msg) { window.__toasts = window.__toasts || []; window.__toasts.push({ type: 'error', msg }); },
  success(msg) { window.__toasts = window.__toasts || []; window.__toasts.push({ type: 'success', msg }); },
  loading(msg, opts) { window.__toasts = window.__toasts || []; window.__toasts.push({ type: 'loading', msg, opts }); return opts?.id || 'id'; },
  dismiss() {}
};
`;
        }
        if (id === '\0fixture-axios') {
          return `
export const isAxiosError = (e) => Boolean(e?.isAxiosError);
export default { isAxiosError };
`;
        }
        if (id === '\0fixture-api') {
          return `
export const getUpdateStatus = (...args) => {
  window.__apiCalls.getUpdateStatus = (window.__apiCalls.getUpdateStatus || 0) + 1;
  return window.__api.getUpdateStatus ? window.__api.getUpdateStatus(...args) : Promise.resolve({ data: { data: {} } });
};
export const getUpdateJob = (...args) => {
  window.__apiCalls.getUpdateJob = (window.__apiCalls.getUpdateJob || 0) + 1;
  return window.__api.getUpdateJob ? window.__api.getUpdateJob(...args) : Promise.resolve({ data: { data: null } });
};
export const applyUpdate = (...args) => {
  window.__apiCalls.applyUpdate = (window.__apiCalls.applyUpdate || 0) + 1;
  return window.__api.applyUpdate ? window.__api.applyUpdate(...args) : Promise.resolve({ data: { data: {} } });
};
export const getUpdateHistory = (...args) => {
  window.__apiCalls.getUpdateHistory = (window.__apiCalls.getUpdateHistory || 0) + 1;
  return window.__api.getUpdateHistory ? window.__api.getUpdateHistory(...args) : Promise.resolve({ data: { data: [] } });
};
export const rollbackUpdate = (...args) => {
  window.__apiCalls.rollbackUpdate = (window.__apiCalls.rollbackUpdate || 0) + 1;
  return window.__api.rollbackUpdate ? window.__api.rollbackUpdate(...args) : Promise.resolve({ data: { data: {} } });
};
export const getUpdateSnapshots = (...args) => {
  window.__apiCalls.getUpdateSnapshots = (window.__apiCalls.getUpdateSnapshots || 0) + 1;
  return window.__api.getUpdateSnapshots ? window.__api.getUpdateSnapshots(...args) : Promise.resolve({ data: { data: [] } });
};
export const setUpdateChannel = (...args) => {
  window.__apiCalls.setUpdateChannel = (window.__apiCalls.setUpdateChannel || 0) + 1;
  return window.__api.setUpdateChannel ? window.__api.setUpdateChannel(...args) : Promise.resolve({ data: { data: {} } });
};
export const diagnoseUpdateRecovery = (...args) => {
  return window.__api.diagnoseUpdateRecovery ? window.__api.diagnoseUpdateRecovery(...args) : Promise.resolve({ data: { data: {} } });
};
export const executeRecoveryAction = (...args) => {
  return window.__api.executeRecoveryAction ? window.__api.executeRecoveryAction(...args) : Promise.resolve({ data: { data: {} } });
};
export const getRecoveryAuditLogs = (...args) => {
  return window.__api.getRecoveryAuditLogs ? window.__api.getRecoveryAuditLogs(...args) : Promise.resolve({ data: { data: [] } });
};
export const runPostUpdateHealthCheck = (...args) => {
  return window.__api.runPostUpdateHealthCheck ? window.__api.runPostUpdateHealthCheck(...args) : Promise.resolve({ data: { data: {} } });
};
export const previewChannelTransition = (...args) => {
  window.__apiCalls.previewChannelTransition = (window.__apiCalls.previewChannelTransition || 0) + 1;
  return window.__api.previewChannelTransition ? window.__api.previewChannelTransition(...args) : Promise.resolve({ data: { data: null } });
};
export const applyChannelTransition = (...args) => {
  window.__apiCalls.applyChannelTransition = (window.__apiCalls.applyChannelTransition || 0) + 1;
  return window.__api.applyChannelTransition ? window.__api.applyChannelTransition(...args) : Promise.resolve({ data: { data: {} } });
};
`;
        }
        if (id === '\0fixture-cart') {
          return `export default { getState: () => ({ items: [] }) };`;
        }
        if (id === '\0fixture-confirm') {
          return `export const useConfirmStore = () => ({ confirm: async () => window.__confirmResult ?? true });`;
        }
        if (id === '\0fixture-store') {
          return `
import { create } from ${JSON.stringify(path.join(root, 'frontend/node_modules/zustand/index.js'))};
const defaultState = {
  hasUpdate: false,
  latestVersion: null,
  currentVersion: '0.0.9',
  channel: null,
  installedChannel: null,
  changelog: [],
  lastChecked: null,
  isChecking: false,
  isPending: false,
  updatesDisabled: false,
  updatesDisabledMessage: '',
  updatesUnreachable: false,
  updateErrorMessage: '',
};

window.__updateStore = create((set, get) => ({
  ...defaultState,
  setChannelState: (channel) => set({ channel, hasUpdate: false, latestVersion: null }),
  invalidate: () => set({ hasUpdate: false, latestVersion: null }),
  hydrateLocalStatus: async () => {
    if (window.__api.getUpdateStatus) {
      const res = await window.__api.getUpdateStatus();
      const d = res.data?.data;
      if (d) set({ currentVersion: d.current_version, channel: d.channel, installedChannel: d.installed_channel });
      return d;
    }
    return null;
  },
  forceCheck: async () => {
    if (window.__api.checkUpdate) return window.__api.checkUpdate();
    return {};
  },
  silentCheck: async () => {}
}));

export default window.__updateStore;
`;
        }
        if (id === '\0fixture-restart') {
          return `
export const isUpdateRestarting = () => false;
export const withUpdateRestart = (fn) => fn();
`;
        }
        if (id === '\0fixture-section-title') {
          return `import React from 'react'; export default (props) => React.createElement('div', { 'data-testid': 'section-title' }, props.label);`;
        }
        if (id === '\0fixture-rollback-points') {
          return `import React from 'react'; export default () => null;`;
        }
        if (id === '\0fixture-channel-panel') {
          return `import React from 'react'; export default () => null;`;
        }
      }
    }]
  });

  await bundle.write({ file: path.join(lab, 'bundle.js'), format: 'iife' });
  await bundle.close();
  console.log('Rolldown bundle created successfully.');

  fs.writeFileSync(path.join(lab, 'index.html'), `
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>Admin Update Center UI Test</title></head>
<body>
  <div id="section-root"></div>
  <div id="progress-root"></div>
  <div id="hook-root"></div>
  <script src="bundle.js"></script>
</body>
</html>
`);

  // Write main.cjs using JSON-serialized script evaluations to avoid nested template escapes
  const mainScript = `
const { app, BrowserWindow } = require('electron');
const assert = require('node:assert/strict');

const delay = (ms) => new Promise((r) => setTimeout(r, ms));

app.setPath('userData', ${JSON.stringify(path.join(lab, 'profile'))});

app.whenReady().then(async () => {
  const win = new BrowserWindow({
    show: false,
    webPreferences: {
      contextIsolation: false,
      nodeIntegration: true,
      sandbox: false
    }
  });

  win.webContents.on('console-message', (event, level, message, line, sourceId) => {
    console.log('[RENDERER CONSOLE]:', message);
  });

  const evaluate = (code) => win.webContents.executeJavaScript(code);

  try {
    await win.loadFile(${JSON.stringify(path.join(lab, 'index.html'))});
    await delay(100);

    // Setup browser harness mocks
    await evaluate([
      '(function() {',
      '  window.__toasts = [];',
      '  window.__apiCalls = {};',
      '  window.__applyStagedDeltaCalls = 0;',
      '  window.__reloadCount = 0;',
      '  window.__confirmResult = true;',
      '  window.posRuntime = {',
      '    getDeltaCapability: async () => ({ capable: true }),',
      '    applyStagedDelta: async (v) => {',
      '      window.__applyStagedDeltaCalls++;',
      '      if (window.__ipcGatePromise) return window.__ipcGatePromise;',
      '      return { ok: true };',
      '    }',
      '  };',
      '  window.electronAPI = {',
      '    updater: {',
      '      getStatus: async () => ({ state: "idle" }),',
      '      onStatus: (cb) => { window.__onStatusCb = cb; return () => {}; },',
      '      download: async () => ({ state: "idle" }),',
      '      install: async () => {}',
      '    }',
      '  };',
      '  window.location.reload = () => { window.__reloadCount++; };',
      '  window.__origDateNow = Date.now;',
      '  window.__resetHarness = () => {',
      '    sessionStorage.clear();',
      '    Date.now = window.__origDateNow;',
      '    window.__toasts = [];',
      '    window.__apiCalls = {};',
      '    window.__applyStagedDeltaCalls = 0;',
      '    window.__reloadCount = 0;',
      '    window.__ipcGatePromise = null;',
      '    window.__ipcGateResolve = null;',
      '    window.__api = {',
      '      getUpdateStatus: async () => ({ data: { data: { current_version: "0.0.9", channel: "stable", installed_channel: "stable" } } }),',
      '      getUpdateHistory: async () => ({ data: { data: [] } }),',
      '      getUpdateSnapshots: async () => ({ data: { data: [] } }),',
      '      diagnoseUpdateRecovery: async () => ({ data: { data: { corrupted_files_count: 0 } } }),',
      '      getRecoveryAuditLogs: async () => ({ data: { data: [] } }),',
      '      getUpdateJob: async () => ({ data: { data: null } }),',
      '      applyUpdate: async () => ({ data: { data: {} } }),',
      '    };',
      '  };',
      '  return null;',
      '})()'
    ].join('\\n'));

    // ─────────────────────────────────────────────────────────────
    // Assertion 1: gated admission with old status completed -> no success/IPC
    // ─────────────────────────────────────────────────────────────
    console.log('Testing Assertion 1: gated admission with old status completed -> no success/IPC...');
    await evaluate([
      '(function() {',
      '  window.__resetHarness();',
      '  window.__api.getUpdateJob = async () => ({ data: { data: { id: 888, status: "completed", result: { ok: true } } } });',
      '  window.mountUpdateSection();',
      '  return null;',
      '})()'
    ].join('\\n'));
    await delay(200);
    const a1DeltaCalls = await evaluate('window.__applyStagedDeltaCalls');
    const a1Reloads = await evaluate('window.__reloadCount');
    const a1SuccessToasts = await evaluate('window.__toasts.filter(t => t.type === "success").length');
    assert.equal(a1DeltaCalls, 0, 'Old completed status must NOT call applyStagedDelta IPC');
    assert.equal(a1Reloads, 0, 'Old completed status must NOT trigger reload');
    assert.equal(a1SuccessToasts, 0, 'Old completed status must NOT trigger success toast');
    await evaluate('window.unmountUpdateSection(); null;');
    console.log('PASS Assertion 1');

    // ─────────────────────────────────────────────────────────────
    // Assertion 2: known queued timeout retains busy and resume doesn't POST
    // ─────────────────────────────────────────────────────────────
    console.log('Testing Assertion 2: known queued timeout retains busy and resume does not POST...');
    await evaluate([
      '(function() {',
      '  window.__resetHarness();',
      '  sessionStorage.setItem("pos.active_update_job_id", "42");',
      '  let simulatedTime = 1000;',
      '  Date.now = () => simulatedTime;',
      '  window.__api.getUpdateJob = async (id) => ({ data: { data: { id: 42, status: "pending" } } });',
      '  window.mountUpdateSection();',
      '  return null;',
      '})()'
    ].join('\\n'));
    await delay(100);
    // Fast-forward simulated clock past 30s pending deadline
    await evaluate('Date.now = () => 40000; null;');
    await delay(2500); // Wait for polling loop iteration
    const a2ActiveJob = await evaluate('sessionStorage.getItem("pos.active_update_job_id")');
    assert.equal(a2ActiveJob, '42', 'Pending timeout must retain activeJobId in sessionStorage');

    // Check that attention/timeout banner is rendered
    const a2HasResumeButton = await evaluate('Boolean(document.querySelector(".btn-warning") && document.body.textContent.includes("استئناف متابعة الحالة"))');
    assert.equal(a2HasResumeButton, true, 'Resume button must be rendered on pending timeout');

    // Click resume button and verify it does NOT POST
    await evaluate([
      '(function() {',
      '  window.__apiCalls.applyUpdate = 0;',
      '  document.querySelector(".btn-warning").click();',
      '  return null;',
      '})()'
    ].join('\\n'));
    await delay(100);
    const a2ApplyCalls = await evaluate('window.__apiCalls.applyUpdate || 0');
    assert.equal(a2ApplyCalls, 0, 'Resuming status follow-up must NOT call applyUpdate POST');
    await evaluate('window.unmountUpdateSection(); null;');
    console.log('PASS Assertion 2');

    // ─────────────────────────────────────────────────────────────
    // Assertion 3: empty/mismatched job cannot settle
    // ─────────────────────────────────────────────────────────────
    console.log('Testing Assertion 3: empty/mismatched job cannot settle...');
    await evaluate([
      '(function() {',
      '  window.__resetHarness();',
      '  sessionStorage.setItem("pos.active_update_job_id", "42");',
      '  window.__api.getUpdateJob = async () => ({ data: { data: { id: 999, status: "completed", result: { ok: true } } } });',
      '  window.mountUpdateSection();',
      '  return null;',
      '})()'
    ].join('\\n'));
    await delay(300);
    const a3Job = await evaluate('sessionStorage.getItem("pos.active_update_job_id")');
    const a3Reloads = await evaluate('window.__reloadCount');
    const a3DeltaCalls = await evaluate('window.__applyStagedDeltaCalls');
    assert.equal(a3Job, '42', 'Mismatched job must retain activeJobId without clearing');
    assert.equal(a3Reloads, 0, 'Mismatched job must not settle or reload');
    assert.equal(a3DeltaCalls, 0, 'Mismatched job must not call delta IPC');
    const a3HasAttention = await evaluate('document.body.textContent.includes("المهمة بحاجة لمراجعة الحالة")');
    assert.equal(a3HasAttention, true, 'Mismatched job must transition to needs_attention banner');
    await evaluate('window.unmountUpdateSection(); null;');
    console.log('PASS Assertion 3');

    // ─────────────────────────────────────────────────────────────
    // Assertion 4: completed null -> no success
    // ─────────────────────────────────────────────────────────────
    console.log('Testing Assertion 4: completed null -> no success...');
    await evaluate([
      '(function() {',
      '  window.__resetHarness();',
      '  sessionStorage.setItem("pos.active_update_job_id", "42");',
      '  window.__api.getUpdateJob = async () => ({ data: { data: { id: 42, status: "completed", result: null } } });',
      '  window.mountUpdateSection();',
      '  return null;',
      '})()'
    ].join('\\n'));
    await delay(300);
    const a4Job = await evaluate('sessionStorage.getItem("pos.active_update_job_id")');
    const a4Reloads = await evaluate('window.__reloadCount');
    const a4SuccessToasts = await evaluate('window.__toasts.filter(t => t.type === "success").length');
    assert.equal(a4Job, '42', 'Completed job with null result must NOT clear activeJobId');
    assert.equal(a4Reloads, 0, 'Completed job with null result must NOT reload');
    assert.equal(a4SuccessToasts, 0, 'Completed job with null result must NOT toast success');
    await evaluate('window.unmountUpdateSection(); null;');
    console.log('PASS Assertion 4');

    // ─────────────────────────────────────────────────────────────
    // Assertion 5: handoff once and indeterminate while IPC gated
    // ─────────────────────────────────────────────────────────────
    console.log('Testing Assertion 5: handoff once and indeterminate while IPC gated...');
    await evaluate([
      '(function() {',
      '  window.__resetHarness();',
      '  sessionStorage.setItem("pos.active_update_job_id", "42");',
      '  window.__ipcGatePromise = new Promise((resolve) => { window.__ipcGateResolve = resolve; });',
      '  window.__api.getUpdateJob = async () => ({',
      '    data: {',
      '      data: {',
      '        id: 42,',
      '        status: "completed",',
      '        result: { ok: true, requires_desktop_handoff: true, handoff_version: "1.2.0" }',
      '      }',
      '    }',
      '  });',
      '  window.mountUpdateSection();',
      '  return null;',
      '})()'
    ].join('\\n'));
    await delay(300);
    const a5DeltaCallsFirst = await evaluate('window.__applyStagedDeltaCalls');
    const a5Marker = await evaluate('sessionStorage.getItem("pos.update_handoff_42")');
    assert.equal(a5DeltaCallsFirst, 1, 'Handoff must be invoked exactly once');
    assert.equal(a5Marker, 'running', 'Handoff attempt marker must be set to running in sessionStorage');

    // While gated, verify indeterminate stage display (not fake downloaded bytes)
    const a5Text = await evaluate('document.body.textContent');
    assert.ok(a5Text.includes('تسليم التحديث لخدمة سطح المكتب') || a5Text.includes('desktop_handoff_pending'), 'Handoff pending stage must be displayed');

    // Release IPC
    await evaluate('window.__ipcGateResolve({ ok: true }); null;');
    await delay(300);
    const a5FinalMarker = await evaluate('sessionStorage.getItem("pos.update_handoff_42")');
    assert.equal(a5FinalMarker, 'done', 'Verified handoff must set marker to done');
    const a5FinalJob = await evaluate('sessionStorage.getItem("pos.active_update_job_id")');
    assert.equal(a5FinalJob, null, 'Safe settlement must clear activeJobId from sessionStorage');
    await evaluate('window.unmountUpdateSection(); null;');
    console.log('PASS Assertion 5');

    // ─────────────────────────────────────────────────────────────
    // Assertion 6: remount during IPC/failure -> no duplicate
    // ─────────────────────────────────────────────────────────────
    console.log('Testing Assertion 6: remount during IPC/failure -> no duplicate...');
    await evaluate([
      '(function() {',
      '  window.__resetHarness();',
      '  sessionStorage.setItem("pos.active_update_job_id", "42");',
      '  sessionStorage.setItem("pos.update_handoff_42", "failed");',
      '  window.__applyStagedDeltaCalls = 0;',
      '  window.__api.getUpdateJob = async () => ({',
      '    data: {',
      '      data: {',
      '        id: 42,',
      '        status: "completed",',
      '        result: { ok: true, requires_desktop_handoff: true, handoff_version: "1.2.0" }',
      '      }',
      '    }',
      '  });',
      '  window.mountUpdateSection();',
      '  return null;',
      '})()'
    ].join('\\n'));
    await delay(300);
    const a6DeltaCalls = await evaluate('window.__applyStagedDeltaCalls');
    assert.equal(a6DeltaCalls, 0, 'Remount after prior handoff failure must NEVER reissue IPC');
    const a6NeedsAttention = await evaluate('document.body.textContent.includes("المهمة بحاجة لمراجعة الحالة")');
    assert.equal(a6NeedsAttention, true, 'Remount with prior handoff failure must stay in needs_attention');
    await evaluate('window.unmountUpdateSection(); null;');
    console.log('PASS Assertion 6');

    // ─────────────────────────────────────────────────────────────
    // Assertion 7: network lost admission sentinel remount no POST
    // ─────────────────────────────────────────────────────────────
    console.log('Testing Assertion 7: network lost admission sentinel remount no POST...');
    await evaluate([
      '(function() {',
      '  window.__resetHarness();',
      '  sessionStorage.setItem("pos.update_admission_unknown", "true");',
      '  window.__apiCalls.applyUpdate = 0;',
      '  window.mountUpdateSection();',
      '  return null;',
      '})()'
    ].join('\\n'));
    await delay(200);
    const a7ApplyCalls = await evaluate('window.__apiCalls.applyUpdate || 0');
    assert.equal(a7ApplyCalls, 0, 'Admission unknown sentinel must NOT trigger automatic POST on mount');
    const a7BannerText = await evaluate('document.body.textContent');
    assert.ok(a7BannerText.includes('حالة إرسال التحديث غير مؤكدة'), 'Admission unknown banner must be rendered');
    assert.ok(a7BannerText.includes('إلغاء القفل بعد التحقق'), 'Manual unlock button must be available');
    await evaluate('window.unmountUpdateSection(); null;');
    console.log('PASS Assertion 7');

    // ─────────────────────────────────────────────────────────────
    // Assertion 8: zero bytes width 0
    // ─────────────────────────────────────────────────────────────
    console.log('Testing Assertion 8: zero bytes width 0...');
    await evaluate([
      '(function() {',
      '  window.mountUpdateProgress({',
      '    step: "downloading",',
      '    percent: 0,',
      '    transferredBytes: 0,',
      '    totalBytes: 1000000',
      '  });',
      '  return null;',
      '})()'
    ].join('\\n'));
    await delay(100);
    const a8BarWidth = await evaluate([
      '(function() {',
      '  const divs = document.querySelectorAll("div");',
      '  for (let i = 0; i < divs.length; i++) {',
      '    if (divs[i].style && divs[i].style.width) return divs[i].style.width;',
      '  }',
      '  return null;',
      '})()'
    ].join('\\n'));
    assert.equal(a8BarWidth, '0%', '0 transferred bytes must render progress bar with style width 0%');
    const a8Text = await evaluate('document.getElementById("progress-root").textContent');
    assert.ok(a8Text.includes('0%'), 'Rendered text must display 0%');
    await evaluate('window.unmountUpdateProgress(); null;');
    console.log('PASS Assertion 8');

    // ─────────────────────────────────────────────────────────────
    // Assertion 9: channel autoPreview=false resets old preview
    // ─────────────────────────────────────────────────────────────
    console.log('Testing Assertion 9: channel autoPreview=false resets old preview...');
    await evaluate([
      '(function() {',
      '  window.__resetHarness();',
      '  window.__apiCalls.previewChannelTransition = 0;',
      '  window.mountChannelHook("stable", { autoPreview: false });',
      '  return null;',
      '})()'
    ].join('\\n'));
    await delay(100);
    const a9PreviewCalls = await evaluate('window.__apiCalls.previewChannelTransition || 0');
    const a9Preview = await evaluate('window.__channelHook ? window.__channelHook.preview : null');
    assert.equal(a9PreviewCalls, 0, 'autoPreview: false must NOT call previewChannelTransition');
    assert.equal(a9Preview, null, 'preview must be null when autoPreview: false');
    await evaluate('window.unmountChannelHook(); null;');
    console.log('PASS Assertion 9');

    console.log('\\nALL 9 ADMIN UPDATE UI ASSERTIONS PASSED ON REAL REACT DOM!\\n');
    app.exit(0);
  } catch (error) {
    console.error(error.stack || error);
    app.exit(1);
  }
});
`;

  fs.writeFileSync(path.join(lab, 'main.cjs'), mainScript);

  const env = { ...process.env };
  delete env.ELECTRON_RUN_AS_NODE;
  const electronPackage = path.join(root, 'node_modules/electron');
  if (!fs.existsSync(path.join(electronPackage, 'dist/electron.exe'))) {
    execFileSync(process.execPath, [path.join(electronPackage, 'install.js')], {
      env: { ...env, ELECTRON_SKIP_BINARY_DOWNLOAD: '' },
      windowsHide: true,
      stdio: 'inherit'
    });
  }

  const electron = require(electronPackage);
  assert.ok(fs.existsSync(electron), 'Pinned test Electron runtime was not installed');

  const child = spawn(electron, [path.join(lab, 'main.cjs')], {
    env,
    windowsHide: true,
    stdio: ['ignore', 'pipe', 'pipe']
  });

  let output = '';
  child.stdout.on('data', (d) => { output += d; process.stdout.write(d); });
  child.stderr.on('data', (d) => { output += d; process.stderr.write(d); });

  const code = await new Promise((resolve, reject) => {
    const timer = setTimeout(() => {
      child.kill();
      reject(new Error('Admin Update UI test timeout (30s)'));
    }, 30000);
    child.on('error', reject);
    child.on('exit', (exitCode) => {
      clearTimeout(timer);
      resolve(exitCode);
    });
  });

  assert.equal(code, 0, output);
} finally {
  try {
    fs.rmSync(lab, { recursive: true, force: true, maxRetries: 10, retryDelay: 100 });
  } catch {}
}
