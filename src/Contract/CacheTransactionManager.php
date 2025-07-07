<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

/**
 * Transaction manager interface for cache operations.
 */
interface CacheTransactionManager
{
    /**
     * Begin a cache transaction.
     */
    public function begin(): void;

    /**
     * Commit the current transaction.
     */
    public function commit(): bool;

    /**
     * Rollback the current transaction.
     */
    public function rollback(): bool;

    /**
     * Check if currently in a transaction.
     */
    public function inTransaction(): bool;
}
