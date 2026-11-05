<?php
declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * ShellDeltaWorkerPipeTransportService
 *
 * PRODUCTION Win32 local-only owned worker named pipe framing transport primitive (ZWP1 framing).
 *
 * ARCHITECTURAL TRUST BOUNDARIES & HONEST BLOCKING DEADLINE PRECONDITION:
 * 1. Process identity is NOT code, image, or payload authentication.
 *    Proving that a connected pipe peer PID matches an owned child process does NOT
 *    prove that the process memory or PHP runtime has not been tampered with.
 * 2. HONEST BLOCKING DEADLINE PRECONDITION:
 *    PHP plain Windows named pipe file wrapper (`\\.\pipe\...` via fopen) does NOT
 *    reliably support stream_set_timeout, non-blocking mode, or stream_select on Windows named pipes.
 *    Transport I/O operations (fread / fwrite) are inherently synchronous and BLOCKING.
 *    Callers (the native C# host or orchestrator) MUST enforce all frame deadlines
 *    (1..30000 ms) and cancellation externally via native Win32 job object termination
 *    or process termination (OwnedChildProcess.TryStopAndClose).
 *    This PHP transport does NOT invent fake local timeouts or ignore unsupported stream options.
 * 3. CanApply is unconditionally false across all operations.
 *    Transporting raw framed bytes provides zero DB quiescence proof, zero apply proof,
 *    and zero schema proof.
 * 4. Production use is NOT wired yet.
 */
final class ShellDeltaWorkerPipeTransportService
{
    public const MAGIC = 'ZWP1';
    public const HEADER_SIZE = 16;
    public const MIN_FRAME_SIZE = 1;
    public const MAX_FRAME_SIZE = 67108864; // 64 MiB
    public const MAX_FRAMES_PER_DIRECTION = 1024;
    public const CHUNK_SIZE = 65536; // 64 KiB transfer block

    /** @var resource|null */
    private $stream = null;
    private int $nextTx = 1;
    private int $expectedRx = 1;
    private bool $isFaulted = false;
    private bool $isClosed = false;

    /**
     * Private constructor for controlled instance allocation before wire acquisition.
     */
    private function __construct()
    {
    }

    /**
     * Connect to local Windows named pipe endpoint.
     * Direct resource owner factory allocates instance before fopen; fails closed without blind retry.
     *
     * @param string $endpointBasename Basename matching \Apos-worker-[a-f0-9]{32}\z
     * @return self
     */
    public static function connect(string $endpointBasename): self
    {
        if (PHP_SAPI !== 'cli') {
            throw new RuntimeException("ShellDeltaWorkerPipeTransportService requires CLI SAPI.");
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            throw new RuntimeException("ShellDeltaWorkerPipeTransportService requires Windows OS.");
        }
        if (PHP_INT_SIZE !== 8) {
            throw new RuntimeException("ShellDeltaWorkerPipeTransportService requires 64-bit PHP runtime.");
        }

        if (preg_match('/\Apos-worker-[a-f0-9]{32}\z/D', $endpointBasename) !== 1) {
            throw new InvalidArgumentException("Invalid endpoint basename: must match pos-worker-[a-f0-9]{32}");
        }

        $instance = new self();

        $pipePath = '\\\\.\\pipe\\' . $endpointBasename;
        $stream = @fopen($pipePath, 'r+b');
        if ($stream === false) {
            throw new RuntimeException("Failed to open named pipe endpoint: {$pipePath}");
        }

        $instance->stream = $stream;
        return $instance;
    }

    /**
     * Reads a single framed payload from the pipe.
     * Validates 16-byte ZWP1 header, enforces sequence counter and payload bounds,
     * reads exact chunked payload bytes, and increments expectedRx counter upon full completion.
     * Wire read exceptions transition transport to faulted state and rethrow primary error.
     *
     * @return array{can_apply: false, sequence: int, payload: string}
     */
    public function readFrame(): array
    {
        if ($this->isFaulted) {
            throw new RuntimeException("Cannot read frame: transport is faulted.");
        }
        if ($this->isClosed || $this->stream === null) {
            throw new RuntimeException("Cannot read frame: transport is closed.");
        }
        if ($this->expectedRx > self::MAX_FRAMES_PER_DIRECTION) {
            $this->fault();
            throw new RuntimeException("Frame limit of " . self::MAX_FRAMES_PER_DIRECTION . " frames per direction reached on Rx. Transport faulted.");
        }

        try {
            $headerBytes = $this->readExactBytes(self::HEADER_SIZE);

            $unpacked = @unpack('a4magic/Vlen/Vseq_lo/Vseq_hi', $headerBytes);
            if ($unpacked === false || !isset($unpacked['magic'], $unpacked['len'], $unpacked['seq_lo'], $unpacked['seq_hi'])) {
                throw new RuntimeException("Failed to unpack frame header.");
            }

            if ($unpacked['magic'] !== self::MAGIC) {
                throw new RuntimeException("Invalid frame header magic bytes (expected ZWP1).");
            }

            $payloadLen = $unpacked['len'];
            if ($payloadLen < self::MIN_FRAME_SIZE || $payloadLen > self::MAX_FRAME_SIZE) {
                throw new RuntimeException("Invalid frame payload length {$payloadLen} (must be between 1 and " . self::MAX_FRAME_SIZE . " bytes).");
            }

            if ($unpacked['seq_hi'] !== 0) {
                throw new RuntimeException("Invalid frame sequence high word: sequence exceeds 32-bit range.");
            }

            $seq = $unpacked['seq_lo'];
            if ($seq !== $this->expectedRx) {
                throw new RuntimeException("Invalid frame sequence: expected {$this->expectedRx}, got {$seq}.");
            }

            $payload = $this->readExactBytes($payloadLen);

            $this->expectedRx++;

            return [
                'can_apply' => false,
                'sequence' => $seq,
                'payload' => $payload,
            ];
        } catch (Throwable $t) {
            $this->fault();
            throw $t;
        }
    }

    /**
     * Writes a single framed payload to the pipe.
     * Transmits 16-byte ZWP1 header followed by payload bytes chunked in <=64 KiB blocks,
     * flushes stream at frame boundary, and increments nextTx counter upon full completion.
     * Preflight payload validation does not fault transport; wire write exceptions do.
     *
     * @param string|null $payload Opaque binary payload (1..67108864 bytes)
     */
    public function writeFrame(?string $payload): void
    {
        if ($payload === null) {
            throw new InvalidArgumentException("Payload cannot be null.");
        }
        $len = strlen($payload);
        if ($len < self::MIN_FRAME_SIZE || $len > self::MAX_FRAME_SIZE) {
            throw new InvalidArgumentException("Payload length must be between " . self::MIN_FRAME_SIZE . " and " . self::MAX_FRAME_SIZE . " bytes ({$len} given).");
        }

        if ($this->isFaulted) {
            throw new RuntimeException("Cannot write frame: transport is faulted.");
        }
        if ($this->isClosed || $this->stream === null) {
            throw new RuntimeException("Cannot write frame: transport is closed.");
        }
        if ($this->nextTx > self::MAX_FRAMES_PER_DIRECTION) {
            $this->fault();
            throw new RuntimeException("Frame limit of " . self::MAX_FRAMES_PER_DIRECTION . " frames per direction reached on Tx. Transport faulted.");
        }

        try {
            $header = pack('a4VVV', self::MAGIC, $len, $this->nextTx, 0);

            $this->writeExactBytes($header);
            $this->writeExactBytes($payload);

            if (!@fflush($this->stream)) {
                throw new RuntimeException("fflush failed after writing frame.");
            }

            $this->nextTx++;
        } catch (Throwable $t) {
            $this->fault();
            throw $t;
        }
    }

    /**
     * Reads exact byte count in chunks up to CHUNK_SIZE.
     * Controlled warning suppression and explicit exceptions on false/0/EOF.
     */
    private function readExactBytes(int $length): string
    {
        $buffer = '';
        $transferred = 0;
        while ($transferred < $length) {
            $toRead = min($length - $transferred, self::CHUNK_SIZE);
            $chunk = @fread($this->stream, $toRead);
            if ($chunk === false || strlen($chunk) === 0) {
                throw new RuntimeException("Pipe read failed or unexpected EOF (transferred {$transferred} of {$length} bytes).");
            }
            $buffer .= $chunk;
            $transferred += strlen($chunk);
        }
        return $buffer;
    }

    /**
     * Writes exact byte string in chunks up to CHUNK_SIZE.
     */
    private function writeExactBytes(string $bytes): void
    {
        $length = strlen($bytes);
        $transferred = 0;
        while ($transferred < $length) {
            $toWrite = min($length - $transferred, self::CHUNK_SIZE);
            $chunk = substr($bytes, $transferred, $toWrite);
            $n = @fwrite($this->stream, $chunk);
            if ($n === false || $n === 0) {
                throw new RuntimeException("Pipe write failed (transferred {$transferred} of {$length} bytes).");
            }
            $transferred += $n;
        }
    }

    /**
     * Checked closure of the endpoint stream.
     * Marks faulted BEFORE close attempt so that a failed or throwing fclose
     * cannot permit subsequent I/O. If fclose returns false, retains resource
     * and throws uncertainty exception requiring host job termination.
     */
    public function close(): void
    {
        if ($this->isClosed && $this->stream === null) {
            return;
        }

        $this->isFaulted = true;

        if ($this->stream === null) {
            $this->isClosed = true;
            return;
        }

        if (!is_resource($this->stream)) {
            throw new RuntimeException("Invalid or non-resource pipe stream; cannot safely close; resource retained in uncertain state; requires host job termination.");
        }

        $res = @fclose($this->stream);
        if ($res === true) {
            $this->stream = null;
            $this->isClosed = true;
            return;
        }

        throw new RuntimeException("fclose returned false on pipe stream; resource retained in uncertain state; requires host job termination.");
    }

    /**
     * Transitions endpoint into FaultCloseOnly state.
     * Aligned with Native FaultCloseOnly: ONLY marks faulted bool.
     * Does NOT silently close or drop errors. Explicit close() handles checked teardown.
     */
    private function fault(): void
    {
        $this->isFaulted = true;
    }

    public function getNextTx(): int
    {
        return $this->nextTx;
    }

    public function getExpectedRx(): int
    {
        return $this->expectedRx;
    }

    public function isFaulted(): bool
    {
        return $this->isFaulted;
    }

    public function isClosed(): bool
    {
        return $this->isClosed;
    }
}
