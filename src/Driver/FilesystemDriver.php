<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver;

use KaririCode\Cache\Contract\CacheCapabilities;
use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\DriverStats;
use KaririCode\Cache\Contract\Filesystem\DirectoryManager;
use KaririCode\Cache\Contract\Filesystem\FileSystemValidator;
use KaririCode\Cache\Driver\Filesystem\Operation\FilesystemCrudOperations;
use KaririCode\Cache\Driver\Filesystem\Operation\FilesystemTagOperations;
use KaririCode\Cache\Driver\Filesystem\Statistics\FileSystemStatistics;
use KaririCode\Cache\Item\CacheItem;
use KaririCode\Contract\Logging\Logger;
use Psr\Cache\CacheItemInterface;

/**
 * Acts as a pure Facade for all Filesystem cache operations.
 *
 * Its single responsibility is to delegate cache operations to the specialized
 * handler classes it is composed of. It does not contain any business logic,
 * adhering strictly to the Facade and Single Responsibility principles.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class FilesystemDriver implements CacheDriver
{
    /**
     * @param DirectoryManager $directoryManager manages directory operations
     * @param FilesystemCrudOperations $crudOperations handles basic get/set/delete operations
     * @param FilesystemTagOperations $tagOperations handles tag-based operations
     * @param FileSystemStatistics $statistics provides driver statistics
     * @param FileSystemValidator $validator validates keys, values, and paths
     * @param Logger $logger a PSR-3 logger instance
     */
    public function __construct(
        private readonly DirectoryManager $directoryManager,
        private readonly FilesystemCrudOperations $crudOperations,
        private readonly FilesystemTagOperations $tagOperations,
        private readonly FileSystemStatistics $statistics,
        private readonly FileSystemValidator $validator,
        private readonly Logger $logger
    ) {
    }

    // --- Basic CRUD Operations (Delegated) ---

    public function get(string $key): mixed
    {
        return $this->crudOperations->get($key);
    }

    public function set(string $key, mixed $value): bool
    {
        // The responsibility of handling TTL is now inside FilesystemCrudOperations.
        return $this->crudOperations->set($key, $value);
    }

    public function has(string $key): bool
    {
        return $this->crudOperations->has($key);
    }

    public function delete(string $key): bool
    {
        return $this->crudOperations->delete($key);
    }

    public function clear(): bool
    {
        return $this->crudOperations->clear();
    }

    // --- Batch Operations (Delegated) ---

    public function getMultiple(iterable $keys): iterable
    {
        return $this->crudOperations->getMultiple($keys);
    }

    public function setMultiple(iterable $values): bool
    {
        // The responsibility of handling TTL is now inside FilesystemCrudOperations.
        return $this->crudOperations->setMultiple($values);
    }

    public function deleteMultiple(iterable $keys): bool
    {
        return $this->crudOperations->deleteMultiple($keys);
    }

    public function getItem(string $key): CacheItemInterface
    {
        $value = $this->crudOperations->get($key);

        return new CacheItem($key, $value, null !== $value);
    }

    public function getItems(iterable $keys = []): iterable
    {
        $items = [];
        foreach ($keys as $key) {
            $items[$key] = $this->getItem($key);
        }

        return $items;
    }

    public function hasItem(string $key): bool
    {
        return $this->crudOperations->has($key);
    }

    public function deleteItem(string $key): bool
    {
        return $this->crudOperations->delete($key);
    }

    public function deleteItems(array $keys): bool
    {
        return $this->crudOperations->deleteMultiple($keys);
    }

    public function save(CacheItemInterface $item): bool
    {
        if (!$item instanceof CacheItem) {
            // If it's not our CacheItem, we can't get the expiration directly.
            // For now, we'll save without specific expiration, relying on default TTL.
            // A more robust solution might involve a custom CacheItem implementation or a way to extract TTL.
            return $this->crudOperations->set($item->getKey(), $item->get());
        }

        // Assuming CacheItem has a getExpiration method or similar for TTL
        return $this->crudOperations->set($item->getKey(), $item->get());
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        // Filesystem driver doesn't inherently support deferred saving.
        // We'll save immediately, effectively making saveDeferred behave like save.
        return $this->save($item);
    }

    public function commit(): bool
    {
        // No deferred operations to commit for the Filesystem driver.
        return true;
    }

    // --- Tag Operations (Delegated) ---

    public function setWithTags(string $key, mixed $value, iterable $tags): bool
    {
        return $this->tagOperations->setWithTags($key, $value, $tags);
    }

    public function getByTags(iterable $tags): iterable
    {
        return $this->tagOperations->getByTags($tags);
    }

    public function invalidateTags(iterable $tags): bool
    {
        return $this->tagOperations->invalidateTags($tags);
    }

    public function getTags(): iterable
    {
        return $this->tagOperations->getTags();
    }

    // --- Statistics, Capabilities, and Maintenance ---

    public function getCapabilities(): array
    {
        return [
            CacheCapabilities::TAGGING_OPERATIONS,
            CacheCapabilities::MAINTENANCE_OPERATIONS,
        ];
    }

    public function getStats(): DriverStats
    {
        return $this->statistics;
    }

    public function maintenance(array $options = []): bool
    {
        $this->logger->info('Filesystem cache maintenance started.');
        $this->crudOperations->cleanupExpired();
        $this->logger->info('Filesystem cache maintenance finished.');

        return true;
    }
}
