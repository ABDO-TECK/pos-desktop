import { existsSync, readFileSync, statSync, openSync, readSync, closeSync } from 'node:fs'
import { createHash } from 'node:crypto'
import { spawnSync } from 'node:child_process'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const manifestPath = path.join(repoRoot, 'scripts', 'desktop-runtime.json')

export function readRuntimeManifest(filePath = manifestPath) {
  return JSON.parse(readFileSync(filePath, 'utf8'))
}

function isFile(filePath) {
  try {
    return statSync(filePath).isFile()
  } catch {
    return false
  }
}

function runProbe(executable, args, cwd) {
  const result = spawnSync(executable, args, {
    cwd,
    encoding: 'utf8',
    windowsHide: true,
    timeout: 10_000,
  })
  return {
    ok: !result.error && result.status === 0,
    output: `${result.stdout || ''}${result.stderr || ''}`.trim(),
    error: result.error?.message || null,
    status: result.status,
  }
}

export function validateRuntimeDirectory(runtimeRoot, { strict = false, runProbes = true, manifest = readRuntimeManifest() } = {}) {
  const errors = []
  const requiredFiles = [
    ...manifest.php.requiredFiles,
    ...manifest.mysql.requiredFiles,
  ]
  const files = requiredFiles.map((relativePath) => ({
    relativePath,
    path: path.join(runtimeRoot, relativePath),
    exists: isFile(path.join(runtimeRoot, relativePath)),
  }))
  for (const file of files) {
    if (!file.exists) errors.push(`missing runtime file: ${file.relativePath}`)
  }

  const installedManifestPath = path.join(runtimeRoot, 'runtime-manifest.json')
  let installedManifest = null
  if (existsSync(installedManifestPath)) {
    try {
      installedManifest = JSON.parse(readFileSync(installedManifestPath, 'utf8'))
    } catch (error) {
      errors.push(`invalid runtime-manifest.json: ${error.message}`)
    }
  } else if (strict) {
    errors.push('missing runtime-manifest.json')
  }

  if (strict && installedManifest) {
    if (String(installedManifest.php?.version) !== String(manifest.php.version)) {
      errors.push(`PHP runtime version is not pinned to ${manifest.php.version}`)
    }
    if (String(installedManifest.mysql?.version) !== String(manifest.mysql.version)) {
      errors.push(`MariaDB runtime version is not pinned to ${manifest.mysql.version}`)
    }
    if (String(installedManifest.php?.sha256).toLowerCase() !== String(manifest.php.sha256).toLowerCase()) {
      errors.push('PHP runtime manifest checksum does not match the pinned archive')
    }
    if (String(installedManifest.mysql?.sha256).toLowerCase() !== String(manifest.mysql.sha256).toLowerCase()) {
      errors.push('MariaDB runtime manifest checksum does not match the pinned archive')
    }
    if (manifest.vcredist) {
      if (String(installedManifest.vcredist?.version) !== String(manifest.vcredist.version)) {
        errors.push(`VC++ prerequisite version is not pinned to ${manifest.vcredist.version}`)
      }
      if (String(installedManifest.vcredist?.sha256).toLowerCase() !== String(manifest.vcredist.sha256).toLowerCase()) {
        errors.push('VC++ prerequisite manifest checksum does not match the pinned archive')
      }
    }
  }

  const probes = {}
  if (runProbes && errors.length === 0) {
    const phpPath = path.join(runtimeRoot, 'php', 'php.exe')
    const mysqlPath = path.join(runtimeRoot, 'mysql', 'bin', 'mysqld.exe')
    probes.php = runProbe(phpPath, ['--version'], path.dirname(phpPath))
    probes.mysql = runProbe(mysqlPath, ['--version'], path.dirname(mysqlPath))
    if (!probes.php.ok) errors.push(`PHP executable probe failed: ${probes.php.error || probes.php.output || probes.php.status}`)
    if (!probes.mysql.ok) errors.push(`MySQL/MariaDB executable probe failed: ${probes.mysql.error || probes.mysql.output || probes.mysql.status}`)
    if (probes.php.ok) {
      probes.phpExtensions = runProbe(
        phpPath,
        [
          '-r',
          "if (!extension_loaded('pdo_mysql') || !extension_loaded('pdo_sqlite')) { fwrite(STDERR, 'pdo_mysql and pdo_sqlite are required'); exit(1); } echo implode(',', PDO::getAvailableDrivers());",
        ],
        path.dirname(phpPath),
      )
      if (!probes.phpExtensions.ok) {
        errors.push(`PHP PDO extension probe failed: ${probes.phpExtensions.error || probes.phpExtensions.output || probes.phpExtensions.status}`)
      }
    }
    if (strict && probes.php.ok && !new RegExp(`PHP ${manifest.php.version.replaceAll('.', '\\.')}(?:\\s|$)`, 'i').test(probes.php.output)) {
      errors.push(`PHP executable did not report version ${manifest.php.version}`)
    }
    if (strict && probes.mysql.ok && !probes.mysql.output.includes(manifest.mysql.version)) {
      errors.push(`MySQL/MariaDB executable did not report version ${manifest.mysql.version}`)
    }
  }

  return { ok: errors.length === 0, errors, files, probes, installedManifest }
}

export function validatePrerequisiteInstaller({
  repoRootDir = repoRoot,
  manifest = readRuntimeManifest(),
  strict = false,
} = {}) {
  const errors = []
  const vcredistConfig = manifest.vcredist
  if (!vcredistConfig) {
    if (strict) errors.push('manifest missing vcredist configuration')
    return { ok: errors.length === 0, errors }
  }

  const prereqPath = path.join(repoRootDir, 'build', 'prerequisites', vcredistConfig.archiveName || 'vc_redist.x64.exe')
  if (!isFile(prereqPath)) {
    errors.push(`missing prerequisite installer: build/prerequisites/${vcredistConfig.archiveName || 'vc_redist.x64.exe'}`)
  } else {
    try {
      const fd = openSync(prereqPath, 'r')
      const headerBuf = Buffer.alloc(1024)
      readSync(fd, headerBuf, 0, 1024, 0)
      closeSync(fd)

      const peOffset = headerBuf.readUInt32LE(0x3c)
      const peSig = headerBuf.subarray(peOffset, peOffset + 4).toString()
      if (peSig !== 'PE\0\0') {
        errors.push('prerequisite installer is not a valid Windows PE binary')
      }
    } catch (peError) {
      errors.push(`failed to parse prerequisite PE header: ${peError.message}`)
    }

    try {
      const fileBytes = readFileSync(prereqPath)
      const actualHash = createHash('sha256').update(fileBytes).digest('hex')
      if (vcredistConfig.sha256 && actualHash.toLowerCase() !== String(vcredistConfig.sha256).toLowerCase()) {
        errors.push(`prerequisite installer SHA-256 mismatch. Expected ${vcredistConfig.sha256}, got ${actualHash}`)
      }
    } catch (hashError) {
      errors.push(`failed to compute prerequisite SHA-256: ${hashError.message}`)
    }
  }

  const packageJsonPath = path.join(repoRootDir, 'package.json')
  let pkg = null
  try {
    pkg = JSON.parse(readFileSync(packageJsonPath, 'utf8'))
  } catch (err) {
    errors.push(`failed to read package.json: ${err.message}`)
  }

  if (pkg) {
    const nsisInclude = pkg.build?.nsis?.include
    if (nsisInclude !== 'build/installer.nsh') {
      errors.push(`package.json build.nsis.include is not configured to 'build/installer.nsh' (got: ${nsisInclude})`)
    }
  }

  const installerNshPath = path.join(repoRootDir, 'build', 'installer.nsh')
  if (!isFile(installerNshPath)) {
    errors.push('missing custom NSIS script: build/installer.nsh')
  } else {
    const nshContent = readFileSync(installerNshPath, 'utf8')
    if (!nshContent.includes('!macro customInstall')) {
      errors.push('build/installer.nsh does not define customInstall macro')
    }
    if (!nshContent.includes('vc_redist.x64.exe')) {
      errors.push('build/installer.nsh does not reference vc_redist.x64.exe')
    }
    if (!nshContent.includes('CheckVcRedistCompatibility')) {
      errors.push('build/installer.nsh does not define or use CheckVcRedistCompatibility')
    }
    if (!nshContent.includes('portable\\php\\php.exe') || !nshContent.includes('-v')) {
      errors.push('build/installer.nsh does not include packaged php.exe -v post-condition check')
    }
  }

  return { ok: errors.length === 0, errors, prereqPath }
}

function main() {
  const strict = process.argv.includes('--strict')
  const rootArgumentIndex = process.argv.indexOf('--root')
  const runtimeRoot = rootArgumentIndex >= 0
    ? path.resolve(process.argv[rootArgumentIndex + 1])
    : path.join(repoRoot, 'portable')

  const runtimeResult = validateRuntimeDirectory(runtimeRoot, { strict })
  const prereqResult = validatePrerequisiteInstaller({ repoRootDir: repoRoot, strict })

  const allErrors = [...runtimeResult.errors, ...prereqResult.errors]
  if (allErrors.length > 0) {
    console.error(`[desktop-runtime] Verification failed:`)
    for (const error of allErrors) console.error(`- ${error}`)
    process.exitCode = 1
    return
  }

  console.log(`[desktop-runtime] Verified PHP ${runtimeResult.probes.php.output.split(/\r?\n/u)[0]}, PDO drivers ${runtimeResult.probes.phpExtensions.output}, and MariaDB ${runtimeResult.probes.mysql.output.split(/\r?\n/u)[0]}`)
  console.log(`[desktop-runtime] Verified Windows VC++ x64 prerequisite installer and NSIS hook integration`)
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) main()
