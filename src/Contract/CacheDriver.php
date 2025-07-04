<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

interface CacheDriver
{
    public function fetch(string $key): mixed;

    public function contains(string $key): bool;

    public function save(string $key, mixed $value, ?\DateTimeInterface $expiration = null): bool;

    public function delete(string $key): bool;

    public function clear(): bool;
}
