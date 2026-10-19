# HTTPS on your LAN (Windows + XAMPP + Vite) — 2026-oriented guide

## التنفيذ الفعلي داخل هذا المشروع

| ماذا | أين |
|------|-----|
| تفعيل HTTPS لـ Vite تلقائياً عند وجود شهادات mkcert في `certs/` | `frontend/vite.config.js` (دالة `findLanTlsCerts`) |
| السماح بـ CORS من الهاتف على `https://192.168.x.x:5173` (وغيره) في وضع التطوير | `backend/index.php` (بعد `APP_DEBUG`) |
| تعليمات إنشاء الشهادات | `certs/README.txt` |
| مثال VirtualHost Apache | `snippets/apache-pos-lan-ssl.conf.example` |

This document explains why you see `ERR_SSL_PROTOCOL_ERROR`, how to fix it, and two **professional** local setups: **Vite HTTPS + mkcert** (simplest for React HMR + mobile camera) and **Apache reverse proxy** (single URL, closer to production).

---

## 1. Why `https://192.168.1.22:5173` fails with `ERR_SSL_PROTOCOL_ERROR`

By default **Vite’s dev server speaks HTTP only** on port 5173. If you open **`https://`** in the browser, the client starts a **TLS** handshake; the server responds with **plain HTTP** → the browser reports **SSL protocol error**.

**Fix:** either enable **TLS inside Vite** on 5173, or put **Apache (or another proxy) in front** on **443** and keep Vite on HTTP behind it.

---

## 2. Secure context rules (camera, service workers, etc.)

Browsers treat these as **secure** (camera APIs work):

- `https://` (valid or user-trusted cert)
- `http://localhost`, `http://127.0.0.1`, `http://[::1]`

**Plain `http://192.168.x.x` is *not* a secure context** → `navigator.mediaDevices` is often missing.

So for phones on Wi‑Fi you need **HTTPS to the LAN IP** (or a hostname that resolves to that IP with a matching cert).

---

## 3. Recommended approach for *development*

| Approach | Best for | Pros | Cons |
|----------|-----------|------|------|
| **A. mkcert + Vite `server.https`** | Daily React dev + phone testing | Fast, HMR works, minimal Apache changes | Phone must trust mkcert CA once |
| **B. Apache TLS + reverse proxy to Vite** | One URL (`https://IP/`), “prod-like” | Single port 443, can add `/api` rules in one place | Must configure WebSocket proxy for Vite HMR |

**Production-like scaling:** build the frontend (`npm run build`) and let **Apache serve `dist/`** over HTTPS with **`/api` → PHP** — same origin, no Vite in production.

---

## 4. Tooling: **mkcert** (recommended for dev certs)

mkcert creates a **local CA** and certificates with correct **SAN** entries (including **IP addresses**). Mobiles need the **root CA** installed once.

### Install (Windows)

- With **Chocolatey:** `choco install mkcert`
- Or download from the [mkcert releases](https://github.com/FiloSottile/mkcert/releases) and add to `PATH`.

### Create and trust CA on your PC

```powershell
mkcert -install
```

### Issue a cert for your LAN IP + localhost

Replace `192.168.1.22` with your PC’s IP (`ipconfig`).

```powershell
cd C:\xampp\htdocs\pos\certs
mkdir C:\xampp\htdocs\pos\certs 2>$null
cd C:\xampp\htdocs\pos\certs
mkcert localhost 127.0.0.1 ::1 192.168.1.22
```

mkcert prints two filenames, e.g.:

- `.\192.168.1.22+3.pem` — **certificate**
- `.\192.168.1.22+3-key.pem` — **private key**

**Never commit the `-key.pem` file to git.**

---

## 5. Configure Vite to use custom certificates

In `frontend/vite.config.js`, extend `server` (merge with your existing `proxy` / `host`):

```js
import fs from 'node:fs'
import path from 'node:path'
import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

const certDir = path.resolve(__dirname, '../certs')
// Adjust names to match mkcert output:
const certFile = path.join(certDir, '192.168.1.22+3.pem')
const keyFile = path.join(certDir, '192.168.1.22+3-key.pem')

const https =
  fs.existsSync(certFile) && fs.existsSync(keyFile)
    ? { cert: fs.readFileSync(certFile), key: fs.readFileSync(keyFile) }
    : undefined

export default defineConfig({
  plugins: [react()],
  server: {
    port: 5173,
    host: '0.0.0.0',
    https, // undefined = HTTP only if certs missing
    proxy: {
      '/api': {
        target: 'http://localhost/pos/backend',
        changeOrigin: true,
      },
      '/pos/backend/sign-message.php': {
        target: 'http://localhost',
        changeOrigin: true,
      },
    },
  },
})
```

Then open:

- **`https://192.168.1.22:5173`** (not `http://`)

Your **proxy targets stay `http://localhost/...`** — that is correct: only the browser↔Vite link is encrypted; Vite talks to PHP on the same machine over HTTP.

### Common mistakes

- Wrong filenames / paths → Vite falls back to HTTP and `https://` still breaks.
- Windows Firewall blocking **inbound** TCP **5173** (or **443** if using Apache).
- Using an OpenSSL cert **without SAN for the IP** → browser shows `NET::ERR_CERT_AUTHORITY_INVALID` (different from SSL protocol error).

---

## 6. Trust the certificate on your phone

### Android

1. On PC, run `mkcert -CAROOT` and copy `rootCA.pem` to the phone (USB, cloud, or email).
2. **Settings → Security → Encryption & credentials → Install a certificate → CA certificate** (wording varies by version).
3. Install `rootCA.pem`.

### iOS

1. AirDrop/email `rootCA.pem`, install profile.
2. **Settings → General → About → Certificate Trust Settings** → enable full trust for your mkcert root.

After this, `https://192.168.1.22:5173` should load without certificate warnings (for mkcert-issued server certs).

---

## 7. Option B: Apache reverse proxy (single HTTPS entry)

Use when you want **`https://192.168.1.22/`** (port **443**) without `:5173`.

### Enable modules in `httpd.conf` (XAMPP)

Uncomment / ensure loaded:

- `mod_ssl`
- `mod_proxy`, `mod_proxy_http`
- `mod_proxy_wstunnel`
- `mod_rewrite` (for WebSocket upgrade routing)

### Certificate files

Place PEM files where Apache can read them, e.g.:

`C:\xampp\apache\conf\ssl\server.crt`  
`C:\xmp\apache\conf\ssl\server.key`  

(Use the **same mkcert** cert+key you generated, renamed if you like.)

### Virtual host example

File: e.g. `C:\xampp\apache\conf\extra\httpd-pos-lan-ssl.conf`  
Include it from `httpd.conf`:

```apache
Include conf/extra/httpd-pos-lan-ssl.conf
```

Example content (adjust IP and paths):

```apache
Listen 443

<VirtualHost *:443>
    ServerName 192.168.1.22
    DocumentRoot "C:/xampp/htdocs"

    SSLEngine on
    SSLCertificateFile "C:/xampp/apache/conf/ssl/lan-cert.pem"
    SSLCertificateKeyFile "C:/xampp/apache/conf/ssl/lan-key.pem"

    # API first (more specific), then SPA dev server
    ProxyPreserveHost On
    ProxyRequests Off

    ProxyPass        /api http://127.0.0.1/pos/backend/api
    ProxyPassReverse /api http://127.0.0.1/pos/backend/api

    # Optional: QZ / PHP scripts if you use absolute paths
    ProxyPass        /pos/backend/sign-message.php http://127.0.0.1/pos/backend/sign-message.php
    ProxyPassReverse /pos/backend/sign-message.php http://127.0.0.1/pos/backend/sign-message.php

    # Vite dev server + HMR WebSocket
    RewriteEngine On
    RewriteCond %{HTTP:Upgrade} websocket [NC]
    RewriteCond %{HTTP:Connection} upgrade [NC]
    RewriteRule ^/?(.*) ws://127.0.0.1:5173/$1 [P,L]

    ProxyPass        / http://127.0.0.1:5173/
    ProxyPassReverse / http://127.0.0.1:5173/
</VirtualHost>
```

**Important:** Your React app uses `baseURL: '/api'`. The example maps `/api` → Apache → PHP. In this mode you may run Vite **without** `server.https` (only Apache terminates TLS).

For **this repo**, routes are registered as `/api/...` and `Router` strips the `/pos/backend` prefix from `REQUEST_URI`. So the upstream URL must be:

`http://127.0.0.1/pos/backend/api/...`

Apache example (already in `snippets/apache-pos-lan-ssl.conf.example`):

```apache
ProxyPass        /api http://127.0.0.1/pos/backend/api
ProxyPassReverse /api http://127.0.0.1/pos/backend/api
```

### If `/api` 404s

Open DevTools → Network on working **Vite HTTPS** dev (or HTTP localhost), copy the full request URL for one API call, then mirror that path in `ProxyPass`.

### CORS (this project)

`backend/index.php` only allows specific `HTTP_ORIGIN` values. When you open the app as `https://192.168.1.22:5173` (or `https://192.168.1.22` behind Apache), add that exact origin to `$allowedOrigins`, for example:

```php
$allowedOrigins = [
    'http://localhost:5173',
    'https://localhost:5173',
    'https://192.168.1.22:5173',
    'https://192.168.1.22',
    // ...
];
```

Adjust IP and port to match how you actually open the frontend.

---

## 8. Production-style setup (no Vite on LAN)

1. `npm run build` in `frontend/`.
2. Apache `DocumentRoot` or `Alias` for `/` → `frontend/dist`.
3. `ProxyPass /api ...` or `Alias /api` → PHP.

One origin, HTTPS only, no Node on the server.

---

## 9. Checklist — quick debugging

| Symptom | Likely cause |
|---------|----------------|
| `ERR_SSL_PROTOCOL_ERROR` | HTTPS URL but server is HTTP-only on that port |
| `NET::ERR_CERT_AUTHORITY_INVALID` on phone | Self-signed / no SAN / phone doesn’t trust CA |
| Blank HMR / page reload loop behind Apache | WebSocket not proxied (`mod_proxy_wstunnel` + rewrite) |
| API fails behind proxy | Wrong `ProxyPass` path; PHP sees wrong `Host` (try `ProxyPreserveHost Off` for backend) |

---

## 10. Firewall (Windows)

Allow inbound:

- **5173** — if using Vite HTTPS directly.
- **443** — if using Apache HTTPS.

```powershell
New-NetFirewallRule -DisplayName "Vite HTTPS 5173" -Direction Inbound -LocalPort 5173 -Protocol TCP -Action Allow
New-NetFirewallRule -DisplayName "Apache HTTPS 443" -Direction Inbound -LocalPort 443 -Protocol TCP -Action Allow
```

---

## Folder structure (suggested)

```text
pos/
  certs/                    # gitignored — mkcert output
    192.168.1.22+3.pem
    192.168.1.22+3-key.pem
  docs/
    local-https-lan.md      # this file
  frontend/
    vite.config.js
  snippets/
    apache-pos-lan-ssl.conf.example
```

Add to `.gitignore`:

```gitignore
certs/
*.pem
*-key.pem
```

---

## Summary

- **`ERR_SSL_PROTOCOL_ERROR` on `:5173`** = you used **HTTPS** while Vite was serving **HTTP** → enable **`server.https`** with real cert files **or** use Apache on **443**.
- **Plain HTTP to a LAN IP** will **not** unlock camera APIs; **HTTPS** (or localhost HTTP) will.
- **mkcert** is the smoothest dev workflow for **IP + localhost** SANs and **phone trust**.
- **Apache reverse proxy** is the cleanest **single-URL** dev/prod-like setup; remember **WebSocket** for Vite HMR.
