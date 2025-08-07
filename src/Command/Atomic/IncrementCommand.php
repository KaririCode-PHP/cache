<?php

declare(strict_types=1);

namespace KaririCode\Cache\Command\Atomic;

use KaririCode\Cache\Command\AbstractCacheCommand;
use KaririCode\Cache\Contract\Driver\LayeredDriver;
use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Cache\Service\EventService;
use KaririCode\Cache\Service\MetricsService;
use KaririCode\Cache\Service\ValidationService;

/**
 * Command to atomically increment a cache value.
 */
final class IncrementCommand extends AbstractCacheCommand
{
    public function __construct(
        private readonly LayeredDriver $cache,
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
     * Validates the parameters for the increment operation.
     */
    protected function validate(): void
    {
        $this->validator->validateKey($this->key);
        if ($this->step <= 0) {
            throw InvalidArgumentException::forInvalidStep($this->step);
        }
    }

    /**
     * Executes the increment operation on the cache driver.
     *
     * @return int the new value after incrementing
     */
    protected function doExecute(): int
    {
        return $this->cache->increment($this->key, $this->step, $this->initialValue);
    }

    protected function afterExecute(mixed $result, float $duration): void
    {
        $this->events->dispatchAtomicOperation(
            'increment',
            $this->key,
            $result,
            $duration,
            ['step' => $this->step]
        );
    }

    /**
     * Returns the name of the command.
     */
    public function getName(): string
    {
        return 'INCREMENT';
    }
}
