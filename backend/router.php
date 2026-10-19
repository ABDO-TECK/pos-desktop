<?php
/**
 * Router for PHP built-in server (replacement for Apache .htaccess)
 * يقدّم ملفات Frontend (SPA) + يوجّه طلبات API للـ Backend.
 * Usage: php -S 127.0.0.1:8080 -t backend router.php
 */

// ── Detect HTTPS proxy (Electron HTTPS proxy sends X-Forwarded-Proto) ──
// This ensures $_SERVER['HTTPS'] is set correctly even when PHP runs behind
// the Node.js HTTPS proxy, fixing cookie Secure flag and CORS issues.
if (
    (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] !== 'on') &&
    isset($_SERVER['HTTP_X_FORWARDED_PROTO']) &&
    strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https' &&
    in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
) {
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['SERVER_PORT'] = $_SERVER['HTTP_X_FORWARDED_PORT'] ?? '443';
}

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

$pharRunning = Phar::running(false);
$baseDir = $pharRunning ? dirname($pharRunning) : __DIR__;

/**
 * Resolve a requested static path and prove that it remains inside its root.
 *
 * @param list<string> $allowedExtensions
 */
function resolveStaticFile(string $root, string $uri, array $allowedExtensions): ?string
{
    if (
        str_contains($uri, "\0")
        || str_contains($uri, '\\')
        || preg_match('#(?:^|/)\.{1,2}(?:/|$)#', $uri)
    ) {
        return null;
    }

    $rootPath = realpath($root);
    if ($rootPath === false) {
        return null;
    }

    $candidate = realpath($rootPath . DIRECTORY_SEPARATOR . ltrim($uri, '/'));
    if ($candidate === false || !is_file($candidate)) {
        return null;
    }

    $prefix = strtolower($rootPath . DIRECTORY_SEPARATOR);
    if (!str_starts_with(strtolower($candidate), $prefix)) {
        return null;
    }

    $extension = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
    return in_array($extension, $allowedExtensions, true) ? $candidate : null;
}

// ── 0.1 حماية الملفات الحساسة (.env, .git, logs, storage) ──────
// حظر أي طلب مباشر لملفات البيئة أو الملفات الحساسة
if (preg_match('#(\.env|\.git|/logs/|/storage/|\.phpunit|composer\.(json|lock|phar))#i', $uri)) {
    http_response_code(403);
    echo '403 Forbidden';
    return true;
}

// ── 0.5 حماية Adminer من الوصول عبر PHP built-in server ────────
// في الإنتاج: ممنوع تماماً | في التطوير: فقط من 127.0.0.1
if (preg_match('#/adminer#i', $uri)) {
    // قراءة APP_ENV: نحاول من $_ENV أولاً، ثم getenv، وأخيراً من ملف .env مباشرة
    $appEnv = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: null;
    if ($appEnv === null || $appEnv === false || $appEnv === '') {
        // قراءة APP_ENV من ملف .env يدوياً كخط دفاع أخير
        $envFile = $baseDir . '/.env';
        if (file_exists($envFile)) {
            $envContent = file_get_contents($envFile);
            if (preg_match('/^APP_ENV\s*=\s*(.+)$/m', $envContent, $m)) {
                $appEnv = trim($m[1], " \t\n\r\"'");
            }
        }
        $appEnv = $appEnv ?: 'development';
    }

    $remoteIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    // حظر في الإنتاج أو من أي IP غير محلي
    if ($appEnv === 'production' || !in_array($remoteIp, ['127.0.0.1', '::1'], true)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        echo '403 Forbidden — Adminer is not available.';
        return true;
    }
}

// ── 0. Rewrite /pos/backend/ to / (for compatibility with XAMPP paths) ──
if (str_starts_with($uri, '/pos/backend/')) {
    $uri = substr($uri, 12); // Keep the starting slash: '/sign-message.php'
}

// ── 1. API requests → forward to backend index.php ──────────
if (str_starts_with($uri, '/api/') || $uri === '/api') {
    require __DIR__ . '/index.php';
    return true;
}

// ── 1.5 Neutralize Service Worker when accessed via HTTPS proxy ──
// The PWA Service Worker causes infinite reload loops when served through
// the Electron HTTPS proxy (self-signed cert + autoUpdate + skipWaiting).
// We serve a no-op SW that unregisters itself for HTTPS proxy clients.
$isHttpsProxy = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' 
    && isset($_SERVER['HTTP_X_FORWARDED_PROTO']);
if ($isHttpsProxy && ($uri === '/sw.js' || $uri === '/registerSW.js' || str_starts_with($uri, '/workbox-'))) {
    header('Content-Type: application/javascript; charset=UTF-8');
    header('Cache-Control: no-store');
    if ($uri === '/sw.js') {
        // Self-unregistering SW: clears all caches and unregisters itself
        echo "self.addEventListener('install', () => self.skipWaiting());\n";
        echo "self.addEventListener('activate', (e) => {\n";
        echo "  e.waitUntil(caches.keys().then(names => Promise.all(names.map(n => caches.delete(n)))).then(() => self.registration.unregister()));\n";
        echo "});\n";
    } else {
        // Empty script — don't register any SW
        echo '// SW disabled via HTTPS proxy';
    }
    return true;
}

// ── 2. Backend static files (whitelisted PHP files only) ─────
// Only explicitly allowed PHP files can be executed directly via URL.
// This prevents execution of debug scripts, uploaded files, or adminer.
$allowedPhpFiles = [
    '/sign-message.php',
];
if (in_array($uri, $allowedPhpFiles, true)) {
    require __DIR__ . $uri;
    return true;
}

// ── 3. Frontend static files (JS, CSS, images) ─────────────
$frontendDist = $baseDir . '/../frontend/dist';
$filePath = resolveStaticFile(
    $frontendDist,
    $uri,
    ['html', 'js', 'css', 'json', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'ico', 'woff', 'woff2', 'ttf', 'webmanifest', 'webp', 'txt']
);

if ($uri !== '/' && $filePath !== null) {
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $mimeTypes = [
        'html' => 'text/html; charset=UTF-8',
        'js'   => 'application/javascript; charset=UTF-8',
        'css'  => 'text/css; charset=UTF-8',
        'json' => 'application/json; charset=UTF-8',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'svg'  => 'image/svg+xml',
        'ico'  => 'image/x-icon',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'webmanifest' => 'application/manifest+json',
        'webp' => 'image/webp',
        'txt'  => 'text/plain; charset=UTF-8',
    ];
    $mime = $mimeTypes[$ext] ?? 'application/octet-stream';
    header("Content-Type: $mime");
    readfile($filePath);
    return true;
}

// ── 3.5 Fallback to public directory for certificates etc. ──
$publicPath = resolveStaticFile(
    $baseDir . '/../frontend/public',
    $uri,
    ['txt', 'js', 'json', 'png', 'jpg', 'jpeg', 'webp', 'svg', 'ico', 'woff', 'woff2', 'ttf']
);
if ($uri !== '/' && $publicPath !== null) {
    $ext = strtolower(pathinfo($publicPath, PATHINFO_EXTENSION));
    $mimeTypes = [
        'txt'  => 'text/plain; charset=UTF-8',
        'js'   => 'application/javascript; charset=UTF-8',
    ];
    $mime = $mimeTypes[$ext] ?? 'application/octet-stream';
    header("Content-Type: $mime");
    readfile($publicPath);
    return true;
}

// ── 4. SPA fallback: أي مسار آخر → index.html ──────────────
$indexHtml = $frontendDist . '/index.html';
if (file_exists($indexHtml)) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    header('Content-Type: text/html; charset=UTF-8');
    readfile($indexHtml);
    return true;
}

http_response_code(404);
echo 'Not Found';
