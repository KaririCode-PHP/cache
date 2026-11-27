<?php

declare(strict_types=1);

namespace KaririCode\Cache\Bridge\Exception;

use KaririCode\Cache\Exception\CacheException;
use KaririCode\Contract\Exception\Runtime;

/**
 * PSR-16 view of a cache failure, thrown by the PSR-16 bridge.
 *
 * PSR-16 requires the exceptions of an implementing library to implement
 * its CacheException interface. The bridge translates native failures at
 * the boundary; the original stays as the previous exception and native
 * catch blocks on CacheException keep working.
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): PSR types stay inside Bridge/.
 * Spec V4: exception, family Runtime (§8.4); code 8651.
 *
 * Preconditions: none.
 * Thread-safety: immutable once thrown.
 *
 * Side effects: none.
 *
 * Example:
 *     throw Psr16CacheException::fromCacheFailure($backendException);
 *
 * References: ADR-011; SPEC-001 §2.3, §11.
 *
 * @see https://www.php-fig.org/psr-16/
 */
final class Psr16CacheException extends \RuntimeException implements CacheException, Runtime, \Psr\SimpleCache\CacheException
{
    public const int BACKEND_FAILURE = 8651;

    public static function fromCacheFailure(CacheException $previous): self
    {
        return new self(message: $previous->getMessage(), code: self::BACKEND_FAILURE, previous: $previous);
    }
}
