<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Layered;

use KaririCode\Cache\Contract\Behavioral\TopicSubscribable;
use KaririCode\Cache\Contract\TopicAwareCacheDriver;
use KaririCode\Cache\Driver\LayeredDriver;

/**
 * Handles topic-based operations for a layered cache.
 *
 * This class orchestrates topic operations across multiple cache layers that
 * support this capability. It relies on the main LayeredDriver to manage the
 * health and availability of each layer via the circuit breaker pattern.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class LayeredTopicOperations implements TopicSubscribable
{
    /**
     * @param array<int, TopicAwareCacheDriver> $layers the layers that support topics
     * @param LayeredDriver $orchestrator the main driver that manages layer state
     */
    public function __construct(
        private readonly array $layers,
        private readonly LayeredDriver $orchestrator
    ) {
    }

    public function subscribeTo(string $key, iterable $topics): void
    {
        foreach (array_keys($this->layers) as $priority) {
            if (!$this->orchestrator->isLayerAvailable($priority)) {
                continue;
            }

            try {
                $this->layers[$priority]->subscribeTo($key, $topics);
                $this->orchestrator->recordSuccess($priority);
            } catch (\Throwable $e) {
                $this->orchestrator->handleFailure($priority, $e, 'subscribeTo', $key);
            }
        }
    }

    public function unsubscribeFrom(string $key, iterable $topics): void
    {
        foreach (array_keys($this->layers) as $priority) {
            if (!$this->orchestrator->isLayerAvailable($priority)) {
                continue;
            }

            try {
                $this->layers[$priority]->unsubscribeFrom($key, $topics);
                $this->orchestrator->recordSuccess($priority);
            } catch (\Throwable $e) {
                $this->orchestrator->handleFailure($priority, $e, 'unsubscribeFrom', $key);
            }
        }
    }

    public function invalidateTopics(iterable $topics): bool
    {
        $overallSuccess = true;
        foreach (array_keys($this->layers) as $priority) {
            if (!$this->orchestrator->isLayerAvailable($priority)) {
                continue;
            }

            try {
                if (false === $this->layers[$priority]->invalidateTopics($topics)) {
                    $overallSuccess = false;
                } else {
                    $this->orchestrator->recordSuccess($priority);
                }
            } catch (\Throwable $e) {
                $this->orchestrator->handleFailure($priority, $e, 'invalidateTopics');
                $overallSuccess = false;
            }
        }

        return $overallSuccess;
    }

    public function getSubscribedTopics(string $key): array
    {
        $allTopics = [];
        foreach (array_keys($this->layers) as $priority) {
            if (!$this->orchestrator->isLayerAvailable($priority)) {
                continue;
            }

            try {
                $allTopics = array_merge($allTopics, $this->layers[$priority]->getSubscribedTopics($key));
                $this->orchestrator->recordSuccess($priority);
            } catch (\Throwable $e) {
                $this->orchestrator->handleFailure($priority, $e, 'getSubscribedTopics', $key);
            }
        }

        return array_unique($allTopics);
    }

    public function getSubscribersForTopic(string $topic): array
    {
        $allSubscribers = [];
        foreach (array_keys($this->layers) as $priority) {
            if (!$this->orchestrator->isLayerAvailable($priority)) {
                continue;
            }

            try {
                $allSubscribers = array_merge($allSubscribers, $this->layers[$priority]->getSubscribersForTopic($topic));
                $this->orchestrator->recordSuccess($priority);
            } catch (\Throwable $e) {
                $this->orchestrator->handleFailure($priority, $e, 'getSubscribersForTopic', $topic);
            }
        }

        return array_unique($allSubscribers);
    }
}
