<?php

declare(strict_types=1);

namespace KaririCode\Cache\Event;

use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Event dispatched when cache topics are invalidated.
 */
final class TopicInvalidationEvent extends CacheEvent implements StoppableEventInterface
{
    /**
     * @param string[] $topics the topics being invalidated
     * @param int $affectedKeys the number of cache keys affected by the invalidation
     * @param array<string, mixed> $context
     */
    public function __construct(
        private readonly array $topics,
        private readonly int $affectedKeys = 0,
        array $context = []
    ) {
        parent::__construct('topics:' . implode(',', $topics), $context);
    }

    /**
     * @return string[]
     */
    public function getTopics(): array
    {
        return $this->topics;
    }

    public function getAffectedKeysCount(): int
    {
        return $this->affectedKeys;
    }
}
