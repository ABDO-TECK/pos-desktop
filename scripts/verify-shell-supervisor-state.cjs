const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const {
  ensurePrivateRoot, ensureDirectory, stateLocation, operationPaths, allocateScratch, assertScratchOwnership,
  assertOperationPathBudget, transactionPathExamples, pruneCompletedCopies, requireSpace, powershell,
  waitForSourceShutdown, writeRecord,
} = require('../electron/services/shell-update-files');

async function verify() {
  if (process.env.GITHUB_ACTIONS !== 'true' || process.platform !== 'win32') {
    throw new Error('This verification requires a GitHub Actions Windows runner.');
  }
  const parent = fs.realpathSync.native(process.env.RUNNER_TEMP);
  const fixture = fs.mkdtempSync(path.join(parent, 'pos-shell-supervisor-state-'));
  try {
    const profileLength = 120;
    const padding = profileLength - Buffer.byteLength(fixture, 'utf8') - 1;
    assert.ok(padding > 0, 'The runner temp root must allow a 120-byte profile fixture.');
    const profile = ensureDirectory(path.join(fixture, 'p'.repeat(padding)));
    const installRoot = ensureDirectory(path.join(fixture, 'installed'));
    const stateRoot = stateLocation(installRoot, profile);
    await ensurePrivateRoot(stateRoot);
    requireSpace(stateRoot, 1n);
    const observedPid = await powershell(
      '(Get-CimInstance Win32_Process -Filter ("ProcessId = " + $env:POS_TEST_PID)).ProcessId',
      { POS_TEST_PID: String(process.pid) },
    );
    assert.equal(Number(observedPid), process.pid, 'The supervisor must be able to query the installed process list.');

    const acknowledgement = (event) => waitForSourceShutdown((onFrame) => onFrame({ event }));
    assert.equal(await acknowledgement('stopped'), 'stopped');
    assert.equal(await acknowledgement('drain-failed-source-exiting'), 'drain-failed-source-exiting');
    await assert.rejects(acknowledgement('abort'), /did not authorize/);

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
    assertOperationPathBudget(admitted);
    const overlongRoot = stateLocation(installRoot, path.join(profile, 'too-long-for-shell'));
    assert.throws(() => assertOperationPathBudget(operationPaths(overlongRoot, id(0))), /profile path is too long/);

    const otherRoot = stateLocation(path.join(fixture, 'other-install'), profile);
    assert.notEqual(otherRoot, stateRoot, 'Separate installations require separate receipt and handoff state.');
    allocateScratch(admitted, stateRoot, id(0));
    assertScratchOwnership(admitted, stateRoot, id(0));
    assert.throws(() => assertScratchOwnership(operationPaths(otherRoot, id(0)), otherRoot, id(0)), /ownership/);
    assert.throws(() => allocateScratch(admitted, stateRoot, id(0)), { code: 'EEXIST' });

    const samplePaths = transactionPathExamples(admitted);
    for (const file of samplePaths) {
      fs.mkdirSync(path.dirname(file), { recursive: true });
      fs.writeFileSync(file, 'transaction path specimen');
    }
    const policyScript = [
      'require $argv[1];',
      'foreach (array_slice($argv, 2) as $path) {',
      '  $resolved = App\\Services\\ProductInventoryService::resolveAbsolutePath($path, true);',
      '  if (!is_file($resolved)) throw new RuntimeException("Path did not resolve to a physical file.");',
      '}',
    ].join('\n');
    const policy = path.join(__dirname, '..', 'backend', 'Services', 'ProductInventoryService.php');
    execFileSync('php', ['-r', policyScript, policy, ...samplePaths], { windowsHide: true, stdio: 'pipe' });
    writeRecord(path.join(admitted.transactionWorkspace, '.pos_transaction_owner'), { operation_id: id(0), root: installRoot });

    ensureDirectory(admitted.worker);
    fs.writeFileSync(path.join(admitted.worker, 'owned-worker-module'), 'test copy');
    fs.writeFileSync(admitted.gate, 'test-only proof token');
    for (let attempt = 1; attempt <= 11; attempt += 1) receipt(attempt, 'failed');
    const unfinished = receipt(12, 'recovery_required');
    allocateScratch(unfinished, stateRoot, id(12));
    ensureDirectory(unfinished.backup);
    writeRecord(path.join(stateRoot, 'active.json'), { id: id(12) });

    pruneCompletedCopies(stateRoot, id(0));
    assert.ok(fs.existsSync(admitted.gate), 'An admitted PHP runtime must keep its release gate during retention.');
    assert.ok(!fs.existsSync(admitted.worker), 'An admitted runtime no longer requires its private worker files.');
    assert.ok(!fs.existsSync(admitted.transactionWorkspace), 'Only the committed operation workspace should be pruned.');
    assert.ok(fs.existsSync(admitted.transaction), 'The private transaction vault and root locks remain reusable.');
    assert.ok(fs.existsSync(unfinished.backup), 'Active rollback backup must remain in place.');
    assert.ok(!fs.existsSync(operationPaths(stateRoot, id(1)).receipt), 'Expired receipts should be pruned.');
    pruneCompletedCopies(stateRoot);
    assert.ok(!fs.existsSync(admitted.operation), 'An old gate can be pruned after the consuming runtime exits.');
  } finally {
    assert.equal(path.dirname(fs.realpathSync.native(fixture)).toLowerCase(), parent.toLowerCase());
    fs.rmSync(fixture, { recursive: true });
  }
  console.log('Verified private Windows ACL provisioning and long-profile PHP transaction paths with owned retention.');
}

verify().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});

