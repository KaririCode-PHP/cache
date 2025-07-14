<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

use KaririCode\Contract\ImmutableValue;

/**
 * Interface CacheTransactionManager.
 *
 * Defines the contract for cache transaction managers.
 * Transaction managers enable atomic operations across multiple
 * cache operations with commit/rollback capabilities, ensuring
 * data consistency in complex cache scenarios.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
interface CacheTransactionManager
{
    /**
     * Begins a new cache transaction.
     *
     * @param array $options transaction options
     *
     * @throws \RuntimeException if transaction cannot be started
     */
    public function begin(array $options = []): void;

    /**
     * Commits the current transaction.
     *
     * @throws \RuntimeException if no active transaction
     *
     * @return bool true if commit was successful, false otherwise
     */
    public function commit(): bool;

    /**
     * Rolls back the current transaction.
     *
     * @throws \RuntimeException if no active transaction
     *
     * @return bool true if rollback was successful, false otherwise
     */
    public function rollback(): bool;

    /**
     * Checks if currently in a transaction.
     *
     * @return bool true if in transaction, false otherwise
     */
    public function inTransaction(): bool;

    /**
     * Creates a savepoint within the current transaction.
     *
     * @param string $name the savepoint name
     *
     * @throws \RuntimeException if not in transaction
     */
    public function savepoint(string $name): void;

    /**
     * Rolls back to a specific savepoint.
     *
     * @param string $name the savepoint name
     *
     * @throws \RuntimeException if savepoint doesn't exist
     */
    public function rollbackToSavepoint(string $name): bool;

    /**
     * Gets the current transaction state.
     *
     * @return ImmutableValue transaction state as an immutable object
     */
    public function getTransactionState(): ImmutableValue;
}
