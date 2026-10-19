<?php
/**
 * QZ Tray — Sign-message endpoint
 *
 * Signs the QZ Tray request string with the private RSA key and returns the
 * base64-encoded SHA-512 signature.
 *
 * Key file location is configured via QZ_PRIVATE_KEY_PATH in .env:
 *   QZ_PRIVATE_KEY_PATH="C:/private/private-key.pem"
 *
 * If the variable is not set, C:/private/private-key.pem is tried for
 * backward-compatible server deployments. Keys beneath the HTTP document
 * root are intentionally unsupported.
 *
 * To use unsigned / anonymous mode (development only), set:
 *   QZ_PRIVATE_KEY_PATH=""
 */

// Resolve base directory correctly inside or outside phar
$pharRunning = Phar::running(false);
$baseDir = $pharRunning ? dirname($pharRunning) : __DIR__;

// Load .env so QZ_PRIVATE_KEY_PATH is available
require_once __DIR__ . '/Helpers/EnvLoader.php';
use App\Helpers\EnvLoader;
$envPath = getenv('ENV_PATH') ?: $baseDir . '/.env';
EnvLoader::load($envPath);
require_once __DIR__ . '/vendor/autoload.php';
\App\Helpers\ErrorHandler::register();
require_once __DIR__ . '/Config/config.php';

// Allow CORS — restricted to known origins only (security fix)
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedSignOrigins = [
    'http://localhost:5173',
    'http://127.0.0.1:5173',
    'https://localhost:5173',
    'https://127.0.0.1:5173',
    'http://localhost:8080',
    'http://127.0.0.1:8080',
    'https://localhost:8080',
    'https://127.0.0.1:8080',
    'app://pos-app',
    'app://.',
];
// Also allow LAN IPs only when explicitly enabled for thermal printing.
$signOriginAllowed = in_array($origin, $allowedSignOrigins, true);
if (!$signOriginAllowed && EnvLoader::getBool('CORS_ALLOW_LAN', false) && $origin !== '') {
    $signOriginAllowed = \App\Helpers\NetworkHelper::isLanOrigin($origin);
}
if ($signOriginAllowed && $origin !== '') {
    header("Access-Control-Allow-Origin: $origin");
    header("Access-Control-Allow-Credentials: true");
}
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");


if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Security: restrict to localhost only ───────────────────────────────────
// This endpoint signs arbitrary data with the private key and must not be
// accessible from remote clients. Only local processes (QZ Tray) should call it.
$remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($remoteAddr, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    echo 'Access denied: localhost only';
    exit(1);
}

// The embedded HTTPS proxy reaches PHP over loopback, so REMOTE_ADDR alone is
// not an authentication boundary in LAN mode. Require a live POS access token
// before exposing the signing oracle.
$accessToken = $_COOKIE['pos_token'] ?? '';
if ($accessToken === '') {
    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (str_starts_with($authorization, 'Bearer ')) {
        $accessToken = substr($authorization, 7);
    }
}

if ($accessToken === '') {
    http_response_code(401);
    echo 'Authentication required';
    exit(1);
}

$db = \App\Config\Database::getInstance();
$authStatement = $db->prepare(
    'SELECT t.user_id, u.role, u.branch_id
     FROM tokens t
     JOIN users u ON u.id = t.user_id
     WHERE t.token = ?
       AND t.expires_at IS NOT NULL
       AND t.expires_at > UTC_TIMESTAMP()
       AND u.is_active = 1
       AND u.force_password_change = 0
     LIMIT 1'
);
$authStatement->execute([hash('sha256', $accessToken)]);
$authenticatedUser = $authStatement->fetch(\PDO::FETCH_ASSOC);
if (!is_array($authenticatedUser)) {
    http_response_code(401);
    echo 'Invalid authentication token';
    exit(1);
}

$authenticatedUserId = (int) $authenticatedUser['user_id'];
$authService = new \App\Services\AuthService();
$authService->setUser([
    'id' => $authenticatedUserId,
    'role' => (string) $authenticatedUser['role'],
    'branch_id' => (int) $authenticatedUser['branch_id'],
]);

if (!\App\Middleware\PermissionMiddleware::allows($authService, 'printing.use')) {
    http_response_code(403);
    echo 'Printing permission required';
    exit(1);
}

// Bound use of the signing oracle per authenticated user. This remains
// effective when the Electron HTTPS proxy makes every request appear local.
(new \App\Middleware\RateLimiter(120, 60))->check('qz_sign', (int) $authenticatedUserId);

// ── Key file path ──────────────────────────────────────────────────────────
// Read from .env first; fall back to known locations.
$envKeyPath = EnvLoader::get('QZ_PRIVATE_KEY_PATH', '');

$KEY = null;
if ($envKeyPath !== '' && file_exists($envKeyPath)) {
    $KEY = $envKeyPath;
} else {
    // Fallback paths (only used when .env key is not set)
    $possibleKeys = ['C:/private/private-key.pem'];
    foreach ($possibleKeys as $path) {
        if (file_exists($path)) { $KEY = $path; break; }
    }
}

// ── Anonymous / unsigned fallback (if no key file exists) ─────────────────
if ($KEY === null) {
    if (defined('APP_ENV') && APP_ENV !== 'development') {
        http_response_code(503);
        echo 'QZ signing is not configured';
        exit(1);
    }

    // In unsigned mode QZ Tray's security.setSignaturePromise should call
    // resolve() with no argument. We just return an empty body so the JS
    // resolve() fallback in qzPrint.js takes over.
    header("Content-type: text/plain");
    echo '';
    exit(0);
}

// ── Sign the request ───────────────────────────────────────────────────────
$req = \App\Security\QzRequestPolicy::normalizeDigest(
    (string) ($_GET['request'] ?? '')
);

if ($req === null) {
    http_response_code(400);
    echo 'Invalid request parameter';
    exit(1);
}

$privateKeyContents = file_get_contents($KEY);
if ($privateKeyContents === false) {
    http_response_code(500);
    echo 'Error loading private key';
    exit(1);
}

$privateKey = openssl_get_privatekey($privateKeyContents);

if (!$privateKey) {
    http_response_code(500);
    echo 'Error loading private key';
    exit(1);
}

$signature = null;
openssl_sign($req, $signature, $privateKey, "sha512");

if ($signature) {
    header("Content-type: text/plain");
    echo base64_encode($signature);
    exit(0);
}

http_response_code(500);
echo 'Error signing message';
exit(1);
?>
