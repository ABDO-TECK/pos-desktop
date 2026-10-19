<?php

namespace App\Services;

use App\Helpers\Logger;


class FrontendBuildService {
    private string $rootDir;

    public function __construct() {
        $this->rootDir = realpath(__DIR__ . '/../../') ?: dirname(__DIR__, 2);
    }

    public function installDependencies(array &$output): bool {
        $output[] = '📦 تثبيت حزم npm...';
        $t0 = microtime(true);

        $frontendDir = $this->rootDir . DIRECTORY_SEPARATOR . 'frontend';
        if (stripos(PHP_OS_FAMILY, 'Windows') !== false) {
            $npmCmd = 'cmd /c "cd /d ' . escapeshellarg($frontendDir) . ' && npm ci --ignore-scripts --no-audit --no-fund 2>&1"';
        } else {
            $npmCmd = 'cd ' . escapeshellarg($frontendDir) . ' && npm ci --ignore-scripts --no-audit --no-fund 2>&1';
        }

        $npmOut = [];
        exec($npmCmd, $npmOut, $npmRet);

        $elapsed = round(microtime(true) - $t0, 1);
        if ($npmRet !== 0) {
            Logger::warning('Update: npm install failed', ['output' => $npmOut, 'code' => $npmRet]);
            $output[] = "⚠️ npm install فشل (رمز: {$npmRet}) — ({$elapsed}s)";
            $output[] = '  ↳ Run: cd frontend && npm ci manually';
            return false;
        } else {
            $output[] = "✅ تم تثبيت الحزم ({$elapsed}s)";
            return true;
        }
    }

    public function buildFrontend(array &$output): bool {
        $frontendDir = $this->rootDir . DIRECTORY_SEPARATOR . 'frontend';
        $distDir     = $this->rootDir . DIRECTORY_SEPARATOR . 'frontend-dist';

        if (!is_dir($frontendDir . DIRECTORY_SEPARATOR . 'node_modules')) {
            return false;
        }

        $output[] = '🏗️ بناء واجهة الإنتاج (frontend-dist)...';
        $t0 = microtime(true);

        if (stripos(PHP_OS_FAMILY, 'Windows') !== false) {
            $buildCmd = 'cmd /c "cd /d ' . escapeshellarg($frontendDir) . ' && npm run build 2>&1"';
        } else {
            $buildCmd = 'cd ' . escapeshellarg($frontendDir) . ' && npm run build 2>&1';
        }

        $buildOut = [];
        exec($buildCmd, $buildOut, $buildRet);

        $elapsed = round(microtime(true) - $t0, 1);
        if ($buildRet !== 0) {
            Logger::warning('Update: npm build failed', ['output' => $buildOut, 'code' => $buildRet]);
            $output[] = "⚠️ بناء الواجهة فشل — ({$elapsed}s)";
            return false;
        } else {
            $srcDist = $frontendDir . DIRECTORY_SEPARATOR . 'dist';
            if (is_dir($srcDist) && is_dir($distDir)) {
                $this->copyDirectory($srcDist, $distDir);
                $output[] = "✅ تم بناء ونسخ الواجهة ({$elapsed}s)";
            } else {
                $output[] = "✅ تم البناء ({$elapsed}s)";
            }
            return true;
        }
    }

    private function copyDirectory(string $src, string $dst) {
        $dir = opendir($src);
        if (!$dir) return;

        if (!is_dir($dst)) {
            mkdir($dst, 0750, true);
        }

        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') continue;

            $srcPath = $src . DIRECTORY_SEPARATOR . $file;
            $dstPath = $dst . DIRECTORY_SEPARATOR . $file;

            if (is_dir($srcPath)) {
                $this->copyDirectory($srcPath, $dstPath);
            } else {
                copy($srcPath, $dstPath);
            }
        }
        closedir($dir);
    }
}
