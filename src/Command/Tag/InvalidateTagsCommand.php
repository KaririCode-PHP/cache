<?php

declare(strict_types=1);

namespace KaririCode\Cache\Command\Tag;

use KaririCode\Cache\Command\AbstractCacheCommand;
use KaririCode\Cache\LayeredCacheManager;
use KaririCode\Cache\Service\EventService;
use KaririCode\Cache\Service\MetricsService;
use KaririCode\Cache\Service\ValidationService;

/**
 * Command to invalidate cache items by tags.
 */
final class InvalidateTagsCommand extends AbstractCacheCommand
{
    private int $affectedKeysCount = 0;

    public function __construct(
        private readonly LayeredCacheManager $cache,
        ValidationService $validator,
        EventService $events,
        MetricsService $metrics,
        private readonly array $tags
    ) {
        parent::__construct($validator, $events, $metrics);
    }

    protected function validate(): void
    {
        $this->validator->validateTags($this->tags);
    }

    /**
     * Executes the invalidateTags operation and stores the count of affected keys.
     *
     * @return bool always returns true, as the detailed result is the count
     */
    protected function doExecute(): bool
    {
        $this->affectedKeysCount = $this->cache->invalidateTags($this->tags);

        return true; // The command itself succeeded if no exception was thrown.
    }

    /**
     * Dispatches a detailed tag invalidation event with the count of affected keys.
     */
    protected function afterExecute(mixed $result, float $duration): void
    {
        if (true === $result) {
            $this->events->dispatchTagInvalidation(
                $this->tags,
                $this->affectedKeysCount,
                $duration
            );
        }
    }

    public function getName(): string
    {
        return 'INVALIDATE_TAGS';
    }
}
