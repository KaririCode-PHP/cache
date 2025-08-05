<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract\Behavioral;

/**
 * MultiOperationCacheable interface.
 *
 * This interface extends the Cacheable interface to provide methods
 * for handling multiple cache operations at once. It allows for batch
 * retrieval, storage, and deletion of cache items, improving performance
 * and efficiency when dealing with multiple keys.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
interface MultiOperationCacheable extends Cacheable
{
    /**
     * Obtains multiple cache items by their unique keys.
     *
     * @param iterable<string> $keys the cache keys to retrieve
     *
     * @return iterable<string, mixed> the retrieved values indexed by cache key
     */
    public function getMultiple(iterable $keys): iterable;

    /**
     * Persists a set of key => value pairs in the cache.
     *
     * @param iterable<string, mixed> $values the key-value pairs to store
     *
     * @return bool true on success, false on failure
     */
    public function setMultiple(iterable $values): bool;

    /**
     * Deletes multiple cache items in a single operation.
     *
     * @param iterable<string> $keys the cache keys to delete
     *
     * @return bool true on success, false on failure
     */
    public function deleteMultiple(iterable $keys): bool;
}
