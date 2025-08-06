<?php

declare(strict_types=1);

namespace KaririCode\Cache\Command\Batch;

use KaririCode\Cache\Command\AbstractCacheCommand;
use KaririCode\Cache\LayeredCacheManager;
use KaririCode\Cache\Service\EventService;
use KaririCode\Cache\Service\MetricsService;
use KaririCode\Cache\Service\ValidationService;

/**
 * Command to delete multiple items from the cache.
 */
final class DeleteMultipleCommand extends AbstractCacheCommand
{
    public function __construct(
        private readonly LayeredCacheManager $cache,
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

    public function getName(): string
    {
        return 'DELETE_MULTIPLE';
    }
}
