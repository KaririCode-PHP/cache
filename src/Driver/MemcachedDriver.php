<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver;

use KaririCode\Cache\Contract\CacheTransactionManager;

class MemcachedDriver extends AbstractDriver
{
    private \Memcached $memcached;

    public function __construct(
        \Memcached $memcached,
        CacheTransactionManager $transactionManager
    ) {
        parent::__construct($transactionManager);
        $this->memcached = $memcached;
    }

    public function fetch(string $key): mixed
    {
        $value = $this->memcached->get($key);
        if (\Memcached::RES_NOTFOUND === $this->memcached->getResultCode()) {
            return null;
        }

        return $value;
    }

    public function contains(string $key): bool
    {
        $this->memcached->get($key);

        return \Memcached::RES_NOTFOUND !== $this->memcached->getResultCode();
    }

    protected function doSave(
        string $key,
        mixed $value,
        ?\DateTimeInterface $expiration = null
    ): bool {
        $ttl = $expiration ? $expiration->getTimestamp() - time() : 0;

        return $this->memcached->set($key, $value, $ttl);
    }

    protected function doDelete(string $key): bool
    {
        return $this->memcached->delete($key);
    }

    public function clear(): bool
    {
        return $this->memcached->flush();
    }
}
