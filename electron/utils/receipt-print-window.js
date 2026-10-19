const PRINT_FRAME_NAME = 'pos-receipt-print';

function getReceiptPrintWindowPolicy({ url, frameName }) {
  if (frameName !== PRINT_FRAME_NAME || (url !== '' && url !== 'about:blank')) {
    return { action: 'deny' };
  }

  return {
    action: 'allow',
    overrideBrowserWindowOptions: {
      autoHideMenuBar: true,
      webPreferences: {
        preload: undefined,
        nodeIntegration: false,
        contextIsolation: true,
        sandbox: true,
        webSecurity: true,
      },
    },
  };
}

function configureReceiptPrintWindows(contents) {
  contents.setWindowOpenHandler(getReceiptPrintWindowPolicy);
  contents.on('did-create-window', (child) => {
    child.setMenu(null);
    child.webContents.setWindowOpenHandler(() => ({ action: 'deny' }));
    child.webContents.on('will-navigate', (event, destination) => {
      if (destination !== 'about:blank') event.preventDefault();
    });
  });
}

module.exports = { getReceiptPrintWindowPolicy, configureReceiptPrintWindows };
