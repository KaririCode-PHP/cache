<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Redis\Operation;

use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Driver\Redis\Component\RedisConnectionManager;
use KaririCode\Cache\Driver\Redis\Component\RedisErrorHandler;
use KaririCode\Cache\Driver\Redis\Component\RedisLuaScriptManager;
use KaririCode\Cache\Driver\Redis\Config\RedisConfig;
use KaririCode\Cache\Driver\Redis\Validation\Trait\RedisResultValidatorTrait;
use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Cache\Serializer\Trait\SerializationTrait;
use KaririCode\Cache\Service\ValidationService;

/**
 * Manages atomic operations for the RedisDriver.
 *
 * This class is solely responsible for operations like increment, add, and
 * compare-and-swap. It internally uses the ExpirationProvider to determine
 * the correct TTL for each operation and leverages Lua scripts for true
 * atomicity where needed (e.g., increment with TTL).
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class RedisAtomicOperations
{
    use SerializationTrait;
    use RedisResultValidatorTrait;

    private readonly ValidationService $validator;

    /**
     * @param \Redis $redis the Redis client instance
     * @param RedisConfig $config configuration settings
     * @param RedisErrorHandler $errorHandler error handling and retry logic
     * @param RedisConnectionManager $connectionManager connection health management
     * @param RedisLuaScriptManager $scriptManager manages Lua scripts for atomic operations
     * @param ExpirationProvider $expirationProvider TTL calculation strategy
     */
    public function __construct(
        private readonly \Redis $redis,
        private readonly RedisConfig $config,
        private readonly RedisErrorHandler $errorHandler,
        private readonly RedisConnectionManager $connectionManager,
        private readonly RedisLuaScriptManager $scriptManager,
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
        $this->validateStep($step);

        $finalExpiration = $this->expirationProvider->calculateExpiration(null, $key, 'increment');

        $result = $this->errorHandler->executeWithRetry(
            fn () => $this->performIncrement($key, $step, $initialValue, $finalExpiration),
            $this->connectionManager,
            'increment',
            $key
        );

        return $this->ensureNumericResult($result, 'increment', $key);
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

        $finalExpiration = $this->expirationProvider->calculateExpiration(null, $key, 'add');

        return (bool) $this->errorHandler->executeWithRetry(
            fn () => $this->performAdd($key, $value, $finalExpiration),
            $this->connectionManager,
            'add',
            $key
        );
    }

    /**
     * Atomically compares the current value with an expected value and swaps it if they match.
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

        $finalExpiration = $this->expirationProvider->calculateExpiration(null, $key, 'compareAndSwap');

        $result = $this->errorHandler->executeWithRetry(
            fn () => $this->performCompareAndSwap($key, $expected, $value, $finalExpiration),
            $this->connectionManager,
            'compareAndSwap',
            $key
        );

        return 1 === $this->ensureNumericResult($result, 'compareAndSwap', $key);
    }

    private function performIncrement(string $key, int $step, int $initialValue, ?\DateTimeInterface $expiration): int
    {
        $fullKey = $this->config->keyPrefix . $key;
        $ttl = $expiration ? max(1, $expiration->getTimestamp() - time()) : 0;

        if ($ttl > 0) {
            return $this->scriptManager->executeIncrementWithTtl($fullKey, $step, $initialValue, $ttl);
        }

        if (!$this->redis->exists($fullKey)) {
            $this->redis->set($fullKey, (string) $initialValue);
        }

        return $this->redis->incrBy($fullKey, $step);
    }

    private function performAdd(string $key, mixed $value, ?\DateTimeInterface $expiration): bool
    {
        $fullKey = $this->config->keyPrefix . $key;
        $serialized = $this->serialize($value);
        $options = ['nx']; // 'NX' (Not eXists) makes this an atomic "add if not exists" operation.

        if ($expiration) {
            $ttl = max(1, $expiration->getTimestamp() - time());
            $options['ex'] = $ttl;
        }

        return (bool) $this->redis->set($fullKey, $serialized, $options);
    }

    private function performCompareAndSwap(string $key, mixed $expected, mixed $value, ?\DateTimeInterface $expiration): int
    {
        $fullKey = $this->config->keyPrefix . $key;
        $serializedExpected = $this->serialize($expected);
        $serializedValue = $this->serialize($value);
        $ttl = $expiration ? max(1, $expiration->getTimestamp() - time()) : 0;

        return $this->scriptManager->executeCompareAndSwap(
            $fullKey,
            $serializedExpected,
            $serializedValue,
            $ttl
        );
    }

    /**
     * Validates that the step for increment/decrement is a positive integer.
     */
    private function validateStep(int $step): void
    {
        if ($step <= 0) {
            throw InvalidArgumentException::invalidValue('Step must be a positive integer.');
        }
    }
}
