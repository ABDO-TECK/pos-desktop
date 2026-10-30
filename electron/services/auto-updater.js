const { app, dialog, ipcMain } = require('electron');
const { autoUpdater } = require('electron-updater');
const semver = require('semver');
const { assessReleaseCompatibility } = require('../utils/release-version-policy');
const { getDataDir, getAppUnpackedPath } = require('../utils/paths');
const { readAppVersion } = require('../utils/migration/metadata');
const {
  classifyVersionChannel,
  readUpdateChannel,
  selectSetupRelease,
} = require('../utils/update-channel-policy');
const {
  acquireUpdateLock,
  heartbeatUpdateLock,
  isActiveUpdateState,
  readUpdateState,
  releaseUpdateLock,
  writeUpdateState,
  writeUpdateStateIfOwned,
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
let activeCheckId = 0;
let activeCheckContext = null;
let trustedReadyContext = null;

function isTargetEligible(targetVersion, clientChannel, deployedVersion) {
  if (!targetVersion || !clientChannel || !deployedVersion) return false;
  const cleanTarget = String(targetVersion).trim().replace(/^v/i, '');
  const cleanDeployed = String(deployedVersion).trim().replace(/^v/i, '');
  if (!semver.valid(cleanTarget) || !semver.valid(cleanDeployed)) return false;

  const normalizedChannel = String(clientChannel || '').trim().toLowerCase();
  const effectiveChannel = normalizedChannel === 'rc' ? 'stable' : normalizedChannel;
  if (!['stable', 'beta'].includes(effectiveChannel)) return false;

  const targetChannel = classifyVersionChannel(cleanTarget);
  if (targetChannel !== effectiveChannel) return false;

  if (!semver.gt(cleanTarget, cleanDeployed)) return false;

  if (semver.lt(cleanDeployed, '1.0.0') && semver.gte(cleanTarget, '1.0.0')) return false;

  const compatibility = assessReleaseCompatibility(cleanDeployed, cleanTarget);
  if (!compatibility.compatible) return false;

  return true;
}

function sanitizeStatusObject(targetStatus) {
  const currentChannel = readUpdateChannel(getDataDir(), getAppUnpackedPath());
  const deployedVersion = readAppVersion();
  const targetVer = targetStatus.updateInfo?.version;

  const sanitized = { ...targetStatus };

  if (targetVer && !isTargetEligible(targetVer, currentChannel, deployedVersion)) {
    sanitized.updateInfo = null;
    sanitized.canInstall = false;
    sanitized.progress = null;
    if (['update_available', 'ready_to_install', 'downloading'].includes(sanitized.state)) {
      sanitized.state = 'idle';
    }
  }

  if (sanitized.state !== 'ready_to_install') {
    sanitized.canInstall = false;
  }

  return sanitized;
}

function getSanitizedStatus() {
  status = sanitizeStatusObject(status);
  return status;
}

function stopFullReadyHeartbeat() {
  if (fullReadyHeartbeatTimer !== null) {
    clearInterval(fullReadyHeartbeatTimer);
    fullReadyHeartbeatTimer = null;
  }
}

function startFullReadyHeartbeat(targetVersion, ownerId) {
  stopFullReadyHeartbeat();
  if (!targetVersion || !ownerId) return false;

  const refresh = () => {
    const storageDir = getDataDir();
    const currentChannel = readUpdateChannel(storageDir, getAppUnpackedPath());
    const deployedVersion = readAppVersion();
    if (!isTargetEligible(targetVersion, currentChannel, deployedVersion)) {
      stopFullReadyHeartbeat();
      return false;
    }
    const current = readUpdateState(storageDir);
    if (
      !current
      || current.state !== 'full_ready_to_install'
      || current.operation !== 'electron_full_download'
      || current.owner_id !== ownerId
      || current.target_version !== targetVersion
    ) {
      stopFullReadyHeartbeat();
      return false;
    }
    const casResult = writeUpdateStateIfOwned(storageDir, 'full_ready_to_install', {
      operation: 'electron_full_download',
      owner_id: ownerId,
      target_version: targetVersion,
    }, {
      owner_id: ownerId,
      operation: 'electron_full_download',
      state: 'full_ready_to_install',
      target_version: targetVersion,
    });

    if (!casResult.ok) {
      stopFullReadyHeartbeat();
      return false;
    }
    return true;
  };

  const initialSuccess = refresh();
  if (!initialSuccess) {
    stopFullReadyHeartbeat();
    return false;
  }
  fullReadyHeartbeatTimer = setInterval(refresh, 30_000);
  fullReadyHeartbeatTimer.unref?.();
  return true;
}

function restorePendingFullUpdate() {
  const storageDir = getDataDir();
  const state = readUpdateState(storageDir);
  if (!state || state.state !== 'full_ready_to_install' || !isActiveUpdateState(state)) {
    trustedReadyContext = null;
    return;
  }

  // Guard: Only restore state managed by electron_full_download; never touch delta/transition states
  if (state.operation !== 'electron_full_download' || !state.owner_id) {
    trustedReadyContext = null;
    return;
  }

  const currentChannel = readUpdateChannel(storageDir, getAppUnpackedPath());
  const deployedVersion = readAppVersion();
  const targetVersion = state.target_version || '';

  if (!isTargetEligible(targetVersion, currentChannel, deployedVersion)) {
    try {
      writeUpdateStateIfOwned(storageDir, 'failed', {
        operation: 'electron_full_download',
        owner_id: state.owner_id,
        target_version: targetVersion,
        reason_code: 'channel_mismatch',
        error: `Pending update ${targetVersion} is not eligible for active channel ${currentChannel}`,
      }, {
        owner_id: state.owner_id,
        operation: 'electron_full_download',
        state: 'full_ready_to_install',
        target_version: targetVersion,
      });
    } catch {
      // Keep startup available even if the stale state cannot be rewritten.
    }
    trustedReadyContext = null;
    status = {
      ...status,
      state: 'error',
      updateInfo: null,
      progress: null,
      error: `Pending update ${targetVersion} is not eligible for active channel ${currentChannel}`,
      canInstall: false,
    };
    return;
  }

  const heartbeatStarted = startFullReadyHeartbeat(targetVersion, state.owner_id);
  if (!heartbeatStarted) {
    trustedReadyContext = null;
    return;
  }

  trustedReadyContext = {
    ownerId: state.owner_id,
    targetVersion,
    channel: currentChannel,
    deployedVersion,
  };

  status = {
    ...status,
    state: 'ready_to_install',
    updateInfo: { version: targetVersion },
    progress: null,
    error: null,
    canInstall: true,
    coordination: null,
  };
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
    const remoteVer = typeof info?.version === 'string' ? info.version.trim().replace(/^v/i, '') : null;
    if (!remoteVer) {
      console.warn('[AutoUpdater] Ignored update-available event with missing or invalid version payload');
      return;
    }

    // Tie available event to active selected release from current check
    if (!activeCheckContext || activeCheckContext.checkId !== activeCheckId) {
      console.warn('[AutoUpdater] Ignored update-available event from stale or missing check context');
      return;
    }
    if (!activeCheckContext.targetVersion || remoteVer !== activeCheckContext.targetVersion) {
      console.warn(`[AutoUpdater] Ignored update-available for ${remoteVer} differing from selected ${activeCheckContext.targetVersion}`);
      return;
    }

    const currentChannel = readUpdateChannel(getDataDir(), getAppUnpackedPath());
    const deployedVersion = readAppVersion();
    if (!isTargetEligible(remoteVer, currentChannel, deployedVersion)) {
      console.warn(`[AutoUpdater] Ignored ineligible update ${remoteVer} for channel ${currentChannel} (deployed ${deployedVersion})`);
      publishStatus('update_not_available', { updateInfo: null, canInstall: false, progress: null, error: null });
      return;
    }
    publishStatus('update_available', { updateInfo: info, progress: null, error: null, canInstall: false });
  });

  autoUpdater.on('update-not-available', (info) => {
    if (!activeCheckContext || activeCheckContext.checkId !== activeCheckId) {
      return;
    }
    publishStatus('update_not_available', { updateInfo: info, progress: null, error: null, canInstall: false });
  });

  autoUpdater.on('download-progress', (progress) => {
    // Only emit progress if our own download lease is actively running
    if (!activeDownloadLease) {
      return;
    }
    const currentChannel = readUpdateChannel(getDataDir(), getAppUnpackedPath());
    const deployedVersion = readAppVersion();
    if (!isTargetEligible(activeDownloadLease.target_version, currentChannel, deployedVersion)) {
      return;
    }
    heartbeatUpdateLock(activeDownloadLease, 'downloading', {
      progress_percent: progress?.percent || 0,
    });
    publishStatus('downloading', { progress, error: null, canInstall: false });
    if (mainWindow && !mainWindow.isDestroyed()) {
      mainWindow.setProgressBar(Math.max(0, Math.min(1, (progress.percent || 0) / 100)));
    }
  });

  autoUpdater.on('update-downloaded', (info) => {
    if (mainWindow && !mainWindow.isDestroyed()) {
      mainWindow.setProgressBar(-1);
    }

    // AutoDownload is false: update-downloaded MUST come from our own active download request
    if (!activeDownloadLease) {
      console.warn('[AutoUpdater] Ignored unsolicited update-downloaded event without active download lease');
      return;
    }

    const currentCheckId = activeCheckContext?.checkId || activeCheckId;
    if (activeDownloadLease.checkId && activeDownloadLease.checkId !== currentCheckId) {
      console.warn('[AutoUpdater] Ignored update-downloaded from superseded check context');
      return;
    }

    const remoteVer = typeof info?.version === 'string' ? info.version.trim().replace(/^v/i, '') : null;
    if (!remoteVer) {
      console.warn('[AutoUpdater] Ignored update-downloaded with missing or invalid version payload');
      return;
    }

    if (remoteVer !== activeDownloadLease.target_version) {
      console.warn(`[AutoUpdater] Ignored update-downloaded for ${remoteVer} differing from active target ${activeDownloadLease.target_version}`);
      return;
    }

    if (activeCheckContext?.targetVersion && activeCheckContext.targetVersion !== activeDownloadLease.target_version) {
      console.warn('[AutoUpdater] Ignored update-downloaded differing from active check context target');
      return;
    }

    const storageDir = getDataDir();
    const currentState = readUpdateState(storageDir);
    if (
      !currentState
      || currentState.owner_id !== activeDownloadLease.owner_id
      || currentState.operation !== 'electron_full_download'
      || currentState.target_version !== activeDownloadLease.target_version
      || !['downloading', 'full_ready_to_install'].includes(currentState.state)
    ) {
      console.warn('[AutoUpdater] Downloaded update ignored: state ownership lost or foreign operation active');
      releaseUpdateLock(activeDownloadLease);
      activeDownloadLease = null;
      stopFullReadyHeartbeat();
      return;
    }

    const currentChannel = readUpdateChannel(storageDir, getAppUnpackedPath());
    const deployedVersion = readAppVersion();

    if (!isTargetEligible(remoteVer, currentChannel, deployedVersion)) {
      console.warn(`[AutoUpdater] Downloaded update ${remoteVer} is not eligible for channel ${currentChannel} (deployed ${deployedVersion})`);
      writeUpdateStateIfOwned(storageDir, 'failed', {
        operation: 'electron_full_download',
        owner_id: activeDownloadLease.owner_id,
        target_version: remoteVer,
        reason_code: 'channel_mismatch',
        error: `Update ${remoteVer} is not eligible for active channel ${currentChannel}`,
      }, {
        owner_id: activeDownloadLease.owner_id,
        operation: 'electron_full_download',
        state: ['downloading', 'full_ready_to_install'],
        target_version: remoteVer,
      });
      releaseUpdateLock(activeDownloadLease);
      activeDownloadLease = null;
      stopFullReadyHeartbeat();
      publishStatus('update_not_available', {
        updateInfo: null,
        progress: null,
        error: `Update ${remoteVer} is not eligible for active channel ${currentChannel}`,
        canInstall: false,
      });
      return;
    }

    // Eligible: atomically transition state if still owned
    const casResult = writeUpdateStateIfOwned(storageDir, 'full_ready_to_install', {
      operation: 'electron_full_download',
      owner_id: activeDownloadLease.owner_id,
      target_version: remoteVer,
    }, {
      owner_id: activeDownloadLease.owner_id,
      operation: 'electron_full_download',
      state: ['downloading', 'full_ready_to_install'],
      target_version: remoteVer,
    });

    if (!casResult.ok) {
      console.warn('[AutoUpdater] Failed to transition to full_ready_to_install on update-downloaded:', casResult.reason);
      return;
    }

    const heartbeatOk = startFullReadyHeartbeat(remoteVer, activeDownloadLease.owner_id);
    if (!heartbeatOk) {
      console.warn('[AutoUpdater] Failed to start heartbeat on update-downloaded');
      return;
    }

    activeDownloadLease.verifiedDownloaded = true;
    activeDownloadLease.downloadedVersion = remoteVer;

    trustedReadyContext = {
      ownerId: activeDownloadLease.owner_id,
      targetVersion: remoteVer,
      channel: currentChannel,
      deployedVersion,
    };

    publishStatus('ready_to_install', { updateInfo: { version: remoteVer }, progress: null, error: null, canInstall: true });
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
    return getSanitizedStatus();
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

  const storageDir = getDataDir();
  const currentState = readUpdateState(storageDir);

  // Early return if actively downloading under our own valid lease
  if (
    activeDownloadLease
    && currentState
    && currentState.owner_id === activeDownloadLease.owner_id
    && currentState.operation === 'electron_full_download'
    && currentState.target_version === activeDownloadLease.target_version
    && currentState.state === 'downloading'
  ) {
    return getSanitizedStatus();
  }

  // Early return if update is already verified and ready to install
  const channel = readUpdateChannel(storageDir, getAppUnpackedPath());
  const deployedVersion = readAppVersion();
  if (
    trustedReadyContext
    && trustedReadyContext.channel === channel
    && trustedReadyContext.deployedVersion === deployedVersion
    && currentState
    && currentState.state === 'full_ready_to_install'
    && currentState.operation === 'electron_full_download'
    && currentState.owner_id === trustedReadyContext.ownerId
    && currentState.target_version === trustedReadyContext.targetVersion
    && isActiveUpdateState(currentState)
  ) {
    return getSanitizedStatus();
  }

  const currentCheckId = ++activeCheckId;

  activeCheckContext = {
    checkId: currentCheckId,
    channel,
    deployedVersion,
    targetVersion: null,
  };

  try {
    publishStatus('checking', { error: null });
    const response = await fetch('https://api.github.com/repos/ABDO-TECK/pos-desktop/releases?per_page=100', {
      headers: { Accept: 'application/vnd.github+json', 'User-Agent': 'POS-Desktop-Updater' },
      signal: AbortSignal.timeout(15000),
    });
    if (!response.ok) throw new Error(`GitHub Setup discovery failed: HTTP ${response.status}`);
    const releases = await response.json();
    if (!Array.isArray(releases)) throw new Error('Invalid GitHub release list.');

    // Stale async check verification: ignore if checkId superseded or environment changed
    if (currentCheckId !== activeCheckId) {
      return getSanitizedStatus();
    }
    const channelNow = readUpdateChannel(storageDir, getAppUnpackedPath());
    const deployedNow = readAppVersion();
    if (channelNow !== channel || deployedNow !== deployedVersion) {
      return getSanitizedStatus();
    }

    const release = selectSetupRelease(releases, channel, deployedVersion);
    if (!release) {
      publishStatus('update_not_available', { updateInfo: null, canInstall: false, progress: null, error: null });
      return getSanitizedStatus();
    }
    const expectedMetadataName = channel === 'beta' ? 'beta.yml' : 'latest.yml';
    const metadata = (release.assets || []).find(asset => asset && asset.name === expectedMetadataName);
    if (!metadata) {
      publishStatus('update_not_available', { updateInfo: null, canInstall: false, progress: null, error: null });
      return getSanitizedStatus();
    }

    const targetVersion = String(release.tag_name || '').trim().replace(/^v/i, '');
    activeCheckContext.targetVersion = targetVersion;

    const baseUrl = `https://github.com/ABDO-TECK/pos-desktop/releases/download/${encodeURIComponent(release.tag_name)}/`;
    autoUpdater.setFeedURL({ provider: 'generic', url: baseUrl });
    autoUpdater.channel = metadata.name.slice(0, -4);
    autoUpdater.allowPrerelease = channel === 'beta';
    autoUpdater.allowDowngrade = false;
    await autoUpdater.checkForUpdates();

    if (currentCheckId !== activeCheckId) {
      return getSanitizedStatus();
    }
    return getSanitizedStatus();
  } catch (err) {
    if (currentCheckId !== activeCheckId) {
      return getSanitizedStatus();
    }
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
    let currentChannel = readUpdateChannel(storageDir, getAppUnpackedPath());
    let deployedVersion = readAppVersion();

    if (status.state !== 'update_available' || !status.updateInfo?.version) {
      await checkForUpdates();
      if (status.state !== 'update_available') {
        return getSanitizedStatus();
      }
    }

    currentChannel = readUpdateChannel(storageDir, getAppUnpackedPath());
    deployedVersion = readAppVersion();

    const targetVersion = status.updateInfo?.version || null;
    if (!isTargetEligible(targetVersion, currentChannel, deployedVersion)) {
      return publishStatus('error', {
        updateInfo: null,
        canInstall: false,
        error: `Update ${targetVersion} is not eligible for active channel ${currentChannel}.`,
      });
    }

    const lease = acquireUpdateLock(storageDir, 'electron_full_download', {
      target_version: targetVersion,
    });
    if (!lease.acquired) {
      return publishCoordinationBlocked(lease);
    }
    const currentCheckId = activeCheckContext?.checkId || activeCheckId;
    activeDownloadLease = {
      ...lease,
      target_version: targetVersion,
      checkId: currentCheckId,
      verifiedDownloaded: false,
    };
    const capturedLease = activeDownloadLease;
    const capturedOwnerId = lease.owner_id;
    const capturedTargetVersion = targetVersion;

    try {
      writeUpdateState(storageDir, 'downloading', {
        operation: 'electron_full_download',
        owner_id: capturedOwnerId,
        target_version: capturedTargetVersion,
      });
      publishStatus('downloading', { error: null, canInstall: false });

      await autoUpdater.downloadUpdate();

      // CRITICAL: Re-read channel, deployed version, and current owned state AFTER inflight download
      const channelAfterDownload = readUpdateChannel(storageDir, getAppUnpackedPath());
      const deployedAfterDownload = readAppVersion();
      const stateAfterDownload = readUpdateState(storageDir);

      const leaseStillActive = activeDownloadLease
        && activeDownloadLease.owner_id === capturedOwnerId
        && activeDownloadLease.target_version === capturedTargetVersion
        && (!capturedLease.checkId || activeDownloadLease.checkId === capturedLease.checkId);

      const stillEligible = isTargetEligible(capturedTargetVersion, channelAfterDownload, deployedAfterDownload);

      const hasMatchingEvent = Boolean(
        capturedLease.verifiedDownloaded
        || (activeDownloadLease && activeDownloadLease.verifiedDownloaded)
        || (trustedReadyContext && trustedReadyContext.ownerId === capturedOwnerId && trustedReadyContext.targetVersion === capturedTargetVersion)
      );

      const isReadyState = stateAfterDownload
        && stateAfterDownload.owner_id === capturedOwnerId
        && stateAfterDownload.operation === 'electron_full_download'
        && stateAfterDownload.target_version === capturedTargetVersion
        && stateAfterDownload.state === 'full_ready_to_install';

      if (!hasMatchingEvent || !isReadyState || !leaseStillActive || !stillEligible) {
        stopFullReadyHeartbeat();
        // Only write failed if we still own the downloading state; never overwrite settled failed or foreign states
        if (stateAfterDownload && stateAfterDownload.owner_id === capturedOwnerId && stateAfterDownload.operation === 'electron_full_download' && stateAfterDownload.state === 'downloading') {
          writeUpdateStateIfOwned(storageDir, 'failed', {
            operation: 'electron_full_download',
            owner_id: capturedOwnerId,
            target_version: capturedTargetVersion,
            reason_code: !stillEligible ? 'channel_mismatch' : (!hasMatchingEvent ? 'missing_download_event' : 'download_invalidated'),
            error: !stillEligible
              ? `Update ${capturedTargetVersion} is no longer eligible after download for channel ${channelAfterDownload}`
              : (!hasMatchingEvent
                ? 'Download completed without a verified matching update-downloaded event'
                : 'Download state invalidated or lease lost during download'),
          }, {
            owner_id: capturedOwnerId,
            operation: 'electron_full_download',
            state: 'downloading',
            target_version: capturedTargetVersion,
          });
        }
        if (activeDownloadLease && activeDownloadLease.owner_id === capturedOwnerId) {
          releaseUpdateLock(activeDownloadLease);
          activeDownloadLease = null;
        }
        trustedReadyContext = null;
        return publishStatus('error', {
          updateInfo: null,
          canInstall: false,
          error: !hasMatchingEvent
            ? 'Download finished without verified update package event'
            : `Download aborted: ${!stillEligible ? 'channel or version became ineligible' : 'operation ownership was lost or state settled'}`,
        });
      }

      // Verified ready: matching event already performed atomic transition and started heartbeat
      releaseUpdateLock(capturedLease);
      if (activeDownloadLease && activeDownloadLease.owner_id === capturedOwnerId) {
        activeDownloadLease = null;
      }
      publishStatus('ready_to_install', {
        updateInfo: { version: capturedTargetVersion },
        progress: null,
        error: null,
        canInstall: true,
      });
      return getSanitizedStatus();
    } catch (err) {
      try {
        writeUpdateStateIfOwned(storageDir, 'failed', {
          operation: 'electron_full_download',
          owner_id: capturedOwnerId,
          target_version: capturedTargetVersion,
          error: normalizeUpdaterError(err),
        }, {
          owner_id: capturedOwnerId,
          operation: 'electron_full_download',
          state: ['downloading', 'full_ready_to_install'],
          target_version: capturedTargetVersion,
        });
      } catch {
        // Continue to release lock
      }
      stopFullReadyHeartbeat();
      trustedReadyContext = null;
      if (activeDownloadLease && activeDownloadLease.owner_id === capturedOwnerId) {
        releaseUpdateLock(activeDownloadLease);
        activeDownloadLease = null;
      }
      throw err;
    }
  } catch (err) {
    return publishStatus('error', { error: normalizeUpdaterError(err), canInstall: false });
  }
}

async function installUpdate() {
  const storageDir = getDataDir();
  const currentChannel = readUpdateChannel(storageDir, getAppUnpackedPath());
  const deployedVersion = readAppVersion();
  const targetBeforePrompt = status.updateInfo?.version || null;

  // Validate state and exact ownership before prompt
  const stateBeforePrompt = readUpdateState(storageDir);
  if (
    !status.canInstall
    || !targetBeforePrompt
    || !trustedReadyContext
    || !trustedReadyContext.ownerId
    || trustedReadyContext.targetVersion !== targetBeforePrompt
    || trustedReadyContext.channel !== currentChannel
    || trustedReadyContext.deployedVersion !== deployedVersion
    || !isTargetEligible(targetBeforePrompt, currentChannel, deployedVersion)
    || !stateBeforePrompt
    || stateBeforePrompt.state !== 'full_ready_to_install'
    || stateBeforePrompt.operation !== 'electron_full_download'
    || stateBeforePrompt.target_version !== targetBeforePrompt
    || stateBeforePrompt.owner_id !== trustedReadyContext.ownerId
    || !isActiveUpdateState(stateBeforePrompt)
  ) {
    stopFullReadyHeartbeat();
    trustedReadyContext = null;
    return publishStatus('error', {
      updateInfo: null,
      canInstall: false,
      error: 'Update is not downloaded or no longer eligible for active channel.',
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
    return getSanitizedStatus();
  }

  // CRITICAL: Re-check AFTER confirmation await
  // Must be EXACT same target confirmed before prompt, and EXACT owned state
  const channelAfterConfirm = readUpdateChannel(storageDir, getAppUnpackedPath());
  const deployedAfterConfirm = readAppVersion();
  const stateAfterConfirm = readUpdateState(storageDir);

  if (
    channelAfterConfirm !== currentChannel
    || deployedAfterConfirm !== deployedVersion
    || !trustedReadyContext
    || !stateAfterConfirm
    || stateAfterConfirm.owner_id !== stateBeforePrompt.owner_id
    || stateAfterConfirm.owner_id !== trustedReadyContext.ownerId
    || stateAfterConfirm.state !== 'full_ready_to_install'
    || stateAfterConfirm.operation !== 'electron_full_download'
    || stateAfterConfirm.target_version !== targetBeforePrompt
    || !isTargetEligible(targetBeforePrompt, channelAfterConfirm, deployedAfterConfirm)
    || !isActiveUpdateState(stateAfterConfirm)
  ) {
    stopFullReadyHeartbeat();
    trustedReadyContext = null;
    return publishStatus('error', {
      updateInfo: null,
      canInstall: false,
      error: 'Active channel changed or update is no longer eligible after confirmation.',
    });
  }

  const lease = acquireUpdateLock(storageDir, 'electron_full_install', {
    target_version: targetBeforePrompt,
  });
  if (!lease.acquired) {
    return publishCoordinationBlocked(lease);
  }

  stopFullReadyHeartbeat();
  activeInstallLease = lease;
  try {
    const casResult = writeUpdateStateIfOwned(storageDir, 'installing', {
      operation: 'electron_full_install',
      owner_id: lease.owner_id,
      target_version: targetBeforePrompt,
    }, {
      owner_id: stateAfterConfirm.owner_id,
      operation: 'electron_full_download',
      state: 'full_ready_to_install',
      target_version: targetBeforePrompt,
    });

    if (!casResult.ok) {
      releaseUpdateLock(lease);
      activeInstallLease = null;
      status.canInstall = false;
      trustedReadyContext = null;
      return publishStatus('error', {
        updateInfo: null,
        canInstall: false,
        error: `Could not transition to install state: ${casResult.reason || 'ownership changed'}`,
      });
    }

    publishStatus('restarting', { error: null });
    autoUpdater.quitAndInstall(false, true);
    return status;
  } catch (err) {
    try {
      writeUpdateStateIfOwned(storageDir, 'failed', {
        operation: 'electron_full_install',
        owner_id: lease.owner_id,
        target_version: targetBeforePrompt,
        error: normalizeUpdaterError(err),
      }, {
        owner_id: lease.owner_id,
        operation: 'electron_full_install',
        state: 'installing',
        target_version: targetBeforePrompt,
      });
    } catch {
      // Continue to release lock
    }
    releaseUpdateLock(lease);
    activeInstallLease = null;
    status.canInstall = false;
    trustedReadyContext = null;
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
  const nextStatus = {
    ...status,
    state,
    isPackaged: app.isPackaged,
    ...patch,
  };

  status = sanitizeStatusObject(nextStatus);

  if (status.state !== 'ready_to_install' && status.state !== 'restarting') {
    trustedReadyContext = null;
  }

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
  getUpdaterStatus: getSanitizedStatus,
};
