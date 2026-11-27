<?php

declare(strict_types=1);

namespace KaririCode\Cache\Bridge\Exception;

use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Exception\InvalidKeyException;
use KaririCode\Contract\Exception\Validation;

/**
 * PSR-6 view of an invalid key, thrown by the PSR-6 bridge.
 *
 * PSR-6 requires exceptions for illegal keys to implement its
 * InvalidArgumentException interface; the native InvalidKeyException cannot,
 * because psr-6 is optional. The bridge translates it at the boundary and
 * keeps it as the previous exception.
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): PSR types stay inside Bridge/.
 * Spec V4: exception, family Validation (§8.4); code 8652.
 *
 * Preconditions: none.
 * Thread-safety: immutable once thrown.
 *
 * Side effects: none.
 *
 * Example:
 *     throw Psr6InvalidArgumentException::fromInvalidKey($invalidKey);
 *
 * References: ADR-011; SPEC-001 §2.3, §11.
 *
 * @see https://www.php-fig.org/psr-6/
 */
final class Psr6InvalidArgumentException extends \InvalidArgumentException implements CacheException, Validation, \Psr\Cache\InvalidArgumentException
{
    public const int INVALID_KEY = 8652;

    public static function fromInvalidKey(InvalidKeyException $previous): self
    {
        return new self(message: $previous->getMessage(), code: self::INVALID_KEY, previous: $previous);
    }
}
