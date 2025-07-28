<?php

declare(strict_types=1);

namespace KaririCode\Cache\Operation;

use KaririCode\Cache\Config\CacheConfig;
use KaririCode\Cache\Contract\Behavioral\Maintainable;
use KaririCode\Cache\Contract\CacheItem;
use KaririCode\Cache\Contract\CacheSerializer;
use KaririCode\Cache\Contract\LayeredCache as LayeredCacheContract;
use KaririCode\Cache\Contract\MetricsCollector as MetricsCollectorContract;
use KaririCode\Cache\Contract\StampedeProtector as StampedeProtectorContract;
use KaririCode\Cache\Event\CacheMissEvent;
use KaririCode\Cache\Event\CacheWarmupEvent;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Item\AdvancedCacheProxy;
use KaririCode\Cache\Metrics\CacheMetrics;
use KaririCode\Cache\Metrics\MetricsCollector;
use KaririCode\Cache\Protection\StampedeProtector;
use KaririCode\Cache\Serializer\NativeSerializer;
use KaririCode\Cache\ValueObject\Tag;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * Handles advanced cache operations (callbacks, atomic operations, metrics).
 * Follows SRP by focusing only on advanced features.
 */
final class AdvancedCacheOperations
{
    private readonly CacheSerializer $serializer;
    private readonly StampedeProtectorContract $stampedeProtector;
    private MetricsCollectorContract $metricsCollector;

    /**
     * @param LayeredCacheContract $layeredCache the underlying layered cache pool
     * @param CacheSerializer|null $serializer the serializer for cache values
     * @param LoggerInterface $logger a PSR-3 logger instance
     * @param EventDispatcherInterface|null $eventDispatcher a PSR-14 event dispatcher
     * @param CacheConfig $config the cache configuration
     */
    public function __construct(
        private readonly LayeredCacheContract $layeredCache,
        ?CacheSerializer $serializer,
        private readonly LoggerInterface $logger,
        private readonly ?EventDispatcherInterface $eventDispatcher,
        CacheConfig $config
    ) {
        $this->serializer = $serializer ?? new NativeSerializer();
        $this->metricsCollector = new MetricsCollector();

        $features = $config->getFeatureConfig();

        $this->stampedeProtector = new StampedeProtector(
            $features->stampedeProtection,
            $config->get('stampede_lock_timeout', 10),
            $config->get('stampede_lock_retry_delay', 100)
        );
    }

    /**
     * @param Tag[] $tags
     *
     * @throws CacheException
     */
    public function getWithCallback(string $key, callable $callback, \DateInterval|int|null $ttl = null, array $tags = []): mixed
    {
        try {
            $item = $this->layeredCache->getItem($key);

            if ($item->isHit()) {
                $startTime = microtime(true);
                $value = $this->deserialize($item->get());
                $this->metricsCollector = $this->metricsCollector->recordHit($key, microtime(true) - $startTime);

                return $value;
            }

            $this->metricsCollector = $this->metricsCollector->recordMiss($key);
            $startTime = microtime(true);

            $value = $this->stampedeProtector->get($key, $callback, $ttl);

            $this->metricsCollector = $this->metricsCollector->recordGeneration($key, microtime(true) - $startTime);

            $serialized = $this->serialize($value);
            $item->set($serialized);

            if (null !== $ttl) {
                $item->expiresAfter($ttl);
            }

            if (!empty($tags) && $item instanceof CacheItem) {
                $item->tag($tags);
            }

            $this->layeredCache->save($item);

            $this->dispatchMissWithCallbackEvent($key);

            return $value;
        } catch (\Throwable $e) {
            $this->logger->error('Cache getWithCallback operation failed', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            throw CacheException::operationFailed('getWithCallback', $key, $e);
        }
    }

    /**
     * @throws CacheException
     */
    public function increment(string $key, int $step = 1, int $initialValue = 0, \DateInterval|int|null $ttl = null): int
    {
        try {
            $startTime = microtime(true);
            $value = $this->layeredCache->increment($key, $step, $initialValue, $ttl);
            $this->metricsCollector = $this->metricsCollector->recordGeneration($key, microtime(true) - $startTime);

            return $value;
        } catch (\Throwable $e) {
            $this->logger->error('Cache increment operation failed', [
                'key' => $key,
                'step' => $step,
                'error' => $e->getMessage(),
            ]);
            throw CacheException::operationFailed('increment', $key, $e);
        }
    }

    /**
     * @throws CacheException
     */
    public function decrement(string $key, int $step = 1, int $initialValue = 0, \DateInterval|int|null $ttl = null): int
    {
        try {
            $startTime = microtime(true);
            $value = $this->layeredCache->decrement($key, $step, $initialValue, $ttl);
            $this->metricsCollector = $this->metricsCollector->recordGeneration($key, microtime(true) - $startTime);

            return $value;
        } catch (\Throwable $e) {
            $this->logger->error('Cache decrement operation failed', [
                'key' => $key,
                'step' => $step,
                'error' => $e->getMessage(),
            ]);
            throw CacheException::operationFailed('decrement', $key, $e);
        }
    }

    /**
     * @throws CacheException
     */
    public function add(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        try {
            $startTime = microtime(true);
            $serialized = $this->serialize($value);
            $result = $this->layeredCache->add($key, $serialized, $ttl);
            if ($result) {
                $this->metricsCollector = $this->metricsCollector->recordGeneration($key, microtime(true) - $startTime);
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Cache add operation failed', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            throw CacheException::operationFailed('add', $key, $e);
        }
    }

    /**
     * @throws CacheException
     */
    public function compareAndSwap(string $key, mixed $expected, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        try {
            $startTime = microtime(true);
            $serializedExpected = $this->serialize($expected);
            $serializedValue = $this->serialize($value);

            $result = $this->layeredCache->compareAndSwap($key, $serializedExpected, $serializedValue, $ttl);

            if ($result) {
                $this->metricsCollector = $this->metricsCollector
                    ->recordGeneration($key, microtime(true) - $startTime);
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Cache compareAndSwap operation failed', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            throw CacheException::operationFailed('compareAndSwap', $key, $e);
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws CacheException
     */
    public function warmup(array $data, \DateInterval|int|null $ttl = null): void
    {
        try {
            $this->dispatchWarmupEvent($data, $ttl);

            $serializedData = [];
            foreach ($data as $key => $value) {
                $serializedData[$key] = $this->serialize($value);
            }

            $items = $this->layeredCache->getItems(array_keys($serializedData));
            foreach ($items as $item) {
                if (isset($serializedData[$item->getKey()])) {
                    $item->set($serializedData[$item->getKey()]);
                    if (null !== $ttl) {
                        $item->expiresAfter($ttl);
                    }
                    $this->layeredCache->saveDeferred($item);
                }
            }
            $this->layeredCache->commit();
        } catch (\Throwable $e) {
            $this->logger->error('Cache warmup operation failed', ['error' => $e->getMessage()]);
            throw CacheException::operationFailed('warmup', 'multiple', $e);
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    public function maintenance(array $options = []): bool
    {
        $overallSuccess = true;
        try {
            foreach ($this->layeredCache->getLayers() as $layer) {
                if ($layer instanceof Maintainable) {
                    if (!$layer->maintenance($options)) {
                        $overallSuccess = false;
                        $this->logger->warning('Maintenance failed for a cache layer.', [
                            'layer' => get_class($layer),
                            'options' => $options,
                        ]);
                    }
                }
            }

            if ($overallSuccess) {
                $this->logger->info('Cache maintenance completed successfully for all applicable layers.', ['options' => $options]);
            }

            return $overallSuccess;
        } catch (\Throwable $e) {
            $this->logger->error('An unexpected error occurred during cache maintenance', [
                'error' => $e->getMessage(),
                'options' => $options,
            ]);

            return false;
        }
    }

    public function getMetrics(): CacheMetrics
    {
        return new CacheMetrics($this->metricsCollector->getMetrics());
    }

    /**
     * @return array<string, mixed>
     */
    public function getHealthCheck(): array
    {
        return [
            'status' => 'healthy',
            'layers' => count($this->layeredCache->getLayers()),
            'metrics' => $this->getMetrics()->toArray(),
            'stampede_protection' => $this->stampedeProtector->isEnabled(),
            'timestamp' => time(),
        ];
    }

    public function getItemPool(): \Psr\Cache\CacheItemPoolInterface
    {
        return new AdvancedCacheProxy($this->layeredCache, $this->serializer);
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

    private function dispatchMissWithCallbackEvent(string $key): void
    {
        if ($this->eventDispatcher) {
            $this->eventDispatcher->dispatch(new CacheMissEvent($key, true));
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function dispatchWarmupEvent(array $data, \DateInterval|int|null $ttl): void
    {
        if ($this->eventDispatcher) {
            $this->eventDispatcher->dispatch(new CacheWarmupEvent($data, $ttl));
        }
    }
}
