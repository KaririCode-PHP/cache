<?php

declare(strict_types=1);

namespace KaririCode\Cache\Command\Tag;

use KaririCode\Cache\Command\AbstractCacheCommand;
use KaririCode\Cache\LayeredCacheManager;
use KaririCode\Cache\Service\EventService;
use KaririCode\Cache\Service\MetricsService;
use KaririCode\Cache\Service\SerializationService;
use KaririCode\Cache\Service\ValidationService;

/**
 * Command to retrieve multiple cache items based on their associated tags.
 */
final class GetByTagsCommand extends AbstractCacheCommand
{
    /**
     * @param LayeredCacheManager $cache the cache manager to perform the operation on
     * @param ValidationService $validator service for validating inputs
     * @param SerializationService $serializer service for deserializing the values
     * @param EventService $events service for dispatching cache events
     * @param MetricsService $metrics service for recording performance metrics
     * @param iterable $tags the tags to search for
     */
    public function __construct(
        private readonly LayeredCacheManager $cache,
        ValidationService $validator,
        private readonly SerializationService $serializer,
        EventService $events,
        MetricsService $metrics,
        private readonly array $tags
    ) {
        parent::__construct($validator, $events, $metrics);
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
     * Returns the name of the command.
     */
    public function getName(): string
    {
        return 'GET_BY_TAGS';
    }
}
