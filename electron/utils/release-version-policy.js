const SEMVER_PATTERN = /^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/;
const LEGACY_GENERATION_REASON = 'Release belongs to a legacy series and cannot be applied.';
const INVALID_VERSION_REASON = 'Release version metadata is invalid.';

function normalizeVersion(version) {
  if (typeof version !== 'string') return null;
  const normalized = version.trim().replace(/^v/i, '');
  return SEMVER_PATTERN.test(normalized) ? normalized : null;
}

function assessReleaseCompatibility(currentVersion, candidateVersion) {
  const current = normalizeVersion(currentVersion);
  const candidate = normalizeVersion(candidateVersion);

  if (!current || !candidate) {
    return {
      compatible: false,
      reasonCode: 'invalid_version',
      reason: INVALID_VERSION_REASON,
      currentVersion: current,
      candidateVersion: candidate,
    };
  }

  const currentIsV0 = compareVersions(current, '1.0.0') < 0;
  const candidateIsV1OrNewer = compareVersions(candidate, '1.0.0') >= 0;
  if (currentIsV0 && candidateIsV1OrNewer) {
    return {
      compatible: false,
      reasonCode: 'legacy_generation',
      reason: LEGACY_GENERATION_REASON,
      currentVersion: current,
      candidateVersion: candidate,
    };
  }

  return {
    compatible: true,
    reasonCode: null,
    reason: null,
    currentVersion: current,
    candidateVersion: candidate,
  };
}

function compareVersions(left, right) {
  const leftParts = left.split(/[.-]/).map((part) => /^\d+$/.test(part) ? Number(part) : part);
  const rightParts = right.split(/[.-]/).map((part) => /^\d+$/.test(part) ? Number(part) : part);

  for (let index = 0; index < Math.max(leftParts.length, rightParts.length); index += 1) {
    const leftPart = leftParts[index] ?? 0;
    const rightPart = rightParts[index] ?? 0;
    if (leftPart === rightPart) continue;
    if (typeof leftPart === 'number' && typeof rightPart === 'number') {
      return leftPart > rightPart ? 1 : -1;
    }
    if (typeof leftPart === 'number') return 1;
    if (typeof rightPart === 'number') return -1;
    return String(leftPart).localeCompare(String(rightPart));
  }

  return 0;
}

module.exports = {
  assessReleaseCompatibility,
  normalizeVersion,
};
