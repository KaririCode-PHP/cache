<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

interface CacheTransactionManager
{
    public function begin(): void;

    public function commit(): void;

    public function rollback(): void;

    public function addOperation(callable $operation): void;
}
