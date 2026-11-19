import fs from 'node:fs';
import path from 'node:path';
import supervisor from '../../electron/services/shell-update-files.js';

export const shellScenarios = ['rollback', 'commit'];

export function installedFixturePaths(scenario) {
  if (!shellScenarios.includes(scenario)) throw new Error('Unknown Windows shell acceptance scenario.');
  const lab = path.join(fs.realpathSync.native(process.env.RUNNER_TEMP), 'shell-inapp');
  const installRoot = path.join(lab, 'installed-'+scenario);
  const profile = path.join(lab, scenario+'-profile');
  const local = path.join(profile, 'local');
  const roaming = path.join(profile, 'roaming');
  const shared = path.join(profile, 'shared');
  return { lab, installRoot, profile, local, roaming, shared, supervisorStateRoot: supervisor.stateLocation(installRoot, local) };
}
