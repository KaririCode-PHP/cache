<?php

declare(strict_types=1);

namespace KaririCode\Cache\Exception;

use KaririCode\Contract\Exception\Runtime;

/**
 * The backend could not be reached: connection refused, authentication or
 * database selection rejected at connect time.
 *
 * ARFA 1.3 P5 (Continuous Observability): code 8610.
 * Spec V4: exception, family Runtime (§8.4); semantic factory.
 *
 * Preconditions: none.
 * Thread-safety: immutable once thrown.
 *
 * Side effects: none.
 *
 * Example:
 *     throw ConnectionException::connectionFailed('redis', $redisException);
 *
 * References: ADR-013; SPEC-001 §7, §11.
 *
 * @see https://www.php.net/manual/en/class.runtimeexception.php
 */
final class ConnectionException extends \RuntimeException implements CacheException, Runtime
{
    public const int CONNECTION_FAILED = 8610;

    /**
     * @param string $backend Adapter name ("redis"), never a host or credential.
     */
    public static function connectionFailed(string $backend, ?\Throwable $previous = null): self
    {
        return new self(message: "Could not connect to the {$backend} cache backend.", code: self::CONNECTION_FAILED, previous: $previous);
    }
}
