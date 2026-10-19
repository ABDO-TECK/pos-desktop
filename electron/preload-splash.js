const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('splashAPI', {
  onStatusUpdate: (callback) => {
    ipcRenderer.on('splash-status', (_event, message) => callback(message));
  }
});
