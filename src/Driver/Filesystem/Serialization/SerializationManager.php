<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Filesystem\Serialization;

use KaririCode\Cache\Contract\Filesystem\SerializationManager as SerializationManagerContract;
use KaririCode\Cache\Exception\CacheException;
use Psr\Log\LoggerInterface;

/**
 * Serialization manager with compression support.
 * Follows SRP - responsible ONLY for data serialization/deserialization.
 */
final class SerializationManager implements SerializationManagerContract
{
    private const COMPRESSION_THRESHOLD = 1024; // 1KB
    private const COMPRESSION_LEVEL = 6;
    private const COMPRESSION_RATIO_THRESHOLD = 0.9;

    public function __construct(
        private bool $useCompression = true,
        private LoggerInterface $logger
    ) {
    }

    public function serialize(mixed $data): string
    {
        try {
            $serialized = serialize($data);

            if ($this->shouldCompress($serialized)) {
                return $this->compressData($serialized);
            }

            return 'raw:' . $serialized;
        } catch (\Throwable $e) {
            throw CacheException::serializationFailed(get_debug_type($data), $e);
        }
    }

    public function unserialize(string $data): mixed
    {
        try {
            if (str_starts_with($data, 'gz:')) {
                return $this->unserializeCompressed($data);
            }

            if (str_starts_with($data, 'raw:')) {
                return unserialize(substr($data, 4));
            }

            // Legacy data without prefix
            return unserialize($data);
        } catch (\Throwable $e) {
            throw CacheException::deserializationFailed('filesystem', $e);
        }
    }

    public function canSerialize(mixed $data): bool
    {
        try {
            serialize($data);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function shouldCompress(string $serialized): bool
    {
        return $this->useCompression && strlen($serialized) > self::COMPRESSION_THRESHOLD;
    }

    private function compressData(string $serialized): string
    {
        $compressed = gzcompress($serialized, self::COMPRESSION_LEVEL);

        if (false === $compressed) {
            $this->logger->warning('Compression failed, falling back to raw data');

            return 'raw:' . $serialized;
        }

        // Only use compression if it provides significant benefit
        if (strlen($compressed) < strlen($serialized) * self::COMPRESSION_RATIO_THRESHOLD) {
            return 'gz:' . $compressed;
        }

        return 'raw:' . $serialized;
    }

    private function unserializeCompressed(string $data): mixed
    {
        $compressed = substr($data, 3);
        $decompressed = gzuncompress($compressed);

        if (false === $decompressed) {
            throw new \RuntimeException('Decompression failed');
        }

        return unserialize($decompressed);
    }
}
