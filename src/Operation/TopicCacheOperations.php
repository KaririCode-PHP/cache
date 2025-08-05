<?php

declare(strict_types=1);

namespace KaririCode\Cache\Operation;

use KaririCode\Cache\Contract\LayeredCache as LayeredCacheContract;
use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Event\TopicInvalidationEvent;
use KaririCode\Cache\Event\TopicSubscriptionEvent;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Cache\Validation\Trait\CacheKeyValidationTrait;
use KaririCode\Cache\ValueObject\Topic;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Enhanced TopicCacheOperations with proper TTL/expiration handling.
 *
 * This class handles cache operations related to topic subscriptions while
 * maintaining consistent TTL/expiration behavior. It follows Clean Architecture
 * principles and implements the Observer pattern for pub/sub functionality.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 * @see       Topic for topic value objects
 * @see       ExpirationProvider for TTL management strategy
 */
final class TopicCacheOperations
{
    use CacheKeyValidationTrait;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly LayeredCacheContract $layeredCache,
        private readonly ExpirationProvider $expirationProvider,
        ?LoggerInterface $logger = null,
        private readonly ?EventDispatcherInterface $eventDispatcher = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    // ========================================================================
    // Topic Subscription Operations
    // ========================================================================

    /**
     * Subscribes a cache key to one or more topics. The expiration is handled
     * implicitly by the configured ExpirationProvider strategy.
     *
     * @param string $key The cache item key
     * @param iterable<string|Topic> $topics Topics to subscribe to
     */
    public function subscribeTo(string $key, iterable $topics): void
    {
        $this->validateKey($key);
        $startTime = microtime(true);

        try {
            $normalizedTopics = $this->normalizeTopics($topics);
            if (empty($normalizedTopics)) {
                $this->logger->debug('Topic subscription skipped - no topics provided', ['key' => $key]);

                return;
            }

            // A chamada para a camada inferior agora inclui a expiração calculada.
            $this->layeredCache->subscribeTo($key, $normalizedTopics);

            $operationTime = microtime(true) - $startTime;
            $this->dispatchSubscriptionEvent($key, $normalizedTopics, $operationTime);

            $this->logger->debug('Topic subscription successful', [
                'key' => $key,
                'topics' => $this->formatTopicsForLogging($normalizedTopics),
                'operation_time_ms' => round($operationTime * 1000, 2),
            ]);
        } catch (\Throwable $exception) {
            $this->logger->error('Topic subscription failed', [
                'key' => $key,
                'topics' => $this->formatTopicsForLogging($this->normalizeTopics($topics)),
                'error' => $exception->getMessage(),
            ]);
            throw CacheException::operationFailed('subscribeTo', $key, $exception);
        }
    }

    /**
     * Unsubscribes a cache key from one or more topics.
     *
     * @param string $key The cache item key
     * @param iterable<string|Topic> $topics Topics to unsubscribe from
     */
    public function unsubscribeFrom(string $key, iterable $topics): void
    {
        $this->validateKey($key);
        $startTime = microtime(true);

        try {
            $normalizedTopics = $this->normalizeTopics($topics);

            if (empty($normalizedTopics)) {
                $this->logger->debug('Topic unsubscription skipped - no topics provided', ['key' => $key]);

                return;
            }

            $this->layeredCache->unsubscribeFrom($key, $normalizedTopics);
            $operationTime = microtime(true) - $startTime;

            $this->logger->debug('Topic unsubscription successful', [
                'key' => $key,
                'topics' => $this->formatTopicsForLogging($normalizedTopics),
                'operation_time_ms' => round($operationTime * 1000, 2),
            ]);
        } catch (\Throwable $exception) {
            $this->logger->error('Topic unsubscription failed', [
                'key' => $key,
                'topics' => $this->formatTopicsForLogging($this->normalizeTopics($topics)),
                'error' => $exception->getMessage(),
            ]);
            throw CacheException::operationFailed('unsubscribeFrom', $key, $exception);
        }
    }

    /**
     * Invalidates all cache items subscribed to the given topics.
     *
     * @param iterable<string|Topic> $topics Topics to invalidate
     *
     * @return bool True on success, false on failure
     */
    public function invalidateTopics(iterable $topics): bool
    {
        $startTime = microtime(true);

        try {
            $normalizedTopics = $this->normalizeTopics($topics);

            if (empty($normalizedTopics)) {
                $this->logger->debug('Topic invalidation skipped - no topics provided');

                return true;
            }

            $keysToInvalidate = [];
            foreach ($normalizedTopics as $topic) {
                $subscribers = $this->getSubscribersForTopic((string) $topic);
                $keysToInvalidate = array_merge($keysToInvalidate, $subscribers);
            }
            $keysToInvalidate = array_unique($keysToInvalidate);

            if (empty($keysToInvalidate)) {
                $this->logger->debug('Topic invalidation skipped - no subscribers found', [
                    'topics' => $this->formatTopicsForLogging($normalizedTopics),
                ]);

                return true;
            }

            $success = $this->layeredCache->deleteItems($keysToInvalidate);
            $operationTime = microtime(true) - $startTime;

            if ($success) {
                $this->dispatchInvalidationEvent($normalizedTopics, count($keysToInvalidate), $operationTime);
                $this->logger->info('Topic invalidation successful', [
                    'topics' => $this->formatTopicsForLogging($normalizedTopics),
                    'keys_invalidated' => count($keysToInvalidate),
                    'operation_time_ms' => round($operationTime * 1000, 2),
                ]);
            } else {
                $this->logger->warning('Topic invalidation failed', [
                    'topics' => $this->formatTopicsForLogging($normalizedTopics),
                    'keys_count' => count($keysToInvalidate),
                    'operation_time_ms' => round($operationTime * 1000, 2),
                ]);
            }

            return $success;
        } catch (\Throwable $exception) {
            $this->logger->error('Topic invalidation operation failed', [
                'topics' => $this->formatTopicsForLogging($this->normalizeTopics($topics)),
                'error' => $exception->getMessage(),
            ]);
            throw CacheException::operationFailed('invalidateTopics', 'topics', $exception);
        }
    }

    /**
     * Gets all topics that a cache key is subscribed to.
     *
     * @param string $key The cache key
     *
     * @return array<string> Array of topic names
     */
    public function getSubscribedTopics(string $key): array
    {
        $this->validateKey($key);

        try {
            $topicsIterable = $this->layeredCache->getSubscribedTopics($key);

            return is_array($topicsIterable) ? $topicsIterable : iterator_to_array($topicsIterable);
        } catch (\Throwable $exception) {
            $this->logger->error('Get subscribed topics operation failed', [
                'key' => $key,
                'error' => $exception->getMessage(),
            ]);
            throw CacheException::operationFailed('getSubscribedTopics', $key, $exception);
        }
    }

    /**
     * Gets all cache keys subscribed to a specific topic.
     *
     * @param string $topic The topic name
     *
     * @return array<string> Array of cache keys
     */
    public function getSubscribersForTopic(string $topic): array
    {
        if (empty($topic)) {
            throw InvalidArgumentException::invalidTopic('Topic name cannot be empty');
        }

        try {
            $subscribersIterable = $this->layeredCache->getSubscribersForTopic($topic);

            return is_array($subscribersIterable) ? $subscribersIterable : iterator_to_array($subscribersIterable);
        } catch (\Throwable $exception) {
            $this->logger->error('Get subscribers for topic operation failed', [
                'topic' => $topic,
                'error' => $exception->getMessage(),
            ]);
            throw CacheException::operationFailed('getSubscribersForTopic', $topic, $exception);
        }
    }

    // ========================================================================
    // Private Helper Methods
    // ========================================================================

    /**
     * @param iterable<string|Topic> $topics
     *
     * @return array<Topic>
     */
    private function normalizeTopics(iterable $topics): array
    {
        $normalizedTopics = [];
        foreach ($topics as $topic) {
            if ($topic instanceof Topic) {
                $normalizedTopics[] = $topic;
            } elseif (is_string($topic)) {
                if (empty(trim($topic))) {
                    throw InvalidArgumentException::invalidTopic('Topic name cannot be empty');
                }
                $normalizedTopics[] = new Topic($topic);
            } else {
                throw InvalidArgumentException::invalidTopic('Topics must be strings or Topic objects, got: ' . get_debug_type($topic));
            }
        }

        return array_unique($normalizedTopics, SORT_REGULAR);
    }

    /**
     * @param array<Topic> $topics
     *
     * @return array<string>
     */
    private function formatTopicsForLogging(array $topics): array
    {
        return array_map(fn (Topic $topic) => (string) $topic, $topics);
    }

    // ========================================================================
    // Event Dispatching Methods
    // ========================================================================

    /** @param array<Topic> $topics */
    private function dispatchSubscriptionEvent(string $key, array $topics, float $duration): void
    {
        if (null === $this->eventDispatcher) {
            return;
        }
        $event = new TopicSubscriptionEvent($key, $this->formatTopicsForLogging($topics), $duration);
        $this->eventDispatcher->dispatch($event);
    }

    /** @param array<Topic> $topics */
    private function dispatchInvalidationEvent(array $topics, int $keysInvalidated, float $duration): void
    {
        if (null === $this->eventDispatcher) {
            return;
        }
        $event = new TopicInvalidationEvent(
            $this->formatTopicsForLogging($topics),
            $keysInvalidated,
            $duration
        );
        $this->eventDispatcher->dispatch($event);
    }
}
