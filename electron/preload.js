const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('electronAPI', {
  getVersion: () => ipcRenderer.invoke('get-version'),
  getQZCert: () => ipcRenderer.invoke('qz-get-cert'),
  signQZMessage: (data) => ipcRenderer.invoke('qz-sign', data),
  backup: {
    restore: () => ipcRenderer.invoke('backup:restore'),
  },
  auth: {
    clearSession: () => ipcRenderer.invoke('auth:clear-session'),
    recoverPassword: (payload) => ipcRenderer.invoke('auth:recover-password', payload),
  },
  setup: {
    getInitialAdmin: () => ipcRenderer.invoke('setup:get-initial-admin'),
    acknowledgeInitialAdmin: () => ipcRenderer.invoke('setup:acknowledge-initial-admin'),
    factoryReset: (options) => ipcRenderer.invoke('system:factory-reset', options),
  },

  // ── Manual Desktop Updates ──
  updater: {
    download: () => ipcRenderer.invoke('updater:download'),
    install: () => ipcRenderer.invoke('updater:install'),
    getStatus: () => ipcRenderer.invoke('updater:get-status'),
    onStatus: (callback) => {
      const listener = (_event, status) => callback(status);
      ipcRenderer.on('updater:status', listener);
      return () => ipcRenderer.removeListener('updater:status', listener);
    },
  },
});

contextBridge.exposeInMainWorld('posRuntime', {
  getApiBaseUrl: () => ipcRenderer.invoke('get-api-base-url'),
  getDeltaCapability: () => ipcRenderer.invoke('delta:get-capability'),
  enableLanAccess: () => ipcRenderer.invoke('network:enable-lan'),
  disableLanAccess: () => ipcRenderer.invoke('network:disable-lan'),
  applyStagedDelta: (version) => ipcRenderer.invoke('delta:apply-staged', version),
});
