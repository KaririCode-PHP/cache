<?php

declare(strict_types=1);

namespace KaririCode\Cache\Command\Tag;

use KaririCode\Cache\Command\AbstractCacheCommand;
use KaririCode\Cache\Contract\Driver\LayeredDriver;
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
        private readonly LayeredDriver $cache,
        ValidationService $validator,
        EventService $events,
        MetricsService $metrics,
        array $tags
    ) {
        parent::__construct($validator, $events, $metrics);
        $this->tags = $tags;
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
        if (empty($this->tags)) {
            $this->affectedKeysCount = 0;

            return true;
        }

        $success = $this->cache->invalidateTags($this->tags);

        $this->affectedKeysCount = $success ? -1 : 0;

        return $success;
    }

    /**
     * Dispatches a detailed tag invalidation event with the count of affected keys.
     */
    protected function afterExecute(mixed $result, float $duration): void
    {
        if (true === $result && !empty($this->tags)) {
            $this->events->dispatchTagInvalidation(
                $this->tags,
                $this->affectedKeysCount,
                $duration
            );
        }
    }

    /**
     * Gets context data about the command for logging and debugging.
     *
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return array_merge(parent::getContext(), [
            'tags' => $this->tags,
            'affected_keys' => $this->affectedKeysCount,
        ]);
    }

    public function getName(): string
    {
        return 'INVALIDATE_TAGS';
    }
}
