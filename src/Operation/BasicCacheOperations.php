<?php

declare(strict_types=1);

namespace KaririCode\Cache\Operation;

use KaririCode\Cache\Contract\CacheSerializer;
use KaririCode\Cache\Contract\LayeredCache;
use KaririCode\Cache\Event\CacheClearEvent;
use KaririCode\Cache\Event\CacheDeleteEvent;
use KaririCode\Cache\Event\CacheHitEvent;
use KaririCode\Cache\Event\CacheMissEvent;
use KaririCode\Cache\Event\CacheSetEvent;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Metrics\MetricsCollector;
use KaririCode\Cache\Serializer\NativeSerializer;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * Handles basic cache operations (get, set, delete, clear, has).
 * Follows SRP by focusing only on fundamental CRUD-like operations.
 */
final class BasicCacheOperations
{
    private readonly CacheSerializer $serializer;
    private MetricsCollector $metricsCollector;

    /**
     * @param LayeredCache $layeredCache the cache pool to operate on
     * @param CacheSerializer|null $serializer the serializer for values
     * @param LoggerInterface $logger a PSR-3 logger instance
     * @param EventDispatcherInterface|null $eventDispatcher a PSR-14 event dispatcher
     */
    public function __construct(
        private readonly LayeredCache $layeredCache,
        ?CacheSerializer $serializer,
        private readonly LoggerInterface $logger,
        private readonly ?EventDispatcherInterface $eventDispatcher
    ) {
        $this->serializer = $serializer ?? new NativeSerializer();
        $this->metricsCollector = new MetricsCollector();
    }

    /**
     * Retrieves an item from the cache.
     *
     * @param string $key the unique key for the item
     * @param mixed|null $default the default value to return if the item is not found
     *
     * @throws CacheException if the operation fails
     *
     * @return mixed the value of the item from the cache, or $default if not found
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $startTime = microtime(true);

        try {
            $item = $this->layeredCache->getItem($key);

            if (!$item->isHit()) {
                $this->dispatchMissEvent($key);
                $this->metricsCollector = $this->metricsCollector->recordMiss($key);

                return $default;
            }

            $value = $this->deserialize($item->get());
            $retrievalTime = microtime(true) - $startTime;

            $this->dispatchHitEvent($key, $value, $retrievalTime);
            $this->metricsCollector = $this->metricsCollector->recordHit($key, $retrievalTime);

            return $value;
        } catch (\Throwable $e) {
            $this->logger->error('Cache get operation failed', ['key' => $key, 'error' => $e->getMessage()]);
            throw CacheException::operationFailed('get', $key, $e);
        }
    }

    /**
     * Persists data in the cache.
     *
     * @param string $key the key of the item to store
     * @param mixed $value the value of the item to store
     * @param \DateInterval|int|null $ttl the TTL for this item
     *
     * @throws CacheException if the operation fails
     *
     * @return bool true on success, false on failure
     */
    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $startTime = microtime(true);

        try {
            $serialized = $this->serialize($value);
            $item = $this->layeredCache->getItem($key);
            $item->set($serialized);

            if (null !== $ttl) {
                $item->expiresAfter($ttl);
            }

            $result = $this->layeredCache->save($item);

            if ($result) {
                $duration = microtime(true) - $startTime;
                $this->dispatchSetEvent($key, $value, $ttl, $duration);
                $this->metricsCollector = $this->metricsCollector->recordGeneration($key, $duration);
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Cache set operation failed', ['key' => $key, 'error' => $e->getMessage()]);
            throw CacheException::operationFailed('set', $key, $e);
        }
    }

    /**
     * Removes an item from the cache.
     *
     * @param string $key the key of the item to delete
     *
     * @throws CacheException if the operation fails
     *
     * @return bool True if the item was successfully removed. False if there was an error.
     */
    public function delete(string $key): bool
    {
        $startTime = microtime(true);

        try {
            $existed = $this->layeredCache->hasItem($key);
            $result = $this->layeredCache->deleteItem($key);

            if ($result) {
                $duration = microtime(true) - $startTime;
                $this->dispatchDeleteEvent($key, $existed, $duration);
                $this->metricsCollector = $this->metricsCollector->recordGeneration($key, $duration);
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Cache delete operation failed', ['key' => $key, 'error' => $e->getMessage()]);
            throw CacheException::operationFailed('delete', $key, $e);
        }
    }

    /**
     * Wipes all items from the cache.
     *
     * @throws CacheException if the operation fails
     *
     * @return bool true on success, false on failure
     */
    public function clear(): bool
    {
        $startTime = microtime(true);

        try {
            $result = $this->layeredCache->clear();

            if ($result) {
                $duration = microtime(true) - $startTime;
                $this->dispatchClearEvent($duration);
                $this->metricsCollector = $this->metricsCollector->recordClear();
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Cache clear operation failed', ['error' => $e->getMessage()]);
            throw CacheException::operationFailed('clear', '*', $e);
        }
    }

    /**
     * Determines whether an item is present in the cache.
     *
     * @param string $key the cache item key
     */
    public function has(string $key): bool
    {
        try {
            return $this->layeredCache->hasItem($key);
        } catch (\Throwable $e) {
            $this->logger->error('Cache has operation failed', ['key' => $key, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Obtains multiple cache items by their unique keys.
     *
     * @param string[] $keys a list of keys that can be obtained in a single operation
     * @param mixed|null $default default value to return for keys that are not found
     *
     * @return array<string, mixed> a map of key => value pairs
     */
    public function getMultiple(array $keys, mixed $default = null): iterable
    {
        $results = [];
        foreach ($keys as $key) {
            $results[$key] = $this->get($key, $default);
        }

        return $results;
    }

    /**
     * Persists a set of key => value pairs in the cache.
     *
     * @param iterable<string, mixed> $values a list of key => value pairs for a multiple-set operation
     * @param \DateInterval|int|null $ttl the TTL for this set of items
     *
     * @return bool true on success, false on failure
     */
    public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
    {
        $success = true;
        foreach ($values as $key => $value) {
            if (!$this->set((string) $key, $value, $ttl)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Deletes multiple cache items in a single operation.
     *
     * @param string[] $keys the list of string-based keys to be deleted
     *
     * @return bool True if all items were successfully removed. False if there was an error.
     */
    public function deleteMultiple(array $keys): bool
    {
        $success = true;
        foreach ($keys as $key) {
            if (!$this->delete($key)) {
                $success = false;
            }
        }

        return $success;
    }

    public function getMetricsCollector(): MetricsCollector
    {
        return $this->metricsCollector;
    }

    private function serialize(mixed $value): string
    {
        return $this->serializer->serialize($value);
    }

    private function deserialize(mixed $data): mixed
    {
        if (!is_string($data)) {
            return $data;
        }

        return $this->serializer->unserialize($data);
    }

    private function dispatchHitEvent(string $key, mixed $value, float $retrievalTime): void
    {
        if ($this->eventDispatcher) {
            $this->eventDispatcher->dispatch(new CacheHitEvent($key, $value, $retrievalTime));
        }
    }

    private function dispatchMissEvent(string $key): void
    {
        if ($this->eventDispatcher) {
            $this->eventDispatcher->dispatch(new CacheMissEvent($key));
        }
    }

    private function dispatchSetEvent(string $key, mixed $value, \DateInterval|int|null $ttl, float $duration): void
    {
        if ($this->eventDispatcher) {
            $this->eventDispatcher->dispatch(new CacheSetEvent($key, $value, $ttl, [], $duration));
        }
    }

    private function dispatchDeleteEvent(string $key, bool $existed, float $duration): void
    {
        if ($this->eventDispatcher) {
            $this->eventDispatcher->dispatch(new CacheDeleteEvent($key, $existed, $duration));
        }
    }

    private function dispatchClearEvent(float $duration): void
    {
        if ($this->eventDispatcher) {
            $this->eventDispatcher->dispatch(new CacheClearEvent(null, $duration));
        }
    }
}
