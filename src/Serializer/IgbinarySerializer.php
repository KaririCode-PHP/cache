<?php

declare(strict_types=1);

namespace KaririCode\Cache\Serializer;

use KaririCode\Cache\Contract\CacheSerializer;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Cache\ValueObject\SerializationScheme;
use KaririCode\Contract\ImmutableValue;

/**
 * High-performance igbinary serializer.
 */
final class IgbinarySerializer implements CacheSerializer
{
    /**
     * @throws ConfigurationException if the 'igbinary' extension is not loaded
     */
    public function __construct()
    {
        if (!extension_loaded('igbinary')) {
            throw ConfigurationException::missingExtension('igbinary');
        }
    }

    public function serialize(mixed $data): string
    {
        try {
            // Suppress the expected warning when serializing a resource.
            $result = @\igbinary_serialize($data);
            if (null === $result) {
                // This case is now correctly triggered by resources.
                throw CacheException::serializationFailed('igbinary', new \RuntimeException('igbinary_serialize returned null, possibly due to an unserializable type like a resource.'));
            }

            return $result;
        } catch (\Throwable $e) {
            throw CacheException::serializationFailed('igbinary', $e);
        }
    }

    public function unserialize(string $data): mixed
    {
        try {
            // Suppress the expected warning on failure.
            $result = @\igbinary_unserialize($data);

            if ($this->isUnserializationFailure($result, $data)) {
                throw CacheException::deserializationFailed('igbinary', new \RuntimeException('Failed to unserialize data, it might be corrupt.'));
            }

            return $result;
        } catch (\Throwable $e) {
            throw CacheException::deserializationFailed('igbinary', $e);
        }
    }

    public function canSerialize(mixed $data): bool
    {
        // Explicitly check for resources, as igbinary_serialize() does not throw for them.
        if (is_resource($data)) {
            return false;
        }

        try {
            \igbinary_serialize($data);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function getMimeType(): string
    {
        return 'application/x-igbinary';
    }

    public function getConfiguration(): ImmutableValue
    {
        return new SerializationScheme(type: 'igbinary');
    }

    /**
     * Checks if the unserialization result indicates a failure.
     *
     * @param mixed $result the result of the unserialization
     * @param string $originalData the original serialized string
     *
     * @return bool true if the result indicates a failure
     */
    private function isUnserializationFailure(mixed $result, string $originalData): bool
    {
        return null === $result && $originalData !== \igbinary_serialize(null);
    }
}
