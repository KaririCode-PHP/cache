<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Memcached\Operation;

use KaririCode\Cache\Driver\Memcached\Component\MemcachedConnectionManager;
use KaririCode\Cache\Driver\Memcached\Component\MemcachedErrorHandler;
use KaririCode\Cache\Driver\Memcached\Component\MemcachedMonitoringService;
use KaririCode\Cache\Driver\Memcached\Config\MemcachedConfig;
use KaririCode\Cache\Driver\Memcached\Validation\Trait\MemcachedResultValidatorTrait;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Validation\Trait\AtomicOperationValidationTrait;
use KaririCode\Cache\Validation\Trait\CacheKeyValidationTrait;
use KaririCode\Cache\Validation\Trait\CacheValueValidationTrait;
use KaririCode\Cache\Validation\Trait\ExpirationManagementTrait;

/**
 * Manages atomic operations for Memcached.
 * SRP: This class is only responsible for operations like increment, decrement, add, and compareAndSwap.
 */
final class MemcachedAtomicOperations
{
    use CacheKeyValidationTrait;
    use CacheValueValidationTrait;
    use ExpirationManagementTrait;
    use AtomicOperationValidationTrait;
    use MemcachedResultValidatorTrait;

    public function __construct(
        private \Memcached $memcached,
        private MemcachedConfig $config,
        private MemcachedErrorHandler $errorHandler,
        private MemcachedConnectionManager $connectionManager,
        private MemcachedMonitoringService $monitoringService
    ) {
    }

    public function increment(string $key, int $step = 1, int $initialValue = 0, \DateInterval|int|null $ttl = null): int
    {
        $this->validateKey($key);
        $this->validateStep($step);

        $result = $this->errorHandler->executeWithRetry(
            fn () => $this->performIncrement($key, $step, $initialValue, $ttl),
            $this->connectionManager,
            'increment',
            $key
        );

        return $this->ensureNumericResult($result, 'increment', $key);
    }

    public function decrement(string $key, int $step = 1, int $initialValue = 0, \DateInterval|int|null $ttl = null): int
    {
        $this->validateKey($key);
        $this->validateStep($step);

        $result = $this->errorHandler->executeWithRetry(
            fn () => $this->performDecrement($key, $step, $initialValue, $ttl),
            $this->connectionManager,
            'decrement',
            $key
        );

        return $this->ensureNumericResult($result, 'decrement', $key);
    }

    public function add(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $this->validateKey($key);
        $this->validateValue($value);

        return (bool) $this->errorHandler->executeWithRetry(
            fn () => $this->performAdd($key, $value, $ttl),
            $this->connectionManager,
            'add',
            $key
        );
    }

    public function compareAndSwap(string $key, mixed $expected, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $this->validateKey($key);
        $this->validateValue($value);

        return (bool) $this->errorHandler->executeWithRetry(
            fn () => $this->performCompareAndSwap($key, $expected, $value, $ttl),
            $this->connectionManager,
            'compareAndSwap',
            $key
        );
    }

    private function performIncrement(string $key, int $step, int $initialValue, \DateInterval|int|null $ttl): int
    {
        $fullKey = $this->config->keyPrefix . $key;
        $ttlInSeconds = $this->calculateTtlInSeconds($ttl);
        $newValue = $this->memcached->increment($fullKey, $step, $initialValue, $ttlInSeconds);

        if (false === $newValue) {
            $finalValue = $this->memcached->get($fullKey);
            if (!is_numeric($finalValue)) {
                throw CacheException::operationFailed('increment', $key, new \UnexpectedValueException('Failed to retrieve value for increment after race condition.'));
            }
            $newValue = (int) $finalValue;
        }

        $this->monitoringService->recordWrite();

        return $newValue;
    }

    private function performDecrement(string $key, int $step, int $initialValue, \DateInterval|int|null $ttl): int
    {
        $fullKey = $this->config->keyPrefix . $key;
        $ttlInSeconds = $this->calculateTtlInSeconds($ttl);
        $newValue = $this->memcached->decrement($fullKey, $step, $initialValue, $ttlInSeconds);

        if (false === $newValue) {
            $finalValue = $this->memcached->get($fullKey);
            if (!is_numeric($finalValue)) {
                throw CacheException::operationFailed('decrement', $key, new \UnexpectedValueException('Failed to retrieve value for decrement after race condition.'));
            }
            $newValue = (int) $finalValue;
        }

        $this->monitoringService->recordWrite();

        return $newValue;
    }

    private function performAdd(string $key, mixed $value, \DateInterval|int|null $ttl): bool
    {
        $fullKey = $this->config->keyPrefix . $key;
        $ttlInSeconds = $this->calculateTtlInSeconds($ttl);
        $result = $this->memcached->add($fullKey, $value, $ttlInSeconds);

        if ($result) {
            $this->monitoringService->recordWrite();
        } elseif (\Memcached::RES_NOTSTORED !== $this->memcached->getResultCode()) {
            $this->monitoringService->recordError();
        }

        return $result;
    }

    private function performCompareAndSwap(string $key, mixed $expected, mixed $value, \DateInterval|int|null $ttl): bool
    {
        $fullKey = $this->config->keyPrefix . $key;

        $fetched = $this->memcached->get($fullKey, null, \Memcached::GET_EXTENDED);

        if (!is_array($fetched) || !isset($fetched['cas']) || !is_float($fetched['cas'])) {
            // Key doesn't exist or result is malformed; cannot be swapped.
            return false;
        }

        $currentValue = $fetched['value'];
        $casToken = $fetched['cas'];

        if ($currentValue !== $expected) {
            // The current value does not match what was expected.
            return false;
        }

        $ttlInSeconds = $this->calculateTtlInSeconds($ttl);
        $wasSet = $this->memcached->cas($casToken, $fullKey, $value, $ttlInSeconds);

        if ($wasSet) {
            $this->monitoringService->recordWrite();
        } elseif (\Memcached::RES_DATA_EXISTS !== $this->memcached->getResultCode()) {
            $this->monitoringService->recordError();
        }

        return $wasSet;
    }
}
