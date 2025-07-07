<?php

declare(strict_types=1);

namespace KaririCode\Cache\Serializer;

use KaririCode\Cache\Contract\Serializer;

/**
 * Compressing serializer wrapper.
 */
final class CompressingSerializer implements Serializer
{
    private Serializer $serializer;
    private int $compressionLevel;

    public function __construct(Serializer $serializer, int $compressionLevel = 6)
    {
        $this->serializer = $serializer;
        $this->compressionLevel = max(1, min(9, $compressionLevel));
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
}
