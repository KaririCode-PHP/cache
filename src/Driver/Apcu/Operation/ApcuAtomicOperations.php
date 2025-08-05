<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Apcu\Operation;

use KaririCode\Cache\Contract\Behavioral\AtomicOperable;
use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Driver\Apcu\Component\ApcuErrorHandler;
use KaririCode\Cache\Driver\Apcu\Component\ApcuMonitoringService;
use KaririCode\Cache\Driver\Apcu\Config\ApcuConfig;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Serializer\Trait\SerializationTrait;
use KaririCode\Cache\Validation\Trait\AtomicOperationValidationTrait;
use KaririCode\Cache\Validation\Trait\CacheKeyValidationTrait;
use KaririCode\Cache\Validation\Trait\CacheValueValidationTrait;

/**
 * Manages atomic operations for APCu.
 * SRP: This class is only responsible for atomic operations like increment, add, and CAS.
 * Uses ExpirationProvider for TTL management - follows Strategy Pattern.
 */
final class ApcuAtomicOperations implements AtomicOperable
{
    use SerializationTrait;
    use CacheKeyValidationTrait;
    use CacheValueValidationTrait;
    use AtomicOperationValidationTrait;

    public function __construct(
        private ApcuConfig $config,
        private ApcuErrorHandler $errorHandler,
        private ApcuMonitoringService $monitoringService,
        private ExpirationProvider $expirationProvider
    ) {
    }

    public function increment(string $key, int $step = 1, int $initialValue = 0, ?\DateTimeInterface $expiration = null): int
    {
        $this->validateKey($key);
        $this->validateStep($step);

        $finalExpiration = $expiration ?? $this->expirationProvider->calculateExpiration(
            $this->expirationProvider->getTtlForOperation('increment')
        );

        $result = $this->errorHandler->executeWithRetry(
            fn () => $this->performIncrement($key, $step, $initialValue, $finalExpiration),
            'increment',
            $key
        );

        return is_numeric($result) ? (int) $result : $initialValue;
    }

    public function decrement(string $key, int $step = 1, int $initialValue = 0, ?\DateTimeInterface $expiration = null): int
    {
        $this->validateKey($key);
        $this->validateStep($step);

        $finalExpiration = $expiration ?? $this->expirationProvider->calculateExpiration(
            $this->expirationProvider->getTtlForOperation('decrement')
        );

        $result = $this->errorHandler->executeWithRetry(
            fn () => $this->performDecrement($key, $step, $initialValue, $finalExpiration),
            'decrement',
            $key
        );

        return is_numeric($result) ? (int) $result : max(0, $initialValue - $step);
    }

    public function add(string $key, mixed $value, ?\DateTimeInterface $expiration = null): bool
    {
        $this->validateKey($key);
        $this->validateValue($value);

        $finalExpiration = $expiration ?? $this->expirationProvider->calculateExpiration(
            $this->expirationProvider->getTtlForKey($key)
        );

        return (bool) $this->errorHandler->executeWithRetry(
            fn () => $this->performAdd($key, $value, $finalExpiration),
            'add',
            $key
        );
    }

    public function compareAndSwap(string $key, mixed $expected, mixed $value, ?\DateTimeInterface $expiration = null): bool
    {
        $this->validateKey($key);
        $this->validateValue($expected);
        $this->validateValue($value);

        $finalExpiration = $expiration ?? $this->expirationProvider->calculateExpiration(
            $this->expirationProvider->getTtlForKey($key)
        );

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
            // Key doesn't exist, initialize it
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

        // Update TTL if needed
        if ($expiration) {
            $ttl = max(0, $expiration->getTimestamp() - time());
            if ($ttl > 0) {
                $currentValue = apcu_fetch($fullKey);
                apcu_store($fullKey, $currentValue, $ttl);
            }
        }

        return (int) $result;
    }

    private function performDecrement(string $key, int $step, int $initialValue, ?\DateTimeInterface $expiration): int
    {
        $fullKey = $this->config->keyPrefix . $key;

        $success = false;
        $result = apcu_dec($fullKey, $step, $success);

        if (!$success) {
            // Key doesn't exist, initialize it
            $ttl = $expiration ? max(0, $expiration->getTimestamp() - time()) : 0;
            $newValue = max(0, $initialValue - $step); // APCu doesn't allow negative values

            if (apcu_store($fullKey, $newValue, $ttl)) {
                $this->monitoringService->recordWrite();

                return $newValue;
            }

            $this->monitoringService->recordError();
            throw CacheException::operationFailed('decrement', $key, 'Failed to initialize key');
        }

        $this->monitoringService->recordWrite();

        // Update TTL if needed
        if ($expiration) {
            $ttl = max(0, $expiration->getTimestamp() - time());
            if ($ttl > 0) {
                $currentValue = apcu_fetch($fullKey);
                apcu_store($fullKey, $currentValue, $ttl);
            }
        }

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
        } else {
            // Check if the failure is because key exists or other error
            $exists = apcu_exists($fullKey);
            if (!$exists) {
                $this->monitoringService->recordError();
            }
        }

        return $result;
    }

    private function performCompareAndSwap(string $key, mixed $expected, mixed $value, ?\DateTimeInterface $expiration): bool
    {
        $fullKey = $this->config->keyPrefix . $key;
        $success = false;
        // APCu doesn't have native CAS, so we simulate it
        $currentValue = apcu_fetch($fullKey, $success);

        if (!$success) {
            return false; // Key doesn't exist
        }

        $currentDeserialized = $this->unserialize($currentValue);

        if ($currentDeserialized !== $expected) {
            return false; // Value doesn't match expected
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
}
