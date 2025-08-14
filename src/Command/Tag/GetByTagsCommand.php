<?php

declare(strict_types=1);

namespace KaririCode\Cache\Command\Tag;

use KaririCode\Cache\Command\AbstractCacheCommand;
use KaririCode\Cache\Contract\Driver\LayeredDriver;
use KaririCode\Cache\Service\EventService;
use KaririCode\Cache\Service\MetricsService;
use KaririCode\Cache\Service\SerializationService;
use KaririCode\Cache\Service\ValidationService;

/**
 * Command to retrieve multiple cache items based on their associated tags.
 */
final class GetByTagsCommand extends AbstractCacheCommand
{
    public function __construct(
        private readonly LayeredDriver $cache,
        ValidationService $validator,
        private readonly SerializationService $serializer,
        EventService $events,
        MetricsService $metrics,
        array $tags
    ) {
        parent::__construct($validator, $events, $metrics);
        $this->tags = $tags;
    }

    /**
     * Validates the tags for the operation.
     */
    protected function validate(): void
    {
        $this->validator->validateTags($this->tags);
    }

    /**
     * Executes the getByTags operation, deserializes the results, and dispatches events.
     *
     * @return iterable an iterable of key-value pairs
     */
    protected function doExecute(): iterable
    {
        $serializedItems = $this->cache->getByTags($this->tags);
        $results = [];

        foreach ($serializedItems as $key => $serializedValue) {
            $value = $this->serializer->unserialize($serializedValue, $this->serializerName);
            $results[$key] = $value;
            // Dispatch a cache hit event for each item found
            $this->events->dispatchCacheHit((string) $key, $value);
        }

        return $results;
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
        ]);
    }

    public function getName(): string
    {
        return 'GET_BY_TAGS';
    }
}
