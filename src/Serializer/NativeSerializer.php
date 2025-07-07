<?php

declare(strict_types=1);

namespace KaririCode\Cache\Serializer;

use KaririCode\Cache\Contract\Serializer;
use KaririCode\Cache\Exception\CacheException;

/**
 * Native PHP serializer with enhanced error handling.
 */
final class NativeSerializer implements Serializer
{
    public function serialize(mixed $data): string
    {
        try {
            return serialize($data);
        } catch (\Exception $e) {
            throw CacheException::serializationFailed('unknown', $e);
        }
    }

    public function unserialize(string $data): mixed
    {
        try {
            $result = unserialize($data);
            if (false === $result && $data !== serialize(false)) {
                throw new \Exception('Unserialization returned false');
            }

            return $result;
        } catch (\Exception $e) {
            throw CacheException::deserializationFailed('unknown', $e);
        }
    }

    public function canSerialize(mixed $data): bool
    {
        try {
            serialize($data);

            return true;
        } catch (\Exception) {
            return false;
        }
    }
}
