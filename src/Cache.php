<?php

declare(strict_types=1);

namespace KaririCode\Cache;

use KaririCode\Cache\Contract\Cache as CacheContract;
use KaririCode\Cache\Contract\CacheItemPool;
use KaririCode\Cache\Contract\CacheSerializer;
use KaririCode\Cache\Contract\LayeredCache as LayeredCacheContract;
use KaririCode\Cache\Contract\MetricsCollector as MetricsCollectorContract;
use KaririCode\Cache\Contract\StampedeProtector as StampedeProtectorContract;
use KaririCode\Cache\Event\CacheClearEvent;
use KaririCode\Cache\Event\CacheDeleteEvent;
use KaririCode\Cache\Event\CacheHitEvent;
use KaririCode\Cache\Event\CacheMissEvent;
use KaririCode\Cache\Event\CacheSetEvent;
use KaririCode\Cache\Event\CacheWarmupEvent;
use KaririCode\Cache\Event\TagInvalidationEvent;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Cache\Item\AdvancedCacheProxy;
use KaririCode\Cache\Item\CacheItem;
use KaririCode\Cache\Metrics\CacheMetrics;
use KaririCode\Cache\Metrics\MetricsCollector;
use KaririCode\Cache\Protection\StampedeProtector;
use KaririCode\Cache\Serializer\NativeSerializer;
use KaririCode\Cache\Validation\Trait\UnifiedCacheValidationTrait;
use KaririCode\Cache\ValueObject\Tag;
use KaririCode\Cache\ValueObject\Topic;
use Psr\Cache\CacheItemInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The main Cache class, acting as a facade for the entire caching subsystem.
 * It coordinates layered caching, serialization, validation, events, and metrics.
 * This class implements the PSR-16 Simple Cache interface along with advanced features.
 */
final class Cache implements CacheContract
{
    // Use the centralized validation trait to keep this class clean and focused.
    use UnifiedCacheValidationTrait;

    private readonly CacheSerializer $serializer;
    private readonly StampedeProtectorContract $stampedeProtector;
    private readonly LoggerInterface $logger;
    private readonly ?EventDispatcherInterface $eventDispatcher;
    private MetricsCollectorContract $metricsCollector;

    /** @var array<string, mixed> */
    private array $config;

    public function __construct(
        private readonly LayeredCacheContract $layeredCache,
        ?CacheSerializer $serializer = null,
        ?StampedeProtectorContract $stampedeProtector = null,
        ?MetricsCollectorContract $metricsCollector = null,
        ?LoggerInterface $logger = null,
        ?EventDispatcherInterface $eventDispatcher = null,
        array $config = []
    ) {
        $this->eventDispatcher = $eventDispatcher;
        $this->config = $config;
        $this->serializer = $serializer ?? new NativeSerializer();
        $this->stampedeProtector = $stampedeProtector ?? new StampedeProtector();
        $this->metricsCollector = $metricsCollector ?? new MetricsCollector();
        $this->logger = $logger ?? new NullLogger();

        $this->validateInstanceConfiguration();

        $this->logger->info('Cache instance initialized', [
            'name' => $this->getName(),
            'serializer' => get_class($this->serializer),
            'config_keys' => array_keys($this->config),
        ]);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $startTime = microtime(true);
        $this->validateCacheRead($key);

        try {
            $value = $this->getValueFromCache($key);

            if (null === $value) {
                $this->metricsCollector = $this->metricsCollector->recordMiss($key);
                $this->dispatchCacheMiss($key);

                return $default;
            }

            $duration = microtime(true) - $startTime;
            $this->metricsCollector = $this->metricsCollector->recordHit($key, $duration);
            $this->dispatchCacheHit($key, $value, $duration);

            return $value;
        } catch (\Throwable $e) {
            $this->logger->error('Cache get operation failed', ['key' => $key, 'error' => $e->getMessage()]);

            return $default;
        }
    }

    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $startTime = microtime(true);
        $this->validateCacheInput($key, $value);

        try {
            $serializedValue = $this->serializer->serialize($value);
            $expiration = $this->calculateExpiration($ttl);

            $item = $this->layeredCache->getItem($key);
            $item->set($serializedValue);
            if ($expiration) {
                $item->expiresAt($expiration);
            }
            $result = $this->layeredCache->save($item);

            if ($result) {
                $this->dispatchCacheSet($key, $value, $ttl, microtime(true) - $startTime);
                $this->logger->debug('Cache item stored successfully', [
                    'key' => $key,
                    'size' => strlen($serializedValue),
                    'ttl_seconds' => $this->normalizeTtlToSeconds($ttl),
                ]);
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Cache set operation failed', ['key' => $key, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public function has(string $key): bool
    {
        $this->validateCacheRead($key);

        try {
            return $this->layeredCache->hasItem($key);
        } catch (\Throwable $e) {
            $this->logger->error('Cache has operation failed', ['key' => $key, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public function delete(string $key): bool
    {
        $startTime = microtime(true);
        $this->validateCacheRead($key);

        try {
            $existed = $this->has($key);
            $result = $this->layeredCache->deleteItem($key);

            if ($result) {
                $this->dispatchCacheDelete($key, $existed, microtime(true) - $startTime);
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Cache delete operation failed', ['key' => $key, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public function clear(): bool
    {
        $startTime = microtime(true);
        try {
            $result = $this->layeredCache->clear();

            if ($result) {
                $this->metricsCollector = $this->metricsCollector->recordClear();
                $this->dispatchCacheClear(microtime(true) - $startTime);
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Cache clear operation failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $keysArray = is_array($keys) ? $keys : iterator_to_array($keys);
        $this->validateKeys($keysArray);

        $results = [];
        foreach ($keysArray as $key) {
            $results[$key] = $this->get($key, $default);
        }

        return $results;
    }

    public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
    {
        $valuesArray = is_array($values) ? $values : iterator_to_array($values);
        $this->validateMultipleValues($valuesArray);

        $success = true;
        foreach ($valuesArray as $key => $value) {
            if (!$this->set((string) $key, $value, $ttl)) {
                $success = false;
            }
        }

        return $success;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $keysArray = is_array($keys) ? $keys : iterator_to_array($keys);
        $this->validateKeys($keysArray);

        $success = true;
        foreach ($keysArray as $key) {
            if (!$this->delete($key)) {
                $success = false;
            }
        }

        return $success;
    }

    public function increment(string $key, int $step = 1, int $initialValue = 0, \DateInterval|int|null $ttl = null): int
    {
        $this->validateCacheIncrement($key, $step);

        return $this->layeredCache->increment($key, $step, $initialValue, $ttl);
    }

    public function decrement(string $key, int $step = 1, int $initialValue = 0, \DateInterval|int|null $ttl = null): int
    {
        return $this->increment($key, -$step, $initialValue, $ttl);
    }

    public function add(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $this->validateCacheInput($key, $value);

        if ($this->has($key)) {
            return false;
        }

        return $this->set($key, $value, $ttl);
    }

    public function compareAndSwap(string $key, mixed $expected, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $this->validateCacheInput($key, $value);
        $current = $this->get($key);

        if ($current === $expected) {
            return $this->set($key, $value, $ttl);
        }

        return false;
    }

    /**
     * @param Tag[] $tags
     */
    public function setWithTags(string $key, mixed $value, array $tags, \DateInterval|int|null $ttl = null): bool
    {
        $this->validateCacheInput($key, $value, $tags);
        $result = $this->set($key, $value, $ttl);

        if ($result && !empty($tags)) {
            $item = $this->layeredCache->getItem($key);
            if ($item instanceof CacheItem) {
                $item->tag($tags);
                $this->layeredCache->save($item);
            }
        }

        return $result;
    }

    /**
     * @param Tag[] $tags
     */
    public function invalidateTags(array $tags): bool
    {
        $this->validateTags($tags);

        try {
            $result = $this->layeredCache->invalidateTags($tags);
            if ($result && $this->eventDispatcher) {
                $this->eventDispatcher->dispatch(new TagInvalidationEvent($tags));
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Tag invalidation failed', [
                'tags' => array_map('strval', $tags),
                'error' => $e->getMessage(),
            ]);
            throw CacheException::tagInvalidationFailed(array_map('strval', $tags), $e);
        }
    }

    /**
     * @param Tag[] $tags
     */
    public function getByTags(array $tags): array
    {
        $this->validateTags($tags);

        return [];
    }

    public function getTags(): array
    {
        return $this->layeredCache->getTags();
    }

    /**
     * @param Topic[] $topics
     */
    public function subscribeTo(string $key, array $topics): void
    {
        $this->validateKey($key);
        $this->validateTopics($topics);
        $this->layeredCache->subscribeTo($key, $topics);
    }

    /**
     * @param Topic[] $topics
     */
    public function unsubscribeFrom(string $key, array $topics): void
    {
        $this->validateKey($key);
        $this->validateTopics($topics);
    }

    /**
     * @param Topic[] $topics
     */
    public function invalidateTopics(array $topics): bool
    {
        $this->validateTopics($topics);

        return $this->layeredCache->invalidateTopics($topics);
    }

    public function getSubscribedTopics(string $key): array
    {
        $this->validateKey($key);

        return $this->layeredCache->getSubscribedTopics($key);
    }

    public function getSubscribersForTopic(string $topic): array
    {
        $this->validateTopics([new Topic($topic)]);

        return $this->layeredCache->getSubscribersForTopic($topic);
    }

    public function getWithCallback(string $key, ?callable $callback = null, \DateInterval|int|null $ttl = null, array $tags = []): mixed
    {
        $value = $this->get($key);
        if (null !== $value) {
            return $value;
        }
        if (null === $callback) {
            return null;
        }

        return $this->stampedeProtector->get($key, function () use ($callback, $key, $ttl, $tags) {
            $value = $callback();
            if (!empty($tags)) {
                $this->setWithTags($key, $value, $tags, $ttl);
            } else {
                $this->set($key, $value, $ttl);
            }
            $this->metricsCollector = $this->metricsCollector->recordGeneration($key, microtime(true));

            return $value;
        });
    }

    public function warm(array $data, \DateInterval|int|null $ttl = null): void
    {
        if ($this->eventDispatcher) {
            $this->eventDispatcher->dispatch(new CacheWarmupEvent($data, $ttl));
        }

        $this->setMultiple($data, $ttl);
        $this->logger->info('Cache warmed', ['keys' => array_keys($data)]);
    }

    public function getMetrics(): CacheMetrics
    {
        return new CacheMetrics($this->metricsCollector->getMetrics());
    }

    public function getName(): string
    {
        $name = $this->config['name'] ?? 'default';

        // Ensure the return value is always a string to satisfy static analysis and the return type.
        return is_string($name) ? $name : 'default';
    }

    public function isHealthy(): bool
    {
        try {
            $testKey = '__health_check_' . time();
            $testValue = 'health_test';
            if (!$this->set($testKey, $testValue, 60)) {
                return false;
            }
            $retrieved = $this->get($testKey);
            $this->delete($testKey);

            return $retrieved === $testValue;
        } catch (\Throwable $e) {
            $this->logger->error('Health check failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    public function maintenance(array $options = []): bool
    {
        try {
            $this->logger->info('Starting cache maintenance', $options);
            $result = $this->layeredCache->maintenance($options);
            $this->logger->info('Cache maintenance completed', ['success' => $result]);

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Cache maintenance failed', ['error' => $e->getMessage(), 'options' => $options]);

            return false;
        }
    }

    public function advanced(): CacheItemPool
    {
        return new AdvancedCacheProxy($this->layeredCache, $this->serializer);
    }

    private function getValueFromCache(string $key): mixed
    {
        $item = $this->layeredCache->getItem($key);

        if (!$item->isHit()) {
            return null;
        }

        $this->handleSlidingExpiration($item);

        $rawValue = $item->get();
        if (!is_string($rawValue)) {
            throw CacheException::deserializationFailed($key, new \UnexpectedValueException('Cached value is not a string.'));
        }

        return $this->serializer->unserialize($rawValue);
    }

    private function validateInstanceConfiguration(): void
    {
        if (isset($this->config['name']) && !is_string($this->config['name'])) {
            throw InvalidArgumentException::invalidConfiguration('Cache "name" must be a string');
        }
    }

    private function calculateExpiration(\DateInterval|int|null $ttl): ?\DateTimeInterface
    {
        if ($ttl instanceof \DateInterval) {
            return (new \DateTimeImmutable())->add($ttl);
        }
        if (is_int($ttl) && $ttl > 0) {
            return new \DateTimeImmutable('+' . $ttl . ' seconds');
        }

        return null;
    }

    private function handleSlidingExpiration(CacheItemInterface $item): void
    {
        $slidingTtl = $this->config['sliding_ttl'] ?? null;
        if (($slidingTtl instanceof \DateInterval || is_int($slidingTtl)) && $item->isHit()) {
            $newExpiration = $this->calculateExpiration($slidingTtl);
            if ($newExpiration) {
                $item->expiresAt($newExpiration);
                $this->layeredCache->save($item);
            }
        }
    }

    private function normalizeTtlToSeconds(\DateInterval|int|null $ttl): ?int
    {
        if (is_int($ttl)) {
            return $ttl;
        }
        if ($ttl instanceof \DateInterval) {
            return (new \DateTimeImmutable())->add($ttl)->getTimestamp() - time();
        }

        return null;
    }

    private function dispatchCacheHit(string $key, mixed $value, float $duration): void
    {
        if ($this->eventDispatcher) {
            $this->eventDispatcher->dispatch(new CacheHitEvent($key, $value, $duration));
        }
    }

    private function dispatchCacheMiss(string $key): void
    {
        if ($this->eventDispatcher) {
            $this->eventDispatcher->dispatch(new CacheMissEvent($key));
        }
    }

    private function dispatchCacheSet(string $key, mixed $value, \DateInterval|int|null $ttl, float $duration): void
    {
        if ($this->eventDispatcher) {
            $this->eventDispatcher->dispatch(new CacheSetEvent($key, $value, $ttl, [], $duration));
        }
    }

    private function dispatchCacheDelete(string $key, bool $existed, float $duration): void
    {
        if ($this->eventDispatcher) {
            $this->eventDispatcher->dispatch(new CacheDeleteEvent($key, $existed, $duration));
        }
    }

    private function dispatchCacheClear(float $duration): void
    {
        if ($this->eventDispatcher) {
            $this->eventDispatcher->dispatch(new CacheClearEvent(null, $duration));
        }
    }
}
