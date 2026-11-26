<?php

declare(strict_types=1);

namespace KaririCode\Cache\Command\Basic;

use KaririCode\Cache\Command\AbstractCacheCommand;
use KaririCode\Cache\Contract\Driver\LayeredDriver;
use KaririCode\Cache\Service\EventService;
use KaririCode\Cache\Service\MetricsService;
use KaririCode\Cache\Service\ValidationService;

/**
 * Command to delete an item from the cache.
 */
final class DeleteCommand extends AbstractCacheCommand
{
    public function __construct(
        private readonly LayeredDriver $cache,
        ValidationService $validator,
        EventService $events,
        MetricsService $metrics,
        private readonly string $key
    ) {
        parent::__construct($validator, $events, $metrics);
    }

    protected function validate(): void
    {
        $this->validator->validateKey($this->key);
    }

    protected function doExecute(): bool
    {
        return $this->cache->deleteItem($this->key);
    }

    protected function afterExecute(mixed $result, float $duration): void
    {
        $this->events->dispatchCacheDelete($this->key, $result, $duration);
    }

    /**
     * Gets context data about the command for logging and debugging.
     *
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return array_merge(parent::getContext(), ['key' => $this->key]);
    }

    public function getName(): string
    {
        return 'DELETE';
    }
}
