<?php

namespace App\Services;

use App\Helpers\Logger;
use RuntimeException;
use Throwable;

class ManifestSignatureService
{
    private ?string $publicKeyPath;
    /** @var list<string> */
    private array $trustedPublicKeys = [];

    public function __construct(?string $publicKeyPath = null, array $additionalTrustedKeys = [])
    {
        $this->publicKeyPath = $publicKeyPath ? str_replace('\\', '/', $publicKeyPath) : $this->resolveDefaultPublicKeyPath();
        $this->trustedPublicKeys = array_values(array_unique(array_filter(
            array_merge(
                $this->resolveAllTrustedKeyPaths(),
                $additionalTrustedKeys
            )
        )));
    }

    public function getPublicKeyPath(): ?string
    {
        return $this->publicKeyPath;
    }

    /**
     * @return list<string>
     */
    public function getTrustedKeyPaths(): array
    {
        return $this->trustedPublicKeys;
    }

    /**
     * Verify data against an RSA signature using SHA-256 with Key Rotation support.
     *
     * @param string $data The original string data (e.g. manifest.json content)
     * @param string $signature The signature (raw binary or base64 encoded)
     * @param string|null $customPublicKey Optional public key content or path
     * @return bool True if valid against any trusted key, false otherwise
     */
    public function verifySignature(string $data, string $signature, ?string $customPublicKey = null): bool
    {
        if (trim($data) === '' || trim($signature) === '') {
            Logger::warning('Manifest signature verification failed: empty data or signature provided.');
            return false;
        }

        // Handle base64 encoded signatures or raw binary signatures
        $rawSignature = $signature;
        $decoded = base64_decode(trim($signature), true);
        if ($decoded !== false && base64_encode($decoded) === trim($signature)) {
            $rawSignature = $decoded;
        }

        // If a specific custom key was provided, verify only against it
        if ($customPublicKey !== null) {
            return $this->verifyWithSingleKey($data, $rawSignature, $customPublicKey);
        }

        // 1. First, attempt verification with primary public key
        if ($this->publicKeyPath && is_file($this->publicKeyPath)) {
            if ($this->verifyWithSingleKey($data, $rawSignature, $this->publicKeyPath)) {
                return true;
            }
        }

        // 2. Key Rotation Fallback: Attempt verification against any secondary/rotation trusted keys
        foreach ($this->trustedPublicKeys as $trustedKeyPathOrContent) {
            if ($trustedKeyPathOrContent === $this->publicKeyPath) {
                continue; // already tested
            }

            if ($this->verifyWithSingleKey($data, $rawSignature, $trustedKeyPathOrContent)) {
                Logger::info('Manifest signature verified using trusted secondary/rotation public key.', [
                    'key' => is_file($trustedKeyPathOrContent) ? basename($trustedKeyPathOrContent) : 'in_memory_key',
                ]);
                return true;
            }
        }

        Logger::warning('Manifest signature verification rejected: No trusted public key could verify the signature.');
        return false;
    }

    /**
     * Verify signature using a single specified key (file path or PEM string).
     */
    private function verifyWithSingleKey(string $data, string $rawSignature, string $keySource): bool
    {
        $publicKey = $this->loadPublicKey($keySource);
        if (!$publicKey) {
            return false;
        }

        // Validate key strength: must be RSA with at least 2048 bits
        $details = openssl_pkey_get_details($publicKey);
        if (!$details || ($details['type'] ?? -1) !== OPENSSL_KEYTYPE_RSA || ($details['bits'] ?? 0) < 2048) {
            Logger::error('Public key rejected: must be RSA-2048 or higher.', [
                'type' => $details['type'] ?? 'unknown',
                'bits' => $details['bits'] ?? 0,
            ]);
            return false;
        }

        try {
            // Strictly enforce OPENSSL_ALGO_SHA256 signature algorithm
            $result = openssl_verify($data, $rawSignature, $publicKey, OPENSSL_ALGO_SHA256);
            return $result === 1;
        } catch (Throwable $e) {
            Logger::error('Exception during manifest signature verification', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Verify a manifest file on disk against its signature file.
     *
     * @param string $manifestPath Path to manifest.json
     * @param string $signaturePath Path to manifest.sig
     * @param string|null $customPublicKey Optional custom public key path or string
     * @return array{ok: bool, error: ?string}
     */
    public function verifyManifestFile(string $manifestPath, string $signaturePath, ?string $customPublicKey = null): array
    {
        if (!is_file($manifestPath)) {
            return ['ok' => false, 'error' => "Manifest file not found at: {$manifestPath}"];
        }

        if (!is_file($signaturePath)) {
            return ['ok' => false, 'error' => "Signature file not found at: {$signaturePath}"];
        }

        $manifestContent = @file_get_contents($manifestPath);
        if ($manifestContent === false) {
            return ['ok' => false, 'error' => "Could not read manifest file: {$manifestPath}"];
        }

        $signatureContent = @file_get_contents($signaturePath);
        if ($signatureContent === false) {
            return ['ok' => false, 'error' => "Could not read signature file: {$signaturePath}"];
        }

        $valid = $this->verifySignature($manifestContent, $signatureContent, $customPublicKey);
        if (!$valid) {
            return ['ok' => false, 'error' => 'Digital signature verification failed. The update manifest may have been modified or signed with an unauthorized key.'];
        }

        return ['ok' => true, 'error' => null];
    }

    /**
     * Sign data using a private RSA key (for developer / CI release automation).
     *
     * @param string $data Content to sign
     * @param string $privateKeyContentOrPath Private key PEM content or path to .pem file
     * @param string|null $passphrase Optional passphrase for private key
     * @return string Base64 encoded signature
     */
    public function signData(string $data, string $privateKeyContentOrPath, ?string $passphrase = null): string
    {
        $privKeyResource = $this->loadPrivateKey($privateKeyContentOrPath, $passphrase);
        if (!$privKeyResource) {
            throw new RuntimeException('Could not load private key for signing.');
        }

        $signature = '';
        $success = openssl_sign($data, $signature, $privKeyResource, OPENSSL_ALGO_SHA256);
        if (!$success) {
            $sslError = openssl_error_string() ?: 'Unknown OpenSSL signing error';
            throw new RuntimeException("Failed to sign manifest: {$sslError}");
        }

        return base64_encode($signature);
    }

    /**
     * Generate a new RSA KeyPair (helper for test suites and initial key setup).
     *
     * @param int $bits Key length (default: 2048)
     * @return array{private_key: string, public_key: string}
     */
    public static function generateKeyPair(int $bits = 2048): array
    {
        $config = [
            'digest_alg' => 'sha256',
            'private_key_bits' => max(2048, $bits),
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $cnfPath = self::resolveOpenSslCnfPath();
        if ($cnfPath !== null) {
            $config['config'] = $cnfPath;
        }

        $res = openssl_pkey_new($config);
        if (!$res) {
            throw new RuntimeException('Failed to generate OpenSSL keypair: ' . (openssl_error_string() ?: 'unknown error'));
        }

        $privateKey = '';
        if ($cnfPath !== null) {
            openssl_pkey_export($res, $privateKey, null, $config);
        } else {
            openssl_pkey_export($res, $privateKey);
        }

        $details = openssl_pkey_get_details($res);
        $publicKey = $details['key'] ?? '';

        return [
            'private_key' => $privateKey,
            'public_key' => $publicKey,
        ];
    }

    private static function resolveOpenSslCnfPath(): ?string
    {
        $envCnf = getenv('OPENSSL_CONF');
        if ($envCnf && is_file($envCnf)) {
            return str_replace('\\', '/', $envCnf);
        }

        $candidates = [
            'C:/xampp/php/extras/ssl/openssl.cnf',
            'C:/xampp/apache/bin/openssl.cnf',
            'C:/php/extras/ssl/openssl.cnf',
            '/etc/ssl/openssl.cnf',
            '/usr/local/ssl/openssl.cnf',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Load public key resource or string.
     */
    private function loadPublicKey(?string $customPublicKey = null): mixed
    {
        $keyContent = null;

        if ($customPublicKey !== null) {
            if (is_file($customPublicKey)) {
                $keyContent = @file_get_contents($customPublicKey);
            } else {
                $keyContent = $customPublicKey;
            }
        } elseif ($this->publicKeyPath && is_file($this->publicKeyPath)) {
            $keyContent = @file_get_contents($this->publicKeyPath);
        }

        if (!$keyContent || !is_string($keyContent)) {
            return false;
        }

        return openssl_pkey_get_public($keyContent);
    }

    /**
     * Load private key resource.
     */
    private function loadPrivateKey(string $keyContentOrPath, ?string $passphrase = null): mixed
    {
        $keyContent = is_file($keyContentOrPath) ? @file_get_contents($keyContentOrPath) : $keyContentOrPath;
        if (!$keyContent || !is_string($keyContent)) {
            return false;
        }

        return openssl_pkey_get_private($keyContent, $passphrase ?? '');
    }

    /**
     * Auto-discover default public key from certs directories.
     */
    private function resolveDefaultPublicKeyPath(): ?string
    {
        $candidates = $this->resolveAllTrustedKeyPaths();
        return $candidates[0] ?? null;
    }

    /**
     * Discover all pinned trusted keys across certs directories for key rotation.
     *
     * @return list<string>
     */
    private function resolveAllTrustedKeyPaths(): array
    {
        $baseDir = UpdateRuntimePaths::deployedRoot(realpath(__DIR__ . '/../../') ?: dirname(__DIR__, 2));
        $dirs = [
            $baseDir . '/backend/certs',
            $baseDir . '/certs',
            $baseDir . '/release',
            dirname(__DIR__) . '/certs',
        ];

        $found = [];
        foreach ($dirs as $dir) {
            if (is_dir($dir)) {
                $files = glob($dir . '/*.pem') ?: [];
                foreach ($files as $file) {
                    $norm = str_replace('\\', '/', $file);
                    if (str_contains(basename($norm), 'public') || str_contains(basename($norm), 'key')) {
                        $found[] = $norm;
                    }
                }
            }
        }

        return array_values(array_unique($found));
    }
}
