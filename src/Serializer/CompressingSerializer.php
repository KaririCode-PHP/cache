<?php

declare(strict_types=1);

namespace KaririCode\Cache\Serializer;

use KaririCode\Cache\Contract\CacheSerializer;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\ValueObject\SerializationScheme;
use KaririCode\Contract\ImmutableValue;

/**
 * A decorator serializer that adds GZIP compression to another serializer.
 */
final class CompressingSerializer implements CacheSerializer
{
    private const PREFIX_COMPRESSED = 'gz:';
    private const PREFIX_RAW = 'raw:';

    /**
     * @param CacheSerializer $serializer the inner serializer to wrap
     * @param int $compressionLevel the GZIP compression level (0-9)
     * @param int $threshold the minimum size in bytes to attempt compression
     */
    public function __construct(
        private readonly CacheSerializer $serializer,
        private readonly int $compressionLevel = 6,
        private readonly int $threshold = 1024
    ) {
    }

    public function serialize(mixed $data): string
    {
        $serialized = $this->serializer->serialize($data);

        if (!$this->shouldCompress($serialized)) {
            return self::PREFIX_RAW . $serialized;
        }

        $compressed = gzcompress($serialized, $this->compressionLevel);

        if ($this->wasCompressionEffective($compressed, $serialized)) {
            return self::PREFIX_COMPRESSED . $compressed;
        }

        return self::PREFIX_RAW . $serialized;
    }

    public function unserialize(string $data): mixed
    {
        if ($this->isCompressed($data)) {
            $compressedData = substr($data, strlen(self::PREFIX_COMPRESSED));
            // Suppress the expected warning when data is corrupt.
            $decompressed = @gzuncompress($compressedData);

            if (false === $decompressed) {
                throw CacheException::deserializationFailed('compressing', new \RuntimeException('Failed to decompress data, it might be corrupt.'));
            }

            return $this->serializer->unserialize($decompressed);
        }

        if ($this->isRaw($data)) {
            return $this->serializer->unserialize(substr($data, strlen(self::PREFIX_RAW)));
        }

        // For backward compatibility, assume old data might not have a prefix.
        return $this->serializer->unserialize($data);
    }

    public function canSerialize(mixed $data): bool
    {
        return $this->serializer->canSerialize($data);
    }

    public function getMimeType(): string
    {
        return 'application/x-php-compressed';
    }

    public function getConfiguration(): ImmutableValue
    {
        return new SerializationScheme(
            type: 'compressing',
            innerSerializer: get_class($this->serializer),
            compressionLevel: $this->compressionLevel,
            threshold: $this->threshold
        );
    }

    /**
     * Determines if the data is large enough to be worth compressing.
     */
    private function shouldCompress(string $data): bool
    {
        return strlen($data) >= $this->threshold;
    }

    /**
     * Checks if compression was successful and resulted in a smaller size.
     */
    private function wasCompressionEffective(string|false $compressed, string $original): bool
    {
        return false !== $compressed && strlen($compressed) < strlen($original);
    }

    /**
     * Checks if the data is prefixed as compressed.
     */
    private function isCompressed(string $data): bool
    {
        return str_starts_with($data, self::PREFIX_COMPRESSED);
    }

    /**
     * Checks if the data is prefixed as raw (uncompressed).
     */
    private function isRaw(string $data): bool
    {
        return str_starts_with($data, self::PREFIX_RAW);
    }
}
