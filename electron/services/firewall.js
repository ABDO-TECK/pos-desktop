const { exec } = require('child_process');
const sudo = require('@vscode/sudo-prompt');
const fs = require('fs');
const path = require('path');
const { app } = require('electron');

const FLAG_VERSION = 'configured-v4-tls-local-subnet';

function getFirewallFlagPath() {
  return path.join(app.getPath('userData'), 'firewall-configured.flag');
}

/**
 * Expose only the TLS proxy to the local subnet on trusted network profiles.
 */
function configureFirewall() {
  return new Promise((resolve) => {
    if (process.platform !== 'win32') {
      return resolve(true);
    }

    const flagPath = getFirewallFlagPath();
    const flagContent = fs.existsSync(flagPath) ? fs.readFileSync(flagPath, 'utf8') : '';
    if (flagContent === FLAG_VERSION) {
      console.log('[Firewall] Firewall rules already configured (v4).');
      return resolve(true);
    }

    try {
      const ruleApp = 'POS System - SSL Port 8443';

      // 1. Delete all previous rule versions
      const cmdDelOldPhp = `netsh advfirewall firewall delete rule name="POS System - Embedded PHP"`;
      const cmdDelOldApp = `netsh advfirewall firewall delete rule name="POS System - Main Executable"`;
      const cmdDelPhp = `netsh advfirewall firewall delete rule name="POS System - Web Port 8080"`;
      const cmdDelApp = `netsh advfirewall firewall delete rule name="${ruleApp}"`;

      const cmdAddApp = `netsh advfirewall firewall add rule name="${ruleApp}" dir=in action=allow protocol=TCP localport=8443 remoteip=LocalSubnet enable=yes profile=private,domain edge=no`;

      const combinedCommand = [
        cmdDelOldPhp, cmdDelOldApp,
        cmdDelPhp, cmdDelApp,
        cmdAddApp
      ].join(' & ');

      const options = {
        name: 'POS System'
      };

      console.log('[Firewall] Requesting UAC elevation for the opt-in LAN TLS rule...');
      sudo.exec(combinedCommand, options, (error, stdout, stderr) => {
        if (error) {
          console.error('[Firewall] Failed to configure firewall rules:', error.message);
          resolve(false);
        } else {
          console.log('[Firewall] LAN TLS firewall rule configured successfully.');
          if (stdout) console.log('[Firewall] stdout:', stdout);
          fs.writeFileSync(flagPath, FLAG_VERSION, 'utf-8');
          resolve(true);
        }
      });
    } catch (err) {
      console.error('[Firewall] Error building firewall commands:', err.message);
      resolve(false);
    }
  });
}

function removeFirewall() {
  return new Promise((resolve) => {
    if (process.platform !== 'win32') {
      return resolve(true);
    }

    try {
      const command = 'netsh advfirewall firewall delete rule name="POS System - SSL Port 8443"';
      sudo.exec(command, { name: 'POS System' }, (error, stdout, stderr) => {
        if (error) {
          console.error('[Firewall] Failed to remove LAN rule:', error.message);
          resolve(false);
          return;
        }

        try {
          const flagPath = getFirewallFlagPath();
          if (fs.existsSync(flagPath)) {
            fs.unlinkSync(flagPath);
          }
        } catch (flagError) {
          console.error('[Firewall] Failed to clear rule flag:', flagError.message);
        }

        if (stdout) console.log('[Firewall] Removal output:', stdout);
        if (stderr) console.warn('[Firewall] Removal warning:', stderr);
        resolve(true);
      });
    } catch (error) {
      console.error('[Firewall] Error removing firewall rule:', error.message);
      resolve(false);
    }
  });
}

module.exports = { configureFirewall, removeFirewall };
