import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import https from 'node:https';
import net from 'node:net';
import path from 'node:path';
import { once } from 'node:events';

const repository = 'ABDO-TECK/pos-desktop-updates';
const setupRepository = 'ABDO-TECK/pos-desktop';
const tag = 'v0.0.14';
export const assetNames = ['shell-manifest.json', 'shell-manifest.sig', 'shell-delta.zip'];

async function listen(server) {
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  return server.address().port;
}

export async function startReleaseFixture(lab, releaseRoot, requests) {
  const assetPrefix = '/'+repository+'/releases/download/'+tag+'/';
  const release = {
    id: 10014,
    tag_name: tag,
    draft: false,
    prerelease: false,
    published_at: '2026-11-18T00:00:00Z',
    name: 'Unpublished Windows CI candidate',
    body: 'Signed, runner-local in-app update acceptance candidate.',
    html_url: 'https://github.com/'+repository+'/releases/tag/'+tag,
    assets: assetNames.map((name, index) => ({
      id: 14001 + index,
      name,
      size: fs.statSync(path.join(releaseRoot, name)).size,
      state: 'uploaded',
      browser_download_url: 'https://github.com'+assetPrefix+name,
    })),
  };
  const records = new Map([[repository, [release]], [setupRepository, []]]);
  const sockets = new Set();

  const tls = https.createServer({
    key: fs.readFileSync(path.join(lab, 'server-private.pem')),
    cert: fs.readFileSync(path.join(lab, 'server.pem')),
  }, (req, res) => {
    const host = req.headers.host;
    const url = new URL(req.url, 'https://'+host);
    const log = { method: req.method, authority: host, path: url.pathname, status: 404, delivered_bytes: 0 };
    requests.push(log);
    function reply(status, data) {
      log.status = status;
      res.writeHead(status, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' });
      res.end(JSON.stringify(data));
    }
    if (!['GET', 'HEAD'].includes(req.method) || req.headers.authorization || req.headers.cookie) {
      reply(403, { message: 'Unexpected release fixture request' });
      return;
    }
    if (host === 'api.github.com') {
      for (const [repo, releases] of records) {
        const root = '/repos/'+repo+'/releases';
        if (url.pathname === root) {
          reply(200, url.searchParams.get('page') === '1' || !url.searchParams.has('page') ? releases : []);
          return;
        }
        if (releases.length && [root+'/latest', root+'/tags/'+tag].includes(url.pathname)) {
          reply(200, releases[0]);
          return;
        }
      }
    }
    if (host === 'github.com' && url.pathname.startsWith(assetPrefix)) {
      const name = url.pathname.slice(assetPrefix.length);
      if (assetNames.includes(name)) {
        const file = path.join(releaseRoot, name);
        const bytes = fs.statSync(file).size;
        log.status = 200;
        res.writeHead(200, { 'Content-Type': 'application/octet-stream', 'Content-Length': bytes });
        if (req.method === 'HEAD') {
          res.end();
          return;
        }
        res.on('finish', () => { log.delivered_bytes = bytes; });
        const stream = fs.createReadStream(file);
        res.on('close', () => stream.destroy());
        stream.on('error', () => res.destroy());
        stream.pipe(res);
        return;
      }
    }
    reply(404, { message: 'Not Found' });
  });
  const tlsPort = await listen(tls);
  const proxy = http.createServer((_req, res) => { res.writeHead(403).end(); });
  proxy.on('connect', (req, client, head) => {
    if (!['github.com:443', 'api.github.com:443'].includes(req.url) || req.headers['proxy-authorization']) {
      client.end('HTTP/1.1 403 Forbidden\r\n\r\n');
      return;
    }
    const server = net.connect(tlsPort, '127.0.0.1');
    server.on('error', () => client.destroy());
    client.on('error', () => server.destroy());
    client.on('close', () => server.destroy());
    server.once('connect', () => {
      client.write('HTTP/1.1 200 Connection Established\r\n\r\n');
      if (head.length) server.write(head);
      client.pipe(server).pipe(client);
    });
  });
  for (const server of [proxy, tls]) {
    server.on('connection', socket => {
      sockets.add(socket);
      socket.once('close', () => sockets.delete(socket));
    });
  }
  const proxyPort = await listen(proxy);
  assert.ok(proxyPort > 0);
  return {
    proxyUrl: 'http://127.0.0.1:'+proxyPort,
    close() {
      for (const socket of sockets) socket.destroy();
      proxy.close();
      tls.close();
    },
  };
}
