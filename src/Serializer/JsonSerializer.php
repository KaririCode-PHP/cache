<?php

declare(strict_types=1);

namespace KaririCode\Cache\Serializer;

use KaririCode\Cache\Contract\Serializer;
use KaririCode\Cache\Exception\CacheException;

/**
 * JSON serializer for interoperability.
 */
final class JsonSerializer implements Serializer
{
    private int $flags;

    public function __construct(int $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    {
        $this->flags = $flags;
    }

    public function serialize(mixed $data): string
    {
        $result = json_encode($data, $this->flags);

        if (false === $result) {
            throw CacheException::serializationFailed('unknown', new \Exception(json_last_error_msg()));
        }

        return $result;
    }

    public function unserialize(string $data): mixed
    {
        $result = json_decode($data, true);

        if (JSON_ERROR_NONE !== json_last_error()) {
            throw CacheException::deserializationFailed('unknown', new \Exception(json_last_error_msg()));
        }

        return $result;
    }

    public function canSerialize(mixed $data): bool
    {
        try {
            json_encode($data, $this->flags);

            return JSON_ERROR_NONE === json_last_error();
        } catch (\Exception) {
            return false;
        }
    }
}
