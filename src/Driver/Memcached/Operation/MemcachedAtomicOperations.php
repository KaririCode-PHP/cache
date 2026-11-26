<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Memcached\Operation;

use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Driver\Memcached\Component\MemcachedConnectionManager;
use KaririCode\Cache\Driver\Memcached\Component\MemcachedErrorHandler;
use KaririCode\Cache\Driver\Memcached\Component\MemcachedMonitoringService;
use KaririCode\Cache\Driver\Memcached\Config\MemcachedConfig;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Cache\Service\ValidationService;

/**
 * Manages atomic operations for the MemcachedDriver.
 *
 * This class is solely responsible for operations like increment, decrement, add,
 * and compare-and-swap. It internally uses the ExpirationProvider to determine
 * the correct TTL for each operation, adhering to SRP and the Strategy Pattern.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class MemcachedAtomicOperations
{
    private readonly ValidationService $validator;

    /**
     * @param \Memcached $memcached the Memcached client instance
     * @param MemcachedConfig $config configuration settings
     * @param MemcachedErrorHandler $errorHandler error handling and retry logic
     * @param MemcachedConnectionManager $connectionManager connection health management
     * @param MemcachedMonitoringService $monitoringService performance monitoring
     * @param ExpirationProvider $expirationProvider TTL calculation strategy
     */
    public function __construct(
        private readonly \Memcached $memcached,
        private readonly MemcachedConfig $config,
        private readonly MemcachedErrorHandler $errorHandler,
        private readonly MemcachedConnectionManager $connectionManager,
        private readonly MemcachedMonitoringService $monitoringService,
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
        $this->validator->validateKey($key);
        $this->validateStep($step);

        $finalExpiration = $this->expirationProvider->calculateExpiration(null, $key, 'decrement');

        $result = $this->errorHandler->executeWithRetry(
            fn () => $this->performDecrement($key, $step, $initialValue, $finalExpiration),
            $this->connectionManager,
            'decrement',
            $key
        );

        return $this->ensureNumericResult($result, 'decrement', $key);
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

        return (bool) $this->errorHandler->executeWithRetry(
            fn () => $this->performCompareAndSwap($key, $expected, $value, $finalExpiration),
            $this->connectionManager,
            'compareAndSwap',
            $key
        );
    }

    private function performIncrement(string $key, int $step, int $initialValue, ?\DateTimeInterface $expiration): int
    {
        $fullKey = $this->config->keyPrefix . $key;
        $ttl = $expiration ? max(0, $expiration->getTimestamp() - time()) : 0;

        $result = $this->memcached->increment($fullKey, $step, $initialValue, $ttl);

        if (false === $result) {
            $this->monitoringService->recordError();
            throw CacheException::operationFailed('increment', $key, new \RuntimeException($this->memcached->getResultMessage()));
        }

        $this->monitoringService->recordWrite();

        return $result;
    }

    private function performDecrement(string $key, int $step, int $initialValue, ?\DateTimeInterface $expiration): int
    {
        $fullKey = $this->config->keyPrefix . $key;
        $ttl = $expiration ? max(0, $expiration->getTimestamp() - time()) : 0;

        $result = $this->memcached->decrement($fullKey, $step, $initialValue, $ttl);

        if (false === $result) {
            $this->monitoringService->recordError();
            throw CacheException::operationFailed('decrement', $key, new \RuntimeException($this->memcached->getResultMessage()));
        }

        $this->monitoringService->recordWrite();

        return $result;
    }

    private function performAdd(string $key, mixed $value, ?\DateTimeInterface $expiration): bool
    {
        $fullKey = $this->config->keyPrefix . $key;
        $ttl = $expiration ? max(0, $expiration->getTimestamp() - time()) : 0;

        $success = $this->memcached->add($fullKey, $value, $ttl);

        if ($success) {
            $this->monitoringService->recordWrite();
        } elseif (\Memcached::RES_NOTSTORED !== $this->memcached->getResultCode()) {
            $this->monitoringService->recordError();
        }

        return $success;
    }

    private function performCompareAndSwap(string $key, mixed $expected, mixed $value, ?\DateTimeInterface $expiration): bool
    {
        $fullKey = $this->config->keyPrefix . $key;
        $fetched = $this->memcached->get($fullKey, null, \Memcached::GET_EXTENDED);

        if (!is_array($fetched) || !isset($fetched['cas']) || !is_float($fetched['cas'])) {
            return false; // Key doesn't exist or result is malformed.
        }

        $currentValue = $fetched['value'];
        $casToken = $fetched['cas'];

        if ($currentValue !== $expected) {
            return false; // Value doesn't match expected.
        }

        $ttl = $expiration ? max(0, $expiration->getTimestamp() - time()) : 0;
        $success = $this->memcached->cas($casToken, $fullKey, $value, $ttl);

        if ($success) {
            $this->monitoringService->recordWrite();
        } elseif (\Memcached::RES_DATA_EXISTS !== $this->memcached->getResultCode()) {
            $this->monitoringService->recordError();
        }

        return $success;
    }

    private function ensureNumericResult(mixed $result, string $operation, string $key): int
    {
        if (!is_numeric($result)) {
            throw CacheException::operationFailed($operation, $key, new \UnexpectedValueException('Memcached command did not return a numeric value.'));
        }

        return (int) $result;
    }

    private function validateStep(int $step): void
    {
        if ($step <= 0) {
            throw InvalidArgumentException::invalidValue('Step must be a positive integer.');
        }
    }
}
