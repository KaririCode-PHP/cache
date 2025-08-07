<?php

declare(strict_types=1);

namespace KaririCode\Cache\Command\Tag;

use KaririCode\Cache\Command\AbstractCacheCommand;
use KaririCode\Cache\Contract\Driver\LayeredDriver;
use KaririCode\Cache\Item\CacheItem;
use KaririCode\Cache\Service\EventService;
use KaririCode\Cache\Service\MetricsService;
use KaririCode\Cache\Service\SerializationService;
use KaririCode\Cache\Service\ValidationService;

/**
 * Command to store an item with associated tags.
 */
final class SetWithTagsCommand extends AbstractCacheCommand
{
    public function __construct(
        private readonly LayeredDriver $cache,
        ValidationService $validator,
        private readonly SerializationService $serializer,
        EventService $events,
        MetricsService $metrics,
        private readonly string $key,
        private readonly mixed $value,
        private readonly array $tags
    ) {
        parent::__construct($validator, $events, $metrics);
    }

    protected function validate(): void
    {
        $this->validator->validateKey($this->key);
        $this->validator->validateValue($this->value);
        $this->validator->validateTags($this->tags);
    }

    protected function doExecute(): bool
    {
        $serializedValue = $this->serializer->serialize($this->value, $this->serializerName);
        $item = new CacheItem($this->key, $serializedValue, false);

        $item->tag($this->tags);

        if (null !== $this->ttl) {
            $item->expiresAfter($this->ttl);
        }

        return $this->cache->save($item);
    }

    public function getName(): string
    {
        return 'SET_WITH_TAGS';
    }
}
