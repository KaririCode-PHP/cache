<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Memcached\Operation;

use KaririCode\Cache\Contract\Behavioral\AtomicOperable;
use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Driver\Memcached\Component\MemcachedConnectionManager;
use KaririCode\Cache\Driver\Memcached\Component\MemcachedErrorHandler;
use KaririCode\Cache\Driver\Memcached\Component\MemcachedMonitoringService;
use KaririCode\Cache\Driver\Memcached\Config\MemcachedConfig;
use KaririCode\Cache\Driver\Memcached\Validation\Trait\MemcachedResultValidatorTrait;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Validation\Trait\AtomicOperationValidationTrait;
use KaririCode\Cache\Validation\Trait\CacheKeyValidationTrait;
use KaririCode\Cache\Validation\Trait\CacheValueValidationTrait;

/**
 * Manages atomic operations for Memcached.
 * SRP: This class is only responsible for operations like increment, decrement, add, and compareAndSwap.
 * Uses ExpirationProvider for TTL management - follows Strategy Pattern.
 */
final class MemcachedAtomicOperations implements AtomicOperable
{
    use CacheKeyValidationTrait;
    use CacheValueValidationTrait;
    use AtomicOperationValidationTrait;
    use MemcachedResultValidatorTrait;

    public function __construct(
        private \Memcached $memcached,
        private MemcachedConfig $config,
        private MemcachedErrorHandler $errorHandler,
        private MemcachedConnectionManager $connectionManager,
        private MemcachedMonitoringService $monitoringService,
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
            $this->connectionManager,
            'increment',
            $key
        );

        return $this->ensureNumericResult($result, 'increment', $key);
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
            $this->connectionManager,
            'decrement',
            $key
        );

        return $this->ensureNumericResult($result, 'decrement', $key);
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
            $this->connectionManager,
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
            $this->connectionManager,
            'compareAndSwap',
            $key
        );
    }

    private function performIncrement(string $key, int $step, int $initialValue, ?\DateTimeInterface $expiration): int
    {
        $fullKey = $this->config->keyPrefix . $key;
        $ttlInSeconds = $expiration ? max(1, $expiration->getTimestamp() - time()) : 0;

        // Try increment first
        $result = $this->memcached->increment($fullKey, $step);

        if (false !== $result) {
            $this->monitoringService->recordWrite();

            // Set expiration if needed
            if ($ttlInSeconds > 0) {
                $this->memcached->touch($fullKey, $ttlInSeconds);
            }

            return (int) $result;
        }

        // Key doesn't exist, set initial value
        if ($this->memcached->add($fullKey, $initialValue + $step, $ttlInSeconds)) {
            $this->monitoringService->recordWrite();

            return $initialValue + $step;
        }

        $this->monitoringService->recordError();
        throw CacheException::operationFailed('increment', $key, 'Failed to increment or add initial value');
    }

    private function performDecrement(string $key, int $step, int $initialValue, ?\DateTimeInterface $expiration): int
    {
        $fullKey = $this->config->keyPrefix . $key;
        $ttlInSeconds = $expiration ? max(1, $expiration->getTimestamp() - time()) : 0;

        // Try decrement first
        $result = $this->memcached->decrement($fullKey, $step);

        if (false !== $result) {
            $this->monitoringService->recordWrite();

            // Set expiration if needed
            if ($ttlInSeconds > 0) {
                $this->memcached->touch($fullKey, $ttlInSeconds);
            }

            return (int) $result;
        }

        // Key doesn't exist, set initial value
        $newValue = max(0, $initialValue - $step); // Memcached doesn't allow negative values
        if ($this->memcached->add($fullKey, $newValue, $ttlInSeconds)) {
            $this->monitoringService->recordWrite();

            return $newValue;
        }

        $this->monitoringService->recordError();
        throw CacheException::operationFailed('decrement', $key, 'Failed to decrement or add initial value');
    }

    private function performAdd(string $key, mixed $value, ?\DateTimeInterface $expiration): bool
    {
        $fullKey = $this->config->keyPrefix . $key;
        $ttlInSeconds = $expiration ? max(1, $expiration->getTimestamp() - time()) : 0;

        $success = $this->memcached->add($fullKey, $value, $ttlInSeconds);

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
            return false; // Key doesn't exist or result is malformed
        }

        $currentValue = $fetched['value'];
        $casToken = $fetched['cas'];

        if ($currentValue !== $expected) {
            return false; // Value doesn't match expected
        }

        $ttlInSeconds = $expiration ? max(1, $expiration->getTimestamp() - time()) : 0;
        $success = $this->memcached->cas($casToken, $fullKey, $value, $ttlInSeconds);

        if ($success) {
            $this->monitoringService->recordWrite();
        } elseif (\Memcached::RES_DATA_EXISTS !== $this->memcached->getResultCode()) {
            $this->monitoringService->recordError();
        }

        return $success;
    }
}
