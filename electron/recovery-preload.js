const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('posRecovery', {
  getLastError: () => ipcRenderer.invoke('recovery:get-last-error'),
  getDiagnostics: () => ipcRenderer.invoke('recovery:get-diagnostics'),
  retryStartup: () => ipcRenderer.invoke('recovery:retry-startup'),
  getRollbackReadiness: () => ipcRenderer.invoke('recovery:get-rollback-readiness'),
  runRollbackDryRun: () => ipcRenderer.invoke('recovery:run-rollback-dry-run'),
  executeRollback: (options) => ipcRenderer.invoke('recovery:execute-mysql-rollback', options),
  prepareRollbackRestoreStaging: (options) => ipcRenderer.invoke('recovery:prepare-rollback-restore-staging', options),
  runFinalRollbackSwitch: (options) => ipcRenderer.invoke('recovery:run-final-rollback-switch', options),
});
