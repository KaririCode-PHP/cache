<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract\Filesystem;

interface SerializationManager
{
    public function serialize(mixed $data): string;

    public function unserialize(string $data): mixed;

    public function canSerialize(mixed $data): bool;
}
