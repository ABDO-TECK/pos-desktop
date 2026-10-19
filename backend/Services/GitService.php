<?php

namespace App\Services;

use App\Config\config;


class GitService {
    private string $rootDir;

    public function __construct() {
        $this->rootDir = realpath(__DIR__ . '/../../') ?: dirname(__DIR__, 2);
    }

    /**
     * حل مسار Git — يبحث في المواقع الشائعة على Windows.
     */
    private function resolveGitExecutable(): string {
        $custom = getenv('GIT_BINARY_PATH');
        if (is_string($custom) && $custom !== '' && is_file($custom)) {
            return $custom;
        }
        if (stripos(PHP_OS_FAMILY, 'Windows') === false) {
            return 'git';
        }
        $pf   = getenv('ProgramFiles') ?: 'C:\\Program Files';
        $pf86 = getenv('ProgramFiles(x86)') ?: 'C:\\Program Files (x86)';
        foreach ([
            $pf   . '\\Git\\cmd\\git.exe',
            $pf   . '\\Git\\bin\\git.exe',
            $pf86 . '\\Git\\cmd\\git.exe',
            $pf86 . '\\Git\\bin\\git.exe',
        ] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        return 'git';
    }

    /**
     * تشغيل أمر Git عبر proc_open (بدون shell).
     *
     * @return array{0: string[], 1: int}  [output_lines, exit_code]
     */
    public function runGit(array $gitArgs, ?string $cwd = null) {
        $git = $this->resolveGitExecutable();
        $dir = $cwd ?? $this->rootDir;
        $cmd = array_merge([$git, '-C', $dir], $gitArgs);

        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes   = [];
        $process = @proc_open($cmd, $descriptorspec, $pipes, null, null, ['bypass_shell' => true]);

        if (!is_resource($process)) {
            return [['تعذر تشغيل Git. ثبّت Git للويندوز، أو عرّف متغير البيئة GIT_BINARY_PATH.'], 127];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code   = proc_close($process);

        $merged = trim((string)$stdout . "\n" . (string)$stderr);
        $lines  = $merged === '' ? [] : preg_split('/\r\n|\r|\n/', $merged);

        return [$lines, $code];
    }

    /**
     * التأكد من أن Git يثق بمجلد المشروع (safe.directory).
     */
    public function ensureSafeDirectory() {
        $dir = str_replace('\\', '/', $this->rootDir);

        [$lines, $code] = $this->runGit(['config', '--global', '--get-all', 'safe.directory']);
        if ($code === 0) {
            foreach ($lines as $line) {
                $l = trim($line);
                if ($l === $dir || $l === '*') {
                    return;
                }
            }
        }

        $this->runGit(['config', '--global', '--add', 'safe.directory', $dir]);
        $this->runGit(['config', '--system', '--add', 'safe.directory', $dir]);
    }

    /**
     * فحص شامل لحالة Git — يُرجع مصفوفة تشخيصية.
     */
    public function diagnoseGit() {
        $diag = [];

        $gitDir = $this->rootDir . DIRECTORY_SEPARATOR . '.git';
        $diag['git_dir_exists']  = is_dir($gitDir);
        $diag['git_dir_path']    = $gitDir;
        $diag['root_dir']        = $this->rootDir;

        $git = $this->resolveGitExecutable();
        $diag['git_executable']  = $git;
        $diag['git_file_exists'] = ($git === 'git') ? 'PATH lookup' : is_file($git);

        [$statusOut, $statusCode] = $this->runGit(['status', '--porcelain']);
        $diag['git_status_code']  = $statusCode;
        $diag['git_status_out']   = implode(' | ', array_slice($statusOut, 0, 3));

        [$remoteOut, $remoteCode] = $this->runGit(['remote', '-v']);
        $diag['git_remote_code']  = $remoteCode;
        $diag['git_remote_out']   = implode(' | ', array_slice($remoteOut, 0, 2));

        $diag['php_user'] = function_exists('get_current_user') ? get_current_user() : 'unknown';
        $diag['php_sapi'] = PHP_SAPI;

        return $diag;
    }
}
