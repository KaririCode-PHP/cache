<?php

declare(strict_types=1);

namespace KaririCode\Cache\Transaction;

use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\CacheTransactionManager as CacheTransactionManagerContract;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Contract\ImmutableValue;

/**
 * Transaction manager for cache operations with rollback capabilities.
 *
 * This class provides ACID-like transaction support for cache operations,
 * allowing multiple cache operations to be grouped together and committed
 * atomically, or rolled back if any operation fails.
 *
 * Features:
 * - Atomic operations (all succeed or all fail)
 * - Savepoint support for nested transactions
 * - Rollback capabilities with state restoration
 * - Immutable transaction state tracking
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class CacheTransactionManager implements CacheTransactionManagerContract
{
    private bool $inTransaction = false;

    /** @var array<array<string, mixed>> */
    private array $operations = [];

    /** @var array<string, array{existed: bool, value: mixed, expiration: \DateTimeInterface|null}> */
    private array $rollbackData = [];

    /** @var array<string, \DateTimeImmutable> */
    private array $savepoints = [];

    private \DateTimeImmutable $transactionStartTime;

    public function __construct(private readonly CacheDriver $driver)
    {
        $this->transactionStartTime = new \DateTimeImmutable();
    }

    /**
     * Begins a new cache transaction.
     *
     * @param array<string, mixed> $options Transaction options (currently unused but available for future extensions)
     *
     * @throws \RuntimeException if transaction cannot be started
     */
    public function begin(array $options = []): void
    {
        if ($this->inTransaction) {
            throw CacheException::operationFailed('begin', 'transaction', new \RuntimeException('Transaction already in progress'));
        }

        $this->inTransaction = true;
        $this->operations = [];
        $this->rollbackData = [];
        $this->savepoints = [];
        $this->transactionStartTime = new \DateTimeImmutable();
    }

    /**
     * Commits the current transaction.
     *
     * @throws \RuntimeException if no active transaction
     */
    public function commit(): bool
    {
        if (!$this->inTransaction) {
            throw CacheException::operationFailed('commit', 'transaction', new \RuntimeException('No transaction in progress'));
        }

        $success = true;

        // Execute all operations in order
        foreach ($this->operations as $operation) {
            try {
                $this->executeOperation($operation);
            } catch (\Throwable $e) {
                $success = false;
                // Log the error but continue to rollback
                error_log(
                    'Transaction operation failed: ' . $e->getMessage() .
                    ' (Operation: ' . ($operation['type'] ?? 'unknown') . ')'
                );
                break;
            }
        }

        if (!$success) {
            // Rollback on failure
            $this->rollback();

            return false;
        }

        // Clear transaction state on successful commit
        $this->clearTransactionState();

        return true;
    }

    /**
     * Rolls back the current transaction.
     *
     * @throws \RuntimeException if no active transaction
     */
    public function rollback(): bool
    {
        if (!$this->inTransaction) {
            return false;
        }

        try {
            // Restore previous values in reverse order

            foreach (array_reverse($this->rollbackData, true) as $key => $data) {
                $existed = $data['existed'];
                $value = $data['value'];
                $expiration = $data['expiration'];

                if (!$expiration instanceof \DateTimeInterface) {
                    $expiration = null;
                }

                $existed
                    ? $this->driver->set($key, $value, $expiration)
                    : $this->driver->delete($key);
            }
        } catch (\Throwable $e) {
            // Log rollback failure but continue to clear state
            error_log('Rollback operation failed: ' . $e->getMessage());
        }

        $this->clearTransactionState();

        return true;
    }

    /**
     * Checks if currently in a transaction.
     */
    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }

    /**
     * Creates a savepoint within the current transaction.
     *
     * @param string $name the savepoint name
     *
     * @throws \RuntimeException if not in transaction
     */
    public function savepoint(string $name): void
    {
        if (!$this->inTransaction) {
            throw CacheException::operationFailed('savepoint', $name, new \RuntimeException('No transaction in progress'));
        }

        $this->savepoints[$name] = new \DateTimeImmutable();
    }

    /**
     * Rolls back to a specific savepoint.
     *
     * @param string $name the savepoint name
     *
     * @throws \RuntimeException if savepoint doesn't exist
     */
    public function rollbackToSavepoint(string $name): bool
    {
        if (!$this->inTransaction) {
            throw CacheException::operationFailed('rollbackToSavepoint', $name, new \RuntimeException('No transaction in progress'));
        }

        if (!isset($this->savepoints[$name])) {
            throw CacheException::operationFailed('rollbackToSavepoint', $name, new \RuntimeException(sprintf('Savepoint "%s" does not exist', $name)));
        }

        $savepointTime = $this->savepoints[$name];

        // Remove operations that occurred after the savepoint
        $this->operations = array_filter(
            $this->operations,
            fn (array $operation) => ($operation['timestamp'] ?? $savepointTime) <= $savepointTime
        );

        // Remove savepoints created after this one
        $this->savepoints = array_filter(
            $this->savepoints,
            fn (\DateTimeImmutable $time) => $time <= $savepointTime
        );

        return true;
    }

    /**
     * Gets the current transaction state.
     */
    public function getTransactionState(): ImmutableValue
    {
        return new TransactionState(
            $this->inTransaction,
            $this->savepoints,
            $this->operations,
            $this->transactionStartTime
        );
    }

    /**
     * Schedule a set operation to be executed on commit.
     *
     * @param string $key Cache key
     * @param mixed $value Value to set
     * @param \DateTimeInterface|null $expiration Optional expiration time
     *
     * @throws \RuntimeException if no transaction in progress
     */
    public function scheduleSet(string $key, mixed $value, ?\DateTimeInterface $expiration = null): void
    {
        if (!$this->inTransaction) {
            throw CacheException::operationFailed('scheduleSet', $key, new \RuntimeException('No transaction in progress'));
        }

        if (!is_string($key) || '' === $key) {
            throw InvalidArgumentException::invalidKeyFormat((string) $key);
        }

        // Store current state for rollback
        $this->storeRollbackData($key);

        $this->operations[] = [
            'type' => 'set',
            'key' => $key,
            'value' => $value,
            'expiration' => $expiration,
            'timestamp' => new \DateTimeImmutable(),
        ];
    }

    /**
     * Schedule a delete operation to be executed on commit.
     *
     * @param string $key Cache key to delete
     *
     * @throws \RuntimeException if no transaction in progress
     */
    public function scheduleDelete(string $key): void
    {
        if (!$this->inTransaction) {
            throw CacheException::operationFailed('scheduleDelete', $key, new \RuntimeException('No transaction in progress'));
        }

        if (!is_string($key) || '' === $key) {
            throw InvalidArgumentException::invalidValue('Cache key must be a non-empty string');
        }

        // Store current state for rollback
        $this->storeRollbackData($key);

        $this->operations[] = [
            'type' => 'delete',
            'key' => $key,
            'timestamp' => new \DateTimeImmutable(),
        ];
    }

    /**
     * Execute a single operation.
     *
     * @param array<string, mixed> $operation Operation to execute
     *
     * @throws \InvalidArgumentException for unknown operation types
     */
    private function executeOperation(array $operation): void
    {
        $type = $operation['type'] ?? '';

        match ($type) {
            'set' => $this->executeSetOperation($operation),
            'delete' => $this->executeDeleteOperation($operation),
            default => throw InvalidArgumentException::invalidValue('Unknown operation type: ' . $type)
        };
    }

    /**
     * Execute a set operation with proper type validation.
     *
     * @param array<string, mixed> $operation
     */
    private function executeSetOperation(array $operation): void
    {
        $key = $operation['key'] ?? '';
        $value = $operation['value'] ?? null;
        $expiration = $operation['expiration'] ?? null;

        if (!is_string($key) || '' === $key) {
            throw InvalidArgumentException::invalidKeyFormat((string) $key);
        }

        // Ensure expiration is properly typed
        if (null !== $expiration && !$expiration instanceof \DateTimeInterface) {
            $expiration = null;
        }

        $this->driver->set($key, $value, $expiration);
    }

    /**
     * Execute a delete operation with proper type validation.
     *
     * @param array<string, mixed> $operation
     */
    private function executeDeleteOperation(array $operation): void
    {
        $key = $operation['key'] ?? '';

        if (!is_string($key) || '' === $key) {
            throw InvalidArgumentException::invalidValue('Operation key must be a non-empty string');
        }

        $this->driver->delete($key);
    }

    /**
     * Store the current state of a key for rollback purposes.
     *
     * @param string $key Cache key
     */
    private function storeRollbackData(string $key): void
    {
        // Only store rollback data once per key per transaction
        if (!isset($this->rollbackData[$key])) {
            $value = $this->driver->get($key);
            $this->rollbackData[$key] = [
                'existed' => null !== $value,
                'value' => $value,
                'expiration' => null, // Note: Most cache drivers don't expose expiration info
            ];
        }
    }

    /**
     * Clear all transaction state.
     */
    private function clearTransactionState(): void
    {
        $this->inTransaction = false;
        $this->operations = [];
        $this->rollbackData = [];
        $this->savepoints = [];
    }
}
