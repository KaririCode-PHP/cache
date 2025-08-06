<?php

declare(strict_types=1);

namespace KaririCode\Cache\Command\Basic;

use KaririCode\Cache\Command\AbstractCacheCommand;
use KaririCode\Cache\LayeredCacheManager;
use KaririCode\Cache\Service\EventService;
use KaririCode\Cache\Service\MetricsService;
use KaririCode\Cache\Service\SerializationService;
use KaririCode\Cache\Service\ValidationService;

/**
 * Command to retrieve an item from the cache.
 * Encapsulates the logic for the 'get' operation, including deserialization and event dispatching.
 */
final class GetCommand extends AbstractCacheCommand
{
    private bool $isHit = false;

    /**
     * @param LayeredCacheManager $cache the cache manager to perform the operation on
     * @param ValidationService $validator service for validating inputs
     * @param SerializationService $serializer service for deserializing the value
     * @param EventService $events service for dispatching cache events
     * @param MetricsService $metrics service for recording performance metrics
     * @param string $key the cache key to retrieve
     * @param mixed $default the default value to return if the key is not found
     */
    public function __construct(
        private readonly LayeredCacheManager $cache,
        ValidationService $validator,
        private readonly SerializationService $serializer,
        EventService $events,
        MetricsService $metrics,
        private readonly string $key,
        private readonly mixed $default = null
    ) {
        parent::__construct($validator, $events, $metrics);
    }

    /**
     * Validates the key for the get operation.
     */
    protected function validate(): void
    {
        $this->validator->validateKey($this->key);
    }

    protected function doExecute(): mixed
    {
        $item = $this->cache->getItem($this->key);

        if (!$item->isHit()) {
            $this->isHit = false;

            return $this->default;
        }

        $this->isHit = true;

        return $this->serializer->unserialize($item->get(), $this->serializerName);
    }

    protected function afterExecute(mixed $result, float $duration): void
    {
        if ($this->isHit) {
            $this->events->dispatchCacheHit($this->key, $result, $duration);
        } else {
            $this->events->dispatchCacheMiss($this->key);
        }
    }

    /**
     * Returns the name of the command.
     */
    public function getName(): string
    {
        return 'GET';
    }
}
