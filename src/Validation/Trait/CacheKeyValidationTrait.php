<?php

declare(strict_types=1);

namespace KaririCode\Cache\Validation\Trait;

use KaririCode\Cache\Exception\InvalidArgumentException;

/**
 * Provides cache key validation functionality.
 *
 * This trait implements the Single Responsibility Principle by focusing
 * solely on cache key validation logic. It can be reused across multiple
 * classes that need key validation, following DRY principles.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
trait CacheKeyValidationTrait
{
    private const MAX_KEY_LENGTH = 250;
    private const VALID_KEY_PATTERN = '/^[a-zA-Z0-9_.:-]+$/';
    private const RESERVED_KEYS = ['', 'null', 'true', 'false'];
    private const MAX_BATCH_SIZE = 1000;

    /**
     * Validates a cache key according to defined rules.
     *
     * Implements fail-fast principle by throwing exception immediately
     * upon detecting invalid input.
     *
     * @param string $key The key to validate
     *
     * @throws InvalidArgumentException if key is invalid
     */
    protected function validateKey(string $key): void
    {
        if ('' === $key) {
            throw InvalidArgumentException::emptyKey();
        }

        if (strlen($key) > self::MAX_KEY_LENGTH) {
            throw InvalidArgumentException::keyTooLong($key, self::MAX_KEY_LENGTH);
        }

        if (!preg_match(self::VALID_KEY_PATTERN, $key)) {
            throw InvalidArgumentException::invalidKeyFormat($key);
        }

        if (in_array($key, self::RESERVED_KEYS, true)) {
            throw InvalidArgumentException::reservedKey($key);
        }
    }

    /**
     * Validates an array of cache keys.
     *
     * @param array<string> $keys Array of keys to validate
     *
     * @throws InvalidArgumentException if any key is invalid
     */
    protected function validateKeys(array $keys): void
    {
        if (count($keys) > self::MAX_BATCH_SIZE) {
            throw InvalidArgumentException::invalidValue('Too many keys in batch: ' . count($keys) . ' (max: ' . self::MAX_BATCH_SIZE . ')');
        }

        foreach ($keys as $key) {
            if (!is_string($key)) {
                throw InvalidArgumentException::invalidKeyFormat('Key must be a string, ' . gettype($key) . ' given');
            }
            $this->validateKey($key);
        }
    }
}
