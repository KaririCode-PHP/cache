<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Redis\Validation\Trait;

use KaririCode\Cache\Exception\CacheException;

/**
 * Provides reusable validation methods for results from Redis commands.
 * This ensures type safety and consistent error handling for operations
 * that are expected to return specific types from a `mixed` result.
 */
trait RedisResultValidatorTrait
{
    /**
     * Ensures the result from a Redis operation is numeric and casts it to an integer.
     *
     * @param mixed $result the value returned from Redis
     * @param string $operation The name of the cache operation (e.g., 'increment').
     * @param string $key the cache key involved, for context in error messages
     *
     * @throws CacheException if the result is not numeric
     *
     * @return int the validated and casted integer result
     */
    private function ensureNumericResult(mixed $result, string $operation, string $key = ''): int
    {
        if (!is_numeric($result)) {
            // Throws a consistent, framework-level exception.
            throw CacheException::operationFailed($operation, $key, new \UnexpectedValueException('Redis command did not return a numeric value.'));
        }

        return (int) $result;
    }
}
