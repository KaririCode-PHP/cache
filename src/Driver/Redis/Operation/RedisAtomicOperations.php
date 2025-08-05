<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Redis\Operation;

use KaririCode\Cache\Contract\Behavioral\AtomicOperable;
use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Driver\Redis\Component\RedisConnectionManager;
use KaririCode\Cache\Driver\Redis\Component\RedisErrorHandler;
use KaririCode\Cache\Driver\Redis\Component\RedisLuaScriptManager;
use KaririCode\Cache\Driver\Redis\Config\RedisConfig;
use KaririCode\Cache\Driver\Redis\Validation\Trait\RedisResultValidatorTrait;
use KaririCode\Cache\Serializer\Trait\SerializationTrait;
use KaririCode\Cache\Validation\Trait\AtomicOperationValidationTrait;
use KaririCode\Cache\Validation\Trait\CacheKeyValidationTrait;
use KaririCode\Cache\Validation\Trait\CacheValueValidationTrait;

/**
 * Manages atomic operations for Redis.
 * SRP: This class is only responsible for operations like increment, add, and compareAndSwap.
 * Uses ExpirationProvider for TTL management - follows Strategy Pattern.
 */
final class RedisAtomicOperations implements AtomicOperable
{
    use SerializationTrait;
    use CacheKeyValidationTrait;
    use CacheValueValidationTrait;
    use AtomicOperationValidationTrait;
    use RedisResultValidatorTrait;

    public function __construct(
        private \Redis $redis,
        private RedisConfig $config,
        private RedisErrorHandler $errorHandler,
        private RedisConnectionManager $connectionManager,
        private readonly RedisLuaScriptManager $scriptManager,
        private readonly ExpirationProvider $expirationProvider
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
        $ttlInSeconds = $expiration ? max(1, $expiration->getTimestamp() - time()) : 0;

        if ($ttlInSeconds > 0) {
            return $this->scriptManager->executeIncrementWithTtl($fullKey, $step, $initialValue, $ttlInSeconds);
        }

        if (!$this->redis->exists($fullKey)) {
            $this->redis->set($fullKey, (string) $initialValue);
        }

        return $this->redis->incrBy($fullKey, $step);
    }

    private function performDecrement(string $key, int $step, int $initialValue, ?\DateTimeInterface $expiration): int
    {
        $fullKey = $this->config->keyPrefix . $key;
        $ttlInSeconds = $expiration ? max(1, $expiration->getTimestamp() - time()) : 0;

        if ($ttlInSeconds > 0) {
            return $this->scriptManager->executeIncrementWithTtl($fullKey, -$step, $initialValue, $ttlInSeconds);
        }

        if (!$this->redis->exists($fullKey)) {
            $this->redis->set($fullKey, (string) $initialValue);
        }

        return $this->redis->decrBy($fullKey, $step);
    }

    private function performAdd(string $key, mixed $value, ?\DateTimeInterface $expiration): bool
    {
        $fullKey = $this->config->keyPrefix . $key;
        $serialized = $this->serialize($value);
        $options = ['nx']; // 'NX' (Not eXists) makes this an atomic "add if not exists" operation.

        if ($expiration) {
            $ttlInSeconds = max(1, $expiration->getTimestamp() - time());
            if ($ttlInSeconds > 0) {
                $options['ex'] = $ttlInSeconds;
            }
        }

        return (bool) $this->redis->set($fullKey, $serialized, $options);
    }

    private function performCompareAndSwap(string $key, mixed $expected, mixed $value, ?\DateTimeInterface $expiration): int
    {
        $fullKey = $this->config->keyPrefix . $key;
        $serializedExpected = $this->serialize($expected);
        $serializedValue = $this->serialize($value);
        $ttlInSeconds = $expiration ? max(1, $expiration->getTimestamp() - time()) : 0;

        return $this->scriptManager->executeCompareAndSwap(
            $fullKey,
            $serializedExpected,
            $serializedValue,
            $ttlInSeconds
        );
    }
}
