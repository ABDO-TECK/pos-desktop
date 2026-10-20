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
  console.log('PASS: Setup identity agrees with displayed application version; delta revisions remain independent of the installed shell');
} finally {
  fs.rmSync(lab,{recursive:true,force:true});
}
