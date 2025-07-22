<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Redis\Operation;

use KaririCode\Cache\Driver\Redis\Component\RedisConnectionManager;
use KaririCode\Cache\Driver\Redis\Component\RedisErrorHandler;
use KaririCode\Cache\Driver\Redis\Component\RedisLuaScriptManager;
use KaririCode\Cache\Driver\Redis\Config\RedisConfig;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Cache\Serializer\Trait\SerializationTrait;
use KaririCode\Cache\Validation\Trait\CacheKeyValidationTrait;
use KaririCode\Cache\Validation\Trait\CacheValueValidationTrait;
use KaririCode\Cache\Validation\Trait\TtlCalculatorTrait;

/**
 * Handles atomic operations for Redis.
 * Single Responsibility: Atomic operations (increment, add, compareAndSwap).
 */
final class RedisAtomicOperations
{
    use SerializationTrait;
    use CacheKeyValidationTrait;
    use CacheValueValidationTrait;
    use TtlCalculatorTrait;

    private RedisLuaScriptManager $scriptManager;

    public function __construct(
        private \Redis $redis,
        private RedisConfig $config,
        private RedisErrorHandler $errorHandler,
        private RedisConnectionManager $connectionManager
    ) {
        $this->scriptManager = new RedisLuaScriptManager($this->redis);
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

        if (!is_int($result)) {
            throw CacheException::operationFailed('increment', $key, new \UnexpectedValueException('Redis command should return an integer'));
        }

        return $result;
    }

    public function add(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $this->validateKey($key);
        $this->validateValue($value);

        return $this->errorHandler->executeWithRetry(
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

        return 1 === $result;
    }

    private function performIncrement(string $key, int $step, int $initialValue, \DateInterval|int|null $ttl): int
    {
        $fullKey = $this->config->keyPrefix . $key;
        $ttlInSeconds = $this->calculateTtl($ttl);

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
        $options = ['nx'];

        $ttlInSeconds = $this->calculateTtl($ttl);
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
        $ttlInSeconds = $this->calculateTtl($ttl);

        return $this->scriptManager->executeCompareAndSwap(
            $fullKey,
            $serializedExpected,
            $serializedValue,
            $ttlInSeconds
        );
    }

    private function validateStep(int $step): void
    {
        if (0 === $step) {
            throw InvalidArgumentException::invalidValue('Step cannot be zero');
        }
    }
}
