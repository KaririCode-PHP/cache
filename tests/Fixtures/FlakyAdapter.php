<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Fixtures;

use KaririCode\Cache\Adapter\ArrayAdapter;
use KaririCode\Cache\Contract\CacheAdapter;
use KaririCode\Cache\ValueObject\AdapterStats;

/**
 * Test double: an in-memory adapter whose backend refuses writes and removes
 * of keys containing "fail", and whose flush() result is configurable.
 */
final class FlakyAdapter implements CacheAdapter
{
    public bool $flushSucceeds = true;

    private readonly ArrayAdapter $inner;

    public function __construct()
    {
        $this->inner = new ArrayAdapter();
    }

    public function read(string $key): mixed
    {
        return $this->inner->read($key);
    }

    public function write(string $key, mixed $value, ?int $ttl = null): bool
    {
        return !str_contains($key, 'fail') && $this->inner->write($key, $value, $ttl);
    }

    public function remove(string $key): bool
    {
        return !str_contains($key, 'fail') && $this->inner->remove($key);
    }

    public function exists(string $key): bool
    {
        return $this->inner->exists($key);
    }

    public function flush(): bool
    {
        $this->inner->flush();

        return $this->flushSucceeds;
    }

    public function readMultiple(array $keys): array
    {
        return $this->inner->readMultiple($keys);
    }

    public function writeMultiple(array $values, ?int $ttl = null): bool
    {
        $success = true;

        foreach ($values as $key => $value) {
            $success = $this->write((string) $key, $value, $ttl) && $success;
        }

        return $success;
    }

    public function removeMultiple(array $keys): bool
    {
        $success = true;

        foreach ($keys as $key) {
            $success = $this->remove($key) && $success;
        }

        return $success;
    }

    public function stats(): AdapterStats
    {
        return $this->inner->stats();
    }

    public function name(): string
    {
        return 'flaky';
    }

    public function isAvailable(): bool
    {
        return true;
    }
}
