<?php

declare(strict_types=1);

namespace KaririCode\Cache\Serializer;

use KaririCode\Cache\Contract\CacheSerializer;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\ValueObject\SerializationScheme;
use KaririCode\Contract\ImmutableValue;

/**
 * JSON serializer for interoperability.
 */
final class JsonSerializer implements CacheSerializer
{
    /**
     * @param int $flags bitmask of JSON encode options
     * @param int $depth maximum depth for encoding/decoding
     */
    public function __construct(
        private readonly int $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        private readonly int $depth = 512
    ) {
    }

    public function serialize(mixed $data): string
    {
        try {
            $result = json_encode($data, $this->flags, $this->depth);

            if (JSON_ERROR_NONE !== json_last_error()) {
                throw new \Exception(json_last_error_msg());
            }

            return $result;
        } catch (\Throwable $e) {
            throw CacheException::serializationFailed('json', $e);
        }
    }

    public function unserialize(string $data): mixed
    {
        try {
            $result = json_decode($data, true, $this->depth);

            if (JSON_ERROR_NONE !== json_last_error()) {
                throw new \Exception(json_last_error_msg());
            }

            return $result;
        } catch (\Throwable $e) {
            throw CacheException::deserializationFailed('json', $e);
        }
    }

    public function canSerialize(mixed $data): bool
    {
        try {
            json_encode($data, $this->flags, $this->depth);

            return JSON_ERROR_NONE === json_last_error();
        } catch (\Throwable) {
            return false;
        }
    }

    public function getMimeType(): string
    {
        return 'application/json';
    }

    public function getConfiguration(): ImmutableValue
    {
        return new SerializationScheme(
            type: 'json',
            flags: $this->flags,
            depth: $this->depth
        );
    }
}
