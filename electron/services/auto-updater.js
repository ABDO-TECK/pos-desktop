const { app, dialog, ipcMain } = require('electron');
const { autoUpdater } = require('electron-updater');
const { assessReleaseCompatibility } = require('../utils/release-version-policy');
const { getDataDir } = require('../utils/paths');
const {
  acquireUpdateLock,
  heartbeatUpdateLock,
  isActiveUpdateState,
  readUpdateState,
  releaseUpdateLock,
  writeUpdateState,
} = require('../utils/update-coordination');

const CHANNEL = 'updater:status';

autoUpdater.autoDownload = false;
autoUpdater.autoInstallOnAppQuit = false;
autoUpdater.logger = app.isPackaged ? null : console;

let mainWindow = null;
let status = {
  state: 'idle',
  isPackaged: app.isPackaged,
  updateInfo: null,
  progress: null,
  error: null,
  canInstall: false,
  coordination: null,
};

let activeDownloadLease = null;
let activeInstallLease = null;
let fullReadyHeartbeatTimer = null;

function stopFullReadyHeartbeat() {
  if (fullReadyHeartbeatTimer !== null) {
    clearInterval(fullReadyHeartbeatTimer);
    fullReadyHeartbeatTimer = null;
  }
}

function startFullReadyHeartbeat(targetVersion, ownerId) {
  stopFullReadyHeartbeat();
  const refresh = () => {
    writeUpdateState(getDataDir(), 'full_ready_to_install', {
      operation: 'electron_full_download',
      owner_id: ownerId || null,
      target_version: targetVersion || null,
    });
  };
  refresh();
  fullReadyHeartbeatTimer = setInterval(refresh, 30_000);
  fullReadyHeartbeatTimer.unref?.();
}

function restorePendingFullUpdate() {
  const state = readUpdateState(getDataDir());
  if (!state || state.state !== 'full_ready_to_install' || !isActiveUpdateState(state)) {
    return;
  }

  const compatibility = assessReleaseCompatibility(app.getVersion(), state.target_version || '');
  if (!compatibility.compatible) {
    try {
      writeUpdateState(getDataDir(), 'failed', {
        operation: state.operation || 'electron_full_download',
        owner_id: state.owner_id || null,
        target_version: state.target_version || null,
        reason_code: compatibility.reasonCode,
        error: compatibility.reason,
      });
    } catch {
      // Keep startup available even if the stale state cannot be rewritten.
    }
    status = {
      ...status,
      state: 'error',
      updateInfo: null,
      progress: null,
      error: compatibility.reason,
      canInstall: false,
    };
    return;
  }

  status = {
    ...status,
    state: 'ready_to_install',
    updateInfo: { version: state.target_version || undefined },
    progress: null,
    error: null,
    canInstall: true,
    coordination: null,
  };
  startFullReadyHeartbeat(state.target_version, state.owner_id);
}

function setupAutoUpdater(window) {
  mainWindow = window;
  registerUpdaterEvents();
  registerUpdaterIpc();
  restorePendingFullUpdate();
  publishStatus(['ready_to_install', 'error'].includes(status.state) ? status.state : 'idle');
}

function registerUpdaterEvents() {
  if (registerUpdaterEvents.registered) return;
  registerUpdaterEvents.registered = true;

  autoUpdater.on('checking-for-update', () => {
    publishStatus('checking');
  });

  autoUpdater.on('update-available', (info) => {
    const currentVer = app.getVersion();
    const remoteVer = info?.version || '';
    const compatibility = assessReleaseCompatibility(currentVer, remoteVer);
    if (!compatibility.compatible) {
      console.warn(`[AutoUpdater] Ignored incompatible update ${remoteVer}: ${compatibility.reason}`);
      publishStatus('update_not_available', { updateInfo: null, canInstall: false });
      return;
    }
    publishStatus('update_available', { updateInfo: info, progress: null, error: null, canInstall: false });
  });

  autoUpdater.on('update-not-available', (info) => {
    publishStatus('update_not_available', { updateInfo: info, progress: null, error: null, canInstall: false });
  });

  autoUpdater.on('download-progress', (progress) => {
    if (activeDownloadLease) {
      heartbeatUpdateLock(activeDownloadLease, 'downloading', {
        progress_percent: progress?.percent || 0,
      });
    }
    publishStatus('downloading', { progress, error: null, canInstall: false });
    if (mainWindow && !mainWindow.isDestroyed()) {
      mainWindow.setProgressBar(Math.max(0, Math.min(1, (progress.percent || 0) / 100)));
    }
  });

  autoUpdater.on('update-downloaded', (info) => {
    if (mainWindow && !mainWindow.isDestroyed()) {
      mainWindow.setProgressBar(-1);
    }
    const compatibility = assessReleaseCompatibility(app.getVersion(), info?.version || '');
    if (!compatibility.compatible) {
      if (activeDownloadLease) {
        try {
          writeUpdateState(getDataDir(), 'failed', {
            operation: 'electron_full_download',
            owner_id: activeDownloadLease.owner_id,
            target_version: info?.version || activeDownloadLease.target_version || null,
            reason_code: compatibility.reasonCode,
            error: compatibility.reason,
          });
        } catch {
          // Continue to release the shared lock even if state persistence fails.
        }
        releaseUpdateLock(activeDownloadLease);
        activeDownloadLease = null;
      }
      publishStatus('update_not_available', {
        updateInfo: null,
        progress: null,
        error: compatibility.reason,
        canInstall: false,
      });
      return;
    }
    if (activeDownloadLease) {
      writeUpdateState(getDataDir(), 'full_ready_to_install', {
        operation: 'electron_full_download',
        owner_id: activeDownloadLease.owner_id,
        target_version: info?.version || activeDownloadLease.target_version || null,
      });
      startFullReadyHeartbeat(
        info?.version || activeDownloadLease.target_version || null,
        activeDownloadLease.owner_id
      );
    }
    publishStatus('ready_to_install', { updateInfo: info, progress: null, error: null, canInstall: true });
  });

  autoUpdater.on('error', (err) => {
    if (mainWindow && !mainWindow.isDestroyed()) {
      mainWindow.setProgressBar(-1);
    }
    publishStatus('error', { error: normalizeUpdaterError(err), canInstall: false });
  });
}

function registerUpdaterIpc() {
  if (registerUpdaterIpc.registered) return;
  registerUpdaterIpc.registered = true;

  const assertTrustedRenderer = (event) => {
    const senderUrl = event.senderFrame?.url || '';
    if (!senderUrl.startsWith('app://pos-app/')) {
      throw new Error('Untrusted renderer');
    }
  };

  ipcMain.handle('updater:get-status', (event) => {
    assertTrustedRenderer(event);
    return status;
  });
  ipcMain.handle('updater:download', async (event) => {
    assertTrustedRenderer(event);
    return downloadUpdate();
  });
  ipcMain.handle('updater:install', async (event) => {
    assertTrustedRenderer(event);
    return installUpdate();
  });
}

async function checkForUpdates() {
  if (!app.isPackaged) {
    return publishStatus('developer_only', {
      error: 'Electron updater is available only in packaged production builds.',
    });
  }

  try {
    publishStatus('checking', { error: null });
    await autoUpdater.checkForUpdates();
    return status;
  } catch (err) {
    return publishStatus('error', { error: normalizeUpdaterError(err), canInstall: false });
  }
}

async function downloadUpdate() {
  if (!app.isPackaged) {
    return publishStatus('developer_only', {
      error: 'Electron updater download is available only in packaged production builds.',
    });
  }

  const storageDir = getDataDir();
  const currentState = readUpdateState(storageDir);
  if (isActiveUpdateState(currentState)) {
    return publishCoordinationBlocked(currentState);
  }

  try {
    if (status.state !== 'update_available') {
      await checkForUpdates();
      if (status.state !== 'update_available') {
        return status;
      }
    }

    const targetVersion = status.updateInfo?.version || null;
    const compatibility = assessReleaseCompatibility(app.getVersion(), targetVersion || '');
    if (!compatibility.compatible) {
      return publishStatus('error', {
        updateInfo: null,
        canInstall: false,
        error: compatibility.reason,
      });
    }
    const lease = acquireUpdateLock(storageDir, 'electron_full_download', {
      target_version: targetVersion,
    });
    if (!lease.acquired) {
      return publishCoordinationBlocked(lease);
    }
    activeDownloadLease = { ...lease, target_version: targetVersion };
    try {
      writeUpdateState(storageDir, 'downloading', {
        operation: 'electron_full_download',
        owner_id: lease.owner_id,
        target_version: targetVersion,
      });
      publishStatus('downloading', { error: null, canInstall: false });
      await autoUpdater.downloadUpdate();
      writeUpdateState(storageDir, 'full_ready_to_install', {
        operation: 'electron_full_download',
        owner_id: lease.owner_id,
        target_version: targetVersion,
      });
      startFullReadyHeartbeat(targetVersion, lease.owner_id);
      releaseUpdateLock(lease);
      activeDownloadLease = null;
      return status;
    } catch (err) {
      try {
        writeUpdateState(storageDir, 'failed', {
          operation: 'electron_full_download',
          owner_id: lease.owner_id,
          target_version: targetVersion,
          error: normalizeUpdaterError(err),
        });
      } catch {
        // The lock release below is still mandatory if state persistence fails.
      }
      releaseUpdateLock(lease);
      activeDownloadLease = null;
      throw err;
    }
  } catch (err) {
    return publishStatus('error', { error: normalizeUpdaterError(err), canInstall: false });
  }
}

async function installUpdate() {
  if (!status.canInstall) {
    return publishStatus('error', {
      error: 'Update is not downloaded yet.',
      canInstall: false,
    });
  }

  const response = await dialog.showMessageBox(mainWindow, {
    type: 'question',
    title: 'تثبيت التحديث',
    message: 'تم تحميل التحديث. هل تريد إعادة تشغيل التطبيق الآن لتثبيته؟',
    buttons: ['إعادة التشغيل الآن', 'لاحقاً'],
    defaultId: 0,
    cancelId: 1,
  });

  if (response.response !== 0) {
    return status;
  }

  const storageDir = getDataDir();
  const currentState = readUpdateState(storageDir);
  if (isActiveUpdateState(currentState) && currentState.state !== 'full_ready_to_install') {
    return publishCoordinationBlocked(currentState);
  }

  const targetVersion = status.updateInfo?.version || currentState?.target_version || null;
  const compatibility = assessReleaseCompatibility(app.getVersion(), targetVersion || '');
  if (!compatibility.compatible) {
    return publishStatus('error', {
      updateInfo: null,
      canInstall: false,
      error: compatibility.reason,
    });
  }
  const lease = acquireUpdateLock(storageDir, 'electron_full_install', {
    target_version: targetVersion,
  });
  if (!lease.acquired) {
    return publishCoordinationBlocked(lease);
  }
  stopFullReadyHeartbeat();
  activeInstallLease = lease;
  try {
    writeUpdateState(storageDir, 'installing', {
      operation: 'electron_full_install',
      owner_id: lease.owner_id,
      target_version: targetVersion,
    });
    publishStatus('restarting', { error: null });
    autoUpdater.quitAndInstall(false, true);
    return status;
  } catch (err) {
    try {
      writeUpdateState(storageDir, 'failed', {
        operation: 'electron_full_install',
        owner_id: lease.owner_id,
        target_version: targetVersion,
        error: normalizeUpdaterError(err),
      });
    } catch {
      // The lock release below is still mandatory if state persistence fails.
    }
    releaseUpdateLock(lease);
    activeInstallLease = null;
    return publishStatus('error', { error: normalizeUpdaterError(err), canInstall: false });
  }
}

function publishCoordinationBlocked(details) {
  const owner = details?.owner || (
    details?.owner_id || details?.operation
      ? {
        owner_id: details.owner_id || null,
        operation: details.operation || 'unknown',
        phase: details.phase || null,
        updated_at: details.updated_at || null,
      }
      : null
  );
  return publishStatus('error', {
    updateInfo: null,
    canInstall: false,
    coordination: {
      reason_code: details?.reason_code || 'update_in_progress',
      owner,
    },
    error: 'Update or sale operation is already in progress. Try again after it completes.',
  });
}

function publishStatus(state, patch = {}) {
  status = {
    ...status,
    state,
    isPackaged: app.isPackaged,
    ...patch,
  };

  if (mainWindow && !mainWindow.isDestroyed()) {
    mainWindow.webContents.send(CHANNEL, status);
  }

  return status;
}

function normalizeUpdaterError(err) {
  const message = err && err.message ? err.message : String(err || 'Unknown updater error');
  const lower = message.toLowerCase();

  if (lower.includes('latest.yml') || lower.includes('latest-mac.yml')) {
    return `update metadata not found: missing latest.yml (${message})`;
  }

  if (lower.includes('404') || lower.includes('not found')) {
    return `no GitHub release found or update metadata not found (${message})`;
  }

  if (lower.includes('ssl') || lower.includes('certificate')) {
    return `network/SSL error: ${message}`;
  }

  return message;
}

module.exports = {
  setupAutoUpdater,
  checkForUpdates,
  downloadUpdate,
  installUpdate,
};
