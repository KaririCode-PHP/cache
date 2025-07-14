<?php

declare(strict_types=1);

namespace KaririCode\Cache\Serializer;

use KaririCode\Cache\Contract\Serializer;
use KaririCode\Contract\Cache\CacheSerializer;
use KaririCode\Contract\ImmutableValue;

/**
 * Compressing serializer wrapper.
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

        // Only compress if it's worth it (saves at least 20 bytes and 10% size)
        $compressed = gzcompress($serialized, $this->compressionLevel);

        if (strlen($compressed) < strlen($serialized) - 20
            && strlen($compressed) < strlen($serialized) * 0.9) {
            return 'gz:' . $compressed;
        }

        return 'raw:' . $serialized;
    }

    public function unserialize(string $data): mixed
    {
        if (str_starts_with($data, 'gz:')) {
            $decompressed = gzuncompress(substr($data, 3));

            return $this->serializer->unserialize($decompressed);
        }

        if (str_starts_with($data, 'raw:')) {
            return $this->serializer->unserialize(substr($data, 4));
        }

        // Backward compatibility - assume it's raw data
        return $this->serializer->unserialize($data);
    }

    public function canSerialize(mixed $data): bool
    {
        return $this->serializer->canSerialize($data);
    }

    public function getMimeType(): string
    {
        return 'application/x-compressed';
    }

    public function getConfiguration(): ImmutableValue
    {
        return new SerializerConfiguration([
            'type' => 'compressing',
            'inner_serializer' => $this->innerSerializer->getConfiguration(),
            'compression_level' => $this->compressionLevel,
            'threshold' => $this->threshold,
        ]);
    }
}
