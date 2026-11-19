import assert from 'node:assert/strict';
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
for (const scenario of shellScenarios) {
  const { supervisorStateRoot } = installedFixturePaths(scenario);
  const paths = supervisor.operationPaths(supervisorStateRoot, operationId);
  supervisor.assertOperationPathBudget(paths);
  const requiredPaths = [paths.receipt, ...supervisor.transactionPathExamples(paths)];
  const longest = Math.max(...requiredPaths.map(value => Buffer.byteLength(value, 'utf8')));
  assert.ok(longest <= phpLimit, scenario+' workspace path has '+longest+' bytes, limit '+phpLimit+'.');
  console.log(scenario+' longest checked workspace path: '+longest+'/'+phpLimit+' bytes');
}
