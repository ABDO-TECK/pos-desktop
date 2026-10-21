import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
const script = path.resolve('scripts/release-metadata.mjs');
const lab = fs.mkdtempSync(path.join(os.tmpdir(), 'pos-release-identity-'));
fs.writeFileSync(path.join(lab, 'package.json'), JSON.stringify({version:'0.0.1'}));
function run(version, tag) {
  fs.writeFileSync(path.join(lab, 'version.json'), JSON.stringify(version));
  return spawnSync(process.execPath, [script, tag], {cwd:lab, encoding:'utf8'});
}
const setup = {version:'0.0.1', application_version:'0.0.1', installer_version:'0.0.1', channel:'stable', release_type:'setup'};
try {
  const result = run(setup,'v0.0.1');
  assert.equal(result.status,0,result.stderr);
  assert.equal(JSON.parse(result.stdout).version,'0.0.1');
  assert.notEqual(run({...setup,version:'0.0.8',application_version:'0.0.8'},'v0.0.1').status,0,'A Setup must reject a different displayed application version');
  assert.notEqual(run({...setup,application_version:'0.0.8'},'v0.0.1').status,0,'Application metadata must agree with installed version');
  assert.notEqual(run(setup,'v0.0.8').status,0);
  const delta = {...setup,version:'0.0.10-beta.1',application_version:'0.0.10-beta.1',channel:'beta',release_type:'delta',delta_baseline_tags:['v0.0.1']};
  assert.equal(run(delta,'v0.0.10-beta.1').status,0);
  assert.notEqual(run({...delta,version:'0.0.10-rc.1',application_version:'0.0.10-rc.1',channel:'rc'},'v0.0.10-rc.1').status,0,'Retired channel can still be published');
  console.log('PASS: Setup identity agrees with displayed application version; delta revisions remain independent of the installed shell');
} finally {
  const resolved=fs.realpathSync(lab);
  assert.equal(path.dirname(resolved).toLowerCase(),fs.realpathSync(os.tmpdir()).toLowerCase());
  assert.ok(path.basename(resolved).startsWith('pos-release-identity-'));
  fs.rmSync(resolved,{recursive:true,force:true});
}
