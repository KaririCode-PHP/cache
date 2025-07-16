<?php

declare(strict_types=1);

namespace KaririCode\Cache\Transaction;

use KaririCode\Contract\ImmutableValue;

/**
 * TransactionState - Immutable transaction state class with proper type annotations.
 *
 * This class represents the complete state of a cache transaction at a specific
 * point in time. It follows the immutable value pattern, ensuring that all
 * modifications return new instances rather than modifying the existing state.
 *
 * Features:
 * - Immutable state representation
 * - Type-safe array annotations
 * - Fluent interface for state modifications
 * - Comprehensive transaction metadata
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class TransactionState implements ImmutableValue
{
    /**
     * @param bool $active Whether the transaction is currently active
     * @param array<string, \DateTimeImmutable> $savepoints Named savepoints with their creation times
     * @param array<array<string, mixed>> $operations List of operations to be executed
     * @param \DateTimeImmutable $startTime When the transaction was started
     */
    public function __construct(
        private readonly bool $active = false,
        private readonly array $savepoints = [],
        private readonly array $operations = [],
        private readonly \DateTimeImmutable $startTime = new \DateTimeImmutable()
    ) {
    }

    /**
     * Check if the transaction is currently active.
     */
    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * Get all savepoints with their creation times.
     *
     * @return array<string, \DateTimeImmutable>
     */
    public function getSavepoints(): array
    {
        return $this->savepoints;
    }

    /**
     * Get all operations in the transaction.
     *
     * @return array<array<string, mixed>>
     */
    public function getOperations(): array
    {
        return $this->operations;
    }

    /**
     * Get the transaction start time.
     */
    public function getStartTime(): \DateTimeImmutable
    {
        return $this->startTime;
    }

    /**
     * Get the number of operations in the transaction.
     */
    public function getOperationCount(): int
    {
        return count($this->operations);
    }

    /**
     * Get the number of savepoints in the transaction.
     */
    public function getSavepointCount(): int
    {
        return count($this->savepoints);
    }

    /**
     * Check if a specific savepoint exists.
     */
    public function hasSavepoint(string $name): bool
    {
        return isset($this->savepoints[$name]);
    }

    /**
     * Get the duration of the transaction in seconds.
     */
    public function getDuration(): float
    {
        return (new \DateTimeImmutable())->getTimestamp() - $this->startTime->getTimestamp();
    }

    /**
     * Get names of all savepoints.
     *
     * @return array<string>
     */
    public function getSavepointNames(): array
    {
        return array_keys($this->savepoints);
    }

    /**
     * Create a new instance with updated active state.
     */
    public function withActive(bool $active): self
    {
        return new self($active, $this->savepoints, $this->operations, $this->startTime);
    }

    /**
     * Create a new instance with an additional savepoint.
     */
    public function withSavepoint(string $name): self
    {
        $newSavepoints = $this->savepoints;
        $newSavepoints[$name] = new \DateTimeImmutable();

        return new self($this->active, $newSavepoints, $this->operations, $this->startTime);
    }

    /**
     * Create a new instance with an additional operation.
     *
     * @param array<string, mixed> $operation
     */
    public function withOperation(array $operation): self
    {
        $newOperations = $this->operations;
        $newOperations[] = $operation;

        return new self($this->active, $this->savepoints, $newOperations, $this->startTime);
    }

    /**
     * Create a new instance with multiple operations.
     *
     * @param array<array<string, mixed>> $operations
     */
    public function withOperations(array $operations): self
    {
        $newOperations = array_merge($this->operations, $operations);

        return new self($this->active, $this->savepoints, $newOperations, $this->startTime);
    }

    /**
     * Create a new instance without a specific savepoint.
     */
    public function withoutSavepoint(string $name): self
    {
        $newSavepoints = $this->savepoints;
        unset($newSavepoints[$name]);

        return new self($this->active, $newSavepoints, $this->operations, $this->startTime);
    }

    /**
     * Create a new instance with operations filtered by timestamp.
     * Useful for savepoint rollback scenarios.
     */
    public function withOperationsAfter(\DateTimeImmutable $timestamp): self
    {
        $filteredOperations = array_filter(
            $this->operations,
            fn (array $operation) => isset($operation['timestamp'])
                                   && $operation['timestamp'] instanceof \DateTimeImmutable
                                   && $operation['timestamp'] > $timestamp
        );

        return new self($this->active, $this->savepoints, $filteredOperations, $this->startTime);
    }

    /**
     * Create a new instance with operations filtered by timestamp.
     * Useful for savepoint rollback scenarios.
     */
    public function withOperationsBefore(\DateTimeImmutable $timestamp): self
    {
        $filteredOperations = array_filter(
            $this->operations,
            fn (array $operation) => isset($operation['timestamp'])
                                   && $operation['timestamp'] instanceof \DateTimeImmutable
                                   && $operation['timestamp'] <= $timestamp
        );

        return new self($this->active, $this->savepoints, $filteredOperations, $this->startTime);
    }

    /**
     * Create a new instance with a fresh start time.
     */
    public function withStartTime(\DateTimeImmutable $startTime): self
    {
        return new self($this->active, $this->savepoints, $this->operations, $startTime);
    }

    /**
     * Create a new instance with cleared operations.
     */
    public function withClearedOperations(): self
    {
        return new self($this->active, $this->savepoints, [], $this->startTime);
    }

    /**
     * Create a new instance with cleared savepoints.
     */
    public function withClearedSavepoints(): self
    {
        return new self($this->active, [], $this->operations, $this->startTime);
    }

    /**
     * Create a completely fresh transaction state.
     */
    public function withFreshState(): self
    {
        return new self(false, [], [], new \DateTimeImmutable());
    }

    /**
     * Convert the transaction state to an array for debugging or logging.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'active' => $this->active,
            'start_time' => $this->startTime->format(\DateTimeInterface::ATOM),
            'duration' => $this->getDuration(),
            'operation_count' => $this->getOperationCount(),
            'savepoint_count' => $this->getSavepointCount(),
            'savepoint_names' => $this->getSavepointNames(),
            'operations' => $this->operations,
        ];
    }

    /**
     * Convert the transaction state to a JSON string.
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }
}
