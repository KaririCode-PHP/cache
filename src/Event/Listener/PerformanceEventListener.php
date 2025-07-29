<?php

declare(strict_types=1);

namespace KaririCode\Cache\Event\Listener;

use KaririCode\Cache\Contract\CacheEventListener as CacheEventListenerContract;
use KaririCode\Cache\Contract\Structural\EventDispatcherAware;
use KaririCode\Cache\Event\CachePerformanceEvent;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * An event listener focused on collecting and analyzing cache performance metrics.
 *
 * This class adheres to the Single Responsibility Principle (SRP) by focusing solely
 * on performance monitoring. It tracks operation timings, detects slow operations,
 * and provides key statistics for observability.
 */
final class PerformanceEventListener implements CacheEventListenerContract, EventDispatcherAware
{
    /**
     * @var array<string, array<int, float>>
     */
    private array $operationTimings = [];

    /**
     * @var array<int, array{operation: string, key: string, duration: float, timestamp: float}>
     */
    private array $slowOperations = [];

    private EventDispatcherInterface $eventDispatcher;

    public function __construct(
        private readonly float $slowThreshold = 0.1, // Operations slower than 100ms are flagged.
        private readonly int $maxHistorySize = 1000
    ) {
        $this->initializeMetrics();
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
        // This listener doesn't need the start time, as the event itself should contain the duration.
        // For simplicity, we assume the processing time is negligible for the listener itself.
        // In a real-world scenario, the event object (e.g., CacheHitEvent) would carry the duration.
    }

    public function onCacheMiss(string $key): void
    {
        // Similar to onCacheHit, logic would depend on data from the event object.
    }

    public function onCacheSet(string $key, mixed $value): void
    {
        // Logic for 'set' operation performance tracking.
    }

    public function onCacheDelete(string $key): void
    {
        // Logic for 'delete' operation performance tracking.
    }

    public function onCacheClear(): void
    {
        $this->recordPerformanceMetric('clear', '*', 0.0); // Duration would come from the event
    }

    /**
     * Gets a summary of the collected performance statistics.
     *
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        $totalOps = 0;
        $totalTime = 0.0;
        $statsByOp = [];

        foreach ($this->operationTimings as $operation => $timings) {
            $count = count($timings);
            $sum = array_sum($timings);

            $totalOps += $count;
            $totalTime += $sum;

            $statsByOp[$operation] = [
                'count' => $count,
                'total_time' => $sum,
                'avg_time' => $count > 0 ? $sum / $count : 0.0,
            ];
        }

        return [
            'total_operations' => $totalOps,
            'average_time_all' => $totalOps > 0 ? $totalTime / $totalOps : 0.0,
            'slow_operations_count' => count($this->slowOperations),
            'slow_operations_threshold' => $this->slowThreshold,
            'operations' => $statsByOp,
        ];
    }

    /**
     * Records a performance metric for a given cache operation.
     * This method is intended to be called by an event dispatcher.
     */
    public function recordPerformanceMetric(string $operation, string $key, float $duration): void
    {
        if (!isset($this->operationTimings[$operation])) {
            $this->operationTimings[$operation] = [];
        }

        $this->operationTimings[$operation][] = $duration;

        if ($duration > $this->slowThreshold) {
            $this->recordSlowOperation($operation, $key, $duration);
        }

        if (count($this->operationTimings[$operation]) > $this->maxHistorySize) {
            array_shift($this->operationTimings[$operation]);
        }

        $this->eventDispatcher->dispatch(new CachePerformanceEvent($operation, $key, $duration));
    }

    private function recordSlowOperation(string $operation, string $key, float $duration): void
    {
        $this->slowOperations[] = [
            'operation' => $operation,
            'key' => $key,
            'duration' => $duration,
            'timestamp' => microtime(true),
        ];

        if (count($this->slowOperations) > $this->maxHistorySize) {
            array_shift($this->slowOperations);
        }
    }

    private function initializeMetrics(): void
    {
        $this->operationTimings = [
            'hit' => [],
            'miss' => [],
            'set' => [],
            'delete' => [],
            'clear' => [],
        ];
        $this->slowOperations = [];
    }
}
