<?php
declare(strict_types=1);

if (getenv('POS_REPAIR_BOOTSTRAP') === '1') {
    // ── Repair Control Plane Branch ──
    // Dedicated, authenticated, read-only SAPI process for the approved candidate backend PHAR.
    // DOES NOT load channel-transition-transport.php or old vendor autoloader to avoid ComposerAutoloaderInit redeclaration.
    if (getenv('GITHUB_ACTIONS') !== 'true') {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Repair control plane requires ephemeral CI environment.']);
        exit;
    }

    if (getenv('GITHUB_REPOSITORY') !== 'ABDO-TECK/pos-desktop-updates') {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Repair control plane requires GITHUB_REPOSITORY === ABDO-TECK/pos-desktop-updates.']);
        exit;
    }

    $rawRunnerTemp = realpath((string)getenv('RUNNER_TEMP'));
    if ($rawRunnerTemp === false || !is_dir($rawRunnerTemp)) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Invalid or missing RUNNER_TEMP boundary.']);
        exit;
    }
    $runnerTemp = rtrim(str_replace('\\', '/', $rawRunnerTemp), '/');

    // Exact lab directory and marker validation
    $expectedLabRaw = $rawRunnerTemp . DIRECTORY_SEPARATOR . 'setup11-channel-transition-lab';
    $realLab = realpath($expectedLabRaw);
    if ($realLab === false || !is_dir($realLab)) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Lab directory missing.']);
        exit;
    }
    $labBoundary = rtrim(str_replace('\\', '/', $realLab), '/');

    $ownedLabMarker = $labBoundary . '/owned-lab.json';
    if (!is_file($ownedLabMarker)) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Owned lab marker missing.']);
        exit;
    }
    $ownedMarker = json_decode((string)file_get_contents($ownedLabMarker), true);
    if (!is_array($ownedMarker) || empty($ownedMarker['application'])) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Owned lab marker invalid.']);
        exit;
    }
    $realOwnedApp = realpath((string)$ownedMarker['application']);
    if ($realOwnedApp === false) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Owned lab application path invalid.']);
        exit;
    }
    $expectedAppUnpacked = rtrim(str_replace('\\', '/', $realOwnedApp), '/') . '/resources/app.asar.unpacked';

    // APP_DEPLOY_ROOT must match owned application unpacked root exactly
    $actualDeployRootRaw = realpath((string)getenv('APP_DEPLOY_ROOT'));
    if ($actualDeployRootRaw === false || rtrim(str_replace('\\', '/', $actualDeployRootRaw), '/') !== $expectedAppUnpacked) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'APP_DEPLOY_ROOT mismatch against owned application unpacked root.']);
        exit;
    }

    $candidatePhar = (string)getenv('POS_REPAIR_CANDIDATE_PHAR');
    $expectedHash = (string)getenv('POS_REPAIR_EXPECTED_HASH');
    $boundJobId = (string)getenv('POS_REPAIR_BOUND_JOB_ID');

    if ($candidatePhar === '' || $expectedHash === '' || $boundJobId === '') {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Missing repair control plane configuration.']);
        exit;
    }

    // Strict 64 hex repair hash check
    if (!preg_match('/^[a-f0-9]{64}$/i', $expectedHash)) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Invalid repair hash format (must be 64-hex).']);
        exit;
    }

    // Strict regex digits for bound job id
    if (!preg_match('/^\d+$/', $boundJobId) || (int)$boundJobId <= 0) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Invalid bound job id format.']);
        exit;
    }

    // Walk raw candidate phar path ancestors for symlink
    $normalizedSeparators = str_replace('\\', '/', $candidatePhar);
    $segments = explode('/', $normalizedSeparators);
    $cursor = '';
    if (preg_match('#^[a-zA-Z]:#', $segments[0])) {
        $cursor = array_shift($segments);
    }
    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.') continue;
        if ($segment === '..') {
            $cursor = dirname($cursor);
            continue;
        }
        $cursor .= '/' . $segment;
        if (file_exists($cursor) && is_link($cursor)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Reparse point / symlink rejected in candidate PHAR path.']);
            exit;
        }
    }

    $realCandidatePhar = realpath($candidatePhar);
    if ($realCandidatePhar === false || !is_file($realCandidatePhar)) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Candidate PHAR not found on disk.']);
        exit;
    }
    $normCandidatePhar = rtrim(str_replace('\\', '/', $realCandidatePhar), '/');

    // Candidate PHAR must be a strict descendant of owned LAB (not whole RUNNER_TEMP)
    if (!str_starts_with(strtolower($normCandidatePhar), strtolower($labBoundary) . '/')) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Candidate PHAR path outside owned lab boundary.']);
        exit;
    }

    // Verify candidate file size > 0
    $candSize = filesize($realCandidatePhar);
    if ($candSize === false || $candSize <= 0) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Candidate PHAR size non-positive.']);
        exit;
    }

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $rawUri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($rawUri, PHP_URL_PATH) ?? '/';

    // Allow GET /api/health for readiness check
    if ($path === '/api/health') {
        if ($method !== 'GET') {
            http_response_code(405);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
            exit;
        }
        header('Content-Type: application/json');
        header('X-Backend-Source: signed_repair_candidate');
        echo json_encode(['status' => 'ok', 'control_plane' => 'repair_bootstrap']);
        exit;
    }

    // Repair control plane is strictly read-only: ONLY GET allowed
    if ($method !== 'GET') {
        http_response_code(405);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Repair control plane only allows GET requests.']);
        exit;
    }

    // Only allow exact bound job route
    $pattern = '#^/(?:api/v1|api)/update/jobs/' . preg_quote($boundJobId, '#') . '$#';
    if (!preg_match($pattern, $path)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => "Repair control plane route forbidden. Only bound job #{$boundJobId} is permitted."]);
        exit;
    }

    // Per repair request check: candidate PHAR SHA256 == verified expected context hash BEFORE require candidate index
    $actualHash = strtolower((string)hash_file('sha256', $realCandidatePhar));
    if (!hash_equals(strtolower($expectedHash), $actualHash)) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Candidate PHAR SHA256 mismatch against verified public context hash.']);
        exit;
    }

    // Check installed source version is 0.0.11
    $versionFile = $expectedAppUnpacked . '/version.json';
    if (!is_file($versionFile)) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Installed version.json not found.']);
        exit;
    }
    $vData = json_decode((string)file_get_contents($versionFile), true);
    if (($vData['version'] ?? '') !== '0.0.11') {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Installed version must be 0.0.11 during repair bootstrap.']);
        exit;
    }

    // Set backend source header to reflect signed repair candidate
    header('X-Backend-Source: signed_repair_candidate');

    // Execute verified candidate backend PHAR production index
    require 'phar://' . str_replace('\\', '/', $realCandidatePhar) . '/index.php';
} else {
    // ── Normal Channel Transition Branch ──
    require __DIR__.'/channel-transition-transport.php';
    require 'phar://'.str_replace('\\','/',(string)getenv('APP_DEPLOY_ROOT')).'/backend/backend.phar/index.php';
}
