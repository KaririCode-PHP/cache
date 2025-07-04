<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver;

use KaririCode\Cache\Contract\CacheDriver;
use KaririCode\Cache\Contract\CacheTransactionManager;

abstract class AbstractDriver implements CacheDriver
{
    public function __construct(
        protected CacheTransactionManager $transactionManager
    ) {
    }

    public function save(
        string $key,
        mixed $value,
        ?\DateTimeInterface $expiration = null
    ): bool {
        $this->transactionManager->addOperation(function () use ($key, $value, $expiration) {
            return $this->doSave($key, $value, $expiration);
        });

        return true;
    }

    abstract protected function doSave(
        string $key,
        mixed $value,
        ?\DateTimeInterface $expiration = null
    ): bool;

    public function delete(string $key): bool
    {
        $this->transactionManager->addOperation(function () use ($key) {
            return $this->doDelete($key);
        });

        return true;
    }

    abstract protected function doDelete(string $key): bool;
}
