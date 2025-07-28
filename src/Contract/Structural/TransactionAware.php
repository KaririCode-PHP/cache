<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract\Structural;

use KaririCode\Cache\Contract\CacheTransactionManager;

/**
 * TransactionAware interface.
 *
 * This interface defines methods for managing cache transactions.
 * Transaction managers enable atomic operations across multiple
 * cache operations with rollback capabilities.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
interface TransactionAware
{
    /**
     * Sets the transaction manager for the current instance.
     *
     * @param CacheTransactionManager $transactionManager the transaction manager to set
     *
     * @return TransactionAware returns the current instance to allow method chaining
     */
    public function setTransactionManager(CacheTransactionManager $transactionManager): TransactionAware;

    /**
     * Retrieves the current transaction manager.
     *
     * @return CacheTransactionManager the current transaction manager
     */
    public function getTransactionManager(): CacheTransactionManager;

    /**
     * Checks if a transaction manager is currently set.
     *
     * @return bool true if transaction manager is set, false otherwise
     */
    public function hasTransactionManager(): bool;

    /**
     * Checks if currently within a transaction.
     *
     * @return bool true if in transaction, false otherwise
     */
    public function inTransaction(): bool;
}
