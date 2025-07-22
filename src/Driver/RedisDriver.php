<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver;

use KaririCode\Cache\Contract\AtomicCacheDriver;
use KaririCode\Cache\Contract\Behavioral\CompareAndSwapAware;
use KaririCode\Cache\Contract\CacheCapabilities;
use KaririCode\Cache\Contract\DriverStats;
use KaririCode\Cache\Contract\TaggableCacheDriver;
use KaririCode\Cache\Contract\TopicAwareCacheDriver;
use KaririCode\Cache\Driver\Redis\Component\RedisConnectionManager;
use KaririCode\Cache\Driver\Redis\Component\RedisErrorHandler;
use KaririCode\Cache\Driver\Redis\Component\RedisMonitoringService;
use KaririCode\Cache\Driver\Redis\Config\RedisConfig;
use KaririCode\Cache\Driver\Redis\Operation\RedisAtomicOperations;
use KaririCode\Cache\Driver\Redis\Operation\RedisBatchOperations;
use KaririCode\Cache\Driver\Redis\Operation\RedisCrudOperations;
use KaririCode\Cache\Driver\Redis\Operation\RedisTagOperations;
use KaririCode\Cache\Driver\Redis\Operation\RedisTopicOperations;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class RedisDriver implements
    AtomicCacheDriver,
    TopicAwareCacheDriver,
    TaggableCacheDriver,
    CompareAndSwapAware
{
    private RedisConnectionManager $connectionManager;
    private RedisCrudOperations $crudOperations;
    private RedisBatchOperations $batchOperations;
    private RedisAtomicOperations $atomicOperations;
    private RedisTagOperations $tagOperations;
    private RedisTopicOperations $topicOperations;
    private RedisMonitoringService $monitoringService;
    private RedisErrorHandler $errorHandler;

    public function __construct(
        \Redis $redis,
        string $keyPrefix = 'cache:',
        string $tagPrefix = 'tag:',
        string $topicPrefix = 'topic:',
        ?LoggerInterface $logger = null,
        array $connectionConfig = []
    ) {
        $logger = $logger ?? new NullLogger();

        // Create configuration object
        $config = new RedisConfig(
            keyPrefix: $keyPrefix,
            tagPrefix: $tagPrefix,
            topicPrefix: $topicPrefix,
            host: $connectionConfig['host'] ?? '127.0.0.1',
            port: $connectionConfig['port'] ?? 6379,
            timeout: $connectionConfig['timeout'] ?? 2.0,
            retryAttempts: $connectionConfig['retry_attempts'] ?? 3,
            retryDelay: $connectionConfig['retry_delay'] ?? 0.1,
            persistent: $connectionConfig['persistent'] ?? false,
            persistentId: $connectionConfig['persistent_id'] ?? null,
            retryInterval: $connectionConfig['retry_interval'] ?? 0,
            readTimeout: $connectionConfig['read_timeout'] ?? 0.0,
            scanBatchSize: $connectionConfig['scan_batch_size'] ?? 500,
            password: $connectionConfig['password'] ?? null,
            database: $connectionConfig['database'] ?? 0
        );

        // Initialize error handler first as other components depend on it
        $this->errorHandler = new RedisErrorHandler($logger, $config);

        // Initialize connection manager
        $this->connectionManager = new RedisConnectionManager(
            $redis,
            $config,
            $this->errorHandler,
            $logger
        );

        // Initialize operation components
        $this->crudOperations = new RedisCrudOperations(
            $redis,
            $config,
            $this->errorHandler,
            $this->connectionManager
        );

        $this->batchOperations = new RedisBatchOperations(
            $redis,
            $config,
            $this->errorHandler,
            $this->connectionManager
        );

        $this->atomicOperations = new RedisAtomicOperations(
            $redis,
            $config,
            $this->errorHandler,
            $this->connectionManager
        );

        $this->tagOperations = new RedisTagOperations(
            $redis,
            $config,
            $this->errorHandler,
            $this->connectionManager
        );

        $this->topicOperations = new RedisTopicOperations(
            $redis,
            $config,
            $this->errorHandler,
            $this->connectionManager
        );

        $this->monitoringService = new RedisMonitoringService(
            $redis,
            $this->errorHandler,
            $logger
        );
    }

    // ========================================================================
    // Basic CRUD Operations - Delegate to CrudOperations
    // ========================================================================

    public function get(string $key): mixed
    {
        return $this->crudOperations->get($key);
    }

    public function set(string $key, mixed $value, ?\DateTimeInterface $expiration = null): bool
    {
        return $this->crudOperations->set($key, $value, $expiration);
    }

    public function has(string $key): bool
    {
        return $this->crudOperations->has($key);
    }

    public function delete(string $key): bool
    {
        return $this->crudOperations->delete($key);
    }

    public function clear(): bool
    {
        return $this->crudOperations->clear();
    }

    // ========================================================================
    // Batch Operations - Delegate to BatchOperations
    // ========================================================================

    public function getMultiple(array $keys): array
    {
        return $this->batchOperations->getMultiple($keys);
    }

    public function setMultiple(array $values, ?\DateTimeInterface $expiration = null): bool
    {
        return $this->batchOperations->setMultiple($values, $expiration);
    }

    public function deleteMultiple(array $keys): bool
    {
        return $this->batchOperations->deleteMultiple($keys);
    }

    // ========================================================================
    // Atomic Operations - Delegate to AtomicOperations
    // ========================================================================

    public function increment(string $key, int $step = 1, int $initialValue = 0, \DateInterval|int|null $ttl = null): int
    {
        return $this->atomicOperations->increment($key, $step, $initialValue, $ttl);
    }

    public function add(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        return $this->atomicOperations->add($key, $value, $ttl);
    }

    public function compareAndSwap(string $key, mixed $expected, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        return $this->atomicOperations->compareAndSwap($key, $expected, $value, $ttl);
    }

    // ========================================================================
    // Tagging Operations - Delegate to TagOperations
    // ========================================================================

    public function setWithTags(string $key, mixed $value, array $tags, \DateInterval|int|null $ttl = null): bool
    {
        return $this->tagOperations->setWithTags($key, $value, $tags, $ttl);
    }

    public function invalidateTags(array $tags): bool
    {
        return $this->tagOperations->invalidateTags($tags);
    }

    public function getByTags(array $tags): array
    {
        return $this->tagOperations->getByTags($tags);
    }

    public function getTags(): array
    {
        return $this->tagOperations->getTags();
    }

    // ========================================================================
    // Topic Operations - Delegate to TopicOperations
    // ========================================================================

    public function subscribeTo(string $key, array $topics): void
    {
        $this->topicOperations->subscribeTo($key, $topics);
    }

    public function invalidateTopics(array $topics): bool
    {
        return $this->topicOperations->invalidateTopics($topics);
    }

    // ========================================================================
    // Monitoring & Statistics - Delegate to MonitoringService
    // ========================================================================

    public function getCapabilities(): array
    {
        return [
            CacheCapabilities::ATOMIC_OPERATIONS,
            CacheCapabilities::TAGGING,
            CacheCapabilities::TOPICS,
            CacheCapabilities::TRANSACTIONS,
        ];
    }

    public function getStats(): DriverStats
    {
        return $this->monitoringService->getStats();
    }

    public function isHealthy(): bool
    {
        return $this->connectionManager->isHealthy();
    }

    public function maintenance(array $options = []): bool
    {
        return $this->monitoringService->performMaintenance($options);
    }
}
