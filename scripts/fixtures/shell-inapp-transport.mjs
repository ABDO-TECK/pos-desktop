import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { assetNames, binaryAssetNames } from './shell-inapp-release-origin.mjs';

export function verifyTransport({ requests, releaseRoot, lab, inventory, binary, scenario }) {
  const [manifest, signature, whole] = assetNames;
  const [pointer, pointerSignature, payload] = binaryAssetNames;
  const transfers = name => requests.filter(request =>
    request.authority === 'github.com' && request.path.endsWith('/'+name));
  const fullTransfer = (name, dir = releaseRoot) => {
    const size = fs.statSync(path.join(dir, name)).size;
    const complete = transfers(name).find(request =>
      request.method === 'GET' && request.status === 200 && request.delivered_bytes === size);
    assert.ok(complete, 'No complete HTTPS fixture transfer observed for '+name);
    return complete;
  };
  fullTransfer(manifest);
  fullTransfer(signature);
  if (!binary) {
    fullTransfer(whole);
    return null;
  }

  const originalPointer = JSON.parse(fs.readFileSync(path.join(releaseRoot, pointer), 'utf8'));
  const wholeBytes = fs.statSync(path.join(releaseRoot, whole)).size;
  assert.equal(wholeBytes, inventory.package.size);
  const optionalBytes = binaryAssetNames.reduce((sum, name) =>
    sum + fs.statSync(path.join(releaseRoot, name)).size, 0);
  assert.ok(optionalBytes < wholeBytes, 'The complete signed optional transport must be smaller than Format 2.');
  const transportDir = scenario === 'format' ? path.join(lab, 'unsupported') : releaseRoot;
  fullTransfer(pointer, transportDir);
  const patchPath = transfers(payload);
  const patchSelected = scenario === 'commit' || scenario === 'rollback';
  if (patchSelected) {
    fullTransfer(pointerSignature);
    assert.equal(fullTransfer(payload).delivered_sha256, originalPointer.sha256);
    assert.equal(transfers(whole).length, 0, 'A successful binary transfer contacted the whole Format 2 asset.');
  } else {
    const delivered = fullTransfer(whole);
    assert.equal(delivered.delivered_sha256, inventory.package.sha256);
    const fullIndex = requests.indexOf(delivered);
    assert.ok(requests.indexOf(transfers(pointer)[0]) < fullIndex, 'Whole Format 2 started before the optional transport.');
    if (scenario === 'corrupt') {
      const corrupted = fullTransfer(payload);
      assert.notEqual(corrupted.delivered_sha256, originalPointer.sha256);
      assert.ok(requests.indexOf(corrupted) < fullIndex, 'Whole Format 2 started before the altered binary bytes.');
    } else if (scenario === 'missing') {
      assert.ok(patchPath.some(request => request.status === 404 && requests.indexOf(request) < fullIndex),
        'The advertised binary payload must return HTTP 404 before fallback.');
    } else {
      assert.equal(patchPath.length, 0, 'An unsupported pointer format must stop before the binary payload.');
    }
  }
  const downloaded = name => transfers(name).reduce((sum, request) => sum + request.delivered_bytes, 0);
  const delivered = Object.fromEntries([...assetNames, ...binaryAssetNames].map(name => [name, downloaded(name)]));
  return {
    transport: patchSelected ? 'binary_delta' : 'whole_file',
    actual_complete_transfer_bytes: delivered,
    actual_total_asset_bytes: Object.values(delivered).reduce((sum, bytes) => sum + bytes, 0),
    signed_optional_bytes: optionalBytes,
    canonical_whole_zip_bytes: wholeBytes,
    binary_savings_bytes: wholeBytes - optionalBytes,
    binary_savings_percent: Number((100 * (wholeBytes - optionalBytes) / wholeBytes).toFixed(2)),
  };
}
