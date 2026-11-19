import assert from 'node:assert/strict';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import supervisor from '../electron/services/shell-update-files.js';
import { installedFixturePaths, shellScenarios } from './fixtures/shell-inapp-layout.mjs';

assert.equal(process.platform, 'win32');
assert.equal(process.env.GITHUB_ACTIONS, 'true');
assert.equal(process.env.RUNNER_ENVIRONMENT, 'github-hosted');

const phpLimit = Number(execFileSync('php', ['-n', '-r',
  "require 'backend/Services/ProductInventoryService.php'; echo App\\Services\\ProductInventoryService::MAX_PATH_LENGTH;",
], { encoding: 'utf8', windowsHide: true, timeout: 15_000 }));
assert.ok(Number.isSafeInteger(phpLimit) && phpLimit > 0, 'The PHP inventory path limit is required.');

const operationId = 'tx_'+'a'.repeat(32);
const fileHash = 'f'.repeat(64);
for (const scenario of shellScenarios) {
  const { supervisorStateRoot } = installedFixturePaths(scenario);
  const { receipt, transaction } = supervisor.operationPaths(supervisorStateRoot, operationId);
  const workspace = path.join(transaction, 'workspaces', operationId);
  const requiredPaths = {
    receipt,
    staged: path.join(workspace, 'staged', fileHash+'.staged'),
    backup: path.join(workspace, 'backups', fileHash+'.bak'),
  };
  for (const [stage, requiredPath] of Object.entries(requiredPaths)) {
    const bytes = Buffer.byteLength(requiredPath, 'utf8');
    assert.ok(bytes <= phpLimit, scenario+' '+stage+' path has '+bytes+' bytes, limit '+phpLimit+'.');
    console.log(scenario+' '+stage+' path: '+bytes+'/'+phpLimit+' bytes');
  }
}
