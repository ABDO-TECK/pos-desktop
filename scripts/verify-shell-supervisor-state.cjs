const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {
  ensurePrivateRoot, ensureDirectory, operationPaths, pruneCompletedCopies, requireSpace, writeRecord,
} = require('../electron/services/shell-update-files');

async function verify() {
  if (process.env.GITHUB_ACTIONS !== 'true' || process.platform !== 'win32') {
    throw new Error('This verification requires a GitHub Actions Windows runner.');
  }
  const parent = fs.realpathSync.native(process.env.RUNNER_TEMP);
  const fixture = fs.mkdtempSync(path.join(parent, 'pos-shell-supervisor-state-'));
  const stateRoot = path.join(fixture, 'private', 'profile');
  try {
    await ensurePrivateRoot(stateRoot);
    requireSpace(stateRoot, 1n);

    const id = (number) => 'tx_' + number.toString(16).padStart(32, '0');
    const receipt = (number, outcome) => {
      const paths = operationPaths(stateRoot, id(number));
      ensureDirectory(paths.operation);
      writeRecord(paths.receipt, { operation_id: id(number), outcome });
      const time = new Date(1_000_000 * (1 + number));
      fs.utimesSync(paths.receipt, time, time);
      return paths;
    };
    const admitted = receipt(0, 'completed');
    ensureDirectory(admitted.worker);
    fs.writeFileSync(path.join(admitted.worker, 'owned-worker-module'), 'test copy');
    fs.writeFileSync(admitted.gate, 'test-only proof token');
    for (let attempt = 1; attempt <= 11; attempt += 1) receipt(attempt, 'failed');
    const unfinished = receipt(12, 'recovery_required');
    ensureDirectory(unfinished.backup);
    writeRecord(path.join(stateRoot, 'active.json'), { id: id(12) });

    pruneCompletedCopies(stateRoot, id(0));
    assert.ok(fs.existsSync(admitted.gate), 'An admitted PHP runtime must keep its release gate during retention.');
    assert.ok(!fs.existsSync(admitted.worker), 'An admitted runtime no longer requires its private worker files.');
    assert.ok(fs.existsSync(unfinished.backup), 'Active rollback backup must remain in place.');
    assert.ok(!fs.existsSync(operationPaths(stateRoot, id(1)).receipt), 'Expired receipts should be pruned.');
    pruneCompletedCopies(stateRoot);
    assert.ok(!fs.existsSync(admitted.operation), 'An old gate can be pruned after the consuming runtime exits.');
  } finally {
    assert.equal(path.dirname(fs.realpathSync.native(fixture)).toLowerCase(), parent.toLowerCase());
    fs.rmSync(fixture, { recursive: true });
  }
  console.log('Verified private Windows ACL provisioning, free space and protected receipt retention.');
}

verify().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});

