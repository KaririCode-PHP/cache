<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract\Transaction;

/**
 * Interface base para todas as operações de transação de cache.
 */
interface Operation
{
    public function getKey(): string;

    public function getTimestamp(): \DateTimeImmutable;
}
