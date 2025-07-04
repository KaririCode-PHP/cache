<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver;

use KaririCode\Cache\Contract\CacheTransactionManager;

class RedisDriver extends AbstractDriver
{
    private \Redis $redis;

    public function __construct(
        \Redis $redis,
        CacheTransactionManager $transactionManager
    ) {
        parent::__construct($transactionManager);
        $this->redis = $redis;
    }

    public function fetch(string $key): mixed
    {
        $value = $this->redis->get($key);

        return false === $value ? null : unserialize($value);
    }

    public function contains(string $key): bool
    {
        return $this->redis->exists($key);
    }

    protected function doSave(
        string $key,
        mixed $value,
        ?\DateTimeInterface $expiration = null
    ): bool {
        $serialized = serialize($value);
        if ($expiration) {
            $ttl = $expiration->getTimestamp() - time();

            return $this->redis->setex($key, $ttl, $serialized);
        }

        return $this->redis->set($key, $serialized);
    }

    protected function doDelete(string $key): bool
    {
        return $this->redis->del($key) > 0;
    }

    public function clear(): bool
    {
        return $this->redis->flushDB();
    }
}
