<?php

declare(strict_types=1);

namespace KaririCode\Cache\Command\Basic;

use KaririCode\Cache\Command\AbstractCacheCommand;
use KaririCode\Cache\Contract\Driver\LayeredDriver;
use KaririCode\Cache\Service\EventService;
use KaririCode\Cache\Service\MetricsService;
use KaririCode\Cache\Service\ValidationService;

/**
 * Command to check if an item exists in the cache.
 */
final class HasCommand extends AbstractCacheCommand
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
        return $this->cache->hasItem($this->key);
    }

    public function getName(): string
    {
        return 'HAS';
    }
}
