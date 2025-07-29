<?php

declare(strict_types=1);

namespace KaririCode\Cache\Event\Listener;

use KaririCode\Cache\Contract\CacheEventListener;
use KaririCode\Cache\Contract\Structural\EventDispatcherAware;
use KaririCode\Cache\Event\DTO\AnalyticsData;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * An essential and functional event listener for cache analytics.
 * This class uses a dedicated DTO for type-safe metrics collection.
 */
final class AnalyticsEventListener implements CacheEventListener, EventDispatcherAware
{
    private AnalyticsData $operations;

    /**
     * @var array<string, array{count: int, last_access: float}>
     */
    private array $accessFrequency = [];

    private EventDispatcherInterface $eventDispatcher;

    public function __construct(private readonly int $maxKeysToTrack = 10000)
    {
        $this->initializeAnalytics();
    }

    public function setEventDispatcher(EventDispatcherInterface $eventDispatcher): void
    {
        $this->eventDispatcher = $eventDispatcher;
    }

    public function getEventDispatcher(): EventDispatcherInterface
    {
        return $this->eventDispatcher;
    }

    public function hasEventDispatcher(): bool
    {
        return null !== $this->eventDispatcher;
    }

    public function onCacheHit(string $key, mixed $value): void
    {
        ++$this->operations->hits;
        $this->updateAccessFrequency($key);
    }

    public function onCacheMiss(string $key): void
    {
        ++$this->operations->misses;
        $this->updateAccessFrequency($key);
    }

    public function onCacheSet(string $key, mixed $value): void
    {
        ++$this->operations->sets;
        $this->updateAccessFrequency($key);
    }

    public function onCacheDelete(string $key): void
    {
        ++$this->operations->deletes;
        unset($this->accessFrequency[$key]);
    }

    public function onCacheClear(): void
    {
        ++$this->operations->clears;
        $this->accessFrequency = [];
    }

    /**
     * @return array<string, int|float>
     */
    public function getStats(): array
    {
        $totalLookups = $this->operations->hits + $this->operations->misses;

        return [
            'hits' => $this->operations->hits,
            'misses' => $this->operations->misses,
            'sets' => $this->operations->sets,
            'deletes' => $this->operations->deletes,
            'clears' => $this->operations->clears,
            'total_operations' => $this->operations->total(),
            'hit_rate' => $totalLookups > 0 ? round($this->operations->hits / $totalLookups, 4) : 0.0,
            'unique_keys_tracked' => count($this->accessFrequency),
        ];
    }

    private function initializeAnalytics(): void
    {
        $this->operations = new AnalyticsData();
        $this->accessFrequency = [];
    }

    private function updateAccessFrequency(string $key): void
    {
        if (!isset($this->accessFrequency[$key])) {
            if (count($this->accessFrequency) >= $this->maxKeysToTrack) {
                asort($this->accessFrequency); // Sort by last_access timestamp (oldest first)
                array_shift($this->accessFrequency);
            }
            $this->accessFrequency[$key] = ['count' => 0, 'last_access' => 0.0];
        }

        ++$this->accessFrequency[$key]['count'];
        $this->accessFrequency[$key]['last_access'] = microtime(true);
    }
}
