<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/** Bounded copy/literal patches addressed against a single signed source file. */
final class ShellBinaryPatchService
{
    private const BLOCK = 16384;
    private const SEARCH_RADIUS = 524288;
    private const MAX_OPERATIONS = 65536;

    /**
     * Encode blocks against the previous source offset, with a bounded search for insertions and deletions.
     * Returns false if the raw patch reaches the already-compressed whole-file size.
     */
    public function create($source, $target, $patch, int $sourceSize, int $targetSize, int $budget): bool
    {
        $window = '';
        $windowStart = 0;
        $shift = 0;
        $copied = false;
        for ($offset = 0; $offset < $targetSize; $offset += self::BLOCK) {
            $length = min(self::BLOCK, $targetSize - $offset);
            $block = self::readExact($target, $length);
            $predicted = $offset + $shift;
            $match = false;

            if ($predicted >= -self::SEARCH_RADIUS && $predicted < $sourceSize + self::SEARCH_RADIUS) {
                $center = max(0, min($predicted, $sourceSize));
                $windowEnd = $windowStart + strlen($window);
                $margin = intdiv(self::SEARCH_RADIUS, 2);
                $nearStart = $windowStart > 0 && $center < $windowStart + $margin;
                $nearEnd = $windowEnd < $sourceSize && $center + $length + $margin > $windowEnd;
                if ($window === '' || $nearStart || $nearEnd) {
                    $windowStart = max(0, $center - self::SEARCH_RADIUS);
                    $windowLength = min($sourceSize - $windowStart, 2 * self::SEARCH_RADIUS + self::BLOCK);
                    if (fseek($source, $windowStart) !== 0) {
                        throw new RuntimeException('Cannot seek to the binary patch source window.');
                    }
                    $window = self::readExact($source, $windowLength);
                }

                $position = $predicted - $windowStart;
                if ($position >= 0 && $position <= strlen($window) - $length
                    && substr_compare($window, $block, $position, $length) === 0) {
                    $match = $predicted;
                } elseif ($length >= 64) {
                    // PHP performs the anchor search in native code. Full block comparison rejects false matches.
                    $anchor = substr($block, 0, 64);
                    $position = 0;
                    for ($attempt = 0; $attempt < 8; $attempt++) {
                        $found = strpos($window, $anchor, $position);
                        if ($found === false || $found > strlen($window) - $length) {
                            break;
                        }
                        if (substr_compare($window, $block, $found, $length) === 0) {
                            $match = $windowStart + $found;
                            $shift = $match - $offset;
                            break;
                        }
                        $position = $found + 1;
                    }
                }
            }

            if ($match === false) {
                $command = 'L' . pack('N', $length) . $block;
            } else {
                $copied = true;
                $command = 'C' . pack('NN', $match, $length);
            }
            $budget -= strlen($command);
            if ($budget <= 0) {
                return false;
            }
            self::write($patch, $command);
        }
        return $copied && fflush($patch);
    }

    /** Verify the patch's base before using the opened file as the source for any copy command. */
    public function apply($source, $patch, int $patchSize, $target, array $sourceMeta, array $targetMeta): void
    {
        $sourceSize = $sourceMeta['size'];
        $targetSize = $targetMeta['size'];
        if ($sourceSize < 1 || $sourceSize > ShellDeltaManifestService::MAX_SINGLE_FILE_SIZE
            || $patchSize < 1 || $patchSize > ShellDeltaManifestService::MAX_SINGLE_FILE_SIZE) {
            throw new RuntimeException('Binary patch exceeds size limits.');
        }
        $hash = hash_init('sha256');
        if ((fstat($source)['size'] ?? null) !== $sourceSize
            || hash_update_stream($hash, $source, $sourceSize) !== $sourceSize
            || !hash_equals($sourceMeta['sha256'], hash_final($hash))) {
            throw new RuntimeException('Installed binary patch source does not match its signed inventory.');
        }

        $remaining = $targetSize;
        $operations = 0;
        $copied = false;
        while ($patchSize > 0) {
            if (++$operations > self::MAX_OPERATIONS || $patchSize < 5) {
                throw new RuntimeException('Invalid binary patch operation count or header.');
            }
            $tag = self::readExact($patch, 1);
            $copy = $tag === 'C';
            $headerSize = $copy ? 8 : 4;
            if (($tag !== 'L' && !$copy) || $patchSize < $headerSize + 1) {
                throw new RuntimeException('Unsupported or incomplete binary patch command.');
            }
            $command = unpack($copy ? 'Noffset/Nlength' : 'Nlength', self::readExact($patch, $headerSize));
            $patchSize -= 1 + $headerSize;
            $length = $command['length'];
            if ($length < 1 || $length > self::BLOCK || $length > $remaining) {
                throw new RuntimeException('Binary patch writes beyond its allowed output.');
            }
            if ($copy) {
                $offset = $command['offset'];
                if ($offset > $sourceSize - $length || fseek($source, $offset) !== 0) {
                    throw new RuntimeException('Binary patch copies outside its signed source.');
                }
                $copied = true;
                $data = self::readExact($source, $length);
            } else {
                if ($length > $patchSize) {
                    throw new RuntimeException('Binary patch literal is truncated.');
                }
                $data = self::readExact($patch, $length);
                $patchSize -= $length;
            }
            self::write($target, $data);
            $remaining -= $length;
        }
        if (!$copied || $remaining !== 0 || fread($patch, 1) !== '') {
            throw new RuntimeException('Binary patch does not produce its complete target.');
        }
    }

    public static function readExact($stream, int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $part = fread($stream, $length - strlen($data));
            if ($part === false || $part === '') {
                throw new RuntimeException('Cannot read exact binary transport bytes.');
            }
            $data .= $part;
        }
        return $data;
    }

    public static function write($stream, string $data): void
    {
        if (fwrite($stream, $data) !== strlen($data)) {
            throw new RuntimeException('Cannot write binary transport bytes.');
        }
    }
}
