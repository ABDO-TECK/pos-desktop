import fs from 'node:fs';
import path from 'node:path';
import http from 'node:http';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const source = path.resolve(process.argv[2] || '.');
const dependencies = process.env.POS_TEST_DEPENDENCIES || source;
const require = createRequire(path.join(dependencies, 'frontend/package.json'));
const ts = require('typescript');
const axios = require('axios');

// The real update request must retain the handoff response after the previous
// 30-second boundary. Keep the transport real and isolate GitHub/authentication.
const server = http.createServer((req, res) => {
  req.resume();
  setTimeout(() => {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({
      success: true,
      data: { requires_desktop_handoff: true, handoff_version: '0.0.3' },
    }));
  }, 31_000);
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const api = axios.create({ baseURL: 'http://127.0.0.1:' + server.address().port });
const output = ts.transpileModule(
  fs.readFileSync(path.join(source, 'frontend/src/api/endpoints.ts'), 'utf8'),
  { compilerOptions: { module: ts.ModuleKind.CommonJS, esModuleInterop: true } },
).outputText;
const exports = {};
vm.runInNewContext(output, {
  exports,
  require: id => {
    if (id === './axios') return { __esModule: true, default: api };
    if (id === './constants') return { DEFAULT_PAGE: 1, DEFAULT_PAGE_SIZE: 20 };
    return require(id);
  },
});
try {
  const response = await exports.applyUpdate(false, true);
  assert.equal(response.data.data.requires_desktop_handoff, true);
  assert.equal(response.data.data.handoff_version, '0.0.3');
  console.log('PASS: frontend retains handoff response from an update taking over 30 seconds');
} catch (error) {
  console.error(error.message);
  process.exitCode = 1;
} finally {
  server.closeAllConnections();
  server.close();
}
