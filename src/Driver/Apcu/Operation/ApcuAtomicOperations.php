<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Apcu\Operation;

use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Driver\Apcu\Component\ApcuErrorHandler;
use KaririCode\Cache\Driver\Apcu\Component\ApcuMonitoringService;
use KaririCode\Cache\Driver\Apcu\Config\ApcuConfig;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Cache\Serializer\Trait\SerializationTrait;
use KaririCode\Cache\Service\ValidationService;

/**
 * Manages atomic operations for APCu.
 *
 * This class is solely responsible for atomic operations like increment, add,
 * and an emulated compare-and-swap. It internally uses the ExpirationProvider
 * to determine the correct TTL for each operation, adhering to SRP and the
 * Strategy Pattern.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class ApcuAtomicOperations
{
    use SerializationTrait;

    private readonly ValidationService $validator;

    /**
     * @param ApcuConfig $config configuration settings for APCu
     * @param ApcuErrorHandler $errorHandler error handling and retry logic
     * @param ApcuMonitoringService $monitoringService performance monitoring
     * @param ExpirationProvider $expirationProvider strategy for TTL management
     */
    public function __construct(
        private readonly ApcuConfig $config,
        private readonly ApcuErrorHandler $errorHandler,
        private readonly ApcuMonitoringService $monitoringService,
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
            'increment',
            $key
        );

        return is_numeric($result) ? (int) $result : $initialValue;
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
            'decrement',
            $key
        );

        return is_numeric($result) ? (int) $result : max(0, $initialValue - $step);
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
            'add',
            $key
        );
    }

    /**
     * Emulates an atomic compare-and-swap operation.
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
            'compareAndSwap',
            $key
        );
    }

    private function performIncrement(string $key, int $step, int $initialValue, ?\DateTimeInterface $expiration): int
    {
        $fullKey = $this->config->keyPrefix . $key;
        $success = false;
        $result = apcu_inc($fullKey, $step, $success);

        if (!$success) {
            // Key doesn't exist, so initialize it.
            $ttl = $expiration ? max(0, $expiration->getTimestamp() - time()) : 0;
            $newValue = $initialValue + $step;

            if (apcu_store($fullKey, $newValue, $ttl)) {
                $this->monitoringService->recordWrite();

                return $newValue;
            }

            $this->monitoringService->recordError();
            throw CacheException::operationFailed('increment', $key, 'Failed to initialize key');
        }

        $this->monitoringService->recordWrite();

        return (int) $result;
    }

    private function performDecrement(string $key, int $step, int $initialValue, ?\DateTimeInterface $expiration): int
    {
        $fullKey = $this->config->keyPrefix . $key;
        $success = false;
        $result = apcu_dec($fullKey, $step, $success);

        if (!$success) {
            // Key doesn't exist, so initialize it.
            $ttl = $expiration ? max(0, $expiration->getTimestamp() - time()) : 0;
            $newValue = max(0, $initialValue - $step); // APCu doesn't allow negative values.

            if (apcu_store($fullKey, $newValue, $ttl)) {
                $this->monitoringService->recordWrite();

                return $newValue;
            }

            $this->monitoringService->recordError();
            throw CacheException::operationFailed('decrement', $key, 'Failed to initialize key');
        }

        $this->monitoringService->recordWrite();

        return (int) $result;
    }

    private function performAdd(string $key, mixed $value, ?\DateTimeInterface $expiration): bool
    {
        $fullKey = $this->config->keyPrefix . $key;
        $serialized = $this->serialize($value);
        $ttl = $expiration ? max(0, $expiration->getTimestamp() - time()) : 0;

        $result = apcu_add($fullKey, $serialized, $ttl);

        if ($result) {
            $this->monitoringService->recordWrite();
        } elseif (!apcu_exists($fullKey)) {
            // Check if the failure is because the key exists or another error.
            $this->monitoringService->recordError();
        }

        return $result;
    }

    private function performCompareAndSwap(string $key, mixed $expected, mixed $value, ?\DateTimeInterface $expiration): bool
    {
        $fullKey = $this->config->keyPrefix . $key;
        $success = false;
        // APCu doesn't have native CAS, so we emulate it. This is not truly atomic.
        $currentValue = apcu_fetch($fullKey, $success);

        if (!$success) {
            return false; // Key doesn't exist.
        }

        if ($this->unserialize($currentValue) !== $expected) {
            return false; // Value doesn't match expected.
        }

        $serialized = $this->serialize($value);
        $ttl = $expiration ? max(0, $expiration->getTimestamp() - time()) : 0;

        $result = apcu_store($fullKey, $serialized, $ttl);

        if ($result) {
            $this->monitoringService->recordWrite();
        } else {
            $this->monitoringService->recordError();
        }

        return $result;
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
