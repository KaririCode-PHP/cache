<?php

declare(strict_types=1);

namespace KaririCode\Cache\Transaction\Operation;

use KaririCode\Cache\Contract\Transaction\Operation;
use KaririCode\Cache\Exception\InvalidArgumentException;

final class SetOperation implements Operation
{
    public readonly \DateTimeImmutable $timestamp;

    public function __construct(
        public readonly string $key,
        public readonly mixed $value
    ) {
        if ('' === $key) {
            throw InvalidArgumentException::emptyKey();
        }
        $this->timestamp = new \DateTimeImmutable();
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getTimestamp(): \DateTimeImmutable
    {
        return $this->timestamp;
    }
}
