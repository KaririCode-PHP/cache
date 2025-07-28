<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Memcached\Validation\Trait;

use KaririCode\Cache\Exception\CacheException;

/**
 * Provides reusable validation methods for results from Memcached commands.
 * This ensures type safety and consistent error handling for operations
 * that are expected to return specific types from a `mixed` result.
 */
trait MemcachedResultValidatorTrait
{
    /**
     * Validates that a Memcached operation was successful.
     *
     * @throws CacheException
     */
    private function validateResultCode(int $resultCode, string $operation, string $key = ''): void
    {
        if (\Memcached::RES_SUCCESS !== $resultCode) {
            $this->handleResultCode($resultCode, $operation, $key);
        }
    }

    /**
     * Handles non-success result codes appropriately.
     *
     * @throws CacheException
     */
    private function handleResultCode(int $resultCode, string $operation, string $key): void
    {
        // Some operations have acceptable non-success codes
        $acceptableCodes = [
            'get' => [\Memcached::RES_NOTFOUND],
            'delete' => [\Memcached::RES_NOTFOUND],
            'add' => [\Memcached::RES_NOTSTORED], // Key already exists
            'cas' => [\Memcached::RES_DATA_EXISTS], // CAS mismatch
        ];

        if (isset($acceptableCodes[$operation])
            && in_array($resultCode, $acceptableCodes[$operation], true)) {
            return;
        }

        throw CacheException::operationFailed($operation, $key, new \RuntimeException($this->getResultMessage($resultCode)));
    }

    /**
     * Gets a human-readable message for a result code.
     */
    private function getResultMessage(int $resultCode): string
    {
        $messages = [
            \Memcached::RES_SUCCESS => 'Success',
            \Memcached::RES_FAILURE => 'General failure',
            \Memcached::RES_HOST_LOOKUP_FAILURE => 'DNS lookup failed',
            \Memcached::RES_CONNECTION_FAILURE => 'Failed to connect',
            \Memcached::RES_WRITE_FAILURE => 'Failed to write',
            \Memcached::RES_READ_FAILURE => 'Failed to read',
            \Memcached::RES_PROTOCOL_ERROR => 'Protocol error',
            \Memcached::RES_CLIENT_ERROR => 'Client error',
            \Memcached::RES_SERVER_ERROR => 'Server error',
            \Memcached::RES_DATA_EXISTS => 'Data exists (CAS mismatch)',
            \Memcached::RES_DATA_DOES_NOT_EXIST => 'Data does not exist',
            \Memcached::RES_NOTSTORED => 'Not stored',
            \Memcached::RES_NOTFOUND => 'Key not found',
            \Memcached::RES_PARTIAL_READ => 'Partial read',
            \Memcached::RES_SOME_ERRORS => 'Some errors occurred',
            \Memcached::RES_NO_SERVERS => 'No servers available',
            \Memcached::RES_TIMEOUT => 'Operation timeout',
            \Memcached::RES_BUFFERED => 'Buffered',
            \Memcached::RES_BAD_KEY_PROVIDED => 'Bad key provided',
            \Memcached::RES_E2BIG => 'Value too large',
            \Memcached::RES_KEY_TOO_BIG => 'Key too large',
            \Memcached::RES_AUTH_PROBLEM => 'Authentication problem',
            \Memcached::RES_AUTH_FAILURE => 'Authentication failed',
        ];

        return $messages[$resultCode] ?? "Unknown error (code: {$resultCode})";
    }

    /**
     * Ensures the result from a Memcached operation is numeric and casts it to an integer.
     *
     * @param mixed $result the value returned from Memcached
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
            throw CacheException::operationFailed($operation, $key, new \UnexpectedValueException('Memcached command did not return a numeric value.'));
        }

        return (int) $result;
    }

    /**
     * Ensures the result is a boolean value.
     *
     * @param mixed $result the value returned from Memcached
     * @param string $operation The name of the cache operation
     * @param string $key the cache key involved
     *
     * @throws CacheException if the result is not boolean
     *
     * @return bool the validated boolean result
     */
    private function ensureBooleanResult(mixed $result, string $operation, string $key = ''): bool
    {
        if (!is_bool($result)) {
            throw CacheException::operationFailed($operation, $key, new \UnexpectedValueException('Memcached command did not return a boolean value.'));
        }

        return $result;
    }
}
