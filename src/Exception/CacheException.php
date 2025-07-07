<?php

declare(strict_types=1);

namespace KaririCode\Cache\Exception;

/**
 * Base cache exception with enhanced static factory methods.
 */
abstract class CacheException extends \Exception
{
    protected const ERROR_CODE_BASE = 1200; // From error code range table

    protected static function createException(
        int $code,
        string $identifier,
        string $message,
        ?\Throwable $previous = null,
        array $context = []
    ): static {
        $exception = new static($message, $code, $previous);

        // Add context data if needed for debugging
        if (!empty($context)) {
            $exception->context = $context;
        }

        return $exception;
    }

    public static function operationFailed(string $operation, string $key, ?\Throwable $previous = null): static
    {
        return static::createException(
            static::ERROR_CODE_BASE + 1,
            'CACHE_OPERATION_FAILED',
            "Cache operation '{$operation}' failed for key '{$key}'",
            $previous,
            ['operation' => $operation, 'key' => $key]
        );
    }

    public static function tagInvalidationFailed(array $tags, ?\Throwable $previous = null): static
    {
        $tagsList = implode(', ', $tags);

        return static::createException(
            static::ERROR_CODE_BASE + 2,
            'TAG_INVALIDATION_FAILED',
            "Failed to invalidate tags: {$tagsList}",
            $previous,
            ['tags' => $tags]
        );
    }

    public static function serializationFailed(string $key, ?\Throwable $previous = null): static
    {
        return static::createException(
            static::ERROR_CODE_BASE + 3,
            'SERIALIZATION_FAILED',
            "Failed to serialize data for key '{$key}'",
            $previous,
            ['key' => $key]
        );
    }

    public static function deserializationFailed(string $key, ?\Throwable $previous = null): static
    {
        return static::createException(
            static::ERROR_CODE_BASE + 4,
            'DESERIALIZATION_FAILED',
            "Failed to deserialize data for key '{$key}'",
            $previous,
            ['key' => $key]
        );
    }

    public static function driverNotSupported(string $driver): static
    {
        return static::createException(
            static::ERROR_CODE_BASE + 5,
            'DRIVER_NOT_SUPPORTED',
            "Cache driver '{$driver}' is not supported",
            null,
            ['driver' => $driver]
        );
    }

    public static function connectionFailed(string $backend, ?\Throwable $previous = null): static
    {
        return static::createException(
            static::ERROR_CODE_BASE + 6,
            'CONNECTION_FAILED',
            "Failed to connect to cache backend '{$backend}'",
            $previous,
            ['backend' => $backend]
        );
    }
}
