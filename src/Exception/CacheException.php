<?php

declare(strict_types=1);

namespace KaririCode\Cache\Exception;

use KaririCode\Exception\AbstractException;

/**
 * Base cache exception with enhanced static factory methods.
 */
class CacheException extends AbstractException
{
    private const CODE_OPERATION_FAILED = 1201;
    private const CODE_TAG_INVALIDATION_FAILED = 1202;
    private const CODE_SERIALIZATION_FAILED = 1203;
    private const CODE_DESERIALIZATION_FAILED = 1204;
    private const CODE_DRIVER_NOT_SUPPORTED = 1205;
    private const CODE_CONNECTION_FAILED = 1206;
    private const CODE_ITEM_NOT_FOUND = 1207;
    private const CODE_STORAGE_ERROR = 1208;
    private const CODE_INVALID_TTL = 1209;
    private const CODE_LOCK_TIMEOUT = 1210;
    private const CODE_MEMORY_LIMIT = 1211;
    private const CODE_TRANSACTION_FAILED = 1212;

    public static function operationFailed(string $operation, string $key, ?\Throwable $previous = null): static
    {
        return static::createException(
            self::CODE_OPERATION_FAILED,
            'CACHE_OPERATION_FAILED',
            "Cache operation '{$operation}' failed for key '{$key}'",
            $previous
        );
    }

    public static function tagInvalidationFailed(array $tags, ?\Throwable $previous = null): static
    {
        $tagsList = implode(', ', $tags);

        return static::createException(
            self::CODE_TAG_INVALIDATION_FAILED,
            'TAG_INVALIDATION_FAILED',
            "Failed to invalidate tags: {$tagsList}",
            $previous
        );
    }

    public static function serializationFailed(string $key, ?\Throwable $previous = null): static
    {
        return static::createException(
            self::CODE_SERIALIZATION_FAILED,
            'SERIALIZATION_FAILED',
            "Failed to serialize data for key '{$key}'",
            $previous
        );
    }

    public static function deserializationFailed(string $key, ?\Throwable $previous = null): static
    {
        return static::createException(
            self::CODE_DESERIALIZATION_FAILED,
            'DESERIALIZATION_FAILED',
            "Failed to deserialize data for key '{$key}'",
            $previous
        );
    }

    public static function driverNotSupported(string $driver): static
    {
        return static::createException(
            self::CODE_DRIVER_NOT_SUPPORTED,
            'DRIVER_NOT_SUPPORTED',
            "Cache driver '{$driver}' is not supported"
        );
    }

    public static function connectionFailed(string $backend, ?\Throwable $previous = null): static
    {
        return static::createException(
            self::CODE_CONNECTION_FAILED,
            'CONNECTION_FAILED',
            "Failed to connect to cache backend '{$backend}'",
            $previous
        );
    }

    public static function itemNotFound(string $key): static
    {
        return static::createException(
            self::CODE_ITEM_NOT_FOUND,
            'CACHE_ITEM_NOT_FOUND',
            "Cache item not found: {$key}"
        );
    }

    public static function storageError(string $details): static
    {
        return static::createException(
            self::CODE_STORAGE_ERROR,
            'CACHE_STORAGE_ERROR',
            "Cache storage error: {$details}"
        );
    }

    public static function invalidTtl(mixed $ttl): static
    {
        $type = is_object($ttl) ? get_class($ttl) : gettype($ttl);

        return static::createException(
            self::CODE_INVALID_TTL,
            'INVALID_TTL',
            "Invalid TTL value. Expected int, DateInterval or null, got {$type}"
        );
    }

    public static function lockTimeout(string $key, int $timeout): static
    {
        return static::createException(
            self::CODE_LOCK_TIMEOUT,
            'LOCK_TIMEOUT',
            "Lock timeout for key '{$key}' after {$timeout} seconds"
        );
    }

    public static function memoryLimit(int $limit, int $current): static
    {
        return static::createException(
            self::CODE_MEMORY_LIMIT,
            'MEMORY_LIMIT_EXCEEDED',
            "Memory limit exceeded: {$current} bytes (limit: {$limit} bytes)"
        );
    }

    public static function transactionFailed(string $operation, ?\Throwable $previous = null): static
    {
        return static::createException(
            self::CODE_TRANSACTION_FAILED,
            'TRANSACTION_FAILED',
            "Cache transaction failed during '{$operation}'",
            $previous
        );
    }
}
