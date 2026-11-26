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
 * Command to atomically decrement a cache value.
 */
final class DecrementCommand extends AbstractCacheCommand
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
     * Validates the parameters for the decrement operation.
     */
    protected function validate(): void
    {
        $this->validator->validateKey($this->key);
        if ($this->step <= 0) {
            throw InvalidArgumentException::forInvalidStep($this->step);
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
     * Gets context data about the command for logging and debugging.
     *
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return array_merge(parent::getContext(), [
            'key' => $this->key,
            'step' => $this->step,
            'initial_value' => $this->initialValue,
        ]);
    }

    /**
     * Returns the name of the command.
     */
    public function getName(): string
    {
        return 'DECREMENT';
    }
}
