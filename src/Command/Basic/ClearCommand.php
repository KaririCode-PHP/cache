<?php

declare(strict_types=1);

namespace KaririCode\Cache\Command\Basic;

use KaririCode\Cache\Command\AbstractCacheCommand;
use KaririCode\Cache\Contract\Driver\LayeredDriver;
use KaririCode\Cache\Service\EventService;
use KaririCode\Cache\Service\MetricsService;
use KaririCode\Cache\Service\ValidationService;

/**
 * Command to clear the entire cache.
 */
final class ClearCommand extends AbstractCacheCommand
{
    public function __construct(
        private readonly LayeredDriver $cache,
        ValidationService $validator,
        EventService $events,
        MetricsService $metrics
    ) {
        parent::__construct($validator, $events, $metrics);
    }

    protected function validate(): void
    {
        // No validation needed for clear operation
    }

    protected function doExecute(): bool
    {
        return $this->cache->clear();
    }

    protected function afterExecute(mixed $result, float $duration): void
    {
        if (true === $result) {
            $this->events->dispatchCacheClear($duration);
        }
    }

    public function getName(): string
    {
        return 'CLEAR';
    }
}
