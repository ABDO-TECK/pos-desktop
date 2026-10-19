<?php
declare(strict_types=1);

$token = trim(shell_exec('gh auth token') ?: '');
if (!$token) {
    echo "No gh token found\n";
    exit(1);
}

$repo = 'ABDO-TECK/pos-desktop';
$tag = $argv[1] ?? 'v1.1.47';
$filePathInput = $argv[2] ?? "release/1.1.47-bootstrap/full-package.zip";
$filePath = str_starts_with($filePathInput, '/') || preg_match('#^[A-Z]:#i', $filePathInput)
    ? $filePathInput
    : __DIR__ . '/../' . $filePathInput;

if (!file_exists($filePath)) {
    echo "File not found: {$filePath}\n";
    exit(1);
}


// Get release ID
$ch = curl_init("https://api.github.com/repos/{$repo}/releases/tags/{$tag}");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: token {$token}",
    "User-Agent: POS-Release-Script",
    "Accept: application/vnd.github.v3+json"
]);
$resp = curl_exec($ch);
curl_close($ch);

$data = json_decode((string)$resp, true);
$releaseId = $data['id'] ?? null;
if (!$releaseId) {
    echo "Release ID not found for {$tag}\n";
    exit(1);
}

echo "Found release ID: {$releaseId}\n";

// Upload asset
$fileName = basename($filePath);
$fileContent = file_get_contents($filePath);
$fileSize = strlen($fileContent);

$uploadUrl = "https://uploads.github.com/repos/{$repo}/releases/{$releaseId}/assets?name=" . urlencode($fileName);
echo "Uploading {$fileName} ({$fileSize} bytes) to {$uploadUrl}...\n";

$ch = curl_init($uploadUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $fileContent);
curl_setopt($ch, CURLOPT_TIMEOUT, 600);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: token {$token}",
    "User-Agent: POS-Release-Script",
    "Content-Type: application/zip",
    "Content-Length: {$fileSize}",
    "Accept: application/vnd.github.v3+json"
]);

$uploadResp = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);


echo "HTTP Status Code: {$httpCode}\n";
$uploadData = json_decode((string)$uploadResp, true);
if ($httpCode === 201 || $httpCode === 200) {
    echo "✅ Successfully uploaded {$fileName} to GitHub Release v1.2.0!\n";
    echo "Download URL: " . ($uploadData['browser_download_url'] ?? 'N/A') . "\n";
} else {
    echo "Upload failed: " . substr((string)$uploadResp, 0, 500) . "\n";
}

