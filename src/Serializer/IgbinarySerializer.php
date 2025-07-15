<?php

declare(strict_types=1);

namespace KaririCode\Cache\Serializer;

use KaririCode\Cache\Contract\CacheSerializer;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Contract\ImmutableValue;

/**
 * High-performance igbinary serializer.
 */
final class IgbinarySerializer implements CacheSerializer, ImmutableValue
{
    public function __construct()
    {
        if (!extension_loaded('igbinary')) {
            throw new \RuntimeException('The igbinary PHP extension is not loaded.');
        }
    }

    public function serialize(mixed $data): string
    {
        $result = \igbinary_serialize($data);
        if (null === $result) {
            throw CacheException::serializationFailed('igbinary');
        }

        return $result;
    }

    public function unserialize(string $data): mixed
    {
        $result = \igbinary_unserialize($data);
        if (null === $result && $data !== \igbinary_serialize(null)) {
            throw CacheException::deserializationFailed('igbinary');
        }

        return $result;
    }

    public function canSerialize(mixed $data): bool
    {
        try {
            \igbinary_serialize($data);

            return true;
        } catch (\Exception) {
            return false;
        }
    }

    public function getMimeType(): string
    {
        return 'application/x-igbinary';
    }

    public function getConfiguration(): ImmutableValue
    {
        return new SerializerConfiguration([
            'type' => 'igbinary',
        ]);
    }
}
