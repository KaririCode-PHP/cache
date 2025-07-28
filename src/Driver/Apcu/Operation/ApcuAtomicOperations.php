<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Apcu\Operation;

use KaririCode\Cache\Driver\Apcu\Component\ApcuErrorHandler;
use KaririCode\Cache\Driver\Apcu\Component\ApcuMonitoringService;
use KaririCode\Cache\Driver\Apcu\Config\ApcuConfig;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Cache\Serializer\Trait\SerializationTrait;
use KaririCode\Cache\Validation\Trait\AtomicOperationValidationTrait;
use KaririCode\Cache\Validation\Trait\CacheKeyValidationTrait;
use KaririCode\Cache\Validation\Trait\CacheValueValidationTrait;
use KaririCode\Cache\Validation\Trait\ExpirationManagementTrait;

/**
 * Manages atomic operations for APCu.
 * SRP: This class is only responsible for atomic operations like increment, add, and CAS.
 */
final class ApcuAtomicOperations
{
    use SerializationTrait;
    use CacheKeyValidationTrait;
    use CacheValueValidationTrait;
    use ExpirationManagementTrait;
    use AtomicOperationValidationTrait;

    public function __construct(
        private ApcuConfig $config,
        private ApcuErrorHandler $errorHandler,
        private ApcuMonitoringService $monitoringService
    ) {
    }

    public function increment(string $key, int $value = 1): int
    {
        $this->validateKey($key);
        $this->validateIncrementValue($value);

        $result = $this->errorHandler->executeWithRetry(
            fn () => $this->performIncrement($key, $value),
            'increment',
            $key
        );

        return is_numeric($result) ? (int) $result : 0;
    }

    public function decrement(string $key, int $value = 1): int
    {
        $this->validateKey($key);
        $this->validateDecrementValue($value);

        $result = $this->errorHandler->executeWithRetry(
            fn () => $this->performDecrement($key, $value),
            'decrement',
            $key
        );

        return is_numeric($result) ? (int) $result : 0;
    }

    public function add(string $key, mixed $value, ?\DateTimeInterface $expiration = null): bool
    {
        $this->validateKey($key);
        $this->validateValue($value);

        return (bool) $this->errorHandler->executeWithRetry(
            fn () => $this->performAdd($key, $value, $expiration),
            'add',
            $key
        );
    }

    public function compareAndSwap(
        string $key,
        mixed $expected,
        mixed $value,
        ?\DateTimeInterface $expiration = null
    ): bool {
        $this->validateKey($key);
        $this->validateValue($value);

        return (bool) $this->errorHandler->executeWithRetry(
            fn () => $this->performCompareAndSwap($key, $expected, $value, $expiration),
            'compareAndSwap',
            $key
        );
    }

    private function performIncrement(string $key, int $value): int
    {
        $fullKey = $this->config->keyPrefix . $key;

        $success = false;
        $result = \apcu_inc($fullKey, $value, $success);

        if (!$success) {
            // Initialize key if it doesn't exist
            apcu_store($fullKey, $value);

            return $value;
        }

        $this->monitoringService->recordWrite();

        return (int) $result;
    }

    private function performDecrement(string $key, int $value): int
    {
        $fullKey = $this->config->keyPrefix . $key;

        $result = apcu_dec($fullKey, $value, $success);

        if (!$success) {
            // Initialize key if it doesn't exist
            apcu_store($fullKey, -$value);

            return -$value;
        }

        $this->monitoringService->recordWrite();

        return (int) $result;
    }

    private function performAdd(string $key, mixed $value, ?\DateTimeInterface $expiration): bool
    {
        $fullKey = $this->config->keyPrefix . $key;
        $serialized = $this->serialize($value);
        $ttl = $this->calculateTtlInSeconds($expiration);

        // apcu_add only succeeds if key doesn't exist
        $result = apcu_add($fullKey, $serialized, $ttl);

        if ($result) {
            $this->monitoringService->recordWrite();
        }

        return $result;
    }

    private function performCompareAndSwap(
        string $key,
        mixed $expected,
        mixed $value,
        ?\DateTimeInterface $expiration
    ): bool {
        $fullKey = $this->config->keyPrefix . $key;
        $lockKey = $this->config->getLockPrefix() . $key;

        // Acquire lock for atomic CAS operation
        $lockAcquired = apcu_add($lockKey, 1, $this->config->lockTimeout);

        if (!$lockAcquired) {
            throw CacheException::operationFailed('compareAndSwap', $key, new \RuntimeException('Failed to acquire lock for CAS operation'));
        }

        try {
            $current = apcu_fetch($fullKey, $success);

            if (!$success) {
                // Key doesn't exist
                if (null === $expected) {
                    // Expected null, so we can set the value
                    $result = apcu_store(
                        $fullKey,
                        $this->serialize($value),
                        $this->calculateTtlInSeconds($expiration)
                    );

                    if ($result) {
                        $this->monitoringService->recordWrite();
                    }

                    return $result;
                }

                return false;
            }

            $currentValue = $this->unserialize($current);

            if ($this->valuesAreEqual($currentValue, $expected)) {
                $result = apcu_store(
                    $fullKey,
                    $this->serialize($value),
                    $this->calculateTtlInSeconds($expiration)
                );

                if ($result) {
                    $this->monitoringService->recordWrite();
                }

                return $result;
            }

            return false;
        } finally {
            apcu_delete($lockKey);
        }
    }

    private function valuesAreEqual(mixed $a, mixed $b): bool
    {
        // Handle special cases for comparison
        if ($a === $b) {
            return true;
        }

        // For objects, use serialize for deep comparison
        if (is_object($a) && is_object($b)) {
            return serialize($a) === serialize($b);
        }

        return false;
    }

    private function validateIncrementValue(int $value): void
    {
        if ($value <= 0) {
            throw InvalidArgumentException::invalidValue('Increment value must be positive');
        }
    }

    private function validateDecrementValue(int $value): void
    {
        if ($value <= 0) {
            throw InvalidArgumentException::invalidValue('Decrement value must be positive');
        }
    }
}
