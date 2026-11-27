<?php

declare(strict_types=1);

namespace KaririCode\Cache\Bridge\Exception;

use KaririCode\Cache\Exception\CacheException;
use KaririCode\Contract\Exception\Runtime;

/**
 * PSR-6 view of a cache failure, thrown by the PSR-6 bridge.
 *
 * PSR-6 requires the exceptions of an implementing library to implement
 * its CacheException interface. The bridge translates native failures at
 * the boundary; the original stays as the previous exception and native
 * catch blocks on CacheException keep working.
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): PSR types stay inside Bridge/.
 * Spec V4: exception, family Runtime (§8.4); code 8653.
 *
 * Preconditions: none.
 * Thread-safety: immutable once thrown.
 *
 * Side effects: none.
 *
 * Example:
 *     throw Psr6CacheException::fromCacheFailure($backendException);
 *
 * References: ADR-011; SPEC-001 §2.3, §11.
 *
 * @see https://www.php-fig.org/psr-6/
 */
final class Psr6CacheException extends \RuntimeException implements CacheException, Runtime, \Psr\Cache\CacheException
{
    public const int BACKEND_FAILURE = 8653;

    public static function fromCacheFailure(CacheException $previous): self
    {
        return new self(message: $previous->getMessage(), code: self::BACKEND_FAILURE, previous: $previous);
    }
}
