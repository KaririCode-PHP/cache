<?php

declare(strict_types=1);

namespace KaririCode\Cache\Exception;

use KaririCode\Contract\Exception\NotFound;

/**
 * CacheRegistry::store() was asked for a store that is not registered.
 * Check with CacheRegistry::has() first when absence is expected.
 *
 * ARFA 1.3 P5 (Continuous Observability): code 8620.
 * Spec V4: exception, family NotFound (§8.4); the store name is not echoed (E-047).
 *
 * Preconditions: none.
 * Thread-safety: immutable once thrown.
 *
 * Side effects: none.
 *
 * Example:
 *     $registry->has('sessions') ? $registry->store('sessions') : $registry->store();
 *
 * References: ADR-013; SPEC-001 §8, §11.
 *
 * @see https://www.php.net/manual/en/class.outofboundsexception.php
 */
final class StoreNotFoundException extends \OutOfBoundsException implements CacheException, NotFound
{
    public const int STORE_NOT_REGISTERED = 8620;

    public static function storeNotRegistered(): self
    {
        return new self(message: 'The requested cache store is not registered.', code: self::STORE_NOT_REGISTERED);
    }
}
