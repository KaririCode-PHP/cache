<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Fixtures;

use KaririCode\Cache\Adapter\ArrayAdapter;
use KaririCode\Cache\Contract\CacheAdapter;
use KaririCode\Cache\ValueObject\AdapterStats;

/**
 * Test double: an ArrayAdapter that records the physical TTL of every
 * write, so tests can assert what a cache asked the backend to keep.
 */
final class TtlRecordingAdapter implements CacheAdapter
{
    /** @var list<array{key: string, ttl: int|null}> */
    public array $writes = [];

    private readonly ArrayAdapter $inner;

    public function __construct()
    {
        $this->inner = new ArrayAdapter();
    }

    /**
     * @return list<int|null> TTLs written for $key, oldest first
     */
    public function ttlsFor(string $key): array
    {
        $ttls = [];

        foreach ($this->writes as $write) {
            if ($write['key'] === $key) {
                $ttls[] = $write['ttl'];
            }
        }

        return $ttls;
    }

    public function read(string $key): mixed
    {
        return $this->inner->read($key);
    }

    public function write(string $key, mixed $value, ?int $ttl = null): bool
    {
        $this->writes[] = ['key' => $key, 'ttl' => $ttl];

        return $this->inner->write($key, $value, $ttl);
    }

    public function remove(string $key): bool
    {
        return $this->inner->remove($key);
    }

    public function exists(string $key): bool
    {
        return $this->inner->exists($key);
    }

    public function flush(): bool
    {
        return $this->inner->flush();
    }

    public function readMultiple(array $keys): array
    {
        return $this->inner->readMultiple($keys);
    }

    public function writeMultiple(array $values, ?int $ttl = null): bool
    {
        foreach (array_keys($values) as $key) {
            $this->writes[] = ['key' => (string) $key, 'ttl' => $ttl];
        }

        return $this->inner->writeMultiple($values, $ttl);
    }

    public function removeMultiple(array $keys): bool
    {
        return $this->inner->removeMultiple($keys);
    }

    public function stats(): AdapterStats
    {
        return $this->inner->stats();
    }

    public function name(): string
    {
        return 'ttl-recording';
    }

    public function isAvailable(): bool
    {
        return true;
    }
}
