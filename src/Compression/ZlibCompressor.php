<?php

declare(strict_types=1);

namespace KaririCode\Cache\Compression;

use KaririCode\Cache\Contract\Compressor;
use KaririCode\Cache\Exception\BackendException;
use KaririCode\Cache\Exception\ConfigurationException;

/**
 * gzip compressor with a size threshold.
 *
 * Payloads shorter than the threshold, or that do not shrink, are stored
 * raw. A one-byte prefix marks the encoding: 0x01 compressed, 0x00 raw.
 * Payloads with any other first byte are returned unchanged, so entries
 * written before compression was enabled stay readable (native
 * serialize() output never starts with 0x00 or 0x01).
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): transparent to adapters and values.
 * Spec V4: stateless service; options validated at construction.
 *
 * Preconditions: ext-zlib; threshold >= 0; level -1..9.
 * Thread-safety: stateless; safe to share.
 *
 * Side effects: none.
 *
 * Example:
 *     $codec = new PayloadCodec(compressor: new ZlibCompressor(threshold: 2048, level: 6));
 *
 * References: ADR-005; SPEC-001 §7.
 *
 * @see https://www.php.net/manual/en/function.gzencode.php
 */
final readonly class ZlibCompressor implements Compressor
{
    private const string PREFIX_RAW = "\x00";
    private const string PREFIX_COMPRESSED = "\x01";

    /**
     * @param int $threshold Minimum payload size in bytes to try compression.
     * @param int $level     zlib level: -1 (library default) or 0..9.
     */
    public function __construct(
        private int $threshold = 1024,
        private int $level = -1,
    ) {
        if (!function_exists('gzencode')) {
            throw ConfigurationException::extensionMissing('zlib');
        }

        if ($threshold < 0) {
            throw ConfigurationException::invalidOption(option: 'threshold', constraint: 'must be >= 0');
        }

        if ($level < -1 || $level > 9) {
            throw ConfigurationException::invalidOption(option: 'level', constraint: 'must be -1 or between 0 and 9');
        }
    }

    #[\Override]
    public function compress(string $data): string
    {
        if (strlen($data) < $this->threshold) {
            return self::PREFIX_RAW . $data;
        }

        $compressed = gzencode($data, $this->level);

        if (false === $compressed || strlen($compressed) >= strlen($data)) {
            return self::PREFIX_RAW . $data;
        }

        return self::PREFIX_COMPRESSED . $compressed;
    }

    #[\Override]
    public function decompress(string $data): string
    {
        if ('' === $data) {
            return '';
        }

        return match ($data[0]) {
            self::PREFIX_RAW => substr($data, 1),
            self::PREFIX_COMPRESSED => $this->inflate(substr($data, 1)),
            default => $data,
        };
    }

    #[\Override]
    public function isAvailable(): bool
    {
        return function_exists('gzencode');
    }

    private function inflate(string $payload): string
    {
        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $result = gzdecode($payload);
        } finally {
            restore_error_handler();
        }

        if (false === $result) {
            throw BackendException::decompressionFailed($warning);
        }

        return $result;
    }
}
