import fs from 'node:fs';
import assert from 'node:assert/strict';
const icon = fs.readFileSync('electron/assets/icon.ico');
const frames = [];
for (let index = 0; index < icon.readUInt16LE(4); index++) {
  const entry = 6 + 16 * index;
  const size = icon.readUInt32LE(entry + 8), offset = icon.readUInt32LE(entry + 12);
  frames.push(icon.subarray(offset, offset + size));
}
for (const file of process.argv.slice(2)) {
  const bytes = fs.readFileSync(file);
  assert.ok(frames.some(frame => bytes.includes(frame)), `POS logo missing from executable resources: ${file}`);
  console.log(`PASS: POS logo embedded in ${file}`);
}
