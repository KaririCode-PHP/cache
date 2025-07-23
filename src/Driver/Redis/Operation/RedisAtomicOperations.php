<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Redis\Operation;

use KaririCode\Cache\Driver\Redis\Component\RedisConnectionManager;
use KaririCode\Cache\Driver\Redis\Component\RedisErrorHandler;
use KaririCode\Cache\Driver\Redis\Component\RedisLuaScriptManager;
use KaririCode\Cache\Driver\Redis\Config\RedisConfig;
use KaririCode\Cache\Driver\Redis\Validation\Trait\RedisResultValidatorTrait;
use KaririCode\Cache\Serializer\Trait\SerializationTrait;
use KaririCode\Cache\Validation\Trait\AtomicOperationValidationTrait;
use KaririCode\Cache\Validation\Trait\CacheKeyValidationTrait;
use KaririCode\Cache\Validation\Trait\CacheValueValidationTrait;
use KaririCode\Cache\Validation\Trait\ExpirationManagementTrait;

/**
 * Manages atomic operations for Redis.
 * SRP: This class is only responsible for operations like increment, add, and compareAndSwap.
 */
final class RedisAtomicOperations
{
    use SerializationTrait;
    use CacheKeyValidationTrait;
    use CacheValueValidationTrait;
    use ExpirationManagementTrait;
    use AtomicOperationValidationTrait;
    use RedisResultValidatorTrait;

    /**
     * The constructor receives all its dependencies via property promotion.
     * The body is empty because no further logic is needed for initialization.
     */
    public function __construct(
        private \Redis $redis,
        private RedisConfig $config,
        private RedisErrorHandler $errorHandler,
        private RedisConnectionManager $connectionManager,
        private readonly RedisLuaScriptManager $scriptManager
    ) {
        // No initialization logic needed.
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

        $result = $this->errorHandler->executeWithRetry(
            fn () => $this->performCompareAndSwap($key, $expected, $value, $ttl),
            $this->connectionManager,
            'compareAndSwap',
            $key
        );

        return 1 === $this->ensureNumericResult($result, 'compareAndSwap', $key);
    }

    private function performIncrement(string $key, int $step, int $initialValue, \DateInterval|int|null $ttl): int
    {
        $fullKey = $this->config->keyPrefix . $key;
        $ttlInSeconds = $this->calculateTtlInSeconds($ttl);

        // To ensure atomicity for both the increment and the TTL setting, a LUA script is used.
        if ($ttlInSeconds > 0) {
            return $this->scriptManager->executeIncrementWithTtl($fullKey, $step, $initialValue, $ttlInSeconds);
        }

        if (!$this->redis->exists($fullKey)) {
            $this->redis->set($fullKey, (string) $initialValue);
        }

        return $this->redis->incrBy($fullKey, $step);
    }

    private function performAdd(string $key, mixed $value, \DateInterval|int|null $ttl): bool
    {
        $fullKey = $this->config->keyPrefix . $key;
        $serialized = $this->serialize($value);
        $options = ['nx']; // 'NX' (Not eXists) makes this an atomic "add if not exists" operation.

        $ttlInSeconds = $this->calculateTtlInSeconds($ttl);
        if ($ttlInSeconds > 0) {
            $options['ex'] = $ttlInSeconds;
        }

        return (bool) $this->redis->set($fullKey, $serialized, $options);
    }

    private function performCompareAndSwap(string $key, mixed $expected, mixed $value, \DateInterval|int|null $ttl): int
    {
        $fullKey = $this->config->keyPrefix . $key;
        $serializedExpected = $this->serialize($expected);
        $serializedValue = $this->serialize($value);
        $ttlInSeconds = $this->calculateTtlInSeconds($ttl);

        // The CAS logic is complex and must be atomic, making a LUA script the best solution.
        $result = $this->scriptManager->executeCompareAndSwap(
            $fullKey,
            $serializedExpected,
            $serializedValue,
            $ttlInSeconds
        );

        return $this->ensureNumericResult($result, 'compareAndSwap', $key);
    }
}
