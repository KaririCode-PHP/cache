<?php

declare(strict_types=1);

namespace KaririCode\Cache\Command\Batch;

use KaririCode\Cache\Command\AbstractCacheCommand;
use KaririCode\Cache\Contract\Driver\LayeredDriver;
use KaririCode\Cache\Item\CacheItem;
use KaririCode\Cache\Service\EventService;
use KaririCode\Cache\Service\MetricsService;
use KaririCode\Cache\Service\SerializationService;
use KaririCode\Cache\Service\ValidationService;

/**
 * Command to store multiple items in the cache.
 */
final class SetMultipleCommand extends AbstractCacheCommand
{
    public function __construct(
        private readonly LayeredDriver $cache,
        ValidationService $validator,
        private readonly SerializationService $serializer,
        EventService $events,
        MetricsService $metrics,
        private readonly iterable $values
    ) {
        parent::__construct($validator, $events, $metrics);
    }

    protected function validate(): void
    {
        $this->validator->validateMultipleValues($this->values);
    }

    protected function doExecute(): bool
    {
        $itemsToSave = [];
        foreach ($this->values as $key => $value) {
            $serializedValue = $this->serializer->serialize($value, $this->serializerName);
            $item = new CacheItem((string) $key, $serializedValue, false);
            if (null !== $this->ttl) {
                $item->expiresAfter($this->ttl);
            }
            $itemsToSave[] = $item;
        }

        $allSuccess = true;
        foreach ($itemsToSave as $item) {
            if (!$this->cache->saveDeferred($item)) {
                $allSuccess = false;
            }
        }

        return $allSuccess && $this->cache->commit();
    }

    public function getName(): string
    {
        return 'SET_MULTIPLE';
    }
}
