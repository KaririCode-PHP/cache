<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

interface SerializerStrategy
{
    public function supports(mixed $data): bool;

    public function serialize(mixed $data): string;

    public function unserialize(string $data): mixed;

    public function getPriority(): int;
}
