<?php

declare(strict_types=1);

namespace KaririCode\Cache\Command\Batch;

use KaririCode\Cache\Command\AbstractCacheCommand;
use KaririCode\Cache\Contract\Driver\LayeredDriver;
use KaririCode\Cache\Service\EventService;
use KaririCode\Cache\Service\MetricsService;
use KaririCode\Cache\Service\SerializationService;
use KaririCode\Cache\Service\ValidationService;

/**
 * Command to retrieve multiple items from the cache.
 */
final class GetMultipleCommand extends AbstractCacheCommand
{
    public function __construct(
        private readonly LayeredDriver $cache,
        ValidationService $validator,
        private readonly SerializationService $serializer,
        EventService $events,
        MetricsService $metrics,
        private readonly iterable $keys
    ) {
        parent::__construct($validator, $events, $metrics);
    }

    protected function validate(): void
    {
        $this->validator->validateKeys($this->keys);
    }

    protected function doExecute(): iterable
    {
        $items = $this->cache->getItems($this->keys);
        $results = [];

        foreach ($items as $key => $item) {
            if ($item->isHit()) {
                $value = $this->serializer->unserialize($item->get(), $this->serializerName);
                $results[$key] = $value;
                $this->events->dispatchCacheHit($key, $value);
            } else {
                $results[$key] = null;
                $this->events->dispatchCacheMiss($key);
            }
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
            'keys' => is_array($this->keys) ? $this->keys : iterator_to_array($this->keys),
        ]);
    }

    public function getName(): string
    {
        return 'GET_MULTIPLE';
    }
}
