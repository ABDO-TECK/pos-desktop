const { BrowserWindow } = require('electron');

async function printHTML(htmlContent, options = {}) {
  const printWin = new BrowserWindow({
    show: false,
    webPreferences: {
      nodeIntegration: false,
      contextIsolation: true,
      sandbox: true,
    }
  });

  await printWin.loadURL(
    `data:text/html;charset=utf-8,${encodeURIComponent(htmlContent)}`
  );

  const printers = printWin.webContents.getPrintersInfo();
  const thermalPrinter = printers.find(p =>
    p.name.toLowerCase().includes('thermal') ||
    p.name.toLowerCase().includes('receipt') ||
    p.name.toLowerCase().includes('pos')
  );

  await printWin.webContents.print({
    silent: true,
    printBackground: true,
    deviceName: thermalPrinter?.name || options.printerName,
    margins: { marginType: 'none' },
    pageSize: { width: 80000, height: 297000 }, // 80mm thermal
  });

  printWin.close();
}

module.exports = { printHTML };
