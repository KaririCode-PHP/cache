<?php

declare(strict_types=1);

namespace KaririCode\Cache\Exception;

use KaririCode\Contract\Exception\Validation;

/**
 * A cache key violates the key rules.
 *
 * Native rule: non-empty and none of {}()/\@. The colon is allowed natively
 * because it is the separator of withScope() and of Redis key conventions.
 * Strict mode applies the full PSR-6/PSR-16 reserved set {}()/\@: and is
 * used by the bridges. Messages state the rule, never the key (E-047).
 *
 * ARFA 1.3 P5 (Continuous Observability): codes 8630-8632.
 * Spec V4: exception, family Validation (§8.4); semantic factories.
 *
 * Preconditions: none.
 * Thread-safety: stateless; validate() is a pure guard.
 *
 * Side effects: none.
 *
 * Example:
 *     InvalidKeyException::validate($key, psrStrict: true);
 *
 * References: ADR-011; SPEC-001 §6, §11.
 *
 * @see https://www.php-fig.org/psr/psr-16/#12-definitions
 */
final class InvalidKeyException extends \InvalidArgumentException implements CacheException, Validation
{
    public const int EMPTY_KEY = 8630;
    public const int RESERVED_CHARACTERS = 8631;
    public const int RESERVED_PREFIX = 8632;

    private const string NATIVE_RESERVED = '{}()/\@';
    private const string PSR_RESERVED = '{}()/\@:';

    public static function empty(): self
    {
        return new self(message: 'Cache key must not be empty.', code: self::EMPTY_KEY);
    }

    public static function reservedCharacters(string $reserved): self
    {
        return new self(message: "Cache key contains a reserved character; reserved: {$reserved}", code: self::RESERVED_CHARACTERS);
    }

    public static function reservedPrefix(string $prefix): self
    {
        return new self(message: "Cache key starts with the reserved internal prefix {$prefix}.", code: self::RESERVED_PREFIX);
    }

    /**
     * Throw when $key is not a legal key.
     *
     * @param bool $psrStrict Also reject ':' (PSR-6/PSR-16 reserved set).
     */
    public static function validate(string $key, bool $psrStrict = false): void
    {
        if ('' === $key) {
            throw self::empty();
        }

        $reserved = $psrStrict ? self::PSR_RESERVED : self::NATIVE_RESERVED;

        if (strpbrk($key, $reserved) !== false) {
            throw self::reservedCharacters($reserved);
        }
    }
}
