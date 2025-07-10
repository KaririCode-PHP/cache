<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

/**
 * Enhanced cache driver interface with atomic operations and tagging.
 */
interface CacheDriver
{
    public function get(string $key): mixed;

    public function set(string $key, mixed $value, ?\DateTimeInterface $expiration = null): bool;

    public function has(string $key): bool;

    public function delete(string $key): bool;

    public function clear(): bool;

    public function getMultiple(array $keys): array;

    public function setMultiple(array $values, ?\DateTimeInterface $expiration = null): bool;

    public function deleteMultiple(array $keys): bool;
}
