import fs from 'node:fs';
import path from 'node:path';
import supervisor from '../../electron/services/shell-update-files.js';

export const binaryAcceptance = process.env.POS_SHELL_ACCEPTANCE_PROFILE === 'binary';
export const shellScenarios = binaryAcceptance
  ? ['rollback', 'commit', 'corrupt', 'format', 'missing', 'older']
  : ['rollback', 'commit'];
export const sourceVersion = binaryAcceptance ? '0.0.14' : '0.0.13';
export const targetVersion = binaryAcceptance ? '0.0.15' : '0.0.14';

export function installedFixturePaths(scenario) {
  if (!shellScenarios.includes(scenario)) throw new Error('Unknown Windows shell acceptance scenario.');
  const lab = path.join(fs.realpathSync.native(process.env.RUNNER_TEMP), binaryAcceptance ? 'pos-binary' : 'shell-inapp');
  const installRoot = path.join(lab, 'installed-'+scenario);
  const profile = path.join(lab, scenario+'-profile');
  const local = path.join(profile, 'local');
  const roaming = path.join(profile, 'roaming');
  const shared = path.join(profile, 'shared');
  return { lab, installRoot, profile, local, roaming, shared, supervisorStateRoot: supervisor.stateLocation(installRoot, local) };
}
