<?php

declare(strict_types=1);

namespace KaririCode\Cache\Transaction;

use KaririCode\Cache\Contract\Transaction\Operation;
use KaririCode\Contract\ImmutableValue;

final class TransactionState implements ImmutableValue
{
    /**
     * @param bool $active Whether the transaction is currently active
     * @param array<string, \DateTimeImmutable> $savepoints Named savepoints with their creation times
     * @param array<int, Operation> $operations List of operations to be executed
     * @param \DateTimeImmutable $startTime When the transaction was started
     */
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

    /**
     * @return array<string, \DateTimeImmutable>
     */
    public function getSavepoints(): array
    {
        return $this->savepoints;
    }

    /**
     * Get all operations in the transaction.
     *
     * @return array<int, Operation>
     */
    public function getOperations(): array
    {
        return $this->operations;
    }

    public function getStartTime(): \DateTimeImmutable
    {
        return $this->startTime;
    }

    public function getOperationCount(): int
    {
        return count($this->operations);
    }

    public function getSavepointCount(): int
    {
        return count($this->savepoints);
    }

    public function hasSavepoint(string $name): bool
    {
        return isset($this->savepoints[$name]);
    }

    public function getDuration(): float
    {
        return (new \DateTimeImmutable())->getTimestamp() - $this->startTime->getTimestamp();
    }

    /**
     * @return array<string>
     */
    public function getSavepointNames(): array
    {
        return array_keys($this->savepoints);
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

    /**
     * Create a new instance with an additional operation.
     */
    public function withOperation(Operation $operation): self
    {
        $newOperations = $this->operations;
        $newOperations[] = $operation;

        return new self($this->active, $this->savepoints, $newOperations, $this->startTime);
    }

    /**
     * Create a new instance with multiple operations.
     *
     * @param array<int, Operation> $operations
     */
    public function withOperations(array $operations): self
    {
        $newOperations = array_merge($this->operations, $operations);

        return new self($this->active, $this->savepoints, $newOperations, $this->startTime);
    }

    public function withoutSavepoint(string $name): self
    {
        $newSavepoints = $this->savepoints;
        unset($newSavepoints[$name]);

        return new self($this->active, $newSavepoints, $this->operations, $this->startTime);
    }

    public function withOperationsAfter(\DateTimeImmutable $timestamp): self
    {
        $filteredOperations = array_filter(
            $this->operations,
            fn (Operation $operation) => $operation->getTimestamp() > $timestamp
        );

        return new self($this->active, $this->savepoints, array_values($filteredOperations), $this->startTime);
    }

    public function withOperationsBefore(\DateTimeImmutable $timestamp): self
    {
        $filteredOperations = array_filter(
            $this->operations,
            fn (Operation $operation) => $operation->getTimestamp() <= $timestamp
        );

        return new self($this->active, $this->savepoints, array_values($filteredOperations), $this->startTime);
    }

    public function withStartTime(\DateTimeImmutable $startTime): self
    {
        return new self($this->active, $this->savepoints, $this->operations, $startTime);
    }

    public function withClearedOperations(): self
    {
        return new self($this->active, $this->savepoints, [], $this->startTime);
    }

    public function withClearedSavepoints(): self
    {
        return new self($this->active, [], $this->operations, $this->startTime);
    }

    public function withFreshState(): self
    {
        return new self(false, [], [], new \DateTimeImmutable());
    }
}
