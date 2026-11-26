<?php

declare(strict_types=1);

namespace KaririCode\Cache\Command\Batch;

use KaririCode\Cache\Command\AbstractCacheCommand;
use KaririCode\Cache\Contract\Driver\LayeredDriver;
use KaririCode\Cache\Service\EventService;
use KaririCode\Cache\Service\MetricsService;
use KaririCode\Cache\Service\ValidationService;

/**
 * Command to delete multiple items from the cache.
 */
final class DeleteMultipleCommand extends AbstractCacheCommand
{
    public function __construct(
        private readonly LayeredDriver $cache,
        ValidationService $validator,
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

    protected function doExecute(): bool
    {
        return $this->cache->deleteItems($this->keys);
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
        return 'DELETE_MULTIPLE';
    }
}
