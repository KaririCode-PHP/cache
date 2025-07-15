<?php

declare(strict_types=1);

namespace KaririCode\Cache\Serializer;

use KaririCode\Cache\Contract\CacheSerializer;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Contract\ImmutableValue;

/**
 * A decorator serializer that adds GZIP compression to another serializer.
 */
final class CompressingSerializer implements CacheSerializer, ImmutableValue
{
    public function __construct(
        private readonly CacheSerializer $serializer,
        private readonly int $compressionLevel = 6,
        private readonly int $threshold = 1024
    ) {
    }

    public function serialize(mixed $data): string
    {
        $serialized = $this->serializer->serialize($data);

        // Only try to compress if the data is larger than the threshold.
        if (strlen($serialized) < $this->threshold) {
            return 'raw:' . $serialized;
        }

        $compressed = gzcompress($serialized, $this->compressionLevel);

        // Check if compression was successful and if it provided a meaningful size reduction.
        if (false !== $compressed && strlen($compressed) < strlen($serialized)) {
            return 'gz:' . $compressed;
        }

        // If compression failed or didn't help, store the raw data.
        return 'raw:' . $serialized;
    }

    public function unserialize(string $data): mixed
    {
        if (str_starts_with($data, 'gz:')) {
            $compressedData = substr($data, 3);
            $decompressed = gzuncompress($compressedData);

            // Check if decompression was successful before passing it on.
            if (false === $decompressed) {
                throw CacheException::deserializationFailed('compressing', new \RuntimeException('Failed to decompress data, it might be corrupt.'));
            }

            return $this->serializer->unserialize($decompressed);
        }

        if (str_starts_with($data, 'raw:')) {
            return $this->serializer->unserialize(substr($data, 4));
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
        return new SerializerConfiguration([
            'type' => 'compressing',
            'inner_serializer' => get_class($this->serializer),
            'compression_level' => $this->compressionLevel,
            'threshold' => $this->threshold,
        ]);
    }
}
