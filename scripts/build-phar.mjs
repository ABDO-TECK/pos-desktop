import { existsSync } from 'node:fs'
import { spawnSync } from 'node:child_process'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const configuredPhp = process.env.PHP_BINARY?.trim()
const candidates = [
  configuredPhp,
  process.platform === 'win32' ? 'C:\\xampp\\php\\php.exe' : null,
  'php',
].filter(Boolean)

function canRun(command) {
  if (path.isAbsolute(command) && !existsSync(command)) return false

  const probe = spawnSync(command, ['--version'], {
    cwd: repoRoot,
    encoding: 'utf8',
    windowsHide: true,
  })
  return !probe.error && probe.status === 0
}

const phpCommand = candidates.find(canRun)
if (!phpCommand) {
  console.error('PHP was not found. Set PHP_BINARY or install PHP 8.1+.')
  process.exit(1)
}

const result = spawnSync(
  phpCommand,
  ['-d', 'phar.readonly=0', 'build-phar.php'],
  {
    cwd: repoRoot,
    encoding: 'utf8',
    windowsHide: true,
  },
)

if (result.stdout) process.stdout.write(result.stdout)
if (result.stderr) process.stderr.write(result.stderr)

if (result.error) {
  console.error(`Unable to start the PHAR build: ${result.error.message}`)
  process.exit(1)
}

process.exit(result.status ?? 1)
