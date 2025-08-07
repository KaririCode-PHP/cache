<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Layered;

use KaririCode\Cache\Contract\Behavioral\AtomicOperable;
use KaririCode\Cache\Contract\Behavioral\CompareAndSwapAware;
use KaririCode\Cache\Driver\LayeredDriver;
use KaririCode\Cache\Item\CacheItem;

/**
 * Handles atomic operations for a layered cache.
 *
 * This class orchestrates atomic operations across multiple cache layers that
 * support them. It executes the operation on the first available, capable layer
 * and then propagates the result to all other layers to ensure data consistency.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class LayeredAtomicOperations implements AtomicOperable, CompareAndSwapAware
{
    /**
     * @param array<int, AtomicOperable&CompareAndSwapAware> $layers the layers that support atomic operations
     * @param LayeredDriver $orchestrator the main driver that manages layer state and write propagation
     */
    public function __construct(
        private readonly array $layers,
        private readonly LayeredDriver $orchestrator
    ) {
    }

    public function increment(string $key, int $step = 1, int $initialValue = 0): int
    {
        foreach (array_keys($this->layers) as $priority) {
            if (!$this->orchestrator->isLayerAvailable($priority)) {
                continue;
            }

            try {
                $layer = $this->layers[$priority];
                $newValue = $layer->increment($key, $step, $initialValue);

                // On success, propagate the new value to all other layers.
                $this->orchestrator->propagateValueToAllLayers($key, $newValue, $priority);
                $this->orchestrator->recordSuccess($priority);

                return $newValue;
            } catch (\Throwable $e) {
                $this->orchestrator->handleFailure($priority, $e, 'increment', $key);
            }
        }

        // Fallback to non-atomic operation if all capable layers fail.
        return $this->orchestrator->nonAtomicIncrement($key, $step, $initialValue);
    }

    public function decrement(string $key, int $step = 1, int $initialValue = 0): int
    {
        return $this->increment($key, -$step, $initialValue);
    }

    public function add(string $key, mixed $value): bool
    {
        // 'add' must be atomic. We attempt it on the first available layer.
        // If it succeeds, we propagate the set to all other layers.
        foreach (array_keys($this->layers) as $priority) {
            if (!$this->orchestrator->isLayerAvailable($priority)) {
                continue;
            }

            try {
                $layer = $this->layers[$priority];
                if ($layer->add($key, $value)) {
                    // Success means the key did not exist. Propagate this 'set' everywhere.
                    $this->orchestrator->propagateValueToAllLayers($key, $value, $priority);
                    $this->orchestrator->recordSuccess($priority);

                    return true;
                } else {
                    // The key already existed in this layer, which is a valid outcome for 'add'.
                    $this->orchestrator->recordSuccess($priority);

                    return false;
                }
            } catch (\Throwable $e) {
                $this->orchestrator->handleFailure($priority, $e, 'add', $key);
            }
        }

        // If all atomic-capable layers fail, we cannot guarantee atomicity.
        // Fallback to a simple check-then-set, which is not atomic.
        if (!$this->orchestrator->hasItem($key)) {
            return $this->orchestrator->save(new CacheItem($key, $value));
        }

        return false;
    }

    public function compareAndSwap(string $key, mixed $expected, mixed $value): bool
    {
        // Similar to 'add', we try on the first available layer.
        foreach (array_keys($this->layers) as $priority) {
            if (!$this->orchestrator->isLayerAvailable($priority)) {
                continue;
            }

            try {
                $layer = $this->layers[$priority];
                $swapped = $layer->compareAndSwap($key, $expected, $value);

                if ($swapped) {
                    // On successful swap, propagate the new value everywhere.
                    $this->orchestrator->propagateValueToAllLayers($key, $value, $priority);
                }

                $this->orchestrator->recordSuccess($priority);

                return $swapped;
            } catch (\Throwable $e) {
                $this->orchestrator->handleFailure($priority, $e, 'compareAndSwap', $key);
            }
        }

        // If all capable layers fail, the operation fails.
        return false;
    }
}
