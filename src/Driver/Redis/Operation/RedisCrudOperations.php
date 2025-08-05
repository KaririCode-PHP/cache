<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Redis\Operation;

use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Driver\Redis\Component\RedisConnectionManager;
use KaririCode\Cache\Driver\Redis\Component\RedisErrorHandler;
use KaririCode\Cache\Driver\Redis\Component\RedisMonitoringService;
use KaririCode\Cache\Driver\Redis\Config\RedisConfig;
use KaririCode\Cache\Validation\Trait\CacheKeyValidationTrait;
use KaririCode\Cache\Validation\Trait\CacheValueValidationTrait;

/**
 * Redis CRUD operations without expiration parameters.
 * Uses ExpirationProvider for TTL management - follows SRP.
 */
final class RedisCrudOperations
{
    use CacheKeyValidationTrait;
    use CacheValueValidationTrait;

    public function __construct(
        private \Redis $redis,
        private RedisConfig $config,
        private RedisErrorHandler $errorHandler,
        private RedisConnectionManager $connectionManager,
        private RedisMonitoringService $monitoringService,
        private ExpirationProvider $expirationProvider
    ) {
    }

    public function get(string $key): mixed
    {
        $this->validateKey($key);

        return $this->errorHandler->executeWithRetry(
            fn () => $this->performGet($key),
            $this->connectionManager,
            'get',
            $key
        );
    }

    public function set(string $key, mixed $value, ?\DateTimeInterface $expiration = null): bool
    {
        $this->validateKey($key);
        $this->validateValue($value);

        // Use provided expiration or get from provider
        if (null === $expiration) {
            $expiration = $this->expirationProvider->calculateExpiration(
                $this->expirationProvider->getTtlForKey($key)
            );
        }

        return (bool) $this->errorHandler->executeWithRetry(
            fn () => $this->performSet($key, $value, $expiration),
            $this->connectionManager,
            'set',
            $key
        );
    }

    public function has(string $key): bool
    {
        $this->validateKey($key);

        return (bool) $this->errorHandler->executeWithRetry(
            fn () => $this->performHas($key),
            $this->connectionManager,
            'has',
            $key
        );
    }

    public function delete(string $key): bool
    {
        $this->validateKey($key);

        return (bool) $this->errorHandler->executeWithRetry(
            fn () => $this->performDelete($key),
            $this->connectionManager,
            'delete',
            $key
        );
    }

    public function clear(): bool
    {
        return (bool) $this->errorHandler->executeWithRetry(
            fn () => $this->performClear(),
            $this->connectionManager,
            'clear'
        );
    }

    private function performGet(string $key): mixed
    {
        $fullKey = $this->config->keyPrefix . $key;
        $value = $this->redis->get($fullKey);

        if (false === $value) {
            $this->monitoringService->recordMiss();

            return null;
        }

        $this->monitoringService->recordHit();

        return $value;
    }

    private function performSet(string $key, mixed $value, ?\DateTimeInterface $expiration): bool
    {
        $fullKey = $this->config->keyPrefix . $key;

        if (null !== $expiration) {
            $ttl = $expiration->getTimestamp() - time();
            if ($ttl <= 0) {
                return false;
            }

            return $this->redis->setex($fullKey, $ttl, $value);
        }

        return $this->redis->set($fullKey, $value);
    }

    private function performHas(string $key): bool
    {
        $fullKey = $this->config->keyPrefix . $key;

        return $this->redis->exists($fullKey) > 0;
    }

    private function performDelete(string $key): bool
    {
        $fullKey = $this->config->keyPrefix . $key;

        return $this->redis->del($fullKey) > 0;
    }

    private function performClear(): bool
    {
        return $this->redis->flushDB();
    }
}
