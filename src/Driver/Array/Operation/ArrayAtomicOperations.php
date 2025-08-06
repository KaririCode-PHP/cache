<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Array\Operation;

use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Driver\Array\Storage\ArrayStorage;
use KaririCode\Cache\Service\ValidationService;

/**
 * Handles atomic operations for the ArrayDriver.
 *
 * This class is solely responsible for atomic operations like increment,
 * decrement, add, and compare-and-swap. It interacts with a central
 * ArrayStorage object and uses the ExpirationProvider strategy to manage TTLs.
 * Note: True atomicity is not guaranteed in a multi-threaded environment;
 * this emulation is suitable for single-process scenarios.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class ArrayAtomicOperations
{
    private readonly ValidationService $validator;

    /**
     * @param ArrayStorage $storage the central in-memory storage
     * @param ExpirationProvider $expirationProvider the strategy for TTL management
     */
    public function __construct(
        private readonly ArrayStorage $storage,
        private readonly ExpirationProvider $expirationProvider
    ) {
        $this->validator = new ValidationService();
    }

    /**
     * Atomically increments a value.
     *
     * @param string $key the key of the item
     * @param int $step the amount to increment by
     * @param int $initialValue the value to set if the key does not exist
     *
     * @return int the new value after incrementing
     */
    public function increment(string $key, int $step = 1, int $initialValue = 0): int
    {
        $this->validator->validateKey($key);

        $currentValue = $this->storage->getValue($key);
        $newValue = (is_numeric($currentValue) ? (int) $currentValue : $initialValue) + $step;

        $expiration = $this->expirationProvider->calculateExpiration(null, $key, 'increment');
        $this->storage->setValue($key, $newValue, $expiration?->getTimestamp());

        return $newValue;
    }

    /**
     * Atomically decrements a value.
     *
     * @param string $key the key of the item
     * @param int $step the amount to decrement by
     * @param int $initialValue the value to set if the key does not exist
     *
     * @return int the new value after decrementing
     */
    public function decrement(string $key, int $step = 1, int $initialValue = 0): int
    {
        // Decrement is just an increment with a negative step.
        return $this->increment($key, -$step, $initialValue);
    }

    /**
     * Adds an item to the cache only if it does not already exist.
     *
     * @param string $key the key of the item
     * @param mixed $value the value to store
     *
     * @return bool true if the item was added, false if it already existed
     */
    public function add(string $key, mixed $value): bool
    {
        $this->validator->validateKey($key);
        $this->validator->validateValue($value);

        if ($this->storage->hasKey($key)) {
            return false;
        }

        $expiration = $this->expirationProvider->calculateExpiration(null, $key, 'add');
        $this->storage->setValue($key, $value, $expiration?->getTimestamp());

        return true;
    }

    /**
     * Compares the current value with an expected value and swaps it if they match.
     *
     * @param string $key the key of the item
     * @param mixed $expected the expected value
     * @param mixed $value the new value to store
     *
     * @return bool true if the value was swapped, false otherwise
     */
    public function compareAndSwap(string $key, mixed $expected, mixed $value): bool
    {
        $this->validator->validateKey($key);
        $this->validator->validateValue($expected);
        $this->validator->validateValue($value);

        // This is a non-atomic emulation for the array driver.
        $currentValue = $this->storage->getValue($key);

        if ($currentValue !== $expected) {
            return false;
        }

        $expiration = $this->expirationProvider->calculateExpiration(null, $key, 'compareAndSwap');
        $this->storage->setValue($key, $value, $expiration?->getTimestamp());

        return true;
    }
}
