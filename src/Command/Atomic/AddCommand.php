<?php

declare(strict_types=1);

namespace KaririCode\Cache\Command\Atomic;

use KaririCode\Cache\Command\AbstractCacheCommand;
use KaririCode\Cache\Contract\Driver\LayeredDriver;
use KaririCode\Cache\Item\CacheItem;
use KaririCode\Cache\Service\EventService;
use KaririCode\Cache\Service\MetricsService;
use KaririCode\Cache\Service\SerializationService;
use KaririCode\Cache\Service\ValidationService;

/**
 * Command to add an item to the cache only if it does not already exist.
 * This is an atomic operation.
 */
final class AddCommand extends AbstractCacheCommand
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

    /**
     * Validates the parameters for the add operation.
     */
    protected function validate(): void
    {
        $this->validator->validateKey($this->key);
        $this->validator->validateValue($this->value);
    }

    /**
     * Executes the add operation on the cache driver.
     *
     * @return bool true if the item was added, false if it already existed
     */
    protected function doExecute(): bool
    {
        $serializedValue = $this->serializer->serialize($this->value, $this->serializerName);
        $item = new CacheItem($this->key, $serializedValue, false);

        if (null !== $this->ttl) {
            $item->expiresAfter($this->ttl);
        }

        return $this->cache->add($item->getKey(), $serializedValue, $item->getExpiration());
    }

    /**
     * Dispatches a cache set event if the operation was successful.
     *
     * @param mixed $result the result of the doExecute method
     */
    protected function afterExecute(mixed $result, float $duration): void
    {
        if (true === $result) {
            $this->events->dispatchCacheSet($this->key, $this->value, $this->ttl, $duration);
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
            'key' => $this->key,
            'value' => $this->value,
        ]);
    }

    /**
     * Returns the name of the command.
     */
    public function getName(): string
    {
        return 'ADD';
    }
}
