<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Layered;

use KaririCode\Cache\Contract\TaggableCache;
use KaririCode\Cache\Driver\LayeredDriver;

/**
 * Handles tag-based operations for a layered cache.
 *
 * This class orchestrates tag operations across multiple cache layers that
 * support tagging. It relies on the main LayeredDriver to manage the health
 * and availability of each layer via the circuit breaker pattern.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class LayeredTagOperations implements TaggableCache
{
    /**
     * @param array<int, TaggableCache> $layers the layers that support tagging
     * @param LayeredDriver $orchestrator the main driver that manages layer state
     */
    public function __construct(
        private readonly array $layers,
        private readonly LayeredDriver $orchestrator
    ) {
    }

    public function invalidateTags(iterable $tags): bool
    {
        $overallSuccess = true;
        foreach (array_keys($this->layers) as $priority) {
            if (!$this->orchestrator->isLayerAvailable($priority)) {
                continue;
            }

            try {
                $layer = $this->layers[$priority];
                if (false === $layer->invalidateTags($tags)) {
                    $overallSuccess = false;
                } else {
                    $this->orchestrator->recordSuccess($priority);
                }
            } catch (\Throwable $e) {
                $this->orchestrator->handleFailure($priority, $e, 'invalidateTags');
                $overallSuccess = false;
            }
        }

        return $overallSuccess;
    }

    public function getByTags(iterable $tags): iterable
    {
        $results = [];
        foreach (array_keys($this->layers) as $priority) {
            if (!$this->orchestrator->isLayerAvailable($priority)) {
                continue;
            }

            try {
                $layer = $this->layers[$priority];
                foreach ($layer->getByTags($tags) as $key => $value) {
                    // Aggregate results, giving priority to faster layers
                    if (!isset($results[$key])) {
                        $results[$key] = $value;
                    }
                }
                $this->orchestrator->recordSuccess($priority);
            } catch (\Throwable $e) {
                $this->orchestrator->handleFailure($priority, $e, 'getByTags');
            }
        }

        return $results;
    }

    public function getTags(): iterable
    {
        $allTags = [];
        foreach (array_keys($this->layers) as $priority) {
            if (!$this->orchestrator->isLayerAvailable($priority)) {
                continue;
            }

            try {
                $layer = $this->layers[$priority];
                foreach ($layer->getTags() as $tag) {
                    $allTags[(string) $tag] = true;
                }
                $this->orchestrator->recordSuccess($priority);
            } catch (\Throwable $e) {
                $this->orchestrator->handleFailure($priority, $e, 'getTags');
            }
        }

        return array_keys($allTags);
    }

    public function setWithTags(string $key, mixed $value, iterable $tags = []): bool
    {
        // This operation is handled by the main LayeredDriver's `save` method,
        // which correctly propagates a tagged CacheItem to all layers.
        // This handler is primarily for tag-specific read/invalidation operations.
        // We can delegate to the orchestrator's PSR-6 implementation.
        $item = new \KaririCode\Cache\Item\CacheItem($key, $value);
        $item->tag($tags);

        return $this->orchestrator->save($item);
    }
}
