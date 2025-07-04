<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

interface Serializer
{
    public function serialize(mixed $data): string;

    public function unserialize(string $data): mixed;
}
