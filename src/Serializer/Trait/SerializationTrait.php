<?php

declare(strict_types=1);

namespace KaririCode\Cache\Serializer\Trait;

use KaririCode\Cache\Exception\CacheException;

/**
 * Provides common serialization/deserialization logic for cache drivers.
 */
trait SerializationTrait
{
    private function serialize(mixed $value): string
    {
        try {
            return serialize($value);
        } catch (\Throwable $e) {
            throw CacheException::serializationFailed(get_debug_type($value), $e);
        }
    }

    private function unserialize(mixed $data): mixed
    {
        if (!is_string($data)) {
            return $data;
        }
        try {
            // Error is suppressed as it raises a notice on failure, which we handle.
            return @unserialize($data);
        } catch (\Throwable $e) {
            throw CacheException::deserializationFailed('driver', $e);
        }
    }
}
