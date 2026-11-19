const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { compileBroker, compileWindowsProgram } = require('../build/prepare-shell-update-assets.cjs');
const { BROKER, EXE, jsonLine, spawnCandidateBroker } = require('../electron/services/shell-update-files');

const fixtureProgram = [
  'using System;',
  'using System.Diagnostics;',
  'using System.IO;',
  'using System.Threading;',
  'public static class Fixture {',
  '  public static int Main(string[] args) {',
  '    if (args.Length == 1 && args[0] == "leaf") {',
  '      using (File.Open("owned.lock", FileMode.Create, FileAccess.ReadWrite, FileShare.None)) {',
  '        File.WriteAllText("leaf.pid", Process.GetCurrentProcess().Id.ToString());',
  '        Thread.Sleep(60000);',
  '      }',
  '      return 0;',
  '    }',
  '    string context = Environment.GetEnvironmentVariable("POS_SHELL_PROBATION");',
  '    if (context == null || Environment.GetEnvironmentVariable("POS_UNTRUSTED_BROKER_FIXTURE") != null) return 80;',
  '    if (context.Contains("\\"endpoint\\":1")) {',
  '      var live = Stopwatch.StartNew();',
  '      while (!File.Exists("finish") && live.ElapsedMilliseconds < 20000) Thread.Sleep(30);',
  '      return File.Exists("finish") ? 0 : 81;',
  '    }',
  '    var info = new ProcessStartInfo(Process.GetCurrentProcess().MainModule.FileName, "leaf");',
  '    info.UseShellExecute = false;',
  '    using (Process.Start(info)) {',
  '      var deadline = Stopwatch.StartNew();',
  '      while (!File.Exists("leaf.pid") && deadline.ElapsedMilliseconds < 6000) Thread.Sleep(30);',
  '      return File.Exists("leaf.pid") ? 78 : 79;',
  '    }',
  '  }',
  '}',
].join('\n');

const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

function isAlive(pid) {
  try { process.kill(pid, 0); return true; }
  catch (error) { if (error.code === 'ESRCH') return false; throw error; }
}

async function waitFor(probe, message, timeout = 15000) {
  const deadline = Date.now() + timeout;
  do {
    const value = probe();
    if (value) return value;
    await delay(30);
  } while (Date.now() < deadline);
  throw new Error(message);
}

async function verify() {
  if (process.env.GITHUB_ACTIONS !== 'true' || process.platform !== 'win32') {
    throw new Error('This verification requires a GitHub Actions Windows runner.');
  }
  const temp = fs.realpathSync.native(process.env.RUNNER_TEMP);
  const lab = fs.mkdtempSync(path.join(temp, 'pos-native-broker-'));
  let active;
  const install = path.join(lab, 'fixture');
  try {
    const worker = path.join(lab, 'worker');
    fs.mkdirSync(worker);
    fs.mkdirSync(install);
    const fixture = path.join(lab, 'Fixture.cs');
    fs.writeFileSync(fixture, fixtureProgram);
    compileWindowsProgram([fixture], path.join(install, EXE));
    const brokerPath = path.join(worker, BROKER);
    compileBroker(path.join(__dirname, '..'), brokerPath);
    const image = fs.readFileSync(brokerPath);
    assert.equal(image.readUInt16LE(0), 0x5a4d);
    const pe = image.readUInt32LE(0x3c);
    assert.equal(image.readUInt32LE(pe), 0x00004550);
    assert.equal(image.readUInt16LE(pe + 4), 0x8664);

    process.env.POS_UNTRUSTED_BROKER_FIXTURE = 'must not reach candidate';
    for (const endpoint of [2, 1]) {
      active = spawnCandidateBroker(worker, install, { token: '0'.repeat(64), id: 'tx_' + '0'.repeat(32), endpoint });
      const frames = [];
      let failure;
      let closed;
      active.once('error', (error) => { failure = error; });
      active.once('close', (code) => { closed = { code }; });
      active.stdin.on('error', (error) => { failure = error; });
      jsonLine(active.stdout, (frame) => {
        if (frame.event === 'error') throw new Error(frame.phase + ': ' + frame.error);
        frames.push(frame);
      }, (error) => { failure = error; });
      const frame = (event) => waitFor(() => {
        if (failure) throw failure;
        return frames.find((item) => item.event === event);
      }, 'No broker frame: ' + event + ' (' + JSON.stringify(frames) + ')');
      const started = await frame('started');
      assert.ok(Number.isInteger(started.pid) && started.pid > 0);
      assert.deepEqual(frames.filter((item) => item.event === 'phase').map((item) => item.phase),
        ['initializing', 'creating_job']);
      if (endpoint === 2) {
        const exited = await frame('exited');
        assert.equal(exited.code, 78, 'Candidate root should die while its known descendant still owns the file.');
        const leafPid = Number(fs.readFileSync(path.join(install, 'leaf.pid'), 'utf8'));
        assert.ok(Number.isInteger(leafPid) && leafPid > 0 && isAlive(leafPid));
        assert.throws(() => fs.closeSync(fs.openSync(path.join(install, 'owned.lock'), 'r')));
        active.stdin.end('stop\n');
        await frame('stopped');
        await waitFor(() => !isAlive(leafPid), 'The released job still had a live descendant.');
        fs.closeSync(fs.openSync(path.join(install, 'owned.lock'), 'r'));
      } else {
        active.stdin.end('release\n');
        await frame('released');
        assert.ok(isAlive(started.pid), 'Admitting a healthy candidate should release it without termination.');
        fs.writeFileSync(path.join(install, 'finish'), 'done');
        await waitFor(() => !isAlive(started.pid), 'The released fixture did not finish.');
      }
      assert.equal((await waitFor(() => closed, 'The native broker did not exit.')).code, 0);
      active = null;
    }
  } finally {
    if (active && active.exitCode === null) {
      active.kill();
      await waitFor(() => active.exitCode !== null || active.signalCode !== null, 'The fixture broker did not stop.');
    }
    assert.equal(path.dirname(fs.realpathSync.native(lab)).toLowerCase(), temp.toLowerCase());
    fs.rmSync(lab, { recursive: true });
  }
  console.log('Verified packaged x64 broker code with production spawn, sparse environment and pipes, plus owned termination and healthy release.');
}

verify().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});

