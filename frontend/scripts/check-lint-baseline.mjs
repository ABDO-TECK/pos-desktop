import { readFile, writeFile } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'
import { ESLint } from 'eslint'

const baselineUrl = new URL('../eslint-baseline.json', import.meta.url)
const baseline = JSON.parse(await readFile(baselineUrl, 'utf8'))
const maxWarnings = Number.isInteger(baseline.maxWarnings) ? baseline.maxWarnings : 0
const shouldUpdate = process.argv.includes('--update-baseline')

const eslint = new ESLint({ cwd: process.cwd() })
const results = await eslint.lintFiles(['.'])
const formatter = await eslint.loadFormatter('stylish')
const formatted = formatter.format(results)
if (formatted) {
  process.stdout.write(formatted)
}

const errors = results.reduce((total, result) => total + result.errorCount, 0)
const warnings = results.reduce((total, result) => total + result.warningCount, 0)

const ruleCounts = {}
for (const file of results) {
  for (const msg of file.messages) {
    if (msg.severity === 1 && msg.ruleId) {
      ruleCounts[msg.ruleId] = (ruleCounts[msg.ruleId] || 0) + 1
    }
  }
}

if (shouldUpdate) {
  const newBaseline = {
    maxWarnings: warnings,
    snapshot: {
      totalWarnings: warnings,
      rules: Object.fromEntries(Object.entries(ruleCounts).sort(([a], [b]) => a.localeCompare(b))),
    },
  }
  await writeFile(baselineUrl, JSON.stringify(newBaseline, null, 2) + '\n', 'utf8')
  process.stdout.write(`Updated ESLint baseline: maxWarnings=${warnings}\n`)
  process.exit(0)
}

if (errors > 0) {
  process.stderr.write(`ESLint failed with ${errors} error(s).\n`)
  process.exit(1)
}

if (warnings > maxWarnings) {
  process.stderr.write(
    `ESLint warning baseline exceeded: ${warnings} warning(s), maximum allowed ${maxWarnings}.\nNew warnings introduced — fix before committing.\n`,
  )
  process.exit(1)
}

process.stdout.write(`ESLint warnings: ${warnings}/${maxWarnings} (Within accepted baseline)\n`)
