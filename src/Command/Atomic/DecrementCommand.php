<?php

declare(strict_types=1);

namespace KaririCode\Cache\Command\Atomic;

use KaririCode\Cache\Command\AbstractCacheCommand;
use KaririCode\Cache\LayeredCacheManager;
use KaririCode\Cache\Service\EventService;
use KaririCode\Cache\Service\MetricsService;
use KaririCode\Cache\Service\ValidationService;

/**
 * Command to atomically decrement a cache value.
 */
final class DecrementCommand extends AbstractCacheCommand
{
    /**
     * @param LayeredCacheManager $cache the cache manager to perform the operation on
     * @param ValidationService $validator service for validating inputs
     * @param EventService $events service for dispatching cache events
     * @param MetricsService $metrics service for recording performance metrics
     * @param string $key the cache key to decrement
     * @param int $step the amount to decrement by
     * @param int $initialValue the value to set if the key does not exist
     */
    public function __construct(
        private readonly LayeredCacheManager $cache,
        ValidationService $validator,
        EventService $events,
        MetricsService $metrics,
        private readonly string $key,
        private readonly int $step = 1,
        private readonly int $initialValue = 0
    ) {
        parent::__construct($validator, $events, $metrics);
    }

    /**
     * Validates the parameters for the decrement operation.
     */
    protected function validate(): void
    {
        $this->validator->validateKey($this->key);
        if ($this->step <= 0) {
            throw new \InvalidArgumentException('Decrement step must be a positive integer.');
        }
    }

    /**
     * Executes the decrement operation on the cache driver.
     *
     * @return int the new value after decrementing
     */
    protected function doExecute(): int
    {
        return $this->cache->decrement($this->key, $this->step, $this->initialValue);
    }

    /**
     * Returns the name of the command.
     */
    public function getName(): string
    {
        return 'DECREMENT';
    }
}
