<?php

declare(strict_types=1);

namespace KaririCode\Cache\Command\Basic;

use KaririCode\Cache\Command\AbstractCacheCommand;
use KaririCode\Cache\Contract\Driver\LayeredDriver;
use KaririCode\Cache\Item\CacheItem;
use KaririCode\Cache\Service\EventService;
use KaririCode\Cache\Service\MetricsService;
use KaririCode\Cache\Service\SerializationService;
use KaririCode\Cache\Service\ValidationService;

/**
 * Command to store an item in the cache.
 * Encapsulates the logic for the 'set' operation.
 */
final class SetCommand extends AbstractCacheCommand
{
    public function __construct(
        private readonly LayeredDriver $cache,
        ValidationService $validator,
        private readonly SerializationService $serializer,
        EventService $events,
        MetricsService $metrics,
        private readonly string $key,
        private readonly mixed $value
    ) {
        parent::__construct($validator, $events, $metrics);
    }

    protected function validate(): void
    {
        $this->validator->validateKey($this->key);
        $this->validator->validateValue($this->value);
    }

    protected function doExecute(): bool
    {
        $serializedValue = $this->serializer->serialize($this->value, $this->serializerName);
        $item = new CacheItem($this->key, $serializedValue, false);

        if (null !== $this->ttl) {
            $item->expiresAfter($this->ttl);
        }

        return $this->cache->save($item);
    }

    protected function afterExecute(mixed $result, float $duration): void
    {
        if (true === $result) {
            $this->events->dispatchCacheSet(
                $this->key,
                $this->value,
                $this->ttl,
                $duration
            );
        }
    }

    public function getName(): string
    {
        return 'SET';
    }
}
