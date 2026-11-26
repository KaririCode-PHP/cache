<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Array\Operation;

use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Driver\Array\Storage\ArrayStorage;
use KaririCode\Cache\Service\ValidationService;

/**
 * Handles basic CRUD and batch operations for the ArrayDriver.
 *
 * This class is solely responsible for create, read, update, delete, and
 * batch operations, adhering to the Single Responsibility Principle. It interacts
 * with a central ArrayStorage object and uses the ExpirationProvider strategy
 * to manage TTLs consistently.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class ArrayCrudOperations
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
     * Retrieves an item from the cache.
     *
     * @param string $key the unique key of the item
     *
     * @return mixed the value of the item, or null if not found or expired
     */
    public function get(string $key): mixed
    {
        $this->validator->validateKey($key);

        return $this->storage->getValue($key);
    }

    /**
     * Stores an item in the cache.
     *
     * @param string $key the key under which to store the value
     * @param mixed $value the value to store
     *
     * @return bool always returns true for ArrayDriver
     */
    public function set(string $key, mixed $value): bool
    {
        $this->validator->validateKey($key);
        $this->validator->validateValue($value);

        $expiration = $this->expirationProvider->calculateExpiration(null, $key, 'set');
        $this->storage->setValue($key, $value, $expiration?->getTimestamp());

        return true;
    }

    /**
     * Checks if an item exists in the cache.
     *
     * @param string $key the key to check
     *
     * @return bool true if the item exists and has not expired
     */
    public function has(string $key): bool
    {
        $this->validator->validateKey($key);

        return $this->storage->hasKey($key);
    }

    /**
     * Deletes an item from the cache.
     *
     * @param string $key the key to delete
     *
     * @return bool always returns true
     */
    public function delete(string $key): bool
    {
        $this->validator->validateKey($key);

        return $this->storage->deleteKey($key);
    }

    /**
     * Clears the entire cache.
     *
     * @return bool always returns true
     */
    public function clear(): bool
    {
        $this->storage->clear();

        return true;
    }

    /**
     * Retrieves multiple items from the cache.
     *
     * @param iterable<string> $keys a list of keys to retrieve
     *
     * @return iterable<string, mixed> a map of keys to their values
     */
    public function getMultiple(iterable $keys): iterable
    {
        $this->validator->validateKeys($keys);
        $results = [];
        foreach ($keys as $key) {
            $results[$key] = $this->storage->getValue($key);
        }

        return $results;
    }

    /**
     * Stores multiple items in the cache.
     *
     * @param iterable<string, mixed> $values a map of keys to their values
     *
     * @return bool always returns true
     */
    public function setMultiple(iterable $values): bool
    {
        $this->validator->validateMultipleValues($values);
        $expiration = $this->expirationProvider->calculateExpiration(null, null, 'setMultiple');
        $timestamp = $expiration?->getTimestamp();

        foreach ($values as $key => $value) {
            $this->storage->setValue((string) $key, $value, $timestamp);
        }

        return true;
    }

    /**
     * Deletes multiple items from the cache.
     *
     * @param iterable<string> $keys a list of keys to delete
     *
     * @return bool always returns true
     */
    public function deleteMultiple(iterable $keys): bool
    {
        $this->validator->validateKeys($keys);
        foreach ($keys as $key) {
            $this->storage->deleteKey($key);
        }

        return true;
    }
}
