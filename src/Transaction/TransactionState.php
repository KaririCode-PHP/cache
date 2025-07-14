<?php

declare(strict_types=1);

namespace KaririCode\Cache\Transaction;

use KaririCode\Contract\ImmutableValue;

/**
 * TransactionState - New immutable transaction state class.
 */
final class TransactionState implements ImmutableValue
{
    public function __construct(
        private readonly bool $active = false,
        private readonly array $savepoints = [],
        private readonly array $operations = [],
        private readonly \DateTimeImmutable $startTime = new \DateTimeImmutable()
    ) {
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function getSavepoints(): array
    {
        return $this->savepoints;
    }

    public function getOperations(): array
    {
        return $this->operations;
    }

    public function getStartTime(): \DateTimeImmutable
    {
        return $this->startTime;
    }

    public function withActive(bool $active): self
    {
        return new self($active, $this->savepoints, $this->operations, $this->startTime);
    }

    public function withSavepoint(string $name): self
    {
        $newSavepoints = $this->savepoints;
        $newSavepoints[$name] = new \DateTimeImmutable();

        return new self($this->active, $newSavepoints, $this->operations, $this->startTime);
    }

    public function withOperation(array $operation): self
    {
        $newOperations = $this->operations;
        $newOperations[] = $operation;

        return new self($this->active, $this->savepoints, $newOperations, $this->startTime);
    }
}
